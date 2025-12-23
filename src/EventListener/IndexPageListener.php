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

        /*
         * =====================
         * PRIORITY
         * =====================
         */
        if (isset($markers['event.priority'])) {
            $data['priority'] = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $data['priority'] = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $data['priority'] = (int) $markers['page.priority'];
        }

        /*
         * =====================
         * KEYWORDS (kombiniert)
         * =====================
         */
        $keywords = [];

        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $parts = preg_split('/\s+/', trim($markers[$key]));
                $keywords = array_merge($keywords, $parts);
            }
        }

        if ($keywords !== []) {
            $keywords = array_unique($keywords);
            $data['keywords'] = implode(' ', $keywords);
        }

        /*
         * =====================
         * SEARCH IMAGE (UUID)
         * =====================
         */
        foreach (
            [
                'event.searchimage',
                'news.searchimage',
                'page.searchimage',
                'custom.searchimage',
            ] as $key
        ) {
            if (!empty($markers[$key])) {
                $data['imagepath'] = $markers[$key];
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
                $timestamp = strtotime($markers[$key]);
                if ($timestamp !== false) {
                    $data['startdate'] = $timestamp;
                }
                break;
            }
        }
    }

    /**
     * Extrahiert MEILISEARCH Marker aus HTML-Kommentar
     */
    private function extractMarkers(string $content): array
    {
        if (!preg_match('/<!--\s*MEILISEARCH(.*?)-->/s', $content, $match)) {
            return [];
        }

        $lines = preg_split('/\R/', trim($match[1]));
        $markers = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $markers[trim($key)] = trim($value);
        }

        return $markers;
    }
}