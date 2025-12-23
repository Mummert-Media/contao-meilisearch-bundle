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

        // JSON aus Kommentar extrahieren + parsen
        $parsed = $this->extractMeilisearchJson($content);

        if ($parsed === null) {
            return;
        }

        /*
         * =====================
         * PRIORITY (event > news > page)
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
         * KEYWORDS (merge)
         * =====================
         */
        $keywordSources = [
            $parsed['event']['keywords'] ?? null,
            $parsed['news']['keywords']  ?? null,
            $parsed['page']['keywords']  ?? null,
        ];

        $kw = [];
        foreach ($keywordSources as $s) {
            if (!is_string($s) || trim($s) === '') {
                continue;
            }

            foreach (preg_split('/\s+/', trim($s)) ?: [] as $p) {
                if ($p !== '') {
                    $kw[] = $p;
                }
            }
        }

        if ($kw) {
            $set['keywords'] = implode(' ', array_unique($kw));
        }

        /*
         * =====================
         * IMAGEPATH (event > news > page > custom)
         * =====================
         */
        $image =
            $parsed['event']['searchimage'] ?? null ??
            $parsed['news']['searchimage']  ?? null ??
            $parsed['page']['searchimage']  ?? null ??
            $parsed['custom']['searchimage'] ?? null;

        if (is_string($image) && $image !== '') {
            $set['searchImage'] = trim($image);
        }

        /*
         * =====================
         * STARTDATE (event.date/news.date => timestamp)
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
}