<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\FilesModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;

class MeilisearchFileHelper
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Zentrale Datei-Verarbeitung
     */
    public function collect(string $url, string $type, int $pageId): void
    {
        $this->log('collect() start', [
            'url'    => $url,
            'type'   => $type,
            'pageId' => $pageId,
        ]);

        // -------------------------------------------------
        // 1. URL zerlegen
        // -------------------------------------------------
        $cleanUrl = strtok($url, '#');
        $parts = parse_url($cleanUrl);

        if (!$parts) {
            $this->log('Invalid URL, skip');
            return;
        }

        // -------------------------------------------------
        // 2. Externe Datei?
        // -------------------------------------------------
        if (!empty($parts['host'])) {
            $pageHost = parse_url(System::getContainer()
                ->get('request_stack')
                ->getCurrentRequest()?->getUri() ?? '', PHP_URL_HOST);

            if ($pageHost && $parts['host'] !== $pageHost) {
                $this->log('External file detected, skip', [
                    'host' => $parts['host'],
                ]);
                return;
            }
        }

        // -------------------------------------------------
        // 3. Normalisierten lokalen Pfad ermitteln
        // -------------------------------------------------
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $pathCandidates = [];

        // direkter Pfad
        if (!empty($parts['path'])) {
            $pathCandidates[] = $parts['path'];
        }

        // Download-Parameter
        foreach (['file', 'f', 'p'] as $param) {
            if (!empty($query[$param])) {
                $pathCandidates[] = $query[$param];
            }
        }

        $normalizedPath = null;

        foreach ($pathCandidates as $candidate) {
            $candidate = rawurldecode(html_entity_decode((string) $candidate, ENT_QUOTES));
            $candidate = ltrim($candidate, '/');

            if (!str_starts_with($candidate, 'files/')) {
                continue;
            }

            $abs = System::getContainer()->getParameter('kernel.project_dir') . '/public/' . $candidate;

            if (is_file($abs)) {
                $normalizedPath = $candidate;
                break;
            }
        }

        if (!$normalizedPath) {
            $this->log('No valid local file path found, skip', [
                'candidates' => $pathCandidates,
            ]);
            return;
        }

        // -------------------------------------------------
        // 4. UUID aus Contao ermitteln
        // -------------------------------------------------
        $fileModel = FilesModel::findByPath($normalizedPath);

        if (!$fileModel || !$fileModel->uuid) {
            $this->log('File has no Contao UUID, skip', [
                'path' => $normalizedPath,
            ]);
            return;
        }

        $uuidBin = $fileModel->uuid;

        $this->log('UUID resolved', [
            'path' => $normalizedPath,
            'uuid' => StringUtil::binToUuid($uuidBin),
        ]);

        // -------------------------------------------------
        // 5. Dateiinformationen
        // -------------------------------------------------
        $abs = System::getContainer()->getParameter('kernel.project_dir') . '/public/' . $normalizedPath;

        $mtime = filemtime($abs) ?: 0;
        $checksum = md5($normalizedPath . '|' . $mtime);

        $now = time();

        // -------------------------------------------------
        // 6. Existiert Eintrag bereits über UUID?
        // -------------------------------------------------
        $existing = $this->connection->fetchAssociative(
            'SELECT id FROM tl_search_files WHERE uuid = ?',
            [$uuidBin]
        );

        if ($existing) {
            // UPDATE
            $this->connection->update(
                'tl_search_files',
                [
                    'tstamp'     => $now,
                    'last_seen'  => $now,
                    'type'       => $type,
                    'url'        => $cleanUrl,
                    'page_id'    => $pageId,
                    'file_mtime' => $mtime,
                    'checksum'   => $checksum,
                ],
                ['id' => $existing['id']]
            );

            $this->log('File updated by UUID', [
                'uuid' => StringUtil::binToUuid($uuidBin),
            ]);
        } else {
            // INSERT
            $this->connection->insert(
                'tl_search_files',
                [
                    'tstamp'     => $now,
                    'last_seen'  => $now,
                    'type'       => $type,
                    'url'        => $cleanUrl,
                    'title'      => basename($normalizedPath),
                    'page_id'    => $pageId,
                    'file_mtime' => $mtime,
                    'checksum'   => $checksum,
                    'uuid'       => $uuidBin,
                ]
            );

            $this->log('File inserted by UUID', [
                'uuid' => StringUtil::binToUuid($uuidBin),
            ]);
        }

        $this->log('collect() end');
    }

    // -------------------------------------------------
    // Logging
    // -------------------------------------------------
    private function log(string $message, array $context = []): void
    {
        $ctx = $context
            ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        error_log('[ContaoMeilisearch][MeilisearchFileHelper] ' . $message . $ctx);
    }
}