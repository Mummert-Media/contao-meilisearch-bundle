<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $isCli = (PHP_SAPI === 'cli');

        if ($isCli) {
            echo "\n=============================\n";
            echo "INDEXPAGE HOOK START\n";
            echo "URL: " . ($set['url'] ?? '[no url]') . "\n";
        }

        // --------------------------------------------------
        // 1. MEILISEARCH_JSON Kommentar finden
        // --------------------------------------------------
        if (
            !preg_match(
                '#<!--\s*MEILISEARCH_JSON\s*(.*?)\s*-->#s',
                $content,
                $m
            )
        ) {
            if ($isCli) {
                echo "❌ MEILISEARCH_JSON not found\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        $json = trim($m[1]);
        $meta = json_decode($json, true);

        if (!is_array($meta)) {
            if ($isCli) {
                echo "❌ Invalid JSON in MEILISEARCH_JSON\n";
                echo "RAW JSON:\n$json\n";
                echo "JSON ERROR: " . json_last_error_msg() . "\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        if ($isCli) {
            echo "✅ MEILISEARCH_JSON parsed\n";
            var_dump($meta);
        }

        // --------------------------------------------------
        // 2. PRIORITY (event > news > page)
        // --------------------------------------------------
        foreach (['event', 'news', 'page'] as $scope) {
            if (!empty($meta[$scope]['priority'])) {
                $data['priority'] = (int) $meta[$scope]['priority'];
                break;
            }
        }

        // --------------------------------------------------
        // 3. KEYWORDS (zusammenführen)
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
            $data['keywords'] = implode(' ', array_unique($keywords));
        }

        // --------------------------------------------------
        // 4. IMAGEPATH
        // Reihenfolge:
        // event > news > custom > page
        // --------------------------------------------------
        foreach (
            [
                $meta['event']['searchimage']  ?? null,
                $meta['news']['searchimage']   ?? null,
                $meta['custom']['searchimage'] ?? null,
                $meta['page']['searchimage']   ?? null,
            ] as $img
        ) {
            if ($img) {
                $data['imagepath'] = trim((string) $img);
                break;
            }
        }

        // --------------------------------------------------
        // 5. STARTDATE (event > news)
        // --------------------------------------------------
        foreach (['event', 'news'] as $scope) {
            if (!empty($meta[$scope]['date'])) {
                $ts = strtotime($meta[$scope]['date']);
                if ($ts !== false) {
                    $data['startDate'] = $ts;
                }
                break;
            }
        }

        // --------------------------------------------------
        // 6. DEBUG FINAL
        // --------------------------------------------------
        if ($isCli) {
            echo "---- FINAL \$data ----\n";
            var_dump([
                'priority'  => $data['priority']  ?? null,
                'keywords'  => $data['keywords']  ?? null,
                'imagepath' => $data['imagepath'] ?? null,
                'startDate' => $data['startDate'] ?? null,
            ]);

            echo "---- RAW \$data ----\n";
            var_dump($data);

            echo "---- RAW \$set ----\n";
            var_dump($set);

            echo "INDEXPAGE HOOK END\n";
            echo "=============================\n";
        }
    }
}