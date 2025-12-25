<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;

    /** @var bool */
    private bool $crawlInitialized = false;

    /** @var array<string, bool> */
    private array $processedChecksums = [];

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim($params->get('kernel.project_dir'), '/');
    }

    /* =====================================================
     * PUBLIC API
     * ===================================================== */

    /**
     * Einstiegspunkt aus dem IndexPageListener
     *
     * @param array<int,array{url:string,text?:string|null}> $pdfLinks
     */
    public function handlePdfLinks(array $pdfLinks): void
    {
        // 🔴 WICHTIG: Reset garantiert VOR dem ersten INSERT
        $this->initializeCrawl();

        foreach ($pdfLinks as $pdf) {
            try {
                $url      = $pdf['url'];
                $linkText = $pdf['text'] ?? null;

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

                // Datei-Zeitstempel
                $mtime = filemtime($absolutePath) ?: 0;

                // Stabiler Crawl-Checksum
                $checksum = md5($relativePath . '|' . $mtime);

                // Pro Crawl deduplizieren
                if (isset($this->processedChecksums[$checksum])) {
                    error_log('→ übersprungen: bereits im Crawl verarbeitet');
                    continue;
                }
                $this->processedChecksums[$checksum] = true;

                // Titel bestimmen
                $title = $this->resolveTitle($absolutePath, $linkText);

                // PDF parsen
                $text = $this->parsePdf($absolutePath);
                if ($text === '') {
                    error_log('→ übersprungen: PDF ohne Textinhalt');
                    continue;
                }

                // Schreiben
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
                error_log($e->getTraceAsString());
            }
        }
    }

    /* =====================================================
     * CRAWL-LIFECYCLE
     * ===================================================== */

    private function initializeCrawl(): void
    {
        if ($this->crawlInitialized) {
            return;
        }

        $this->crawlInitialized = true;
        $this->processedChecksums = [];

        Database::getInstance()->execute('TRUNCATE TABLE tl_search_pdf');

        error_log('PDF Crawl initialisiert → tl_search_pdf geleert');
    }

    /* =====================================================
     * URL-NORMALISIERUNG
     * ===================================================== */

    private function normalizePdfUrl(string $url): ?string
    {
        // Direkter /files-Link
        if (str_starts_with($url, '/files/') && str_ends_with($url, '.pdf')) {
            return $url;
        }

        // Contao Hash-/Download-Link (?p=)
        $decoded = html_entity_decode($url);
        $parts   = parse_url($decoded);

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
     * TITEL-AUFLÖSUNG
     * ===================================================== */

    private function resolveTitle(string $absolutePath, ?string $linkText): string
    {
        // 1. Linktext aus HTML
        if (is_string($linkText) && trim($linkText) !== '') {
            return trim($linkText);
        }

        // 2. PDF-Metadaten
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($absolutePath);
            $details = $pdf->getDetails();

            if (!empty($details['Title'])) {
                return trim((string) $details['Title']);
            }
        } catch (\Throwable) {
            // ignorieren
        }

        // 3. Fallback: Dateiname
        return basename($absolutePath);
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
            ->prepare(
                'INSERT INTO tl_search_pdf
                 (tstamp, url, title, text, checksum, file_mtime)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
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
     * PDF PARSING
     * ===================================================== */

    private function parsePdf(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($absolutePath);

            $text = $this->cleanPdfContent($pdf->getText());

            // Begrenzen (Performance + Relevanz)
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

        // Sonderglyphen raus
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);

        // Worttrennungen reparieren
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', '', $text);

        // Apostrophe normalisieren
        $text = str_replace(["\\'", '’', '‘'], "'", $text);

        // Mehrfache Satzzeichen
        $text = preg_replace('/([.,;:!?])\1+/', '$1', $text);

        // Whitespaces
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}