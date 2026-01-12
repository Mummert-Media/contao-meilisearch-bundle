<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\System;
use Contao\FilesModel;
use Contao\File;
use Contao\StringUtil;

class IndexPageListener
{
    public function __construct()
    {
    }

    private function debug(string $message, array $context = []): void
    {
        $ctx = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        error_log('[ContaoMeilisearch][IndexPageListener] ' . $message . $ctx);
    }

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        $this->debug('Hook start', [
            'url'       => $data['url'] ?? null,
            'protected' => $data['protected'] ?? null,
            'checksum'  => $data['checksum'] ?? null,
            'set_keys'  => array_keys($set),
        ]);

        /*
         * =====================
         * DATEI-ERKENNUNG + UPSERT
         * =====================
         */
        if ((int) ($data['protected'] ?? 0) !== 0) {
            return;
        }

        if (!Config::get('meilisearch_index_files')) {
            return;
        }

        $links = $this->findAllLinks($content);
        $fileLinks = [];

        foreach ($links as $link) {
            $type = $this->detectIndexableFileType($link['url']);
            if ($type !== null) {
                $fileLinks[] = $link + ['type' => $type];
            }
        }

        if ($fileLinks) {
            $db   = System::getContainer()->get('database_connection');
            $time = time();

            $projectDir = System::getContainer()->getParameter('kernel.project_dir');

            foreach ($fileLinks as $file) {
                try {
                    $url = strtok($file['url'], '#');

                    // =================================================
                    // 🟢 valider lokaler Dateipfad
                    // =================================================
                    $localFilePath = $this->getValidLocalFilePathFromUrl($url, $projectDir);

                    // =================================================
                    // UUID nur über echten Contao File-Model
                    // =================================================
                    $uuidBin = null;

                    if ($localFilePath !== null) {
                        try {
                            $objFile = new File($localFilePath);
                            $objModel = $objFile->getModel();

                            if ($objModel && $objModel->uuid) {
                                $uuidBin = $objModel->uuid; // bereits binary(16)
                            }
                        } catch (\Throwable $e) {
                            // bewusst still
                        }
                    }

                    // =================================================
                    // bestehende Logik unverändert
                    // =================================================
                    $abs = $localFilePath
                        ? $projectDir . '/' . ltrim($localFilePath, '/')
                        : null;

                    $mtime    = ($abs && is_file($abs)) ? filemtime($abs) : 0;
                    $checksum = md5($url . '|' . $mtime);

                    $existing = $db->fetchAssociative(
                        'SELECT id FROM tl_search_files WHERE url = ?',
                        [$url]
                    );

                    if ($existing) {
                        $db->update(
                            'tl_search_files',
                            [
                                'tstamp'     => $time,
                                'last_seen'  => $time,
                                'page_id'    => (int) ($data['pid'] ?? 0),
                                'file_mtime' => $mtime,
                                'checksum'   => $checksum,
                                'uuid'       => $uuidBin,
                            ],
                            ['id' => $existing['id']]
                        );
                    } else {
                        $db->insert(
                            'tl_search_files',
                            [
                                'tstamp'     => $time,
                                'last_seen'  => $time,
                                'type'       => $file['type'],
                                'url'        => $url,
                                'title'      => $file['linkText'] ?? basename($url),
                                'page_id'    => (int) ($data['pid'] ?? 0),
                                'file_mtime' => $mtime,
                                'checksum'   => $checksum,
                                'uuid'       => $uuidBin,
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    $this->debug('File upsert FAILED', [
                        'url'   => $file['url'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // =========================================================
    // 🟢 PFAD-ERMITTLUNG
    // =========================================================
    private function getValidLocalFilePathFromUrl(string $url, string $projectDir): ?string
    {
        $arrUrl = parse_url($url);
        if (!$arrUrl) {
            return null;
        }

        $path = $arrUrl['path'] ?? null;

        // Download-Parameter ?file=
        if (!empty($arrUrl['query']) && preg_match('#file=(?<path>[^&]+)#i', $arrUrl['query'], $m)) {
            $path = $m['path'];
        }

        if (!$path) {
            return null;
        }

        $path = ltrim(urldecode($path), '/');

        if (!str_starts_with($path, 'files/')) {
            return null;
        }

        if (!is_file($projectDir . '/' . $path)) {
            return null;
        }

        return $path;
    }

    /* === Hilfsmethoden unverändert === */

    private function findAllLinks(string $content): array
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $content,
            $matches
        )) {
            return [];
        }

        $result = [];

        foreach ($matches[1] as $i => $href) {
            $result[] = [
                'url'      => html_entity_decode($href),
                'linkText' => trim(strip_tags($matches[2][$i])) ?: null,
            ];
        }

        return $result;
    }

    private function detectIndexableFileType(string $url): ?string
    {
        $url = strtok($url, '#');
        $parts = parse_url($url);

        if (!empty($parts['path'])) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                return $ext;
            }
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (['file', 'p', 'f'] as $param) {
                if (!empty($query[$param])) {
                    $candidate = rawurldecode((string) $query[$param]);
                    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                        return $ext;
                    }
                }
            }
        }

        return null;
    }
}