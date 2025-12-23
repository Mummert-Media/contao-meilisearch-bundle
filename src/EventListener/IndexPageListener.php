<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $debug = (PHP_SAPI === 'cli');

        if ($debug) {
            echo "\n=============================\n";
            echo "INDEXPAGE HOOK START\n";
            echo "URL: " . ($set['url'] ?? $data['url'] ?? '[no url]') . "\n";
        }

        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            if ($debug) {
                echo "❌ MEILISEARCH_JSON marker NOT found\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        // JSON aus Kommentar extrahieren + parsen
        $parsed = $this->extractMeilisearchJson($content);

        if ($parsed === null) {
            if ($debug) {
                echo "❌ Invalid JSON in MEILISEARCH_JSON\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        if ($debug) {
            echo "✅ MEILISEARCH_JSON parsed\n";
            var_dump($parsed);
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
            $data['priority'] = (int) $priority; // optional
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

            $parts = preg_split('/\s+/', trim($s)) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $kw[] = $p;
                }
            }
        }

        if ($kw) {
            $kw = array_values(array_unique($kw));
            $set['keywords'] = implode(' ', $kw);
            $data['keywords'] = $set['keywords']; // optional
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
            $set['imagepath'] = trim($image);
            $data['imagepath'] = $set['imagepath']; // optional
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
                $data['startDate'] = $ts; // optional
            }
        }

        if ($debug) {
            echo "---- FINAL \$set (what should be persisted) ----\n";
            var_dump([
                'priority'  => $set['priority']  ?? null,
                'keywords'  => $set['keywords']  ?? null,
                'imagepath' => $set['imagepath'] ?? null,
                'startDate' => $set['startDate'] ?? null,
            ]);

            echo "INDEXPAGE HOOK END\n";
            echo "=============================\n";
        }
    }

    private function extractMeilisearchJson(string $content): ?array
    {
        // Erwartetes Format:
        // <!--
        // MEILISEARCH_JSON
        // { ...json... }
        // -->
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = trim($m[1]);

        // BOM / Sonderzeichen am Anfang killen (kommt manchmal beim Copy/Paste vor)
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}