<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Doctrine\DBAL\Connection;

class IndexPageListener
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    private function debug(string $message, array $context = []): void
    {
        $ctx = $context
            ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        error_log('[ContaoMeilisearch][IndexPageListener] ' . $message . $ctx);
    }

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $this->debug('Hook start', [
            'url' => $data['url'] ?? null,
        ]);

        /*
         * =====================
         * SEITEN-METADATEN
         * =====================
         */
        if (str_contains($content, 'MEILISEARCH_JSON')) {
            $parsed = $this->extractMeilisearchJson($content);

            if (is_array($parsed)) {
                if (isset($parsed['page']['priority'])) {
                    $set['priority'] = (int) $parsed['page']['priority'];
                }

                if (!empty($parsed['page']['searchimage'])) {
                    $set['imagepath'] = (string) $parsed['page']['searchimage'];
                }

                $checksumSeed  = (string) ($data['checksum'] ?? '');
                $checksumSeed .= '|' . ($set['priority']  ?? '');
                $checksumSeed .= '|' . ($set['imagepath'] ?? '');

                $set['checksum'] = md5($checksumSeed);
            }
        }

        /*
         * =====================
         * DATEI-ERKENNUNG
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
             * 🔥 DOWNLOAD-URL-NORMALISIERUNG (FIX)
             * =====================
             */
            if (str_contains($url, 'p=')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);

                if (!empty($q['p'])) {
                    $url = '/' . ltrim(rawurldecode($q['p']), '/');

                    $this->debug('Normalized download URL', [
                        'normalized' => $url,
                    ]);
                }
            }

            if (!is_string($url) || $url === '') {
                continue;
            }

            $path = parse_url($url, PHP_URL_PATH);
            $abs  = $path ? TL_ROOT . '/' . ltrim($path, '/') : null;

            $mtime    = ($abs && is_file($abs)) ? filemtime($abs) : 0;
            $checksum = md5($url . '|' . $mtime);

            try {
                $existing = $db->fetchAssociative(
                    'SELECT id FROM tl_search_files WHERE url = ?',
                    [$url]
                );
            } catch (\Throwable $e) {
                $this->debug('DB error (file indexing)', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($existing) {
                $db->update(
                    'tl_search_files',
                    [
                        'tstamp'     => $time,
                        'last_seen'  => $time,
                        'page_id'    => (int) ($data['pid'] ?? 0),
                        'file_mtime' => $mtime,
                        'checksum'   => $checksum,
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

        $this->debug('Hook end');
    }

    /* === Hilfsmethoden === */

    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $data = json_decode(trim($m[1]), true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
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
                    $ext = strtolower(pathinfo($query[$param], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                        return $ext;
                    }
                }
            }
        }

        return null;
    }
}