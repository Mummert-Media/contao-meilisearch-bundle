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
        $this->projectDir = rtrim((string) $params->get('kernel.project_dir'), '/');
    }

    /**
     * @param array<int,array{url:string,linkText:?string}> $pdfLinks
     */
    public function handlePdfLinks(array $pdfLinks): void
    {
        // Dedupe nur pro Aufruf (nicht "pro Crawl")
        $seen = [];
        $now  = time();

        foreach ($pdfLinks as $row) {
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

            $normalizedPath = $this->normalizePdfUrl($url);
            if ($normalizedPath === null) {
                continue;
            }

            $absolutePath = $this->getAbsolutePath($normalizedPath);
            if (!is_file($absolutePath)) {
                continue;
            }

            $mtime    = (int) (filemtime($absolutePath) ?: 0);
            $checksum = md5($normalizedPath . '|' . $mtime);

            // existiert bereits?
            $existing = Database::getInstance()
                ->prepare('SELECT checksum FROM tl_search_pdf WHERE url=? LIMIT 1')
                ->execute($normalizedPath)
                ->fetchAssoc();

            $needsParse = !$existing || ($existing['checksum'] ?? '') !== $checksum;

            // Titel-Priorität:
            // 1) Linktext
            // 2) PDF-Metadaten
            // 3) Dateiname
            $title = $linkText ?: basename($absolutePath);
            $text  = '';

            if ($needsParse) {
                $pdfMetaTitle = $this->readPdfMetaTitle($absolutePath);
                $title = $linkText ?: ($pdfMetaTitle ?: basename($absolutePath));

                $text = $this->parsePdf($absolutePath);
                if ($text === '') {
                    // wenn parsing fehlschlägt, NICHT überschreiben
                    continue;
                }
            }

            $this->upsertPdf(
                $normalizedPath,
                $title,
                $text,      // kann '' sein → wird in SQL nicht überschrieben
                $checksum,
                $mtime,
                $now
            );
        }
    }

    private function normalizePdfUrl(string $url): ?string
    {
        $decoded = html_entity_decode($url);
        $parts   = parse_url($decoded);

        if (!$parts) {
            return null;
        }

        // 1) files/...pdf (ohne führenden Slash)
        if (
            !empty($parts['path'])
            && str_starts_with($parts['path'], 'files/')
            && str_ends_with(strtolower($parts['path']), '.pdf')
        ) {
            return '/' . $parts['path'];
        }

        // 2) /files/...pdf
        if (
            !empty($parts['path'])
            && str_starts_with($parts['path'], '/files/')
            && str_ends_with(strtolower($parts['path']), '.pdf')
        ) {
            return $parts['path'];
        }

        if (empty($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        // 3) Contao 4: ?file=files/...
        if (!empty($query['file'])) {
            $file = urldecode((string) $query['file']);
            $file = ltrim($file, '/');

            if (
                str_starts_with($file, 'files/')
                && str_ends_with(strtolower($file), '.pdf')
            ) {
                return '/' . $file;
            }
        }

        // 4) Contao 5: ?p=...
        if (!empty($query['p'])) {
            $p = urldecode((string) $query['p']);
            return '/files/' . ltrim($p, '/');
        }

        return null;
    }

    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . ltrim($relativePath, '/');
    }

    private function upsertPdf(
        string $url,
        string $title,
        string $text,
        string $checksum,
        int $mtime,
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
                'pdf',
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
            $pdf    = $parser->parseFile($absolutePath);
            $text   = $this->cleanPdfContent($pdf->getText());

            return mb_substr($text, 0, 20000);
        } catch (\Throwable) {
            return '';
        }
    }

    private function readPdfMetaTitle(string $absolutePath): ?string
    {
        try {
            $parser  = new Parser();
            $pdf     = $parser->parseFile($absolutePath);
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

    private function cleanPdfContent(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?? $text;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}