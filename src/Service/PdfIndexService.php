<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;

    public function __construct(ParameterBagInterface $params)
    {
        // Contao 5 / Symfony-konform
        $this->projectDir = rtrim($params->get('kernel.project_dir'), '/');
    }

    /**
     * Einstiegspunkt aus dem IndexPageListener
     */
    public function handlePdfLinks(array $pdfLinks): void
    {
        error_log('PDF Service aufgerufen');
        error_log('PDF Links Count: ' . count($pdfLinks));
        error_log('PDF Links: ' . json_encode($pdfLinks, JSON_UNESCAPED_SLASHES));

        foreach ($pdfLinks as $url) {
            try {
                error_log('bearbeite PDF: ' . $url);

                $normalizedPath = $this->normalizePdfUrl($url);
                error_log('umgewandelte URL: ' . var_export($normalizedPath, true));

                if ($normalizedPath === null) {
                    error_log('→ übersprungen: kein gültiger PDF-Pfad');
                    continue;
                }

                $absolutePath = $this->getAbsolutePath($normalizedPath);
                error_log('absoluter Pfad: ' . var_export($absolutePath, true));

                if (!is_file($absolutePath)) {
                    error_log('→ übersprungen: Datei existiert nicht');
                    continue;
                }

                $mtime = filemtime($absolutePath) ?: 0;
                $checksum = md5($normalizedPath . $mtime);

                if ($this->alreadyIndexed($checksum)) {
                    error_log('→ übersprungen: bereits indexiert');
                    continue;
                }

                $title = basename($absolutePath);
                error_log('gefundener Title: ' . $title);

                $text = $this->parsePdf($absolutePath);
                if ($text === '') {
                    error_log('→ übersprungen: PDF ohne Textinhalt');
                    continue;
                }

                $this->insertPdf(
                    $normalizedPath,
                    $title,
                    $text,
                    $checksum,
                    $mtime
                );

                error_log('geschrieben in tl_search_pdf');

            } catch (\Throwable $e) {
                error_log('PDF Service FEHLER (pro PDF): ' . $e->getMessage());
                error_log($e->getTraceAsString());
            }
        }
    }

    /* =====================================================
     * URL → relativer /files-Pfad
     * ===================================================== */
    private function normalizePdfUrl(string $url): ?string
    {
        $url = html_entity_decode($url);

        // direkter /files/*.pdf-Link
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && preg_match('~^/files/.*\.pdf$~i', $path)) {
            return $path;
        }

        return null;
    }

    /* =====================================================
     * relativer Pfad → absoluter Pfad
     * ===================================================== */
    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . ltrim($relativePath, '/');
    }

    /* =====================================================
     * DB-Helfer
     * ===================================================== */
    private function alreadyIndexed(string $checksum): bool
    {
        $db = Database::getInstance();

        $result = $db
            ->prepare('SELECT id FROM tl_search_pdf WHERE checksum = ?')
            ->execute($checksum);

        return $result->numRows > 0;
    }

    private function insertPdf(
        string $path,
        string $title,
        string $text,
        string $checksum,
        int $mtime
    ): void {
        $db = Database::getInstance();

        $db
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
     * PDF-Parsing
     * ===================================================== */
    private function parsePdf(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);

            $text = $this->cleanPdfContent($pdf->getText());

            // bewusst begrenzen (Performance + Relevanz)
            return mb_substr($text, 0, 5000);

        } catch (\Throwable $e) {
            error_log('PDF Parser FEHLER: ' . $e->getMessage());
            return '';
        }
    }

    private function cleanPdfContent(string $text): string
    {
        // 1. Unicode normalisieren (wichtig!)
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        // 2. Musik- & Spezialglyphen entfernen
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);

        // 3. Falsche Worttrennungen reparieren: "ges pielt" → "gespielt"
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', ' ', $text);

        // 4. Spezielle PDF-Apostrophe reparieren
        $text = str_replace(
            ["\\'", "’", "‘"],
            "'",
            $text
        );

        // 5. Mehrfache Satzzeichen bereinigen
        $text = preg_replace('/([.,;:!?])\1+/', '$1', $text);

        // 6. Überflüssige Leerzeichen & Zeilenumbrüche
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}