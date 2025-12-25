<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            return;
        }

        // JSON aus Kommentar extrahieren
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
         * PDF DEBUG (NUR ERKENNEN)
         * =====================
         */
        $this->debugPdfLinks($content);
    }

    /* =====================================================
     * JSON-Parser
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
     * PDF DEBUG
     * ===================================================== */

    private function debugPdfLinks(string $content): void
    {
        // irgendein <a href="..."> mit PDF (direkt oder über ?p=)
        if (preg_match(
            '/<a\s+[^>]*href=["\'][^"\']*(\.pdf|\bp=pdf\/)[^"\']*["\']/i',
            $content
        )) {
            error_log('PDF-Link gefunden');
        }
    }
}