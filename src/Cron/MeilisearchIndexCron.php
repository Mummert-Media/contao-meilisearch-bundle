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
        $this->log('Cron START');

        // Contao initialisieren
        $this->framework->initialize();
        $this->log('Contao framework initialized');

        // 1) Contao Crawl
        $this->log('Step 1 START: contao:crawl');
        $this->runConsole('contao:crawl');
        $this->log('Step 1 END: contao:crawl');

        // 2) Cleanup (24h Grace)
        $this->log('Step 2 START: meilisearch:files:cleanup');
        $this->runConsole('meilisearch:files:cleanup --grace=86400');
        $this->log('Step 2 END: meilisearch:files:cleanup');

        // 3) Meilisearch Index
        $this->log('Step 3 START: MeilisearchIndexService::run()');
        $this->indexService->run();
        $this->log('Step 3 END: MeilisearchIndexService::run()');

        $this->log('Cron END');
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
        $process->run();

        $duration = round(microtime(true) - $start, 2);

        $this->log(sprintf(
            'Command "%s" finished (exit=%d, time=%ss)',
            $command,
            $process->getExitCode(),
            $duration
        ));

        if (!$process->isSuccessful()) {
            $this->log('STDERR: ' . trim($process->getErrorOutput()));
        } else {
            $out = trim($process->getOutput());
            if ($out !== '') {
                $this->log('STDOUT: ' . $out);
            }
        }
    }

    private function log(string $message): void
    {
        error_log('[MeilisearchCron] ' . $message);
    }
}