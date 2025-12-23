<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // Schneller Exit, wenn kein Marker existiert
        if (!str_contains($content, 'MEILISEARCH')) {
            return;
        }

        $markers = $this->extractMarkers($content);
        if ($markers === []) {
            return;
        }

        /*
         * =====================
         * PRIORITY
         * event/news > page
         * =====================
         */
        if (isset($markers['event.priority'])) {
            $data['priority'] = (int) $markers['event.priority'];
            $set['priority'] = true;
        } elseif (isset($markers['news.priority'])) {
            $data['priority'] = (int) $markers['news.priority'];
            $set['priority'] = true;
        } elseif (isset($markers['page.priority'])) {
            $data['priority'] = (int) $markers['page.priority'];
            $set['priority'] = true;
        }

        /*
         * =====================
         * KEYWORDS (kombiniert)
         * =====================
         */
        $keywords = [];

        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $parts = preg_split('/\s+/', trim($markers[$key])) ?: [];
                $keywords = array_merge($keywords, $parts);
            }
        }

        if ($keywords !== []) {
            $keywords = array_values(array_unique(array_filter($keywords)));
            $data['keywords'] = implode(' ', $keywords);
            $set['keywords'] = true;
        }

        /*
         * =====================
         * SEARCH IMAGE (UUID)
         * event/news > page > custom
         * =====================
         */
        foreach (['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage'] as $key) {
            if (!empty($markers[$key])) {
                $data['imagepath'] = trim($markers[$key]);   // vorerst nur UUID
                $set['imagepath'] = true;
                break;
            }
        }

        /*
         * =====================
         * START DATE (Timestamp)
         * =====================
         */
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime(trim($markers[$key]));
                if ($ts !== false) {
                    $data['startDate'] = $ts;
                    $set['startDate'] = true;
                }
                break;
            }
        }
    }

    private function extractMarkers(string $content): array
    {
        // Kommentarblock isolieren
        if (!preg_match('/<!--\s*MEILISEARCH(.*?)-->/s', $content, $m)) {
            return [];
        }

        $block = trim($m[1]);
        $lines = preg_split('/\R/', $block) ?: [];

        $markers = [];
        foreach ($lines as $line) {
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