<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use MummertMedia\ContaoMeilisearchBundle\Service\PdfIndexService;

class PdfCleanupListener
{
    public function __construct(
        private PdfIndexService $pdfIndexService
    ) {}

    public function onLastChunk(): void
    {
        error_log('Crawler beendet → PDF Cleanup startet');
        $this->pdfIndexService->cleanupRemovedPdfs();
    }
}