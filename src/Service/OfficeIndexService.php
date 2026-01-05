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

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim((string) $params->get('kernel.project_dir'), '/');
    }

    /**
     * @param array<int,array{url:string,linkText:?string}> $officeLinks
     */
    public function handleOfficeLinks(array $officeLinks): void
    {
        // Dedupe nur pro Aufruf (nicht "pro Crawl")
        $seen = [];
        $now  = time();

        foreach ($officeLinks as $row) {
            $url      = (string) ($row['url'] ?? '');
            $linkText = $row['linkText'] ?? null;

            if ($url === '') {
                continue;
            }

            // doppelte URLs pro Aufruf vermeiden
            $seenKey = md5($url);
            if (isset($seen[$seenKey])) {
                continue;
            }
            $seen[$seenKey] = true;

            $normalized = $this->normalizeOfficeUrl($url);
            if ($normalized === null) {
                continue;
            }

            [$relativePath, $type] = $normalized;

            $absolutePath = $this->getAbsolutePath($relativePath);
            if (!is_file($absolutePath)) {
                continue;
            }

            $mtime    = (int) (filemtime($absolutePath) ?: 0);
            $checksum = md5($relativePath . '|' . $mtime);

            // existiert bereits?
            $existing = Database::getInstance()
                ->prepare('SELECT checksum FROM tl_search_pdf WHERE url=? LIMIT 1')
                ->execute($relativePath)
                ->fetchAssoc();

            $needsParse = !$existing || ($existing['checksum'] ?? '') !== $checksum;

            // Titel-Priorität:
            // 1) Linktext
            // 2) Dateiname
            $title = $linkText ?: basename($absolutePath);
            $text  = '';

            if ($needsParse) {
                $text = $this->parseOfficeFile($absolutePath, $type);
                if ($text === '') {
                    // Parsing fehlgeschlagen → nichts überschreiben
                    continue;
                }
            }

            $this->upsertOffice(
                $relativePath,
                $title,
                $text,      // kann '' sein → SQL überschreibt dann nicht
                $checksum,
                $mtime,
                $type,
                $now
            );
        }
    }

    /**
     * @return array{string,string}|null [relativePath, type]
     */
    private function normalizeOfficeUrl(string $url): ?array
    {
        $decoded = html_entity_decode($url);
        $parts   = parse_url($decoded);

        if (!$parts) {
            return null;
        }

        // 1) files/... (ohne führenden Slash)
        if (!empty($parts['path']) && str_starts_with($parts['path'], 'files/')) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                return ['/' . $parts['path'], $ext];
            }
        }

        // 2) /files/...
        if (!empty($parts['path']) && str_starts_with($parts['path'], '/files/')) {
            $ext = strtolower(pathinfo($parts['path'], PATHINFO_EXTENSION));
            if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                return [$parts['path'], $ext];
            }
        }

        if (empty($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        // 3) Contao 4: ?file=files/...
        if (!empty($query['file'])) {
            $file = urldecode((string) $query['file']);
            $file = ltrim($file, '/');
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (
                str_starts_with($file, 'files/') &&
                in_array($ext, ['docx', 'xlsx', 'pptx'], true)
            ) {
                return ['/' . $file, $ext];
            }
        }

        // 4) Contao 5: ?p=...
        if (!empty($query['p'])) {
            $p   = urldecode((string) $query['p']);
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));

            if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                return ['/files/' . ltrim($p, '/'), $ext];
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
        string $type,
        int $now
    ): void {
        Database::getInstance()
            ->prepare('
                INSERT INTO tl_search_pdf
                    (tstamp, last_seen, type, url, title, text, checksum, file_mtime)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    tstamp     = VALUES(tstamp),
                    last_seen  = VALUES(last_seen),
                    type       = VALUES(type),
                    url        = VALUES(url),
                    title      = VALUES(title),
                    checksum   = VALUES(checksum),
                    file_mtime = VALUES(file_mtime),
                    text       = IF(VALUES(text) = "" OR VALUES(text) IS NULL, text, VALUES(text))
            ')
            ->execute(
                $now,
                $now,
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