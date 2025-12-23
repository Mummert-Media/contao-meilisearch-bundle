<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CoreBundle\ServiceAnnotation\Hook;

class IndexPageListener
{
    #[Hook('indexPage')]
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        if (!str_contains($content, 'MEILISEARCH')) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            echo "INDEXPAGE LISTENER ACTIVE: " . ($set['url'] ?? '[no url in $set]') . "\n";
        }

        $markers = $this->extractMarkers($content);
        if ($markers === []) {
            return;
        }

        /*
         * PRIORITY: event/news > page
         */
        if (isset($markers['event.priority'])) {
            $set['priority'] = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $set['priority'] = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $set['priority'] = (int) $markers['page.priority'];
        }

        /*
         * KEYWORDS: kombinieren (alle vorhandenen)
         */
        $keywords = [];

        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $parts = preg_split('/\s+/', trim($markers[$key])) ?: [];
                $keywords = array_merge($keywords, $parts);
            }
        }

        $keywords = array_values(array_unique(array_filter($keywords)));
        if ($keywords !== []) {
            $set['keywords'] = implode(' ', $keywords);
        }

        /*
         * SEARCH IMAGE UUID: event/news > page > custom
         * (vorerst NUR UUID in tl_search)
         */
        foreach (['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage'] as $key) {
            if (!empty($markers[$key])) {
                $set['imagepath'] = trim($markers[$key]);
                break;
            }
        }

        /*
         * START DATE: event/news.date -> Timestamp (sortierbar)
         */
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime(trim($markers[$key]));
                if ($ts !== false) {
                    $set['startDate'] = $ts;
                }
                break;
            }
        }
    }

    private function extractMarkers(string $content): array
    {
        if (!preg_match('/<!--\s*MEILISEARCH(.*?)-->/s', $content, $m)) {
            return [];
        }

        $markers = [];
        foreach (preg_split('/\R/', trim($m[1])) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $line, 2);
            $markers[trim($k)] = trim($v);
        }

        return $markers;
    }
}