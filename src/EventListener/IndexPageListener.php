<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\System;
use Doctrine\DBAL\Connection;

class IndexPageListener
{
    private static bool $shutdownRegistered = false;

    /** @var array<string, array{priority?:int, keywords?:string, imagepath?:string, startDate?:int}> */
    private static array $queue = [];

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

        // Debug ohne Risiko
        if (PHP_SAPI === 'cli') {
            echo "INDEXPAGE LISTENER ACTIVE: " . ($set['url'] ?? '[no url]') . "\n";
        }

        // Wir updaten final über checksum (stabil, egal ob URL mit/ohne Domain)
        $checksum = $data['checksum'] ?? null;
        if (!$checksum) {
            return;
        }

        /*
         * PRIORITY: event/news > page
         */
        $priority = null;
        if (isset($markers['event.priority'])) {
            $priority = (int) $markers['event.priority'];
        } elseif (isset($markers['news.priority'])) {
            $priority = (int) $markers['news.priority'];
        } elseif (isset($markers['page.priority'])) {
            $priority = (int) $markers['page.priority'];
        }

        if ($priority !== null) {
            $data['priority'] = $priority;     // bleibt bei dir schon stehen
            $set['priority']  = $priority;     // harmless, aber konsistent
        }

        /*
         * KEYWORDS: kombinieren
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

        $keywords = array_values(array_unique(array_filter($keywords)));
        $keywordsString = $keywords ? implode(' ', $keywords) : '';

        // Wichtig: in $set setzen, weil Contao später oft nochmal keywords finalisiert
        $set['keywords'] = $keywordsString;

        /*
         * IMAGEPATH (UUID): event/news > page > custom
         */
        $imageUuid = '';
        foreach (['event.searchimage', 'news.searchimage', 'page.searchimage', 'custom.searchimage'] as $key) {
            if (!empty($markers[$key])) {
                $imageUuid = trim($markers[$key]);
                break;
            }
        }

        // Ebenso: in $set setzen (damit es nicht überschrieben wird)
        $set['imagepath'] = $imageUuid;

        /*
         * STARTDATE (Timestamp)
         */
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

        $set['startDate'] = $startDate;

        /*
         * Sicherheitsnetz: am Ende definitiv in tl_search schreiben
         */
        self::$queue[$checksum] = [
            'priority'  => $priority,
            'keywords'  => $keywordsString,
            'imagepath' => $imageUuid,
            'startDate' => $startDate,
        ];

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;

            register_shutdown_function(function (): void {
                $db = System::getContainer()->get('database_connection');

                foreach (self::$queue as $checksum => $values) {
                    $params = ['checksum' => $checksum];
                    $sets   = [];

                    if (array_key_exists('priority', $values) && $values['priority'] !== null) {
                        $sets[] = 'priority = :priority';
                        $params['priority'] = $values['priority'];
                    }

                    $sets[] = 'keywords = :keywords';
                    $params['keywords'] = $values['keywords'] ?? '';

                    $sets[] = 'imagepath = :imagepath';
                    $params['imagepath'] = $values['imagepath'] ?? '';

                    $sets[] = 'startDate = :startDate';
                    $params['startDate'] = $values['startDate'] ?? 0;

                    $sql = 'UPDATE tl_search SET ' . implode(', ', $sets) . ' WHERE checksum = :checksum';
                    $db->executeStatement($sql, $params);
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