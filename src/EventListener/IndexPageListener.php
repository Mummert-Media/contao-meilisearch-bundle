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

    /* =====================================================
     * JSON-Parser
     * ===================================================== */

    private function extractMeilisearchJson(string $content): ?array
    {
        if (!preg_match('/<!--\s*MEILISEARCH_JSON\s*(\{.*?\})\s*-->/s', $content, $m)) {
            return null;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($m[1]));
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /* =====================================================
     * PDF-Link-Erkennung
     * ===================================================== */

    private function indexPdfLinks(string $content): void
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $content,
            $matches
        )) {
            return;
        }

        foreach ($matches[1] as $i => $href) {
            $title = trim(strip_tags($matches[2][$i])) ?: 'PDF';

            $pdfUrl = $this->resolvePdfUrl($href);
            if ($pdfUrl === null) {
                continue;
            }

            $this->indexSinglePdf($pdfUrl, $title);
        }
    }

    /**
     * Erkennt
     *  - direkte PDF-Links (/files/...pdf)
     *  - Contao-Download-Links (?p=...pdf)
     */
    private function resolvePdfUrl(string $href): ?string
    {
        $href = html_entity_decode($href);

        // 1) Direkter PDF-Link
        $path = parse_url($href, PHP_URL_PATH);
        if ($path && str_ends_with(strtolower($path), '.pdf')) {
            return $this->normalizeUrl($href);
        }

        // 2) Contao-Download-Link (?p=...pdf)
        $query = parse_url($href, PHP_URL_QUERY);
        if (!$query) {
            return null;
        }

        parse_str($query, $params);

        if (
            empty($params['p']) ||
            !str_ends_with(strtolower($params['p']), '.pdf')
        ) {
            return null;
        }

        return $this->normalizeUrl('/files/' . ltrim($params['p'], '/'));
    }

    private function normalizeUrl(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return
            ($_SERVER['REQUEST_SCHEME'] ?? 'https')
            . '://' . ($_SERVER['HTTP_HOST'] ?? '')
            . '/' . ltrim($url, '/');
    }

    /* =====================================================
     * PDF-Indexierung
     * ===================================================== */

    private function indexSinglePdf(string $url, string $title): void
    {
        $checksum = md5($url);
        $db = Database::getInstance();

        // schon indexiert?
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

            // bewusst begrenzen (Performance + Relevanz)
            return mb_substr($text, 0, 5000);

        } catch (\Throwable) {
            return '';
        }
    }

    private function cleanPdfContent(string $content): string
    {
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $content);
        $content = preg_replace('/\s+/u', ' ', $content);

        return trim($content);
    }
}