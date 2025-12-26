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
        $this->pdfIndexService->resetTableOnce();

        /*
         * =====================
         * SEITEN-METADATEN (IMMER)
         * =====================
         */
        if (str_contains($content, 'MEILISEARCH_JSON')) {
            $parsed = $this->extractMeilisearchJson($content);

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
                        $word = trim($word);
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
                 * STARTDATE (für Sortierung)
                 */
                $date =
                    $parsed['event']['date'] ?? null ??
                    $parsed['news']['date']  ?? null;

                if (is_string($date) && $date !== '') {
                    $ts = strtotime($date);
                    if ($ts !== false) {
                        $set['startDate'] = $ts;
                    }
                }
            }
        }

        /*
         * =====================
         * PDF-INDEXIERUNG (OPTIONAL)
         * =====================
         */
        if (
            (bool) Config::get('meilisearch_index_pdfs')
            && (int) ($data['protected'] ?? 0) === 0
        ) {
            $pdfLinks = $this->findPdfLinks($content);
            if ($pdfLinks !== []) {
                $this->pdfIndexService->handlePdfLinks($pdfLinks);
            }
        }

        /*
         * =====================
         * OFFICE-INDEXIERUNG (OPTIONAL)
         * =====================
         */
        if (
            (bool) Config::get('meilisearch_index_office')
            && (int) ($data['protected'] ?? 0) === 0
        ) {
            $officeLinks = $this->findOfficeLinks($content);
            if ($officeLinks !== []) {
                $this->officeIndexService->handleOfficeLinks($officeLinks);
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

        return is_array($data) ? $data : null;
    }

    /**
     * Findet PDF-Links im Content
     *
     * @return array<int,array{url:string,linkText:?string}>
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
     * Findet Office-Links (docx, xlsx, pptx) im Content
     *
     * @return array<int,array{url:string,linkText:?string}>
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