<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use MummertMedia\ContaoMeilisearchBundle\Service\PdfIndexService;
use MummertMedia\ContaoMeilisearchBundle\Service\OfficeIndexService;

class IndexPageListener
{
    public function __construct(
        private readonly PdfIndexService $pdfIndexService,
        private readonly OfficeIndexService $officeIndexService,
    ) {}

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        /*
         * =====================
         * PDF: Reset genau 1× pro Crawl
         * =====================
         */
        try {
            $this->pdfIndexService->resetTableOnce();
        } catch (\Throwable $e) {
            error_log('[ContaoMeilisearch] PDF reset failed: ' . $e->getMessage());
        }

        /*
         * =====================
         * SEITEN-METADATEN
         * =====================
         */
        if (str_contains($content, 'MEILISEARCH_JSON')) {
            try {
                $parsed = $this->extractMeilisearchJson($content);
            } catch (\Throwable $e) {
                error_log('[ContaoMeilisearch] Failed to extract MEILISEARCH_JSON: ' . $e->getMessage());
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
                try {
                    $checksumSeed  = (string) ($data['checksum'] ?? '');
                    $checksumSeed .= '|' . ($set['keywords']  ?? '');
                    $checksumSeed .= '|' . ($set['priority']  ?? '');
                    $checksumSeed .= '|' . ($set['imagepath'] ?? '');
                    $checksumSeed .= '|' . ($set['startDate'] ?? '');

                    $set['checksum'] = md5($checksumSeed);
                } catch (\Throwable $e) {
                    error_log('[ContaoMeilisearch] Failed to generate checksum: ' . $e->getMessage());
                }
            }
        }

        /*
         * =====================
         * DATEI-INDEXIERUNG (PDF / OFFICE)
         * =====================
         */
        if ((int) ($data['protected'] ?? 0) !== 0) {
            return;
        }

        $indexPdfs   = (bool) Config::get('meilisearch_index_pdfs');
        $indexOffice = (bool) Config::get('meilisearch_index_office');

        if (!$indexPdfs && !$indexOffice) {
            return;
        }

        $links = $this->findAllLinks($content);

        $pdfLinks    = [];
        $officeLinks = [];

        foreach ($links as $link) {
            $type = $this->detectIndexableFileType($link['url']);

            if ($type === 'pdf' && $indexPdfs) {
                $pdfLinks[] = $link;
                continue;
            }

            if (
                in_array($type, ['docx', 'xlsx', 'pptx'], true)
                && $indexOffice
            ) {
                $officeLinks[] = $link;
            }
        }

        try {
            if ($pdfLinks !== []) {
                $this->pdfIndexService->handlePdfLinks($pdfLinks);
            }

            if ($officeLinks !== []) {
                $this->officeIndexService->handleOfficeLinks($officeLinks);
            }
        } catch (\Throwable $e) {
            error_log('[ContaoMeilisearch] File indexing failed: ' . $e->getMessage());
        }
    }

    /**
     * Extrahiert MEILISEARCH_JSON aus HTML-Kommentar
     */
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

    /**
     * Sammle alle <a href="…"> Links
     */
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

    /**
     * Ermittelt indexierbaren Dateityp (pdf|docx|xlsx|pptx) oder null
     */
    private function detectIndexableFileType(string $url): ?string
    {
        // Hash entfernen
        $url = strtok($url, '#');

        $parts = parse_url($url);
        if (!$parts) {
            return null;
        }

        // direkter Pfad (/files/…)
        if (!empty($parts['path'])) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                return $ext;
            }
        }

        // Query-Parameter (Contao 4 + 5)
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (['file', 'p', 'f'] as $param) {
                if (!empty($query[$param])) {
                    $candidate = urldecode((string) $query[$param]);
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