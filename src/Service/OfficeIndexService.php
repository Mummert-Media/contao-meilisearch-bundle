<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class OfficeIndexService
{
    private string $projectDir;

    // pro Crawl-Durchlauf: doppelte Verarbeitung vermeiden
    private array $seenThisCrawl = [];

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim((string) $params->get('kernel.project_dir'), '/');
    }

    /**
     * @param array<int,array{url:string,linkText:?string}> $officeLinks
     */
    public function handleOfficeLinks(array $officeLinks): void
    {
        foreach ($officeLinks as $row) {
            $url = (string) ($row['url'] ?? '');
            $linkText = $row['linkText'] ?? null;

            if ($url === '') {
                continue;
            }

            try {
                error_log('bearbeite Office-Datei: ' . $url);

                // innerhalb des Crawls gleiche URL nicht mehrfach parsen
                $seenKey = md5($url);
                if (isset($this->seenThisCrawl[$seenKey])) {
                    error_log('→ übersprungen: bereits im Crawl verarbeitet');
                    continue;
                }
                $this->seenThisCrawl[$seenKey] = true;

                $normalized = $this->normalizeOfficeUrl($url);
                if ($normalized === null) {
                    error_log('→ übersprungen: kein gültiger Office-Pfad');
                    continue;
                }

                [$relativePath, $type] = $normalized;

                $absolutePath = $this->getAbsolutePath($relativePath);
                if (!is_file($absolutePath)) {
                    error_log('→ übersprungen: Datei existiert nicht: ' . $absolutePath);
                    continue;
                }

                $mtime = (int) (filemtime($absolutePath) ?: 0);
                $checksum = md5($relativePath . '|' . $mtime);

                $title = $linkText ?: basename($absolutePath);

                $text = $this->parseOfficeFile($absolutePath, $type);
                if ($text === '') {
                    error_log('→ übersprungen: Office-Datei ohne Textinhalt');
                    continue;
                }

                $this->upsertOffice(
                    $relativePath,
                    $title,
                    $text,
                    $checksum,
                    $mtime,
                    $type
                );

                error_log('geschrieben in tl_search_pdf');

            } catch (\Throwable $e) {
                error_log('Office Service FEHLER: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return array{string,string}|null [relativePath, type]
     */
    private function normalizeOfficeUrl(string $url): ?array
    {
        $decoded = html_entity_decode($url);
        $parts = parse_url($decoded);

        // direkter /files/-Pfad
        if (!empty($parts['path']) && str_starts_with($parts['path'], '/files/')) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                return [$parts['path'], $ext];
            }
        }

        // Contao-Download-Link mit ?p=
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);

            if (!empty($query['p'])) {
                $p = (string) $query['p'];

                // Query-Parameter korrekt dekodieren
                $p = urldecode($p);

                // Leerzeichen aus Query-Parametern wieder zu '+' normalisieren
                $p = str_replace(' ', '+', $p);

                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));

                if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                    return ['/files/' . ltrim($p, '/'), $ext];
                }
            }
        }

        return null;
    }

    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . ltrim($relativePath, '/');
    }

    private function upsertOffice(
        string $url,
        string $title,
        string $text,
        string $checksum,
        int $mtime,
        string $type
    ): void {
        $db = Database::getInstance();

        $db->prepare('
            INSERT INTO tl_search_pdf
                (tstamp, type, url, title, text, checksum, file_mtime)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                tstamp=VALUES(tstamp),
                type=VALUES(type),
                url=VALUES(url),
                title=VALUES(title),
                text=VALUES(text),
                file_mtime=VALUES(file_mtime)
        ')->execute(
            time(),
            $type,
            $url,
            $title,
            $text,
            $checksum,
            $mtime
        );
    }

    private function parseOfficeFile(string $absolutePath, string $type): string
    {
        return match ($type) {
            'docx' => $this->parseDocx($absolutePath),
            'xlsx' => $this->parseXlsx($absolutePath),
            'pptx' => $this->parsePptx($absolutePath),
            default => '',
        };
    }

    private function parseDocx(string $absolutePath): string
    {
        try {
            $phpWord = WordIOFactory::load($absolutePath);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= ' ' . $element->getText();
                    }
                }
            }

            return $this->cleanText($text);

        } catch (\Throwable) {
            return '';
        }
    }

    private function parseXlsx(string $absolutePath): string
    {
        try {
            $spreadsheet = SpreadsheetIOFactory::load($absolutePath);
            $text = '';

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                foreach ($sheet->toArray() as $row) {
                    $text .= ' ' . implode(' ', array_filter($row, 'is_scalar'));
                }
            }

            return $this->cleanText($text);

        } catch (\Throwable) {
            return '';
        }
    }

    private function parsePptx(string $absolutePath): string
    {
        try {
            $presentation = PresentationIOFactory::load($absolutePath);
            $text = '';

            foreach ($presentation->getAllSlides() as $slide) {
                foreach ($slide->getShapeCollection() as $shape) {
                    if (method_exists($shape, 'getPlainText')) {
                        $text .= ' ' . $shape->getPlainText();
                    }
                }
            }

            return $this->cleanText($text);

        } catch (\Throwable) {
            return '';
        }
    }

    private function cleanText(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?? $text;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim(mb_substr($text, 0, 20000));
    }
}