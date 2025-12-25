<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;

    /**
     * Merkt sich Checksums innerhalb eines Crawls
     * → verhindert Duplicate INSERTs
     */
    private array $processedChecksums = [];

    /**
     * Flag, damit das Reset nur 1× pro Crawl passiert
     */
    private bool $resetDone = false;

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim($params->get('kernel.project_dir'), '/');
    }

    /* =====================================================
     * Crawl-Start: Tabelle leeren
     * ===================================================== */
    public function startCrawl(): void
    {
        if ($this->resetDone) {
            return;
        }

        Database::getInstance()->execute('TRUNCATE TABLE tl_search_pdf');

        $this->processedChecksums = [];
        $this->resetDone = true;

        error_log('PDF Crawl Start → tl_search_pdf geleert');
    }

    /* =====================================================
     * Einstiegspunkt aus dem Listener
     * ===================================================== */
    public function handlePdfLinks(array $pdfLinks): void
    {
        foreach ($pdfLinks as $pdf) {
            try {
                $url = $pdf['url'];
                $linkText = $pdf['text'] ?? null;

                error_log('bearbeite PDF: ' . $url);

                $relativePath = $this->normalizePdfUrl($url);
                if ($relativePath === null) {
                    error_log('→ übersprungen: kein gültiger PDF-Pfad');
                    continue;
                }

                $absolutePath = $this->getAbsolutePath($relativePath);
                if (!is_file($absolutePath)) {
                    error_log('→ übersprungen: Datei existiert nicht');
                    continue;
                }

                $mtime = filemtime($absolutePath) ?: 0;
                $checksum = md5($relativePath . $mtime);

                if (isset($this->processedChecksums[$checksum])) {
                    error_log('→ übersprungen: bereits im Crawl verarbeitet');
                    continue;
                }

                $this->processedChecksums[$checksum] = true;

                $title = $this->resolveTitle($linkText, $absolutePath);
                $text  = $this->parsePdf($absolutePath);

                if ($text === '') {
                    error_log('→ übersprungen: PDF ohne Textinhalt');
                    continue;
                }

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
     * Titel-Ermittlung (Prio!)
     * ===================================================== */
    private function resolveTitle(?string $linkText, string $absolutePath): string
    {
        if (is_string($linkText) && trim($linkText) !== '') {
            return trim(strip_tags($linkText));
        }

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $details = $pdf->getDetails();

            if (!empty($details['Title'])) {
                return trim((string) $details['Title']);
            }
        } catch (\Throwable) {
            // ignorieren
        }

        return basename($absolutePath);
    }

    /* =====================================================
     * URL → relativer /files-Pfad
     * ===================================================== */
    private function normalizePdfUrl(string $url): ?string
    {
        // direkter /files-Link
        if (str_starts_with($url, '/files/') && str_ends_with($url, '.pdf')) {
            return $url;
        }

        // Contao Download-Link (?p=pdf/...)
        $decoded = html_entity_decode($url);
        $parts = parse_url($decoded);

        if (!isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        if (!empty($query['p'])) {
            return '/files/' . ltrim($query['p'], '/');
        }

        return null;
    }

    /* =====================================================
     * relativer → absoluter Pfad
     * ===================================================== */
    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . ltrim($relativePath, '/');
    }

    /* =====================================================
     * DB INSERT
     * ===================================================== */
    private function insertPdf(
        string $path,
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
                $path,
                $title,
                $text,
                $checksum,
                $mtime
            );
    }

    /* =====================================================
     * PDF-Parsing + Cleanup
     * ===================================================== */
    private function parsePdf(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $this->cleanPdfContent($pdf->getText());

            return mb_substr($text, 0, 5000);
        } catch (\Throwable $e) {
            error_log('PDF Parser FEHLER: ' . $e->getMessage());
            return '';
        }
    }

    private function cleanPdfContent(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', ' ', $text);
        $text = str_replace(["\\'", "’", "‘"], "'", $text);
        $text = preg_replace('/([.,;:!?])\1+/', '$1', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}