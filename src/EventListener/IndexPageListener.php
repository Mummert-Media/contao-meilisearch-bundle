<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\System;
use MummertMedia\ContaoMeilisearchBundle\Service\PdfIndexService;
use MummertMedia\ContaoMeilisearchBundle\Service\OfficeIndexService;

class IndexPageListener
{
    private ?PdfIndexService $pdfIndexService = null;
    private ?OfficeIndexService $officeIndexService = null;

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // ✅ IMMER: Service einmal pro Crawl holen + Tabelle einmal leeren
        if ($this->pdfIndexService === null) {
            $this->pdfIndexService = System::getContainer()->get(PdfIndexService::class);
            $this->pdfIndexService->resetTableOnce(); // darf NICHT von Checkboxen abhängen
        }

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
                 * IMAGEPATH
                 */
                $image =
                    $parsed['event']['searchimage']  ?? null ??
                    $parsed['news']['searchimage']   ?? null ??
                    $parsed['page']['searchimage']   ?? null ??
                    $parsed['custom']['searchimage'] ?? null;

                if (is_string($image) && $image !== '') {
                    $set['imagepath'] = trim($image);
                }

                /*
                 * STARTDATE
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
        $pdfEnabled = (bool) Config::get('meilisearch_index_pdfs');
        if ($pdfEnabled && (int) ($data['protected'] ?? 0) === 0) {

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
        $officeEnabled = (bool) Config::get('meilisearch_index_office');
        if ($officeEnabled && (int) ($data['protected'] ?? 0) === 0) {

            if ($this->officeIndexService === null) {
                $this->officeIndexService = System::getContainer()->get(OfficeIndexService::class);
            }

            $officeLinks = $this->findOfficeLinks($content);

            if ($officeLinks !== []) {
                $this->officeIndexService->handleOfficeLinks($officeLinks);
            }
        }
    }

    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($m[1]));
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

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