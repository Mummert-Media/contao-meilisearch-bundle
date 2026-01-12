<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

class MeilisearchFileHelper
{
    public function __construct()
    {
    }

    /**
     * Minimal-Methode zum Testen des Aufrufs aus dem IndexPageListener
     */
    public function collect(string $url, string $type, int $pageId): void
    {
        error_log('[ContaoMeilisearch][MeilisearchFileHelper] collect() called | ' . json_encode([
                'url'    => $url,
                'type'   => $type,
                'pageId' => $pageId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}