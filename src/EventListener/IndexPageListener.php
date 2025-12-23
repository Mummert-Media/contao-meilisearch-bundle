<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use MummertMedia\ContaoMeilisearchBundle\Service\MeilisearchImageHelper;

class IndexPageListener
{
    public function __construct(
        private readonly MeilisearchImageHelper $imageHelper,
    ) {}

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $debug = (PHP_SAPI === 'cli');

        if ($debug) {
            echo "\n=============================\n";
            echo "INDEXPAGE LISTENER\n";
            echo "URL: " . ($set['url'] ?? $data['url'] ?? '[unknown]') . "\n";
        }

        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            if ($debug) {
                echo "❌ MEILISEARCH_JSON not found\n";
                echo "=============================\n";
            }
            return;
        }

        if ($debug) {
            echo "✔ MEILISEARCH_JSON marker found\n";
        }

        // JSON aus Kommentar extrahieren + parsen
        $parsed = $this->extractMeilisearchJson($content);

        if ($parsed === null) {
            if ($debug) {
                echo "❌ JSON could not be parsed\n";
                echo "=============================\n";
            }
            return;
        }

        if ($debug) {
            echo "✔ JSON parsed successfully:\n";
            print_r($parsed);
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

            if ($debug) {
                echo "✔ priority set to: {$set['priority']}\n";
            }
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

            if ($debug) {
                echo "✔ keywords set to: {$set['keywords']}\n";
            }
        }

        /*
         * =====================
         * IMAGEPATH
         * =====================
         */
        $image =
            $parsed['event']['searchimage'] ?? null ??
            $parsed['news']['searchimage']  ?? null ??
            $parsed['page']['searchimage']  ?? null ??
            $parsed['custom']['searchimage'] ?? null;

        if ($debug) {
            echo "Resolved image UUID: ";
            var_dump($image);
        }

        if (is_string($image) && $image !== '') {
            if ($debug) {
                echo "→ Calling MeilisearchImageHelper\n";
            }

            $path = $this->imageHelper->getImagePathFromUuid($image);

            if ($debug) {
                echo "← Image helper returned: ";
                var_dump($path);
            }

            if ($path !== null) {
                $set['imagepath'] = $path;

                if ($debug) {
                    echo "✔ imagepath set to: {$set['imagepath']}\n";
                }
            } elseif ($debug) {
                echo "❌ image helper returned NULL\n";
            }
        }

        /*
         * =====================
         * STARTDATE
         * =====================
         */
        $date =
            $parsed['event']['date'] ?? null ??
            $parsed['news']['date']  ?? null;

        if ($debug) {
            echo "Resolved date: ";
            var_dump($date);
        }

        if (is_string($date) && $date !== '') {
            $ts = strtotime($date);

            if ($ts !== false) {
                $set['startDate'] = $ts;

                if ($debug) {
                    echo "✔ startDate set to timestamp: {$set['startDate']}\n";
                }
            } elseif ($debug) {
                echo "❌ strtotime failed\n";
            }
        }

        if ($debug) {
            echo "---- FINAL \$set ----\n";
            print_r([
                'priority'  => $set['priority']  ?? null,
                'keywords'  => $set['keywords']  ?? null,
                'imagepath' => $set['imagepath'] ?? null,
                'startDate' => $set['startDate'] ?? null,
            ]);
            echo "=============================\n";
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