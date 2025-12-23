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
            fwrite(STDERR, "\n=============================\n");
            fwrite(STDERR, "INDEXPAGE LISTENER\n");
            fwrite(STDERR, "URL: " . ($set['url'] ?? $data['url'] ?? '[unknown]') . "\n");
        }

        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            if ($debug) {
                fwrite(STDERR, "❌ MEILISEARCH_JSON not found\n");
                fwrite(STDERR, "=============================\n");
            }
            return;
        }

        if ($debug) {
            fwrite(STDERR, "✔ MEILISEARCH_JSON marker found\n");
        }

        // JSON aus Kommentar extrahieren + parsen
        $parsed = $this->extractMeilisearchJson($content);

        if ($parsed === null) {
            if ($debug) {
                fwrite(STDERR, "❌ JSON could not be parsed\n");
                fwrite(STDERR, "=============================\n");
            }
            return;
        }

        if ($debug) {
            fwrite(STDERR, "✔ JSON parsed successfully:\n");
            fwrite(STDERR, print_r($parsed, true));
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
                fwrite(STDERR, "✔ priority set to: {$set['priority']}\n");
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
                fwrite(STDERR, "✔ keywords set to: {$set['keywords']}\n");
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
            fwrite(STDERR, "Resolved image UUID: " . var_export($image, true) . "\n");
        }

        if (is_string($image) && $image !== '') {
            if ($debug) {
                fwrite(STDERR, "→ Calling MeilisearchImageHelper\n");
            }

            $path = $this->imageHelper->getImagePathFromUuid($image);

            if ($debug) {
                fwrite(STDERR, "← Image helper returned: " . var_export($path, true) . "\n");
            }

            if ($path !== null) {
                $set['imagepath'] = $path;

                if ($debug) {
                    fwrite(STDERR, "✔ imagepath set to: {$set['imagepath']}\n");
                }
            } elseif ($debug) {
                fwrite(STDERR, "❌ image helper returned NULL\n");
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
            fwrite(STDERR, "Resolved date: " . var_export($date, true) . "\n");
        }

        if (is_string($date) && $date !== '') {
            $ts = strtotime($date);

            if ($ts !== false) {
                $set['startDate'] = $ts;

                if ($debug) {
                    fwrite(STDERR, "✔ startDate set to timestamp: {$set['startDate']}\n");
                }
            } elseif ($debug) {
                fwrite(STDERR, "❌ strtotime failed\n");
            }
        }

        if ($debug) {
            fwrite(STDERR, "---- FINAL \$set ----\n");
            fwrite(
                STDERR,
                print_r([
                    'priority'  => $set['priority']  ?? null,
                    'keywords'  => $set['keywords']  ?? null,
                    'imagepath' => $set['imagepath'] ?? null,
                    'startDate' => $set['startDate'] ?? null,
                ], true)
            );
            fwrite(STDERR, "=============================\n");
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