<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        if (PHP_SAPI === 'cli') {
            echo "\n=============================\n";
            echo "INDEXPAGE HOOK START\n";
            echo "URL: " . ($set['url'] ?? '[no url]') . "\n";
        }

        // --------------------------------------------------
        // 1. JSON-Marker finden
        // --------------------------------------------------
        if (
            !preg_match('#MEILISEARCH_JSON\s*(\{.*?\})#s', $content, $m)
        ) {
            if (PHP_SAPI === 'cli') {
                echo "❌ MEILISEARCH_JSON not found\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        $meta = json_decode($m[1], true);

        if (!is_array($meta)) {
            if (PHP_SAPI === 'cli') {
                echo "❌ Invalid JSON in MEILISEARCH_JSON\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        if (PHP_SAPI === 'cli') {
            echo "✅ MEILISEARCH_JSON parsed\n";
            var_dump($meta);
        }

        // --------------------------------------------------
        // 2. PRIORITY (klar definierte Reihenfolge)
        // --------------------------------------------------
        if (isset($meta['event']['priority'])) {
            $data['priority'] = (int) $meta['event']['priority'];
        } elseif (isset($meta['news']['priority'])) {
            $data['priority'] = (int) $meta['news']['priority'];
        } elseif (isset($meta['page']['priority'])) {
            $data['priority'] = (int) $meta['page']['priority'];
        }

        // --------------------------------------------------
        // 3. KEYWORDS
        // --------------------------------------------------
        $keywords = [];

        foreach (['event', 'news', 'page'] as $scope) {
            if (!empty($meta[$scope]['keywords'])) {
                $keywords = array_merge(
                    $keywords,
                    preg_split('/\s+/', trim($meta[$scope]['keywords'])) ?: []
                );
            }
        }

        if ($keywords) {
            $data['keywords'] = implode(' ', array_unique($keywords));
        }

        // --------------------------------------------------
        // 4. IMAGEPATH (custom > event > news > page)
        // --------------------------------------------------
        foreach (
            [
                $meta['custom']['searchimage'] ?? null,
                $meta['event']['searchimage'] ?? null,
                $meta['news']['searchimage'] ?? null,
                $meta['page']['searchimage'] ?? null,
            ] as $uuid
        ) {
            if ($uuid) {
                $data['imagepath'] = (string) $uuid;
                break;
            }
        }

        // --------------------------------------------------
        // 5. STARTDATE
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
        // DEBUG
        // --------------------------------------------------
        if (PHP_SAPI === 'cli') {
            echo "---- FINAL \$data ----\n";
            var_dump([
                'priority'  => $data['priority']  ?? null,
                'keywords'  => $data['keywords']  ?? null,
                'imagepath' => $data['imagepath'] ?? null,
                'startDate' => $data['startDate'] ?? null,
            ]);
            echo "INDEXPAGE HOOK END\n";
            echo "=============================\n";
        }
    }
}