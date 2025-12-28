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
        $this->debug("projectDir={$this->projectDir}");
    }

    private function debug(string $message): void
    {
        $stream = \defined('STDERR')
            ? STDERR
            : fopen('php://stderr', 'wb');

        fwrite($stream, "[Meili PDF DEBUG] {$message}\n");
    }

    public function resetTableOnce(): void
    {
        if ($this->didReset) {
            $this->debug('resetTableOnce(): already reset');
            return;
        }

        $this->debug('resetTableOnce(): TRUNCATE tl_search_pdf');

        $this->didReset = true;
        $this->seenThisCrawl = [];

        try {
            Database::getInstance()->execute('TRUNCATE tl_search_pdf');
        } catch (\Throwable $e) {
            $this->debug('TRUNCATE failed: ' . $e->getMessage());
        }
    }

    public function handlePdfLinks(array $pdfLinks): void
    {
        $this->debug('handlePdfLinks(): count=' . count($pdfLinks));

        foreach ($pdfLinks as $row) {
            $url = (string) ($row['url'] ?? '');
            $linkText = $row['linkText'] ?? null;

            $this->debug("URL={$url}");

            if ($url === '') {
                $this->debug('→ empty URL, skip');
                continue;
            }

            $seenKey = md5($url);
            if (isset($this->seenThisCrawl[$seenKey])) {
                $this->debug('→ already processed, skip');
                continue;
            }
            $this->seenThisCrawl[$seenKey] = true;

            $normalizedPath = $this->normalizePdfUrl($url);
            $this->debug('normalizePdfUrl() → ' . ($normalizedPath ?? 'NULL'));

            if ($normalizedPath === null) {
                $this->debug('→ normalization failed, skip');
                continue;
            }

            $absolutePath = $this->getAbsolutePath($normalizedPath);
            $this->debug("absolutePath={$absolutePath}");

            if (!is_file($absolutePath)) {
                $this->debug('→ file does NOT exist');
                continue;
            }

            $this->debug('→ file exists');

            $mtime = (int) (filemtime($absolutePath) ?: 0);
            $checksum = md5($normalizedPath . '|' . $mtime);

            $this->debug("mtime={$mtime} checksum={$checksum}");

            $pdfMetaTitle = $this->readPdfMetaTitle($absolutePath);
            $this->debug('metaTitle=' . ($pdfMetaTitle ?: 'NULL'));

            $title = $linkText ?: ($pdfMetaTitle ?: basename($absolutePath));
            $this->debug("final title={$title}");

            $text = $this->parsePdf($absolutePath);
            $this->debug('parsed text length=' . strlen($text));

            if ($text === '') {
                $this->debug('→ empty text, skip');
                continue;
            }

            $this->debug('→ writing to DB');

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
        $this->debug("normalizePdfUrl(): {$url}");

        $decoded = html_entity_decode($url);
        $parts = parse_url($decoded);

        if (!empty($parts['path']) && str_starts_with($parts['path'], 'files/') && str_ends_with(strtolower($parts['path']), '.pdf')) {
            $r = '/' . $parts['path'];
            $this->debug("→ relative files path {$r}");
            return $r;
        }

        if (!empty($parts['path']) && str_starts_with($parts['path'], '/files/') && str_ends_with(strtolower($parts['path']), '.pdf')) {
            $this->debug("→ absolute files path {$parts['path']}");
            return $parts['path'];
        }

        if (empty($parts['query'])) {
            $this->debug('→ no query');
            return null;
        }

        parse_str($parts['query'], $query);

        if (!empty($query['file'])) {
            $file = urldecode((string) $query['file']);
            $file = ltrim($file, '/');

            if (str_starts_with($file, 'files/') && str_ends_with(strtolower($file), '.pdf')) {
                $r = '/' . $file;
                $this->debug("→ file= normalized {$r}");
                return $r;
            }
        }

        if (!empty($query['p'])) {
            $p = urldecode((string) $query['p']);
            $r = '/files/' . ltrim($p, '/');
            $this->debug("→ p= normalized {$r}");
            return $r;
        }

        $this->debug('→ no usable parameter');
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

            $this->debug('→ DB write OK');
        } catch (\Throwable $e) {
            $this->debug('DB write failed: ' . $e->getMessage());
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
            $this->debug('parsePdf failed: ' . $e->getMessage());
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
            $this->debug('readPdfMetaTitle failed: ' . $e->getMessage());
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