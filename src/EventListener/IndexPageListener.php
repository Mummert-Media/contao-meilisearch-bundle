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

                /*
                 * PRIORITY
                 */
                $priority =
                    $parsed['event']['priority'] ?? null ??
                    $parsed['news']['priority']  ?? null ??
                    $parsed['page']['priority']  ?? null;

                if ($priority !== null && $priority !== '') {
                    $set['priority'] = (int) $priority;
                }

                /*
                 * KEYWORDS
                 */
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
                        if ($word !== '') {
                            $keywords[] = $word;
                        }
                    }
                }

                if ($keywords) {
                    $set['keywords'] = implode(' ', array_unique($keywords));
                }

                /*
                 * IMAGEPATH (UUID)
                 */
                if (
                    isset($parsed['page']['searchimage'])
                    && is_string($parsed['page']['searchimage'])
                    && $parsed['page']['searchimage'] !== ''
                ) {
                    $set['imagepath'] = trim($parsed['page']['searchimage']);
                }

                /*
                 * STARTDATE (Unix Timestamp)
                 */
                $startDate =
                    $parsed['event']['startDate'] ?? null ??
                    $parsed['news']['startDate']  ?? null;

                if (is_numeric($startDate) && (int) $startDate > 0) {
                    $set['startDate'] = (int) $startDate;
                }

                /*
                 * =====================
                 * CHECKSUM-FIX
                 * =====================
                 */
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
         * PDF-INDEXIERUNG
         * =====================
         */
        if (
            (bool) Config::get('meilisearch_index_pdfs')
            && (int) ($data['protected'] ?? 0) === 0
        ) {
            try {
                $pdfLinks = $this->findPdfLinks($content);
                if ($pdfLinks !== []) {
                    $this->pdfIndexService->handlePdfLinks($pdfLinks);
                }
            } catch (\Throwable $e) {
                error_log('[ContaoMeilisearch] PDF indexing failed: ' . $e->getMessage());
            }
        }

        /*
         * =====================
         * OFFICE-INDEXIERUNG
         * =====================
         */
        if (
            (bool) Config::get('meilisearch_index_office')
            && (int) ($data['protected'] ?? 0) === 0
        ) {
            try {
                $officeLinks = $this->findOfficeLinks($content);
                if ($officeLinks !== []) {
                    $this->officeIndexService->handleOfficeLinks($officeLinks);
                }
            } catch (\Throwable $e) {
                error_log('[ContaoMeilisearch] Office indexing failed: ' . $e->getMessage());
            }
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

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[ContaoMeilisearch] Invalid MEILISEARCH_JSON: ' . json_last_error_msg());
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Findet PDF-Links im Content
     */
    private function findPdfLinks(string $content): array
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']*(?:\.pdf|p=pdf(?:%2F|\/)[^"\']*))["\'][^>]*>(.*?)<\/a>/is',
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
     * Findet Office-Links (docx, xlsx, pptx)
     */
    private function findOfficeLinks(string $content): array
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']*(?:\.(?:docx|xlsx|pptx)|p=(?:docx|xlsx|pptx)(?:%2F|\/)[^"\']*))["\'][^>]*>(.*?)<\/a>/is',
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
}