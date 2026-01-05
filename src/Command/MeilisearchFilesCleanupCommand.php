<?php

namespace MummertMedia\ContaoMeilisearchBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MeilisearchFilesCleanupCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('meilisearch:files:cleanup')
            ->setDescription('Remove stale indexed files (PDF, DOCX, XLSX, PPTX) from tl_search_pdf')
            ->addOption(
                'grace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Grace period in seconds (files newer than now-grace are kept)',
                3600
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show how many entries would be removed without deleting them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // wichtig für Contao 4.13 & 5.x
        $this->framework->initialize();

        $grace  = max(0, (int) $input->getOption('grace'));
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = time() - $grace;

        if ($dryRun) {
            $count = Database::getInstance()
                ->prepare('SELECT COUNT(*) AS cnt FROM tl_search_pdf WHERE last_seen < ?')
                ->execute($cutoff)
                ->cnt;

            $output->writeln(sprintf(
                '<comment>[DRY-RUN]</comment> %d stale file(s) would be removed (last_seen < %s)',
                $count,
                date('Y-m-d H:i:s', $cutoff)
            ));

            return Command::SUCCESS;
        }

        $affected = Database::getInstance()
            ->prepare('DELETE FROM tl_search_pdf WHERE last_seen < ?')
            ->execute($cutoff)
            ->affectedRows;

        $output->writeln(sprintf(
            '<info>Removed %d stale file(s)</info> (last_seen < %s)',
            $affected,
            date('Y-m-d H:i:s', $cutoff)
        ));

        return Command::SUCCESS;
    }
}