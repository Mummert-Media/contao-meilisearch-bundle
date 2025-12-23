<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\System;
use Doctrine\DBAL\Connection;

class IndexPageListener
{
    private Connection $db;

    public function __construct()
    {
        $this->db = System::getContainer()->get('database_connection');
    }

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        if (!str_contains($content, 'MEILISEARCH')) {
            return;
        }

        $markers = $this->extractMarkers($content);
        if ($markers === []) {
            return;
        }

        // URL aus $set (für Crawl vorhanden)
        $url = $set['url'] ?? null;
        if (!$url) {
            return;
        }

        // priority: event/news > page
        $priority = null;
        if (isset($markers['event.priority'])) {
            $priority = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $priority = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $priority = (int) $markers['page.priority'];
        }

        // keywords kombiniert
        $keywords = [];
        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $keywords = array_merge($keywords, preg_split('/\s+/', trim($markers[$key])) ?: []);
            }
        }
        $keywords = array_values(array_unique(array_filter($keywords)));
        $keywordsString = $keywords ? implode(' ', $keywords) : null;

        // searchimage uuid: event/news > page > custom
        $imageUuid = null;
        foreach (['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage'] as $key) {
            if (!empty($markers[$key])) {
                $imageUuid = trim($markers[$key]);
                break;
            }
        }

        // startDate (timestamp)
        $startDate = null;
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime(trim($markers[$key]));
                if ($ts !== false) {
                    $startDate = (int) $ts;
                }
                break;
            }
        }

        // Nichts zu schreiben? Dann raus.
        if ($priority === null && $keywordsString === null && $imageUuid === null && $startDate === null) {
            return;
        }

        // ✅ DB-Update (tl_search) anhand der URL
        $update = [];
        $params = ['url' => $url];
        $types  = [];

        if ($priority !== null) {
            $update['priority'] = ':priority';
            $params['priority'] = $priority;
        }
        if ($keywordsString !== null) {
            $update['keywords'] = ':keywords';
            $params['keywords'] = $keywordsString;
        }
        if ($imageUuid !== null) {
            $update['imagepath'] = ':imagepath';
            $params['imagepath'] = $imageUuid;
        }
        if ($startDate !== null) {
            $update['startDate'] = ':startDate';
            $params['startDate'] = $startDate;
        }

        $setSql = implode(', ', array_map(fn($col, $ph) => "$col = $ph", array_keys($update), $update));

        $this->db->executeStatement(
            "UPDATE tl_search SET $setSql WHERE url = :url",
            $params
        );
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