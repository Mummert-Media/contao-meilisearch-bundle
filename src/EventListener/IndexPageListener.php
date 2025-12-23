<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Smalot\PdfParser\Parser;

class IndexPageListener
{
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // Marker vorhanden?
        if (!str_contains($content, 'MEILISEARCH_JSON')) {
            return;
        }

        // JSON aus Kommentar extrahieren + parsen
        $parsed = $this->extractMeilisearchJson($content);

        if ($parsed === null) {
            return;
        }

        /*
         * =====================
         * PRIORITY (event > news > page)
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
         * KEYWORDS (merge)
         * =====================
         */
        $keywordSources = [
            $parsed['event']['keywords'] ?? null,
            $parsed['news']['keywords']  ?? null,
            $parsed['page']['keywords']  ?? null,
        ];

        $kw = [];
        foreach ($keywordSources as $s) {
            if (!is_string($s) || trim($s) === '') {
                continue;
            }

            foreach (preg_split('/\s+/', trim($s)) ?: [] as $p) {
                if ($p !== '') {
                    $kw[] = $p;
                }
            }
        }

        if ($kw) {
            $set['keywords'] = implode(' ', array_unique($kw));
        }

        /*
         * =====================
         * IMAGEPATH (event > news > page > custom)
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
         * STARTDATE (event/news)
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
         * PDF LINKS INDEXIEREN
         * =====================
         */
        $pdfText = $this->extractPdfTextFromContent($content);

        if ($pdfText !== '') {
            $set['text'] = trim(
                ($set['text'] ?? '') . "\n\n" . $pdfText
            );
        }
    }

    /**
     * Extrahiert das JSON aus <!-- MEILISEARCH_JSON {...} -->
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
     * Findet PDF-Links im HTML und extrahiert deren Text
     */
    private function extractPdfTextFromContent(string $content): string
    {
        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']+\.pdf[^"\']*)["\'][^>]*>/i',
            $content,
            $matches
        )) {
            return '';
        }

        $texts = [];

        foreach ($matches[1] as $url) {
            $pdfText = $this->parsePdfFromUrl($url);

            if ($pdfText !== '') {
                $texts[] = $pdfText;
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Lädt und parsed ein PDF (nur /files/)
     */
    private function parsePdfFromUrl(string $url): string
    {
        // Nur interne PDFs
        if (!str_contains($url, '/files/')) {
            return '';
        }

        // relative URLs normalisieren
        if (str_starts_with($url, '/')) {
            $url =
                ($_SERVER['REQUEST_SCHEME'] ?? 'https')
                . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                . $url;
        }

        try {
            $pdfContent = @file_get_contents($url);
            if (!$pdfContent) {
                return '';
            }

            $parser = new Parser();
            $pdf = $parser->parseContent($pdfContent);

            $text = $this->cleanPdfContent($pdf->getText());

            // Begrenzung für Meilisearch
            return mb_substr($text, 0, 2000);

        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Bereinigt PDF-Text
     */
    private function cleanPdfContent(string $content): string
    {
        // UTF-8 normalisieren
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        // Steuerzeichen entfernen
        $content = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $content);

        // Whitespaces normalisieren
        $content = preg_replace('/\s+/u', ' ', $content);

        return trim($content);
    }
}