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
        $this->out('=== Meilisearch Cron START ===');

        // Contao initialisieren
        $this->framework->initialize();
        $this->out('Contao framework initialized');

        // 1) Contao Crawl
        $this->out('--- Step 1: contao:crawl ---');
        $this->runConsole('contao:crawl');

        // 2) Cleanup
        $this->out('--- Step 2: meilisearch:files:cleanup ---');
        $this->runConsole('meilisearch:files:cleanup --grace=86400');

        // 3) Meilisearch Index
        $this->out('--- Step 3: meilisearch:index (service) ---');
        $this->indexService->run();
        $this->out('Meilisearch index finished');

        $this->out('=== Meilisearch Cron END ===');
    }

    private function runConsole(string $command): void
    {
        $start = microtime(true);

        $process = new Process([
            'php',
            $this->projectDir . '/vendor/bin/contao-console',
            ...explode(' ', $command),
        ]);

        $process->setTimeout(null);

        // LIVE-Ausgabe an Konsole durchreichen
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        $duration = round(microtime(true) - $start, 2);

        $this->out(sprintf(
            'Command "%s" finished (exit=%d, time=%ss)',
            $command,
            $process->getExitCode(),
            $duration
        ));

        if (!$process->isSuccessful()) {
            $this->out('ERROR OUTPUT:');
            echo $process->getErrorOutput();
        }
    }

    private function out(string $message): void
    {
        $line = '[MeilisearchCron] ' . $message;

        // Konsole
        echo $line . PHP_EOL;

        // Log (für Cron/Hosting)
        error_log($line);
    }
}