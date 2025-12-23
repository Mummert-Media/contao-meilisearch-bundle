<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\System;
use Doctrine\DBAL\Connection;

class IndexPageListener
{
    private static bool $shutdownRegistered = false;

    /** @var array<string, array{keywords?:string, imagepath?:string, startDate?:int}> */
    private static array $queue = [];

    private Connection $db;

    public function __construct()
    {
        $this->db = System::getContainer()->get('database_connection');
    }

    public function onIndexPage(string $content, array &$pageData, array &$indexData): void
    {
        if (!str_contains($content, 'MEILISEARCH')) {
            return;
        }

        // Debug (ohne Crash)
        if (PHP_SAPI === 'cli') {
            echo "INDEXPAGE LISTENER ACTIVE: " . ($indexData['url'] ?? '[no url]') . "\n";
        }

        $markers = $this->extractMarkers($content);
        if ($markers === []) {
            return;
        }

        // priority klappt bei dir schon -> lassen wir direkt im indexData
        if (isset($markers['event.priority'])) {
            $indexData['priority'] = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $indexData['priority'] = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $indexData['priority'] = (int) $markers['page.priority'];
        }

        $url = $indexData['url'] ?? null;
        if (!$url) {
            return;
        }

        // keywords kombinieren
        $keywords = [];
        foreach (['event.keywords', 'news.keywords', 'page.keywords'] as $key) {
            if (!empty($markers[$key])) {
                $keywords = array_merge($keywords, preg_split('/\s+/', trim($markers[$key])) ?: []);
            }
        }
        $keywords = array_values(array_unique(array_filter($keywords)));
        $keywordsString = $keywords ? implode(' ', $keywords) : '';

        // searchimage uuid: event/news > page > custom
        $imageUuid = '';
        foreach (['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage'] as $key) {
            if (!empty($markers[$key])) {
                $imageUuid = trim($markers[$key]);
                break;
            }
        }

        // startDate
        $startDate = 0;
        foreach (['event.date', 'news.date'] as $key) {
            if (!empty($markers[$key])) {
                $ts = strtotime(trim($markers[$key]));
                if ($ts !== false) {
                    $startDate = (int) $ts;
                }
                break;
            }
        }

        // In Queue legen (Core überschreibt später, wir schreiben am Ende final in tl_search)
        self::$queue[$url] = [
            'keywords'  => $keywordsString,
            'imagepath' => $imageUuid,
            'startDate' => $startDate,
        ];

        // Shutdown einmalig registrieren
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;

            register_shutdown_function(function (): void {
                $db = System::getContainer()->get('database_connection');

                foreach (self::$queue as $url => $values) {
                    $db->executeStatement(
                        'UPDATE tl_search
                         SET keywords = :keywords,
                             imagepath = :imagepath,
                             startDate = :startDate
                         WHERE url = :url',
                        [
                            'keywords'  => $values['keywords'] ?? '',
                            'imagepath' => $values['imagepath'] ?? '',
                            'startDate' => $values['startDate'] ?? 0,
                            'url'       => $url,
                        ]
                    );
                }
            });
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