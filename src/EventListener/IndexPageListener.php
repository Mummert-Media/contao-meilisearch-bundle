<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\System;
use MummertMedia\ContaoMeilisearchBundle\Service\PdfIndexService;

class IndexPageListener
{
    private ?PdfIndexService $pdfIndexService = null;

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
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
                if ($word !== '') {
                    $keywords[] = $word;
                }
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

        if ($pdfLinks !== []) {
            error_log('PDF gefunden');

            // PdfIndexService lazy aus dem Container holen
            if ($this->pdfIndexService === null) {
                $this->pdfIndexService = System::getContainer()->get(PdfIndexService::class);
            }

            $this->pdfIndexService->startCrawl();
            $this->pdfIndexService->handlePdfLinks($pdfLinks);
        }
    }

    /* =====================================================
     * JSON aus Marker extrahieren
     * ===================================================== */
    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($m[1]));
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /* =====================================================
     * PDF-Links im Markup finden
     * ===================================================== */
    private function findPdfLinks(string $content): array
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']*(?:\.pdf|p=pdf(?:%2F|\/)[^"\']*))["\']/i',
            $content,
            $matches
        )) {
            return [];
        }

        return array_unique(
            array_map('html_entity_decode', $matches[1])
        );
    }
}