<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CoreBundle\ServiceAnnotation\Hook;

class IndexPageListener
{
    /**
     * @Hook("indexPage")
     */
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
         */
        if (isset($markers['event.priority'])) {
            $set['priority'] = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $set['priority'] = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $set['priority'] = (int) $markers['page.priority'];
        }

        /*
         * KEYWORDS (kombiniert)
         */
        $keywords = [];

        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $keywords = array_merge(
                    $keywords,
                    preg_split('/\s+/', trim($markers[$key]))
                );
            }
        }

        if ($keywords !== []) {
            $set['keywords'] = implode(' ', array_unique($keywords));
        }

        /*
         * SEARCH IMAGE (UUID)
         */
        foreach (
            ['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage']
            as $key
        ) {
            if (!empty($markers[$key])) {
                $set['imagepath'] = trim($markers[$key]);
                break;
            }
        }

        /*
         * START DATE (Timestamp)
         */
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime($markers[$key]);
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