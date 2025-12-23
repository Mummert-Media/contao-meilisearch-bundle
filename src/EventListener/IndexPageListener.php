<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        if (!str_contains($content, 'MEILISEARCH')) {
            return;
        }

        $markers = $this->extractMarkers($content);
        if ($markers === []) {
            return;
        }

        /*
         * PRIORITY
         * event/news > page
         */
        if (isset($markers['event.priority'])) {
            $data['priority'] = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $data['priority'] = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $data['priority'] = (int) $markers['page.priority'];
        }

        /*
         * KEYWORDS – alle kombinieren
         */
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

        /*
         * IMAGEPATH – UUID
         * event/news > page > custom
         */
        foreach (
            ['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage']
            as $key
        ) {
            if (!empty($markers[$key])) {
                $data['imagepath'] = trim($markers[$key]);
                break;
            }
        }

        /*
         * STARTDATE – Timestamp
         */
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime($markers[$key]);
                if ($ts !== false) {
                    $data['startDate'] = $ts;
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