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
        $output->writeln('<info>Meilisearch index started</info>');

        $this->indexService->run();

        $output->writeln('<info>Meilisearch index finished</info>');

        return Command::SUCCESS;
    }
}