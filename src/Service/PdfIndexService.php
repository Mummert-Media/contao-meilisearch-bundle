<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;

    // pro PHP-Process genau 1x resetten
    private bool $didReset = false;

    // pro Crawl-Durchlauf: doppelte Verarbeitung vermeiden
    private array $seenThisCrawl = [];

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim((string) $params->get('kernel.project_dir'), '/');
    }

    /**
     * Wird aus dem Listener beim ersten Hook-Call pro Crawl aufgerufen.
     * MUSS IMMER laufen (auch wenn Checkbox später aus ist).
     */
    public function resetTableOnce(): void
    {
        if ($this->didReset) {
            return;
        }

        $this->didReset = true;
        $this->seenThisCrawl = [];

        // bei <=100 PDFs: sauber & simpel
        Database::getInstance()->execute('TRUNCATE tl_search_pdf');

        error_log('PDF Reset: tl_search_pdf geleert (TRUNCATE)');
    }

    /**
     * @param array<int,array{url:string,linkText:?string}> $pdfLinks
     */
    public function handlePdfLinks(array $pdfLinks): void
    {
        foreach ($pdfLinks as $row) {
            $url = (string) ($row['url'] ?? '');
            $linkText = $row['linkText'] ?? null;

            if ($url === '') {
                continue;
            }

            try {
                error_log('bearbeite PDF: ' . $url);

                // innerhalb des Crawls gleiche URL nicht 20x parsen (News-Teaser etc.)
                $seenKey = md5($url);
                if (isset($this->seenThisCrawl[$seenKey])) {
                    error_log('→ übersprungen: bereits im Crawl verarbeitet');
                    continue;
                }
                $this->seenThisCrawl[$seenKey] = true;

                $normalizedPath = $this->normalizePdfUrl($url);
                if ($normalizedPath === null) {
                    error_log('→ übersprungen: kein gültiger PDF-Pfad');
                    continue;
                }

                $absolutePath = $this->getAbsolutePath($normalizedPath);
                if (!is_file($absolutePath)) {
                    error_log('→ übersprungen: Datei existiert nicht: ' . $absolutePath);
                    continue;
                }

                $mtime = (int) (filemtime($absolutePath) ?: 0);
                $checksum = md5($normalizedPath . '|' . $mtime);

                // Titel-Priorität:
                // 1) Linktext
                // 2) PDF-Metadaten Title
                // 3) Dateiname
                $pdfMetaTitle = $this->readPdfMetaTitle($absolutePath);
                $title = $linkText ?: ($pdfMetaTitle ?: basename($absolutePath));

                $text = $this->parsePdf($absolutePath);
                if ($text === '') {
                    error_log('→ übersprungen: PDF ohne Textinhalt');
                    continue;
                }

                $this->upsertPdf(
                    $normalizedPath,
                    $title,
                    $text,
                    $checksum,
                    $mtime
                );

                error_log('geschrieben in tl_search_pdf');

            } catch (\Throwable $e) {
                error_log('PDF Service FEHLER: ' . $e->getMessage());
            }
        }
    }

    private function normalizePdfUrl(string $url): ?string
    {
        // Fall 1: direkter /files/-Pfad
        if (str_starts_with($url, '/files/') && preg_match('~\.pdf(\?.*)?$~i', $url)) {
            return preg_replace('~\?.*$~', '', $url);
        }

        $decoded = html_entity_decode($url);
        $parts = parse_url($decoded);

        // Fall 2: absolute URL auf gleiche Site -> Pfad extrahieren
        if (!empty($parts['path']) && str_starts_with($parts['path'], '/files/') && str_ends_with(strtolower($parts['path']), '.pdf')) {
            return $parts['path'];
        }

        // Fall 3: Contao-Download-Link mit ?p=
        if (empty($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        if (!empty($query['p'])) {
            $p = (string) $query['p'];

            // Query-Parameter korrekt dekodieren
            $p = urldecode($p);

            // Leerzeichen aus Query-Parametern wieder zu '+' normalisieren
            $p = str_replace(' ', '+', $p);

            // deine Links enthalten oft "pdf/DATEI.pdf"
            // => wird zu "/files/pdf/DATEI.pdf"
            return '/files/' . ltrim($p, '/');
        }

        return null;
    }

    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . ltrim($relativePath, '/');
    }

    private function upsertPdf(string $url, string $title, string $text, string $checksum, int $mtime): void
    {
        $db = Database::getInstance();

        // wichtig: UNIQUE(checksum) -> entweder INSERT oder UPDATE
        $db->prepare('
            INSERT INTO tl_search_pdf
                (tstamp, url, title, text, checksum, file_mtime)
            VALUES
                (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                tstamp=VALUES(tstamp),
                url=VALUES(url),
                title=VALUES(title),
                text=VALUES(text),
                file_mtime=VALUES(file_mtime)
        ')->execute(
            time(),
            $url,
            $title,
            $text,
            $checksum,
            $mtime
        );
    }

    private function parsePdf(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);

            $text = $this->cleanPdfContent($pdf->getText());

            return mb_substr($text, 0, 20000);

        } catch (\Throwable) {
            return '';
        }
    }

    private function cleanPdfContent(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?? $text;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', ' ', $text);
        $text = str_replace(["\\'", "’", "‘"], "'", $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function readPdfMetaTitle(string $absolutePath): ?string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);

            $details = $pdf->getDetails();

            foreach (['Title', 'title'] as $key) {
                if (!empty($details[$key]) && is_string($details[$key])) {
                    $t = trim($details[$key]);
                    if ($t !== '') {
                        return $t;
                    }
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }
}