<?php

namespace MummertMedia\ContaoMeilisearchBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

class MeilisearchFilesParseCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('meilisearch:files:parse')
            ->setDescription('Parse indexed files via Apache Tika and store extracted text')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum number of files to parse per run',
                20
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not send files to Tika, just show what would be parsed'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $this->log('Parser gestartet');

        $limit  = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $tikaUrl = rtrim((string) $GLOBALS['TL_CONFIG']['meilisearch_tika_url'], '/');
        if ($tikaUrl === '') {
            $output->writeln('<error>Tika URL not configured</error>');
            return Command::FAILURE;
        }

        $db = Database::getInstance();

        $files = $db
            ->prepare(
                "SELECT * FROM tl_search_files
                 ORDER BY tstamp ASC
                 LIMIT ?"
            )
            ->execute($limit)
            ->fetchAllAssoc();

        if (!$files) {
            $this->log('No files to parse');
            return Command::SUCCESS;
        }

        $client = HttpClient::create([
            'timeout' => 120,
        ]);

        foreach ($files as $file) {

            $absolutePath = TL_ROOT . '/' . ltrim($file['url'], '/');
            if (!is_file($absolutePath)) {
                $this->log('File missing, skip', ['url' => $file['url']]);
                continue;
            }

            $mtime    = filemtime($absolutePath) ?: 0;
            $checksum = md5($file['url'] . '|' . $mtime);

            if ($file['checksum'] === $checksum && !empty($file['text'])) {
                $this->log('Skip unchanged file', ['url' => $file['url']]);
                continue;
            }

            if ($dryRun) {
                $output->writeln('[DRY-RUN] Would parse: ' . $file['url']);
                continue;
            }

            try {
                $this->log('Parsing file', ['url' => $file['url']]);

                $response = $client->request(
                    'PUT',
                    $tikaUrl . '/tika',
                    [
                        'headers' => [
                            'Accept' => 'text/plain',
                        ],
                        'body' => fopen($absolutePath, 'rb'),
                    ]
                );

                $text = trim((string) $response->getContent(false));

                $db->prepare(
                    "UPDATE tl_search_files
                     SET text = ?, checksum = ?, file_mtime = ?, tstamp = ?
                     WHERE id = ?"
                )->execute(
                    $text,
                    $checksum,
                    $mtime,
                    time(),
                    $file['id']
                );

                $this->log('File parsed', [
                    'url'      => $file['url'],
                    'chars'    => strlen($text),
                ]);

            } catch (\Throwable $e) {
                $this->log('Parse failed', [
                    'url'   => $file['url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->log('Parser finished');
        return Command::SUCCESS;
    }

    private function log(string $message, array $context = []): void
    {
        $ctx = $context
            ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        error_log('[MeilisearchFilesParse] ' . $message . $ctx);
    }
}