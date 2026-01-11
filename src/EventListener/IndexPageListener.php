<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\System;
use Doctrine\DBAL\Connection;

class IndexPageListener
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    private function debug(string $message, array $context = []): void
    {
        // Debug bewusst immer aktiv (bis du es wieder entfernst)
        // Kontext kurz halten, damit Logs nicht explodieren
        $ctx = $context
            ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

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

                if ($priority !== null && $priority !== '') {
                    $set['priority'] = (int) $priority;
                }

                // KEYWORDS
                $keywords = [];
                foreach ([
                             $parsed['event']['keywords'] ?? null,
                             $parsed['news']['keywords']  ?? null,
                             $parsed['page']['keywords']  ?? null,
                         ] as $src) {
                    if (is_string($src) && trim($src) !== '') {
                        foreach (preg_split('/\s+/', trim($src)) as $word) {
                            $keywords[] = $word;
                        }
                    }
                }

                if ($keywords) {
                    $set['keywords'] = implode(' ', array_unique($keywords));
                }

                // IMAGEPATH
                if (!empty($parsed['page']['searchimage'])) {
                    $set['imagepath'] = trim((string) $parsed['page']['searchimage']);
                }

                // STARTDATE
                $startDate =
                    $parsed['event']['startDate']
                    ?? $parsed['news']['startDate']
                    ?? null;

                if (is_numeric($startDate) && (int) $startDate > 0) {
                    $set['startDate'] = (int) $startDate;
                }

                // CHECKSUM
                $checksumSeed  = (string) ($data['checksum'] ?? '');
                $checksumSeed .= '|' . ($set['keywords']  ?? '');
                $checksumSeed .= '|' . ($set['priority']  ?? '');
                $checksumSeed .= '|' . ($set['imagepath'] ?? '');
                $checksumSeed .= '|' . ($set['startDate'] ?? '');

                $set['checksum'] = md5($checksumSeed);
            }
        }

        /*
         * =====================
         * DATEI-ERKENNUNG + UPSERT
         * =====================
         */
        if ((int) ($data['protected'] ?? 0) !== 0) {
            return;
        }

        if (!Config::get('meilisearch_index_files')) {
            return;
        }

        $links = $this->findAllLinks($content);
        $fileLinks = [];

        foreach ($links as $link) {
            $type = $this->detectIndexableFileType($link['url']);
            if ($type !== null) {
                $fileLinks[] = $link + ['type' => $type];
            }
        }

        if (!$fileLinks) {
            return;
        }

        $db   = $this->connection;
        $time = time();

        foreach ($fileLinks as $file) {
            $url = strtok($file['url'], '#');

            /*
             * =====================
             * URL-NORMALISIERUNG (NEU)
             * =====================
             */
            if (str_contains($url, 'p=')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                if (!empty($query['p'])) {
                    $url = '/' . ltrim(rawurldecode($query['p']), '/');
                    $this->debug('Normalized download URL', ['url' => $url]);
                }
            }

            $path = parse_url($url, PHP_URL_PATH);
            $abs  = $path ? TL_ROOT . '/' . ltrim($path, '/') : null;

            $mtime    = ($abs && is_file($abs)) ? filemtime($abs) : 0;
            $checksum = md5($url . '|' . $mtime);

            try {
                $existing = $db->fetchAssociative(
                    'SELECT id, checksum FROM tl_search_files WHERE url = ?',
                    [$url]
                );
            } catch (\Throwable $e) {
                $this->debug('DB error', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($existing) {
                $db->update(
                    'tl_search_files',
                    [
                        'tstamp'      => $time,
                        'last_seen'   => $time,
                        'page_id'     => (int) ($data['pid'] ?? 0),
                        'file_mtime'  => $mtime,
                        'checksum'    => $checksum,
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
                    ]
                );
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