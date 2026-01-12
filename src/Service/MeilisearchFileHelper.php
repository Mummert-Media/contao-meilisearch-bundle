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
// 3. Normalisierten lokalen Pfad ermitteln (UUID-first)
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

        $pathCandidates = array_values(array_unique(array_filter(array_map(
            static function ($c) {
                $c = rawurldecode(html_entity_decode((string) $c, ENT_QUOTES));
                $c = ltrim($c, '/');
                return $c !== '' ? $c : null;
            },
            $pathCandidates
        ))));

        $this->log('Path candidates (normalized)', ['candidates' => $pathCandidates]);

        $resolvedModel = null;

// Wir testen Kandidaten in dieser Reihenfolge:
// - kandidat selbst
// - falls nicht mit "files/" beginnt: zusätzlich "files/".$kandidat
        foreach ($pathCandidates as $candidate) {

            // 1) direkt versuchen
            $model = FilesModel::findByPath($candidate);
            if ($model && $model->uuid) {
                $resolvedModel = $model;
                $this->log('Resolved via FilesModel (direct)', ['candidate' => $candidate, 'path' => $model->path]);
                break;
            }

            // 2) fallback: "files/" davor (ohne Annahme über Ordnerstruktur)
            if (!str_starts_with($candidate, 'files/')) {
                $model = FilesModel::findByPath('files/' . $candidate);
                if ($model && $model->uuid) {
                    $resolvedModel = $model;
                    $this->log('Resolved via FilesModel (files/ prefix)', [
                        'candidate' => $candidate,
                        'path'      => $model->path,
                    ]);
                    break;
                }
            }
        }

        if (!$resolvedModel) {
            $this->log('No Contao file model found for candidates, skip', ['candidates' => $pathCandidates]);
            return;
        }

        $normalizedPath = (string) $resolvedModel->path;
        $uuidBin        = $resolvedModel->uuid;

        $this->log('UUID resolved', [
            'path' => $normalizedPath,
            'uuid' => StringUtil::binToUuid($uuidBin),
        ]);

        $projectDir = System::getContainer()->getParameter('kernel.project_dir');
        $abs = $projectDir . '/public/' . $normalizedPath;

        if (!is_file($abs)) {
            $this->log('Resolved model but file missing on FS, skip', [
                'path' => $normalizedPath,
                'abs'  => $abs,
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