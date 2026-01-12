<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\System;
use MummertMedia\ContaoMeilisearchBundle\Service\MeilisearchFileHelper;

class IndexPageListener
{
    public function __construct(
        private readonly MeilisearchFileHelper $fileHelper,
    ) {
    }

    private function debug(string $message, array $context = []): void
    {
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

                // IMAGEPATH
                if (!empty($parsed['page']['searchimage'] ?? null)) {
                    $set['imagepath'] = trim((string) $parsed['page']['searchimage']);
                }

                // STARTDATE
                if (is_numeric($parsed['event']['startDate'] ?? null)) {
                    $set['startDate'] = (int) $parsed['event']['startDate'];
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
         * DATEI-ERKENNUNG (NUR ERKENNUNG!)
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

        $this->debug('Indexable file links found', [
            'count' => count($fileLinks),
        ]);

        if ($fileLinks) {
            foreach ($fileLinks as $file) {
                $this->fileHelper->collect(
                    $file['url'],
                    $file['type'],
                    (int) ($data['pid'] ?? 0)
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