<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;

#[AsHook('indexPage')]
class IndexPageListener
{
    public function __invoke(string $content, array $pageData, array &$indexData): void
    {
        // Debug nur im CLI (Crawler)
        $debug = (PHP_SAPI === 'cli');

        if ($debug) {
            echo "\n=============================\n";
            echo "INDEXPAGE HOOK START\n";
            echo "URL: " . ($pageData['url'] ?? '[no url]') . "\n";
        }

        // 1) Marker finden
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            if ($debug) {
                echo "❌ MEILISEARCH_JSON marker NOT found\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        // 2) JSON Block extrahieren
        $json = $this->extractJsonBlock($content);

        if ($json === null) {
            if ($debug) {
                echo "❌ Could not extract JSON block from MEILISEARCH_JSON\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        // 3) JSON dekodieren
        $parsed = json_decode($json, true);

        if (!is_array($parsed)) {
            if ($debug) {
                echo "❌ Invalid JSON in MEILISEARCH_JSON\n";
                echo "RAW:\n" . $json . "\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        if ($debug) {
            echo "✅ MEILISEARCH_JSON parsed\n";
            var_dump($parsed);
        }

        // 4) PRIORITY: event > news > page
        $priority = $parsed['event']['priority']
            ?? $parsed['news']['priority']
            ?? $parsed['page']['priority']
            ?? null;

        if ($priority !== null && $priority !== '') {
            $indexData['priority'] = (int) $priority;
        }

        // 5) KEYWORDS: event + news + page zusammenführen
        $kwParts = [];
        foreach (['event', 'news', 'page'] as $scope) {
            if (!empty($parsed[$scope]['keywords'])) {
                $kwParts[] = (string) $parsed[$scope]['keywords'];
            }
        }

        if ($kwParts) {
            $all = preg_split('/\s+/', trim(implode(' ', $kwParts))) ?: [];
            $all = array_values(array_unique(array_filter($all)));
            if ($all) {
                $indexData['keywords'] = implode(' ', $all);
            }
        }

        // 6) IMAGEPATH: custom > event > news > page
        $image = $parsed['custom']['searchimage']
            ?? $parsed['event']['searchimage']
            ?? $parsed['news']['searchimage']
            ?? $parsed['page']['searchimage']
            ?? null;

        if (!empty($image)) {
            $indexData['imagepath'] = trim((string) $image);
        }

        // 7) STARTDATE: event.date oder news.date -> timestamp
        $date = $parsed['event']['date'] ?? $parsed['news']['date'] ?? null;
        if (!empty($date)) {
            $ts = strtotime((string) $date);
            if ($ts !== false) {
                $indexData['startDate'] = $ts;
            }
        }

        if ($debug) {
            echo "---- FINAL \$indexData (what should be persisted) ----\n";
            var_dump([
                'priority'  => $indexData['priority']  ?? null,
                'keywords'  => $indexData['keywords']  ?? null,
                'imagepath' => $indexData['imagepath'] ?? null,
                'startDate' => $indexData['startDate'] ?? null,
            ]);
            echo "INDEXPAGE HOOK END\n";
            echo "=============================\n";
        }
    }

    private function extractJsonBlock(string $content): ?string
    {
        // Nimmt alles zwischen:
        // <!--
        // MEILISEARCH_JSON
        // { ... }
        // -->
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        return trim($m[1]);
    }
}