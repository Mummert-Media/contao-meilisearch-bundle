<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Database;
use Contao\StringUtil;
use Smalot\PdfParser\Parser;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            return;
        }

        // JSON aus Kommentar extrahieren
        $parsed = $this->extractMeilisearchJson($content);
        if ($parsed === null) {
            return;
        }

        /*
         * =====================
         * PRIORITY
         * =====================
         */
        $priority =
            $parsed['event']['priority'] ?? null ??
            $parsed['news']['priority']  ?? null ??
            $parsed['page']['priority']  ?? null;

        if ($priority !== null && $priority !== '') {
            $set['priority'] = (int) $priority;
        }

        /*
         * =====================
         * KEYWORDS
         * =====================
         */
        $keywordSources = [
            $parsed['event']['keywords'] ?? null,
            $parsed['news']['keywords']  ?? null,
            $parsed['page']['keywords']  ?? null,
        ];

        $keywords = [];
        foreach ($keywordSources as $src) {
            if (!is_string($src) || trim($src) === '') {
                continue;
            }

            foreach (preg_split('/\s+/', trim($src)) as $word) {
                if ($word !== '') {
                    $keywords[] = $word;
                }
            }
        }

        if ($keywords) {
            $set['keywords'] = implode(' ', array_unique($keywords));
        }

        /*
         * =====================
         * IMAGEPATH
         * =====================
         */
        $image =
            $parsed['event']['searchimage']  ?? null ??
            $parsed['news']['searchimage']   ?? null ??
            $parsed['page']['searchimage']   ?? null ??
            $parsed['custom']['searchimage'] ?? null;

        if (is_string($image) && $image !== '') {
            $set['imagepath'] = trim($image);
        }

        /*
         * =====================
         * STARTDATE
         * =====================
         */
        $date =
            $parsed['event']['date'] ?? null ??
            $parsed['news']['date']  ?? null;

        if (is_string($date) && $date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) {
                $set['startDate'] = $ts;
            }
        }

        /*
         * =====================
         * PDFS ALS EIGENE DOKUMENTE INDEXIEREN
         * =====================
         */
        $this->indexPdfLinks($content);
    }

    /**
     * -------------------------------------
     * JSON aus Kommentar extrahieren
     * -------------------------------------
     */
    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($m[1]));
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * -------------------------------------
     * PDF-Links finden und indexieren
     * -------------------------------------
     */
    private function indexPdfLinks(string $content): void
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']+\.pdf[^"\']*)["\'][^>]*>(.*?)<\/a>/is',
            $content,
            $matches
        )) {
            return;
        }

        foreach ($matches[1] as $i => $url) {
            $title = trim(strip_tags($matches[2][$i])) ?: basename($url);
            $this->indexSinglePdf($url, $title);
        }
    }

    /**
     * -------------------------------------
     * Einzelnes PDF indexieren
     * -------------------------------------
     */
    private function indexSinglePdf(string $url, string $title): void
    {
        // nur interne PDFs
        if (!str_contains($url, '/files/')) {
            return;
        }

        // relative URLs normalisieren
        if (str_starts_with($url, '/')) {
            $url =
                ($_SERVER['REQUEST_SCHEME'] ?? 'https')
                . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                . $url;
        }

        $checksum = md5($url);
        $db = Database::getInstance();

        // bereits indexiert?
        $exists = $db
            ->prepare('SELECT id FROM tl_search WHERE checksum=?')
            ->execute($checksum);

        if ($exists->numRows > 0) {
            return;
        }

        $text = $this->parsePdf($url);
        if ($text === '') {
            return;
        }

        $db
            ->prepare('
                INSERT INTO tl_search
                    (tstamp, title, url, text, checksum, protected, pid, type)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
            ')
            ->execute(
                time(),
                StringUtil::decodeEntities($title),
                $url,
                $text,
                $checksum,
                '',
                0,
                'file'
            );
    }

    /**
     * -------------------------------------
     * PDF parsen (Smalot)
     * -------------------------------------
     */
    private function parsePdf(string $url): string
    {
        try {
            $content = @file_get_contents($url);
            if (!$content) {
                return '';
            }

            $parser = new Parser();
            $pdf = $parser->parseContent($content);

            $text = $this->cleanPdfContent($pdf->getText());

            // Begrenzen (Performance + Relevanz)
            return mb_substr($text, 0, 5000);

        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * -------------------------------------
     * PDF-Text bereinigen
     * -------------------------------------
     */
    private function cleanPdfContent(string $content): string
    {
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $content);
        $content = preg_replace('/\s+/u', ' ', $content);

        return trim($content);
    }
}