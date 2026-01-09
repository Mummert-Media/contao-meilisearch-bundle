<?php

namespace MummertMedia\ContaoMeilisearchBundle\Command;

use MummertMedia\ContaoMeilisearchBundle\Service\MeilisearchIndexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MeilisearchIndexCommand extends Command
{
    public function __construct(
        private readonly MeilisearchIndexService $indexService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('meilisearch:index')
            ->setDescription('Rebuild Meilisearch index from Contao search tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->log('Meilisearch index gestartet');
        $output->writeln('<info>Meilisearch index started</info>');

        try {
            $this->indexService->run();

            $this->log('Meilisearch index successfully stopped');
            $output->writeln('<info>Meilisearch index finished</info>');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->log('Meilisearch index ERROR: ' . $e->getMessage());
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    /**
     * Einheitliches Logging mit Zeitstempel
     */
    private function log(string $message): void
    {
        error_log(sprintf(
            '[%s] %s',
            date('Y-m-d H:i:s'),
            $message
        ));
    }
}