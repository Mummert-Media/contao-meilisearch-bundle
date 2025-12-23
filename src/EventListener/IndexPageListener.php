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
            echo "URL: " . ($set['url'] ?? '[no url]') . "\n";
        }

        // --------------------------------------------------
        // 1. MEILISEARCH_JSON finden
        // --------------------------------------------------
        if (
            !preg_match(
                '#<!--\s*MEILISEARCH_JSON\s*(.*?)\s*-->#s',
                $content,
                $m
            )
        ) {
            if ($debug) {
                echo "❌ MEILISEARCH_JSON not found\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        $json = trim($m[1]);
        $meta = json_decode($json, true);

        if (!is_array($meta)) {
            if ($debug) {
                echo "❌ Invalid JSON in MEILISEARCH_JSON\n";
                echo "RAW JSON:\n$json\n";
                echo "JSON ERROR: " . json_last_error_msg() . "\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        if ($debug) {
            echo "✅ MEILISEARCH_JSON parsed\n";
            var_dump($meta);
        }

        // --------------------------------------------------
        // 2. PRIORITY (event > news > page)
        // --------------------------------------------------
        foreach (['event', 'news', 'page'] as $scope) {
            if (!empty($meta[$scope]['priority'])) {
                $set['priority'] = (int) $meta[$scope]['priority'];
                break;
            }
        }

        // --------------------------------------------------
        // 3. KEYWORDS (kombinieren)
        // --------------------------------------------------
        $keywords = [];

        foreach (['event', 'news', 'page'] as $scope) {
            if (!empty($meta[$scope]['keywords'])) {
                $parts = preg_split(
                    '/\s+/',
                    trim((string) $meta[$scope]['keywords'])
                ) ?: [];

                $keywords = array_merge($keywords, $parts);
            }
        }

        if ($keywords) {
            $set['keywords'] = implode(' ', array_unique($keywords));
        }

        // --------------------------------------------------
        // 4. IMAGEPATH
        // Reihenfolge: custom > event > news > page
        // --------------------------------------------------
        foreach (
            [
                $meta['custom']['searchimage'] ?? null,
                $meta['event']['searchimage']  ?? null,
                $meta['news']['searchimage']   ?? null,
                $meta['page']['searchimage']   ?? null,
            ] as $img
        ) {
            if ($img) {
                $set['imagepath'] = trim((string) $img);
                break;
            }
        }

        // --------------------------------------------------
        // 5. STARTDATE
        // --------------------------------------------------
        foreach (['event', 'news'] as $scope) {
            if (!empty($meta[$scope]['date'])) {
                $ts = strtotime((string) $meta[$scope]['date']);
                if ($ts !== false) {
                    $set['startDate'] = $ts;
                }
                break;
            }
        }

        // --------------------------------------------------
        // DEBUG
        // --------------------------------------------------
        if ($debug) {
            echo "---- FINAL \$set ----\n";
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
}