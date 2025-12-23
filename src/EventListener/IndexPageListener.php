<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Database;

class IndexPageListener
{
    public function onIndexPage(array &$data, array $set, array $page): void
    {
        // --------------------------------------------------
        // DEBUG START
        // --------------------------------------------------
        if (PHP_SAPI === 'cli') {
            echo "\n=============================\n";
            echo "INDEXPAGE HOOK START\n";
            echo "URL: {$set['url']}\n";
        }

        // --------------------------------------------------
        // 1. MEILISEARCH_JSON aus HTML extrahieren
        // --------------------------------------------------
        if (
            !isset($set['content']) ||
            !preg_match('#MEILISEARCH_JSON\s*(\{.*?\})#s', $set['content'], $m)
        ) {
            if (PHP_SAPI === 'cli') {
                echo "❌ Kein MEILISEARCH_JSON gefunden\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        $meta = json_decode($m[1], true);

        if (!is_array($meta)) {
            if (PHP_SAPI === 'cli') {
                echo "❌ MEILISEARCH_JSON ist kein valides JSON\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        if (PHP_SAPI === 'cli') {
            echo "✅ MEILISEARCH_JSON gefunden\n";
            echo "---- RAW JSON ----\n";
            var_dump($meta);
            echo "------------------\n";
        }

        // --------------------------------------------------
        // 2. Sauberes Mapping (klar definierte Priorität)
        // --------------------------------------------------
        $priority =
            $meta['event']['priority']
            ?? $meta['news']['priority']
            ?? $meta['page']['priority']
            ?? null;

        $keywords =
            $meta['event']['keywords']
            ?? $meta['news']['keywords']
            ?? $meta['page']['keywords']
            ?? null;

        $imagepath =
            $meta['custom']['searchimage']
            ?? $meta['event']['searchimage']
            ?? $meta['news']['searchimage']
            ?? $meta['page']['searchimage']
            ?? null;

        $startDate =
            $meta['event']['date']
            ?? $meta['news']['date']
            ?? null;

        // --------------------------------------------------
        // 3. Daten vorbereiten
        // --------------------------------------------------
        $update = [];

        if ($priority !== null) {
            $update['priority'] = (int) $priority;
        }

        if ($keywords !== null) {
            $update['keywords'] = trim((string) $keywords);
        }

        if ($imagepath !== null) {
            $update['imagepath'] = (string) $imagepath;
        }

        if ($startDate !== null) {
            // ISO-Datum → UNIX-Timestamp
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $update['startDate'] = $ts;
            }
        }

        if (PHP_SAPI === 'cli') {
            echo "---- FINAL UPDATE ----\n";
            var_dump($update);
            echo "----------------------\n";
        }

        if (!$update) {
            if (PHP_SAPI === 'cli') {
                echo "ℹ️ Keine Felder zu aktualisieren\n";
                echo "INDEXPAGE HOOK END\n";
                echo "=============================\n";
            }
            return;
        }

        // --------------------------------------------------
        // 4. tl_search aktualisieren
        // --------------------------------------------------
        Database::getInstance()
            ->prepare(
                'UPDATE tl_search %s WHERE url=?'
            )
            ->set($update)
            ->execute($set['url']);

        if (PHP_SAPI === 'cli') {
            echo "✅ tl_search aktualisiert\n";
            echo "INDEXPAGE HOOK END\n";
            echo "=============================\n";
        }
    }
}