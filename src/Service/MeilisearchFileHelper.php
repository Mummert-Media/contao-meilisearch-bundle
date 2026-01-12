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
        // 1. URL normalisieren
        // -------------------------------------------------
        $cleanUrl = strtok($url, '#');
        $parts    = parse_url($cleanUrl);

        if (!$parts) {
            $this->log('Invalid URL, skip');
            return;
        }

        // -------------------------------------------------
        // 2. Externe Datei? → skip
        // -------------------------------------------------
        if (!empty($parts['host'])) {
            $currentRequest = System::getContainer()
                ->get('request_stack')
                ->getCurrentRequest();

            $pageHost = $currentRequest
                ? parse_url($currentRequest->getSchemeAndHttpHost(), PHP_URL_HOST)
                : null;

            if ($pageHost && $parts['host'] !== $pageHost) {
                $this->log('External file detected, skip', [
                    'host' => $parts['host'],
                ]);
                return;
            }
        }

        // -------------------------------------------------
        // 3. Pfad-Kandidaten sammeln (ohne Annahmen!)
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

        // normalisieren
        $pathCandidates = array_values(array_unique(array_filter(array_map(
            static function ($candidate) {
                $candidate = rawurldecode(html_entity_decode((string) $candidate, ENT_QUOTES));
                return ltrim($candidate, '/') ?: null;
            },
            $pathCandidates
        ))));

        $this->log('Path candidates (normalized)', [
            'candidates' => $pathCandidates,
        ]);

        // -------------------------------------------------
        // 4. FilesModel (DBAFS) auflösen → UUID
        // -------------------------------------------------
        $fileModel = null;

        foreach ($pathCandidates as $candidate) {

            // 1) direkt
            $model = FilesModel::findByPath($candidate);
            if ($model && $model->uuid) {
                $fileModel = $model;
                $this->log('Resolved via FilesModel (direct)', [
                    'candidate' => $candidate,
                    'path'      => $model->path,
                ]);
                break;
            }

            // 2) fallback: files/ davor
            if (!str_starts_with($candidate, 'files/')) {
                $model = FilesModel::findByPath('files/' . $candidate);
                if ($model && $model->uuid) {
                    $fileModel = $model;
                    $this->log('Resolved via FilesModel (files/ prefix)', [
                        'candidate' => $candidate,
                        'path'      => $model->path,
                    ]);
                    break;
                }
            }
        }

        if (!$fileModel) {
            $this->log('No Contao file model found, skip', [
                'candidates' => $pathCandidates,
            ]);
            return;
        }

        $normalizedPath = (string) $fileModel->path;
        $uuidBin        = $fileModel->uuid;
        $uuid           = StringUtil::binToUuid($uuidBin);

        $this->log('UUID resolved', [
            'path' => $normalizedPath,
            'uuid' => $uuid,
        ]);

        // -------------------------------------------------
        // 5. Datei im Filesystem prüfen
        // -------------------------------------------------
        $projectDir = System::getContainer()->getParameter('kernel.project_dir');
        $abs        = $projectDir . '/public/' . $normalizedPath;

        if (!is_file($abs)) {
            $this->log('Resolved model but file missing on filesystem, skip', [
                'path' => $normalizedPath,
                'abs'  => $abs,
            ]);
            return;
        }

        // -------------------------------------------------
        // 6. Redaktionellen Titel aus tl_files.meta
        // -------------------------------------------------
        $title = null;
        $meta  = StringUtil::deserialize($fileModel->meta, true);
        $lang  = $GLOBALS['TL_LANGUAGE'] ?? 'de';

        if (!empty($meta[$lang]['title'])) {
            $title = trim((string) $meta[$lang]['title']);
        }

        if ($title) {
            $this->log('Title resolved from tl_files', [
                'title' => $title,
            ]);
        }

        // -------------------------------------------------
        // 7. Datei-Infos
        // -------------------------------------------------
        $mtime    = filemtime($abs) ?: 0;
        $checksum = md5($normalizedPath . '|' . $mtime);
        $now      = time();

        // -------------------------------------------------
        // 8. Upsert über UUID
        // -------------------------------------------------
        $existing = $this->connection->fetchAssociative(
            'SELECT id FROM tl_search_files WHERE uuid = ?',
            [$uuidBin]
        );

        if ($existing) {
            $data = [
                'tstamp'     => $now,
                'last_seen'  => $now,
                'type'       => $type,
                'url'        => $cleanUrl,
                'page_id'    => $pageId,
                'file_mtime' => $mtime,
                'checksum'   => $checksum,
            ];

            if ($title !== null) {
                $data['title'] = $title;
            }

            $this->connection->update(
                'tl_search_files',
                $data,
                ['id' => $existing['id']]
            );

            $this->log('File updated by UUID', [
                'uuid' => $uuid,
            ]);
        } else {
            $this->connection->insert(
                'tl_search_files',
                [
                    'tstamp'     => $now,
                    'last_seen'  => $now,
                    'type'       => $type,
                    'url'        => $cleanUrl,
                    'title'      => $title ?? basename($normalizedPath),
                    'page_id'    => $pageId,
                    'file_mtime' => $mtime,
                    'checksum'   => $checksum,
                    'uuid'       => $uuidBin,
                ]
            );

            $this->log('File inserted by UUID', [
                'uuid' => $uuid,
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