<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\System;
use MummertMedia\ContaoMeilisearchBundle\Service\PdfIndexService;

class IndexPageListener
{
    private ?PdfIndexService $pdfIndexService = null;

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        /*
         * =====================================================
         * IMMER: Service einmal pro Crawl initialisieren
         * + Tabelle initial leeren (auch wenn Feature später deaktiviert wurde)
         * =====================================================
         */
        if ($this->pdfIndexService === null) {
            $this->pdfIndexService = System::getContainer()->get(PdfIndexService::class);
            $this->pdfIndexService->resetTableOnce();
        }

        /*
         * =====================================================
         * PDF-Indexierung global deaktiviert?
         * → ab hier nichts mehr tun (aber Reset ist schon passiert)
         * =====================================================
         */
        if (!Config::get('meilisearch_index_pdfs')) {
            return;
        }

        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            return;
        }

        $parsed = $this->extractMeilisearchJson($content);
        if ($parsed === null) {
            return;
        }

        /*
         * =====================
         * PRIORITY
         * =====================
         */
        $priority =
            $parsed['event']['priority'] ?? null ??
            $parsed['news']['priority']  ?? null ??
            $parsed['page']['priority']  ?? null;

        if ($priority !== null && $priority !== '') {
            $set['priority'] = (int) $priority;
        }

        /*
         * =====================
         * KEYWORDS
         * =====================
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
                $keywords[] = $word;
            }
        }

        if ($keywords) {
            $set['keywords'] = implode(' ', array_unique($keywords));
        }

        /*
         * =====================
         * IMAGEPATH
         * =====================
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
         * =====================
         * STARTDATE
         * =====================
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

        /*
         * =====================
         * PDF-ERKENNUNG
         * =====================
         */
        $pdfLinks = $this->findPdfLinks($content);

        // PDFs NUR auf öffentlichen Seiten indexieren
        if ($pdfLinks !== [] && ($data['protected'] ?? 0) == 0) {
            $this->pdfIndexService->handlePdfLinks($pdfLinks);
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
}