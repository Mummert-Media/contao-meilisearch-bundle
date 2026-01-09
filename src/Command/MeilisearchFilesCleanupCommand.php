<?php

namespace MummertMedia\ContaoMeilisearchBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MeilisearchFilesCleanupCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('meilisearch:files:cleanup')
            ->setDescription('Remove stale indexed files from tl_search_files')
            ->addOption(
                'grace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Grace period in seconds (files newer than now-grace are kept)',
                86400
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
        $this->framework->initialize();

        $this->log('Cleaner gestartet');

        try {
            $grace  = max(0, (int) $input->getOption('grace'));
            $dryRun = (bool) $input->getOption('dry-run');
            $cutoff = time() - $grace;

            if ($dryRun) {
                $count = $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_search_files WHERE last_seen < ?',
                    [$cutoff]
                );

                $message = sprintf(
                    '[DRY-RUN] %d stale file(s) would be removed (last_seen < %s)',
                    $count,
                    date('Y-m-d H:i:s', $cutoff)
                );

                $output->writeln('<comment>' . $message . '</comment>');
                $this->log($message);

                $this->log('Cleaner stopped (dry-run)');
                return Command::SUCCESS;
            }

            $affected = $this->connection->executeStatement(
                'DELETE FROM tl_search_files WHERE last_seen < ?',
                [$cutoff]
            );

            $message = sprintf(
                'Removed %d stale file(s) (last_seen < %s)',
                $affected,
                date('Y-m-d H:i:s', $cutoff)
            );

            $output->writeln('<info>' . $message . '</info>');
            $this->log($message);

            $this->log('Cleaner successfully stopped');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->log('Cleaner ERROR: ' . $e->getMessage());
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    private function log(string $message): void
    {
        error_log(sprintf('[%s] %s', date('Y-m-d H:i:s'), $message));
    }
}