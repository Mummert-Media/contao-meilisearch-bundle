<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;
    private bool $crawlStarted = false;

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim($params->get('kernel.project_dir'), '/');
    }

    /* =====================================================
     * Crawl-Start (immer aufrufen!)
     * ===================================================== */
    public function startCrawl(): void
    {
        if ($this->crawlStarted) {
            return;
        }

        $this->crawlStarted = true;

        // bewusst simpel: bei JEDEM Crawl komplett leeren
        Database::getInstance()->execute('TRUNCATE TABLE tl_search_pdf');

        error_log('PDF Crawl gestartet → tl_search_pdf geleert');
    }

    /* =====================================================
     * Einstiegspunkt aus IndexPageListener
     * ===================================================== */
    public function handlePdfLinks(array $pdfLinks): void
    {
        foreach ($pdfLinks as $pdf) {
            try {
                $url = $pdf['url'];
                $linkText = $pdf['linkText'] ?? null;

                error_log('bearbeite PDF: ' . $url);

                $relativePath = $this->normalizePdfUrl($url);
                if ($relativePath === null) {
                    error_log('→ übersprungen: kein gültiger PDF-Pfad');
                    continue;
                }

                $absolutePath = $this->projectDir . '/' . ltrim($relativePath, '/');
                if (!is_file($absolutePath)) {
                    error_log('→ übersprungen: Datei existiert nicht');
                    continue;
                }

                $mtime = filemtime($absolutePath) ?: 0;
                $checksum = md5($relativePath . $mtime);

                // PDF parsen
                [$text, $metaTitle] = $this->parsePdf($absolutePath);

                if ($text === '') {
                    error_log('→ übersprungen: kein Textinhalt');
                    continue;
                }

                // TITEL-PRIORITÄT
                $title =
                    $linkText
                        ?: $metaTitle
                        ?: basename($absolutePath);

                $this->insertPdf(
                    $relativePath,
                    $title,
                    $text,
                    $checksum,
                    $mtime
                );

                error_log('→ geschrieben in tl_search_pdf');

            } catch (\Throwable $e) {
                error_log('PDF Service FEHLER: ' . $e->getMessage());
            }
        }
    }

    /* =====================================================
     * URL → relativer /files-Pfad
     * ===================================================== */
    private function normalizePdfUrl(string $url): ?string
    {
        // direkter /files-Link
        if (str_starts_with($url, '/files/') && str_ends_with(strtolower($url), '.pdf')) {
            return $url;
        }

        // Contao-Download-Link (?p=)
        $decoded = html_entity_decode($url);
        $parts = parse_url($decoded);

        if (!isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        if (!empty($query['p']) && str_ends_with(strtolower($query['p']), '.pdf')) {
            return '/files/' . ltrim($query['p'], '/');
        }

        return null;
    }

    /* =====================================================
     * DB
     * ===================================================== */
    private function insertPdf(
        string $url,
        string $title,
        string $text,
        string $checksum,
        int $mtime
    ): void {
        Database::getInstance()
            ->prepare('
                INSERT INTO tl_search_pdf
                    (tstamp, url, title, text, checksum, file_mtime)
                VALUES (?, ?, ?, ?, ?, ?)
            ')
            ->execute(
                time(),
                $url,
                $title,
                $text,
                $checksum,
                $mtime
            );
    }

    /* =====================================================
     * PDF Parsing
     * ===================================================== */
    private function parsePdf(string $absolutePath): array
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);

            $details = $pdf->getDetails();
            $metaTitle = $details['Title'] ?? null;

            $text = $this->cleanPdfContent($pdf->getText());

            return [
                mb_substr($text, 0, 5000),
                is_string($metaTitle) && trim($metaTitle) !== '' ? trim($metaTitle) : null,
            ];

        } catch (\Throwable $e) {
            error_log('PDF Parser FEHLER: ' . $e->getMessage());
            return ['', null];
        }
    }

    /* =====================================================
     * Text-Bereinigung
     * ===================================================== */
    private function cleanPdfContent(string $text): string
    {
        // Unicode normalisieren
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        // Sonderglyphen entfernen (Noten, Steuerzeichen etc.)
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);

        // falsche Worttrennungen ("ges pielt")
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', '', $text);

        // Apostrophe vereinheitlichen
        $text = str_replace(["\\'", "’", "‘"], "'", $text);

        // Mehrfach-Leerzeichen
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}