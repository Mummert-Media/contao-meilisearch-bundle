<?php
namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Psr\Log\LoggerInterface;

class MeilisearchIndexService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        // hier später:
        // - Seiten / Events / News laden
        // - normalisieren
        // - an Meilisearch senden

        $this->logger->info('Meilisearch indexing started');

        // TODO: Indexierung

        $this->logger->info('Meilisearch indexing finished');
    }
}
