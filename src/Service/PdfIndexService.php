<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;

    private bool $didReset = false;
    private array $seenThisCrawl = [];

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim((string) $params->get('kernel.project_dir'), '/');
        fwrite(STDERR, "[Meili PDF DEBUG] projectDir={$this->projectDir}\n");
    }

    public function resetTableOnce(): void
    {
        if ($this->didReset) {
            fwrite(STDERR, "[Meili PDF DEBUG] resetTableOnce(): already reset\n");
            return;
        }

        fwrite(STDERR, "[Meili PDF DEBUG] resetTableOnce(): TRUNCATE tl_search_pdf\n");

        $this->didReset = true;
        $this->seenThisCrawl = [];

        try {
            Database::getInstance()->execute('TRUNCATE tl_search_pdf');
        } catch (\Throwable $e) {
            fwrite(STDERR, "[Meili PDF DEBUG] TRUNCATE failed: {$e->getMessage()}\n");
        }
    }

    public function handlePdfLinks(array $pdfLinks): void
    {
        fwrite(
            STDERR,
            "[Meili PDF DEBUG] handlePdfLinks(): count=" . count($pdfLinks) . "\n"
        );

        foreach ($pdfLinks as $row) {
            $url = (string) ($row['url'] ?? '');
            $linkText = $row['linkText'] ?? null;

            fwrite(STDERR, "\n[Meili PDF DEBUG] URL={$url}\n");

            if ($url === '') {
                fwrite(STDERR, "[Meili PDF DEBUG] → empty URL, skip\n");
                continue;
            }

            $seenKey = md5($url);
            if (isset($this->seenThisCrawl[$seenKey])) {
                fwrite(STDERR, "[Meili PDF DEBUG] → already processed, skip\n");
                continue;
            }
            $this->seenThisCrawl[$seenKey] = true;

            $normalizedPath = $this->normalizePdfUrl($url);
            fwrite(
                STDERR,
                "[Meili PDF DEBUG] normalizePdfUrl() → "
                . ($normalizedPath ?? 'NULL')
                . "\n"
            );

            if ($normalizedPath === null) {
                fwrite(STDERR, "[Meili PDF DEBUG] → normalization failed, skip\n");
                continue;
            }

            $absolutePath = $this->getAbsolutePath($normalizedPath);
            fwrite(STDERR, "[Meili PDF DEBUG] absolutePath={$absolutePath}\n");

            if (!is_file($absolutePath)) {
                fwrite(STDERR, "[Meili PDF DEBUG] → file does NOT exist\n");
                continue;
            }

            fwrite(STDERR, "[Meili PDF DEBUG] → file exists\n");

            $mtime = (int) (filemtime($absolutePath) ?: 0);
            $checksum = md5($normalizedPath . '|' . $mtime);

            fwrite(
                STDERR,
                "[Meili PDF DEBUG] mtime={$mtime} checksum={$checksum}\n"
            );

            $pdfMetaTitle = $this->readPdfMetaTitle($absolutePath);
            fwrite(
                STDERR,
                "[Meili PDF DEBUG] metaTitle="
                . ($pdfMetaTitle ?: 'NULL')
                . "\n"
            );

            $title = $linkText ?: ($pdfMetaTitle ?: basename($absolutePath));
            fwrite(STDERR, "[Meili PDF DEBUG] final title={$title}\n");

            $text = $this->parsePdf($absolutePath);
            fwrite(
                STDERR,
                "[Meili PDF DEBUG] parsed text length=" . strlen($text) . "\n"
            );

            if ($text === '') {
                fwrite(STDERR, "[Meili PDF DEBUG] → empty text, skip\n");
                continue;
            }

            fwrite(STDERR, "[Meili PDF DEBUG] → writing to DB\n");

            $this->upsertPdf(
                $normalizedPath,
                $title,
                $text,
                $checksum,
                $mtime
            );
        }
    }

    private function normalizePdfUrl(string $url): ?string
    {
        fwrite(STDERR, "[Meili PDF DEBUG] normalizePdfUrl(): {$url}\n");

        if (str_starts_with($url, '/files/') && preg_match('~\.pdf(\?.*)?$~i', $url)) {
            $r = preg_replace('~\?.*$~', '', $url);
            fwrite(STDERR, "[Meili PDF DEBUG] → direct /files path {$r}\n");
            return $r;
        }

        $decoded = html_entity_decode($url);
        $parts = parse_url($decoded);

        if (
            !empty($parts['path'])
            && str_starts_with($parts['path'], '/files/')
            && str_ends_with(strtolower($parts['path']), '.pdf')
        ) {
            fwrite(STDERR, "[Meili PDF DEBUG] → absolute URL path {$parts['path']}\n");
            return $parts['path'];
        }

        if (empty($parts['query'])) {
            fwrite(STDERR, "[Meili PDF DEBUG] → no query\n");
            return null;
        }

        parse_str($parts['query'], $query);

        if (!empty($query['p'])) {
            $p = urldecode((string) $query['p']);
            $r = '/files/' . ltrim($p, '/');
            fwrite(STDERR, "[Meili PDF DEBUG] → p= normalized {$r}\n");
            return $r;
        }

        fwrite(STDERR, "[Meili PDF DEBUG] → no usable parameter\n");
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
        int $mtime
    ): void {
        try {
            Database::getInstance()
                ->prepare('
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
                ')
                ->execute(
                    time(),
                    $url,
                    $title,
                    $text,
                    $checksum,
                    $mtime
                );

            fwrite(STDERR, "[Meili PDF DEBUG] → DB write OK\n");
        } catch (\Throwable $e) {
            fwrite(
                STDERR,
                "[Meili PDF DEBUG] DB write failed: {$e->getMessage()}\n"
            );
        }
    }

    private function parsePdf(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $this->cleanPdfContent($pdf->getText());
            return mb_substr($text, 0, 20000);
        } catch (\Throwable $e) {
            fwrite(
                STDERR,
                "[Meili PDF DEBUG] parsePdf failed: {$e->getMessage()}\n"
            );
            return '';
        }
    }

    private function readPdfMetaTitle(string $absolutePath): ?string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $details = $pdf->getDetails();

            foreach (['Title', 'title'] as $key) {
                if (!empty($details[$key]) && is_string($details[$key])) {
                    return trim($details[$key]);
                }
            }
        } catch (\Throwable $e) {
            fwrite(
                STDERR,
                "[Meili PDF DEBUG] readPdfMetaTitle failed: {$e->getMessage()}\n"
            );
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