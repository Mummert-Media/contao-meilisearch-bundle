<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\System;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;

class IndexPageListener
{
    public function __construct()
    {
    }

    private function debug(string $message, array $context = []): void
    {
        // Debug bewusst immer aktiv (bis du es wieder entfernst)
        // Kontext kurz halten, damit Logs nicht explodieren
        $ctx = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        error_log('[ContaoMeilisearch][IndexPageListener] ' . $message . $ctx);
    }

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $this->debug('Hook start', [
            'url'       => $data['url'] ?? null,
            'protected' => $data['protected'] ?? null,
            'checksum'  => $data['checksum'] ?? null,
            'set_keys'  => array_keys($set),
        ]);

        /*
         * =====================
         * SEITEN-METADATEN
         * =====================
         */
        $hasMeta = str_contains($content, 'MEILISEARCH_JSON');
        $this->debug('Meta marker scan', [
            'contains_MEILISEARCH_JSON' => $hasMeta,
            'content_length'            => strlen($content),
        ]);

        if ($hasMeta) {
            try {
                $parsed = $this->extractMeilisearchJson($content);
                $this->debug('extractMeilisearchJson(): done', [
                    'parsed_is_array' => is_array($parsed),
                    'parsed_keys'     => is_array($parsed) ? array_keys($parsed) : null,
                ]);
            } catch (\Throwable $e) {
                $this->debug('Failed to extract MEILISEARCH_JSON', [
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $parsed = null;
            }

            if (is_array($parsed)) {

                // PRIORITY
                $priority =
                    $parsed['event']['priority']
                    ?? $parsed['news']['priority']
                    ?? $parsed['page']['priority']
                    ?? null;

                $this->debug('Meta: priority candidate', ['priority' => $priority]);

                if ($priority !== null && $priority !== '') {
                    $set['priority'] = (int) $priority;
                }

                // KEYWORDS
                $keywordSources = [
                    $parsed['event']['keywords'] ?? null,
                    $parsed['news']['keywords']  ?? null,
                    $parsed['page']['keywords']  ?? null,
                ];

                $this->debug('Meta: keyword sources', ['sources' => $keywordSources]);

                $keywords = [];
                foreach ($keywordSources as $src) {
                    if (!is_string($src) || trim($src) === '') {
                        continue;
                    }
                    foreach (preg_split('/\s+/', trim($src)) as $word) {
                        $keywords[] = $word;
                    }
                }

                if ($keywords) {
                    $set['keywords'] = implode(' ', array_unique($keywords));
                }

                $this->debug('Meta: keywords result', [
                    'keywords' => $set['keywords'] ?? null,
                ]);

                // IMAGEPATH (UUID)
                $searchImage = $parsed['page']['searchimage'] ?? null;
                $this->debug('Meta: searchimage candidate', ['searchimage' => $searchImage]);

                if (!empty($searchImage)) {
                    $set['imagepath'] = trim((string) $searchImage);
                }

                // STARTDATE
                $startDate =
                    $parsed['event']['startDate']
                    ?? $parsed['news']['startDate']
                    ?? null;

                $this->debug('Meta: startDate candidate', ['startDate' => $startDate]);

                if (is_numeric($startDate) && (int) $startDate > 0) {
                    $set['startDate'] = (int) $startDate;
                }

                // CHECKSUM
                try {
                    $checksumSeed  = (string) ($data['checksum'] ?? '');
                    $checksumSeed .= '|' . ($set['keywords']  ?? '');
                    $checksumSeed .= '|' . ($set['priority']  ?? '');
                    $checksumSeed .= '|' . ($set['imagepath'] ?? '');
                    $checksumSeed .= '|' . ($set['startDate'] ?? '');

                    $set['checksum'] = md5($checksumSeed);

                    $this->debug('Checksum generated', [
                        'seed_preview' => substr($checksumSeed, 0, 120) . (strlen($checksumSeed) > 120 ? '…' : ''),
                        'checksum'     => $set['checksum'],
                    ]);
                } catch (\Throwable $e) {
                    $this->debug('Failed to generate checksum', [
                        'error' => $e->getMessage(),
                        'class' => $e::class,
                    ]);
                }
            }
        }

        /*
         * =====================
         * DATEI-ERKENNUNG + UPSERT
         * =====================
         */
        if ((int) ($data['protected'] ?? 0) !== 0) {
            $this->debug('Abort: protected page', ['protected' => $data['protected'] ?? null]);
            return;
        }

        $indexFiles = (bool) Config::get('meilisearch_index_files');

        $this->debug('File indexing setting', [
            'meilisearch_index_files' => $indexFiles,
        ]);

        if (!$indexFiles) {
            $this->debug('Abort: file indexing disabled');
            return;
        }

        $links = $this->findAllLinks($content);
        $this->debug('Links found', ['count' => count($links)]);

        $fileLinks = [];

        foreach ($links as $link) {
            $type = $this->detectIndexableFileType($link['url']);
            if ($type !== null) {
                $fileLinks[] = $link + ['type' => $type];
            }
        }

        $this->debug('Indexable file links found', [
            'count' => count($fileLinks),
            'types' => array_count_values(array_column($fileLinks, 'type')),
        ]);

        if ($fileLinks) {
            $db   = System::getContainer()->get('database_connection');
            $time = time();

            $projectDir = System::getContainer()->getParameter('kernel.project_dir');

            foreach ($fileLinks as $file) {
                try {
                    // -------------------------------------------------
                    // URL normalisieren (Fragment weg)
                    // -------------------------------------------------
                    $url = strtok($file['url'], '#');
                    $parts = parse_url($url);

                    // -------------------------------------------------
                    // ⬅️ NEU: externe Links überspringen
                    // -------------------------------------------------
                    if (!empty($parts['host'])) {
                        // absolute URL → nur erlauben, wenn eigener Host
                        $currentHost = parse_url(System::getContainer()->get('request_stack')->getCurrentRequest()?->getSchemeAndHttpHost() ?? '', PHP_URL_HOST);
                        if ($currentHost && $parts['host'] !== $currentHost) {
                            continue;
                        }
                    }

                    // -------------------------------------------------
                    // ⬅️ NEU: lokalen Dateipfad ermitteln
                    // -------------------------------------------------
                    $normalizedPath = null;

                    // 1) direkter Pfad /files/…
                    if (!empty($parts['path'])) {
                        $candidate = ltrim(urldecode($parts['path']), '/');
                        if (str_starts_with($candidate, 'files/')) {
                            $normalizedPath = $candidate;
                        }
                    }

                    // 2) Download-Parameter ?file= / ?p= / ?f=
                    if (!$normalizedPath && !empty($parts['query'])) {
                        parse_str($parts['query'], $query);
                        foreach (['file', 'p', 'f'] as $param) {
                            if (!empty($query[$param])) {
                                $candidate = ltrim(urldecode(html_entity_decode((string) $query[$param], ENT_QUOTES)), '/');
                                if (str_starts_with($candidate, 'files/')) {
                                    $normalizedPath = $candidate;
                                    break;
                                }
                            }
                        }
                    }

                    // -------------------------------------------------
                    // ⬅️ NEU: wenn keine lokale Datei → skip
                    // -------------------------------------------------
                    if (!$normalizedPath || !is_file($projectDir . '/public/' . $normalizedPath)) {
                        continue;
                    }

                    // -------------------------------------------------
                    // UUID aus normalisiertem Pfad
                    // -------------------------------------------------
                    $uuid    = null;
                    $uuidBin = null;

                    if (interface_exists(VirtualFilesystemInterface::class)) {
                        try {
                            $vfs  = System::getContainer()->get(VirtualFilesystemInterface::class);
                            $uuid = $vfs->pathToUuid($normalizedPath);
                        } catch (\Throwable) {
                            $uuid = null;
                        }
                    }

                    if (!$uuid) {
                        $fileModel = FilesModel::findByPath($normalizedPath);
                        if ($fileModel) {
                            $uuid = $fileModel->uuid;
                        }
                    }

                    if ($uuid) {
                        $uuidBin = StringUtil::uuidToBin($uuid);
                    }

                    // -------------------------------------------------
                    // bestehende Logik (unverändert)
                    // -------------------------------------------------
                    $abs = $projectDir . '/public/' . $normalizedPath;

                    $mtime    = is_file($abs) ? filemtime($abs) : 0;
                    $checksum = md5($url . '|' . $mtime);

                    $existing = $db->fetchAssociative(
                        'SELECT id FROM tl_search_files WHERE url = ?',
                        [$url]
                    );

                    if ($existing) {
                        $db->update(
                            'tl_search_files',
                            [
                                'tstamp'     => $time,
                                'last_seen'  => $time,
                                'page_id'    => (int) ($data['pid'] ?? 0),
                                'file_mtime' => $mtime,
                                'checksum'   => $checksum,
                                'uuid'       => $uuidBin,
                            ],
                            ['id' => $existing['id']]
                        );
                    } else {
                        $db->insert(
                            'tl_search_files',
                            [
                                'tstamp'     => $time,
                                'last_seen'  => $time,
                                'type'       => $file['type'],
                                'url'        => $url,
                                'title'      => $file['linkText'] ?? basename($url),
                                'page_id'    => (int) ($data['pid'] ?? 0),
                                'file_mtime' => $mtime,
                                'checksum'   => $checksum,
                                'uuid'       => $uuidBin,
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    $this->debug('File upsert FAILED', [
                        'url'   => $file['url'] ?? null,
                        'error' => $e->getMessage(),
                        'class' => $e::class,
                        'code'  => $e->getCode(),
                    ]);
                }
            }
        }


        $this->debug('Hook end', [
            'final_set_keys' => array_keys($set),
        ]);
    }

    /* === Hilfsmethoden unverändert === */

    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($m[1]));
        $data = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($data)
            ? $data
            : null;
    }

    private function findAllLinks(string $content): array
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $content,
            $matches
        )) {
            return [];
        }

        $result = [];

        foreach ($matches[1] as $i => $href) {
            $result[] = [
                'url'      => html_entity_decode($href),
                'linkText' => trim(strip_tags($matches[2][$i])) ?: null,
            ];
        }

        return $result;
    }

    private function detectIndexableFileType(string $url): ?string
    {
        $url = strtok($url, '#');

        $parts = parse_url($url);
        if (!$parts) {
            return null;
        }

        if (!empty($parts['path'])) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                return $ext;
            }
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (['file', 'p', 'f'] as $param) {
                if (!empty($query[$param])) {
                    $candidate = rawurldecode(html_entity_decode((string) $query[$param], ENT_QUOTES));
                    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

                    if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                        return $ext;
                    }
                }
            }
        }

        return null;
    }
}