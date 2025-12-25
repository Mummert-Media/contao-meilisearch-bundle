<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private string $projectDir;
    private bool $tableReset = false;

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim($params->get('kernel.project_dir'), '/');
    }

    /* =====================================================
     * Reset tl_search_pdf einmal pro Crawl
     * ===================================================== */
    public function resetTableOnce(): void
    {
        if ($this->tableReset) {
            return;
        }

        Database::getInstance()->execute('TRUNCATE TABLE tl_search_pdf');
        $this->tableReset = true;

        error_log('PDF Reset: tl_search_pdf geleert');
    }

    /* =====================================================
     * Einstiegspunkt aus Listener
     * ===================================================== */
    public function handlePdfLinks(array $pdfLinks): void
    {
        foreach ($pdfLinks as $url) {
            try {
                $normalizedPath = $this->normalizePdfUrl($url);
                if ($normalizedPath === null) {
                    continue;
                }

                $absolutePath = $this->getAbsolutePath($normalizedPath);
                if (!is_file($absolutePath)) {
                    continue;
                }

                $mtime = filemtime($absolutePath) ?: 0;
                $checksum = md5($normalizedPath . $mtime);

                if ($this->alreadyIndexed($checksum)) {
                    continue;
                }

                $text = $this->parsePdf($absolutePath);
                if ($text === '') {
                    continue;
                }

                $this->insertPdf(
                    $normalizedPath,
                    basename($absolutePath),
                    $text,
                    $checksum,
                    $mtime
                );

            } catch (\Throwable $e) {
                error_log('PDF Service Fehler: ' . $e->getMessage());
            }
        }
    }

    /* =====================================================
     * URL → relativer /files-Pfad
     * ===================================================== */
    private function normalizePdfUrl(string $url): ?string
    {
        if (str_starts_with($url, '/files/') && str_ends_with($url, '.pdf')) {
            return $url;
        }

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
     * Pfade
     * ===================================================== */
    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . ltrim($relativePath, '/');
    }

    /* =====================================================
     * DB
     * ===================================================== */
    private function alreadyIndexed(string $checksum): bool
    {
        $result = Database::getInstance()
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
     * PDF-Parsing
     * ===================================================== */
    private function parsePdf(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);

            $text = $pdf->getText();

            if (class_exists(\Normalizer::class)) {
                $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
            }

            $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}]/u', ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);

            return trim(mb_substr($text, 0, 5000));
        } catch (\Throwable) {
            return '';
        }
    }
}