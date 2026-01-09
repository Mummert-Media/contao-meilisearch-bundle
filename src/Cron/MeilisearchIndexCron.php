<?php

namespace MummertMedia\ContaoMeilisearchBundle\Cron;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Process\Process;

class MeilisearchIndexCron
{
    private string $logFile;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
        $this->logFile = $this->projectDir . '/var/logs/meilisearch_bundle.log';
    }

    public function __invoke(): void
    {
        $this->framework->initialize();

        $this->log('=== CRON START ===');

        // 1) Cleanup
        $this->runStep(
            'meilisearch:files:cleanup',
            'meilisearch:files:cleanup'
        );

        // 2) Contao Crawl
        $this->runStep(
            'contao:crawl',
            'contao:crawl'
        );

        // 3) Meilisearch Index
        $this->runStep(
            'meilisearch:index',
            'meilisearch:index'
        );

        $this->log('=== CRON END ===');
    }

    /**
     * Führt einen Console-Command aus und loggt sauber Start/Ende
     */
    private function runStep(string $command, string $label): void
    {
        $this->log($label . ' gestartet');

        $process = new Process([
            PHP_BINARY,
            $this->projectDir . '/vendor/bin/contao-console',
            ...explode(' ', $command),
        ]);

        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log(
                $label . ' FEHLGESCHLAGEN',
                $process->getErrorOutput() ?: 'Unbekannter Fehler'
            );

            // ❌ Abbruch – Folgeschritte NICHT ausführen
            return;
        }

        $this->log($label . ' erfolgreich beendet');
    }

    /**
     * Schreibt eine Logzeile mit Zeitstempel
     */
    private function log(string $message, string $details = ''): void
    {
        $line = sprintf(
            "[%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $message,
            $details ? ' | ' . trim($details) : ''
        );

        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}