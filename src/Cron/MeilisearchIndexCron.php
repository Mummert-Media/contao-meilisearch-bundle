<?php

namespace MummertMedia\ContaoMeilisearchBundle\Cron;

use Contao\CoreBundle\Framework\ContaoFramework;
use MummertMedia\ContaoMeilisearchBundle\Service\MeilisearchIndexService;
use Symfony\Component\Process\Process;

class MeilisearchIndexCron
{
    public function __construct(
        private readonly MeilisearchIndexService $indexService,
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {}

    public function __invoke(): void
    {
        // Contao initialisieren
        $this->framework->initialize();

        // 1) Contao Crawl
        $this->runConsole('contao:crawl');

        // 2) Cleanup (24h Grace)
        $this->runConsole('meilisearch:files:cleanup');

        // 3) Meilisearch Index
        $this->indexService->run();
    }

    private function runConsole(string $command): void
    {
        $process = new Process([
            'php',
            $this->projectDir . '/vendor/bin/contao-console',
            ...explode(' ', $command),
        ]);

        $process->setTimeout(null);
        $process->run();
    }
}