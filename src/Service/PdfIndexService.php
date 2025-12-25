<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfIndexService
{
    private bool $tableReset = false;
    private string $projectDir;

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = rtrim($params->get('kernel.project_dir'), '/');
    }

    /**
     * 🔥 Wird bei JEDEM Crawl einmal aufgerufen
     */
    public function resetTableOnce(): void
    {
        if ($this->tableReset) {
            return;
        }

        Database::getInstance()->execute('TRUNCATE TABLE tl_search_pdf');
        error_log('tl_search_pdf wurde geleert');

        $this->tableReset = true;
    }

    /**
     * Einstiegspunkt vom Listener
     */
    public function handlePdfLinks(array $pdfLinks): void
    {
        foreach ($pdfLinks as $url) {
            try {
                $path = $this->normalizePdfUrl($url);
                if ($path === null) {
                    continue;
                }

                $absolutePath = $this->projectDir . '/' . ltrim($path, '/');
                if (!is_file($absolutePath)) {
                    continue;
                }

                $parser = new Parser();
                $pdf = $parser->parseFile($absolutePath);
                $text = $this->cleanPdfContent($pdf->getText());

                if ($text === '') {
                    continue;
                }

                Database::getInstance()
                    ->prepare('
                        INSERT INTO tl_search_pdf
                            (tstamp, url, title, text, checksum, file_mtime)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ')
                    ->execute(
                        time(),
                        $path,
                        basename($absolutePath),
                        mb_substr($text, 0, 5000),
                        md5($path),
                        filemtime($absolutePath) ?: 0
                    );

            } catch (\Throwable $e) {
                error_log('PDF Fehler: ' . $e->getMessage());
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

        $parts = parse_url(html_entity_decode($url));
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
     * Textbereinigung
     * ===================================================== */
    private function cleanPdfContent(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}\n]/u', ' ', $text);
        $text = preg_replace('/(?<=\p{L})\s+(?=\p{L})/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}