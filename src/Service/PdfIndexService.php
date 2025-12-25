<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Contao\StringUtil;
use Smalot\PdfParser\Parser;

class PdfIndexService
{
    public function handlePdfLinks(array $pdfLinks): void
    {
        error_log('PDF Service aufgerufen');

        foreach ($pdfLinks as $url) {
            error_log('bearbeite PDF: ' . $url);

            $normalizedUrl = $this->normalizePdfUrl($url);
            if ($normalizedUrl === null) {
                error_log('→ PDF übersprungen (URL nicht normalisierbar)');
                continue;
            }

            error_log('umgewandelte URL: ' . $normalizedUrl);

            $absolutePath = $this->getAbsolutePath($normalizedUrl);
            if ($absolutePath === null || !is_file($absolutePath)) {
                error_log('→ PDF übersprungen (Datei nicht gefunden): ' . $absolutePath);
                continue;
            }

            $mtime = filemtime($absolutePath) ?: 0;
            $checksum = md5($normalizedUrl . $mtime);

            if ($this->alreadyIndexed($checksum)) {
                error_log('→ PDF bereits indexiert (Checksumme vorhanden)');
                continue;
            }

            $title = basename($absolutePath);
            error_log('gefundener Title: ' . $title);

            $text = $this->parsePdf($absolutePath);
            if ($text === '') {
                error_log('→ PDF übersprungen (kein Text extrahiert)');
                continue;
            }

            $this->insertPdf(
                $normalizedUrl,
                $title,
                $text,
                $checksum,
                $mtime
            );

            error_log('geschrieben in tl_search_pdf');
        }
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

            return mb_substr($text, 0, 5000);
        } catch (\Throwable $e) {
            error_log('→ Fehler beim Parsen der PDF: ' . $e->getMessage());
            return '';
        }
    }

    private function cleanPdfContent(string $content): string
    {
        $content = StringUtil::decodeEntities($content);
        $content = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $content);
        $content = preg_replace('/\s+/u', ' ', $content);

        return trim($content);
    }

    /* =====================================================
     * DB
     * ===================================================== */

    private function alreadyIndexed(string $checksum): bool
    {
        $result = Database::getInstance()
            ->prepare('SELECT id FROM tl_search_pdf WHERE checksum=?')
            ->execute($checksum);

        return $result->numRows > 0;
    }

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
                VALUES
                    (?, ?, ?, ?, ?, ?)
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
     * URL & Pfad-Helfer
     * ===================================================== */

    private function normalizePdfUrl(string $url): ?string
    {
        $url = html_entity_decode($url);

        // 1) direkter /files/*.pdf-Link (immer korrekt)
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && preg_match('~^/files/.*\.pdf$~i', $path)) {
            return $path;
        }

        // 2) Query-Parameter prüfen
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return null;
        }

        parse_str($query, $params);

        // 2a) Contao p=pdf/xyz.pdf
        if (!empty($params['p']) && preg_match('~\.pdf$~i', $params['p'])) {
            return '/files/' . ltrim($params['p'], '/');
        }

        // 2b) Contao Download: f=Dateiname → Dateisystem suchen
        if (!empty($params['f'])) {
            $file = basename($params['f']);

            // Suche im /files-Verzeichnis (rekursiv, aber schnell genug)
            $matches = glob(TL_ROOT . '/files/**/' . $file, GLOB_BRACE);

            if (!empty($matches)) {
                return str_replace(TL_ROOT, '', $matches[0]);
            }
        }

        return null;
    }

    private function getAbsolutePath(string $url): ?string
    {
        if (!str_starts_with($url, '/files/')) {
            return null;
        }

        return TL_ROOT . $url;
    }
}