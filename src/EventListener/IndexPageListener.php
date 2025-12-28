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

    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        fwrite(STDERR, "\n[Meili DEBUG] onIndexPage() called\n");

        /*
         * =====================
         * PDF: Reset genau 1× pro Crawl
         * =====================
         */
        try {
            fwrite(STDERR, "[Meili DEBUG] resetTableOnce()\n");
            $this->pdfIndexService->resetTableOnce();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[Meili DEBUG] PDF reset failed: {$e->getMessage()}\n");
        }

        /*
         * =====================
         * DATEI-INDEXIERUNG (PDF / OFFICE)
         * =====================
         */
        if ((int) ($data['protected'] ?? 0) !== 0) {
            fwrite(STDERR, "[Meili DEBUG] Page is protected → skip files\n");
            return;
        }

        $indexPdfs   = (bool) Config::get('meilisearch_index_pdfs');
        $indexOffice = (bool) Config::get('meilisearch_index_office');

        fwrite(
            STDERR,
            "[Meili DEBUG] Settings: pdfs="
            . ($indexPdfs ? '1' : '0')
            . " office="
            . ($indexOffice ? '1' : '0')
            . "\n"
        );

        if (!$indexPdfs && !$indexOffice) {
            fwrite(STDERR, "[Meili DEBUG] No file indexing enabled → return\n");
            return;
        }

        $links = $this->findAllLinks($content);
        fwrite(STDERR, "[Meili DEBUG] Found " . count($links) . " <a> links\n");

        $pdfLinks    = [];
        $officeLinks = [];

        foreach ($links as $link) {
            fwrite(STDERR, "[Meili DEBUG] URL: {$link['url']}\n");

            $type = $this->detectIndexableFileType($link['url']);
            fwrite(
                STDERR,
                "[Meili DEBUG]  → detected type: "
                . ($type ?? 'none')
                . "\n"
            );

            if ($type === 'pdf') {
                if ($indexPdfs) {
                    fwrite(STDERR, "[Meili DEBUG]  → add to PDF queue\n");
                    $pdfLinks[] = $link;
                } else {
                    fwrite(STDERR, "[Meili DEBUG]  → PDF indexing disabled\n");
                }
                continue;
            }

            if (in_array($type, ['docx', 'xlsx', 'pptx'], true)) {
                if ($indexOffice) {
                    fwrite(STDERR, "[Meili DEBUG]  → add to OFFICE queue\n");
                    $officeLinks[] = $link;
                } else {
                    fwrite(STDERR, "[Meili DEBUG]  → Office indexing disabled\n");
                }
                continue;
            }

            fwrite(STDERR, "[Meili DEBUG]  → ignored\n");
        }

        fwrite(
            STDERR,
            "[Meili DEBUG] Final queues: pdf="
            . count($pdfLinks)
            . " office="
            . count($officeLinks)
            . "\n"
        );

        try {
            if ($pdfLinks !== []) {
                fwrite(STDERR, "[Meili DEBUG] Calling handlePdfLinks()\n");
                $this->pdfIndexService->handlePdfLinks($pdfLinks);
            }

            if ($officeLinks !== []) {
                fwrite(STDERR, "[Meili DEBUG] Calling handleOfficeLinks()\n");
                $this->officeIndexService->handleOfficeLinks($officeLinks);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "[Meili DEBUG] File indexing failed: {$e->getMessage()}\n");
        }
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
        fwrite(STDERR, "[Meili DEBUG] detectIndexableFileType(): $url\n");

        $url = strtok($url, '#');
        $parts = parse_url($url);

        if (!$parts) {
            fwrite(STDERR, "[Meili DEBUG]  → parse_url failed\n");
            return null;
        }

        if (!empty($parts['path'])) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            fwrite(STDERR, "[Meili DEBUG]  → path ext: $ext\n");

            if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                return $ext;
            }
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (['file', 'p', 'f'] as $param) {
                if (!empty($query[$param])) {
                    $candidate = rawurldecode(
                        html_entity_decode((string) $query[$param], ENT_QUOTES)
                    );

                    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                    fwrite(
                        STDERR,
                        "[Meili DEBUG]  → query $param=$candidate ext=$ext\n"
                    );

                    if (in_array($ext, ['pdf', 'docx', 'xlsx', 'pptx'], true)) {
                        return $ext;
                    }
                }
            }
        }

        return null;
    }
}