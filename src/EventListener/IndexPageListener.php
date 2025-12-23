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

        // 1. Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH')) {
            if (PHP_SAPI === 'cli') {
                echo "❌ MEILISEARCH marker NOT found in content\n";
            }
            return;
        }

        if (PHP_SAPI === 'cli') {
            echo "✅ MEILISEARCH marker found\n";
        }

        // 2. Marker extrahieren
        $markers = $this->extractMarkers($content);

        if (PHP_SAPI === 'cli') {
            echo "---- PARSED MARKERS ----\n";
            var_dump($markers);
            echo "------------------------\n";
        }

        if ($markers === []) {
            if (PHP_SAPI === 'cli') {
                echo "❌ Marker array EMPTY after parsing\n";
            }
            return;
        }

        // 3. PRIORITY
        if (isset($markers['event.priority'])) {
            $data['priority'] = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $data['priority'] = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $data['priority'] = (int) $markers['page.priority'];
        }

        // 4. KEYWORDS
        $keywords = [];
        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $keywords = array_merge(
                    $keywords,
                    preg_split('/\s+/', trim($markers[$key])) ?: []
                );
            }
        }

        if ($keywords) {
            $data['keywords'] = implode(' ', array_unique($keywords));
        }

        // 5. IMAGEPATH
        foreach (
            ['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage']
            as $key
        ) {
            if (!empty($markers[$key])) {
                $data['imagepath'] = trim($markers[$key]);
                break;
            }
        }

        // 6. STARTDATE
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime($markers[$key]);
                if ($ts !== false) {
                    $data['startDate'] = $ts;
                }
                break;
            }
        }

        // 7. FINAL STATE
        if (PHP_SAPI === 'cli') {
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

    private function extractMarkers(string $content): array
    {
        if (!preg_match('/<!--\s*MEILISEARCH(.*?)-->/s', $content, $m)) {
            return [];
        }

        $markers = [];
        foreach (preg_split('/\R/', trim($m[1])) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $markers[trim($k)] = trim($v);
        }

        return $markers;
    }
}