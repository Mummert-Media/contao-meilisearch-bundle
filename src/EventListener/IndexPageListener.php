<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Database;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $debug = (PHP_SAPI === 'cli');
        $url   = $set['url'] ?? $data['url'] ?? null;

        if ($debug) {
            echo "\n=============================\n";
            echo "INDEXPAGE HOOK START\n";
            echo "URL: " . ($url ?? '[no url]') . "\n";
        }

        if (!$url || !str_contains($content, 'MEILISEARCH_JSON')) {
            if ($debug) {
                echo "❌ No URL or no MEILISEARCH_JSON marker\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

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

        /* =====================
         * PRIORITY
         * ===================== */
        $priority =
            $parsed['event']['priority'] ??
            $parsed['news']['priority']  ??
            $parsed['page']['priority']  ??
            null;

        if ($priority !== null && $priority !== '') {
            $set['priority'] = (int) $priority;
        }

        /* =====================
         * KEYWORDS
         * ===================== */
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
            foreach (preg_split('/\s+/', trim($s)) as $p) {
                if ($p !== '') {
                    $kw[] = $p;
                }
            }
        }

        $keywords = $kw ? implode(' ', array_unique($kw)) : null;

        /* =====================
         * IMAGEPATH
         * ===================== */
        $image =
            $parsed['event']['searchimage'] ??
            $parsed['news']['searchimage']  ??
            $parsed['page']['searchimage']  ??
            $parsed['custom']['searchimage'] ??
            null;

        $imagepath = is_string($image) && $image !== '' ? trim($image) : null;

        /* =====================
         * STARTDATE
         * ===================== */
        $date =
            $parsed['event']['date'] ??
            $parsed['news']['date']  ??
            null;

        $startDate = null;
        if (is_string($date) && $date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) {
                $startDate = $ts;
            }
        }

        /* =====================
         * 🔥 WICHTIGER TEIL:
         * tl_search UPDATE
         * ===================== */
        $this->updateSearchRow($url, $keywords, $imagepath, $startDate, $debug);

        if ($debug) {
            echo "INDEXPAGE HOOK END\n";
            echo "=============================\n";
        }
    }

    private function updateSearchRow(
        string $url,
        ?string $keywords,
        ?string $imagepath,
        ?int $startDate,
        bool $debug
    ): void {
        $db = Database::getInstance();

        $fields = [];
        $values = [];

        if ($keywords !== null) {
            $fields[] = 'keywords = ?';
            $values[] = $keywords;
        }

        if ($imagepath !== null) {
            $fields[] = 'imagepath = ?';
            $values[] = $imagepath;
        }

        if ($startDate !== null) {
            $fields[] = 'startDate = ?';
            $values[] = $startDate;
        }

        if (!$fields) {
            if ($debug) {
                echo "ℹ️ Nothing to update in tl_search\n";
            }
            return;
        }

        $values[] = $url;

        $sql = 'UPDATE tl_search SET ' . implode(', ', $fields) . ' WHERE url = ?';

        $db->prepare($sql)->execute(...$values);

        if ($debug) {
            echo "✅ tl_search updated:\n";
            var_dump([
                'keywords'  => $keywords,
                'imagepath' => $imagepath,
                'startDate' => $startDate,
            ]);
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