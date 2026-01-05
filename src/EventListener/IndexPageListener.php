<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use MummertMedia\ContaoMeilisearchBundle\Service\PdfIndexService;
use MummertMedia\ContaoMeilisearchBundle\Service\OfficeIndexService;

class IndexPageListener
{
    public function __construct(
        private readonly PdfIndexService $pdfIndexService,
        private readonly OfficeIndexService $officeIndexService,
    ) {}

    private function debug(string $message, array $context = []): void
    {
        // Debug bewusst immer aktiv (bis du es wieder entfernst)
        // Kontext kurz halten, damit Logs nicht explodieren
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
         * SEITEN-METADATEN
         * =====================
         */
        $hasMeta = str_contains($content, 'MEILISEARCH_JSON');
        $this->debug('Meta marker scan', [
            'contains_MEILISEARCH_JSON' => $hasMeta,
            'content_length'            => strlen($content),
        ]);

        if ($hasMeta) {
            try {
                $parsed = $this->extractMeilisearchJson($content);
                $this->debug('extractMeilisearchJson(): done', [
                    'parsed_is_array' => is_array($parsed),
                    'parsed_keys'     => is_array($parsed) ? array_keys($parsed) : null,
                ]);
            } catch (\Throwable $e) {
                $this->debug('Failed to extract MEILISEARCH_JSON', [
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $parsed = null;
            }

            if (is_array($parsed)) {

                // PRIORITY
                $priority =
                    $parsed['event']['priority']
                    ?? $parsed['news']['priority']
                    ?? $parsed['page']['priority']
                    ?? null;

                $this->debug('Meta: priority candidate', ['priority' => $priority]);

                if ($priority !== null && $priority !== '') {
                    $set['priority'] = (int) $priority;
                }

                // KEYWORDS
                $keywordSources = [
                    $parsed['event']['keywords'] ?? null,
                    $parsed['news']['keywords']  ?? null,
                    $parsed['page']['keywords']  ?? null,
                ];

                $this->debug('Meta: keyword sources', ['sources' => $keywordSources]);

                $keywords = [];
                foreach ($keywordSources as $src) {
                    if (!is_string($src) || trim($src) === '') {
                        continue;
                    }
                    foreach (preg_split('/\s+/', trim($src)) as $word) {
                        $keywords[] = $word;
                    }
                }

                if ($keywords) {
                    $set['keywords'] = implode(' ', array_unique($keywords));
                }

                $this->debug('Meta: keywords result', [
                    'keywords' => $set['keywords'] ?? null,
                ]);

                // IMAGEPATH (UUID)
                $searchImage = $parsed['page']['searchimage'] ?? null;
                $this->debug('Meta: searchimage candidate', ['searchimage' => $searchImage]);

                if (!empty($searchImage)) {
                    // >>> HINWEIS: falls dein tl_search-Feld "image" heißt, hier auf $set['image'] ändern!
                    $set['imagepath'] = trim((string) $searchImage);
                }

                // STARTDATE
                $startDate =
                    $parsed['event']['startDate']
                    ?? $parsed['news']['startDate']
                    ?? null;

                $this->debug('Meta: startDate candidate', ['startDate' => $startDate]);

                if (is_numeric($startDate) && (int) $startDate > 0) {
                    $set['startDate'] = (int) $startDate;
                }

                // CHECKSUM
                try {
                    $checksumSeed  = (string) ($data['checksum'] ?? '');
                    $checksumSeed .= '|' . ($set['keywords']  ?? '');
                    $checksumSeed .= '|' . ($set['priority']  ?? '');
                    $checksumSeed .= '|' . ($set['imagepath'] ?? '');
                    $checksumSeed .= '|' . ($set['startDate'] ?? '');

                    $set['checksum'] = md5($checksumSeed);

                    $this->debug('Checksum generated', [
                        'seed_preview' => substr($checksumSeed, 0, 120) . (strlen($checksumSeed) > 120 ? '…' : ''),
                        'checksum'     => $set['checksum'],
                    ]);
                } catch (\Throwable $e) {
                    $this->debug('Failed to generate checksum', [
                        'error' => $e->getMessage(),
                        'class' => $e::class,
                    ]);
                }

                $this->debug('Meta: final set snapshot', [
                    'priority'  => $set['priority'] ?? null,
                    'keywords'  => $set['keywords'] ?? null,
                    'imagepath' => $set['imagepath'] ?? null,
                    'startDate' => $set['startDate'] ?? null,
                    'checksum'  => $set['checksum'] ?? null,
                ]);
            }
        }

        /*
         * =====================
         * DATEI-INDEXIERUNG (PDF / OFFICE)
         * =====================
         */
        if ((int) ($data['protected'] ?? 0) !== 0) {
            $this->debug('Abort: protected page', ['protected' => $data['protected'] ?? null]);
            return;
        }

        $indexPdfs   = (bool) Config::get('meilisearch_index_pdfs');
        $indexOffice = (bool) Config::get('meilisearch_index_office');

        $this->debug('File indexing settings', [
            'meilisearch_index_pdfs'   => $indexPdfs,
            'meilisearch_index_office' => $indexOffice,
        ]);

        if (!$indexPdfs && !$indexOffice) {
            $this->debug('Abort: file indexing disabled');
            return;
        }

        $links = $this->findAllLinks($content);
        $this->debug('Links found', ['count' => count($links)]);

        $pdfLinks    = [];
        $officeLinks = [];

        foreach ($links as $link) {
            $type = $this->detectIndexableFileType($link['url']);

            if ($type === 'pdf' && $indexPdfs) {
                $pdfLinks[] = $link;
                continue;
            }

            if (in_array($type, ['docx', 'xlsx', 'pptx'], true) && $indexOffice) {
                $officeLinks[] = $link;
            }
        }

        $this->debug('Indexable file links', [
            'pdf'    => count($pdfLinks),
            'office' => count($officeLinks),
        ]);

        try {
            if ($pdfLinks !== []) {
                $this->debug('PDF handlePdfLinks(): call', ['count' => count($pdfLinks)]);
                $this->pdfIndexService->handlePdfLinks($pdfLinks);
                $this->debug('PDF handlePdfLinks(): ok');
            }

            if ($officeLinks !== []) {
                $this->debug('Office handleOfficeLinks(): call', ['count' => count($officeLinks)]);
                $this->officeIndexService->handleOfficeLinks($officeLinks);
                $this->debug('Office handleOfficeLinks(): ok');
            }
        } catch (\Throwable $e) {
            $this->debug('File indexing failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }

        $this->debug('Hook end', [
            'final_set_keys' => array_keys($set),
            'final_set'      => [
                'priority'  => $set['priority'] ?? null,
                'keywords'  => $set['keywords'] ?? null,
                'imagepath' => $set['imagepath'] ?? null,
                'startDate' => $set['startDate'] ?? null,
                'checksum'  => $set['checksum'] ?? null,
            ],
        ]);
    }

    /**
     * Extrahiert MEILISEARCH_JSON aus HTML-Kommentar
     */
    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($m[1]));
        $data = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($data)
            ? $data
            : null;
    }

    /**
     * Sammle alle <a href="…"> Links
     */
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

    /**
     * Ermittelt indexierbaren Dateityp (pdf|docx|xlsx|pptx) oder null
     */
    private function detectIndexableFileType(string $url): ?string
    {
        // Hash entfernen
        $url = strtok($url, '#');

        $parts = parse_url($url);
        if (!$parts) {
            return null;
        }

        // direkter Pfad (/files/…)
        if (!empty($parts['path'])) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                return $ext;
            }
        }

        // Query-Parameter (Contao 4 + 5)
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (['file', 'p', 'f'] as $param) {
                if (!empty($query[$param])) {
                    $candidate = (string) $query[$param];

                    // sicher decodieren (Contao 4 + 5)
                    $candidate = html_entity_decode($candidate, ENT_QUOTES);
                    $candidate = rawurldecode($candidate);

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