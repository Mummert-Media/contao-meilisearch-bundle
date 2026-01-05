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
        $start = microtime(true);

        error_log('[MeilisearchCron] === START ===');

        // Contao initialisieren
        $this->framework->initialize();
        error_log('[MeilisearchCron] Contao framework initialized');

        // 1) Contao Crawl
        $this->runConsole(
            'contao:crawl',
            'Contao crawl'
        );

        // 2) Cleanup (24h Grace)
        $this->runConsole(
            'meilisearch:files:cleanup',
            'Meilisearch files cleanup'
        );

        // 3) Meilisearch Index
        try {
            error_log('[MeilisearchCron] Meilisearch index started');
            $this->indexService->run();
            error_log('[MeilisearchCron] Meilisearch index finished');
        } catch (\Throwable $e) {
            error_log('[MeilisearchCron] ERROR during Meilisearch index: ' . $e->getMessage());
        }

        $duration = round(microtime(true) - $start, 2);
        error_log('[MeilisearchCron] === END (duration: ' . $duration . 's) ===');
    }

    private function runConsole(string $command, string $label): void
    {
        error_log('[MeilisearchCron] ' . $label . ' started');

        $process = new Process([
            PHP_BINARY,
            $this->projectDir . '/vendor/bin/contao-console',
            ...explode(' ', $command),
        ]);

        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            error_log(
                '[MeilisearchCron] ERROR in ' . $label . ': ' .
                $process->getErrorOutput()
            );
        } else {
            $output = trim($process->getOutput());
            if ($output !== '') {
                error_log('[MeilisearchCron] ' . $label . ' output: ' . $output);
            }
            error_log('[MeilisearchCron] ' . $label . ' finished');
        }
    }
}