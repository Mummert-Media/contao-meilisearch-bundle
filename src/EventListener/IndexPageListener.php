<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // Nur reagieren, wenn unser Marker existiert
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
                $keywords = array_merge(
                    $keywords,
                    preg_split('/\s+/', trim($markers[$key])) ?: []
                );
            }
        }

        if ($keywords !== []) {
            $data['keywords'] = implode(' ', array_unique($keywords));
        }

        /*
         * =====================
         * SEARCH IMAGE
         * WICHTIG:
         * -> Key heißt "image", NICHT "imagepath"
         * =====================
         */
        foreach (
            ['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage']
            as $key
        ) {
            if (!empty($markers[$key])) {
                $data['image'] = trim($markers[$key]); // UUID
                break;
            }
        }

        /*
         * =====================
         * START DATE
         * WICHTIG:
         * -> Key heißt "startdate" (lowercase)
         * =====================
         */
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime($markers[$key]);
                if ($ts !== false) {
                    $data['startdate'] = $ts;
                }
                break;
            }
        }
    }

    /**
     * Liest die MEILISEARCH-Kommentare aus dem HTML
     */
    private function extractMarkers(string $content): array
    {
        if (!preg_match('/<!--\s*MEILISEARCH(.*?)-->/s', $content, $match)) {
            return [];
        }

        $markers = [];
        $lines = preg_split('/\R/', trim($match[1])) ?: [];

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