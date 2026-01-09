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
            ->query(
                "SELECT *
                 FROM tl_search_files
                 ORDER BY tstamp ASC
                 LIMIT " . (int) $limit
            )
            ->fetchAllAssoc();

        if (!$files) {
            $this->log('No files to parse');
            return Command::SUCCESS;
        }

        $client = HttpClient::create([
            'timeout' => 120,
        ]);

        foreach ($files as $file) {

            $originalUrl = (string) $file['url'];
            $normalized  = $originalUrl;

            // -------------------------------------------------
            // 1) Query-URL behandeln (?file=files/...)
            // -------------------------------------------------
            if (str_contains($normalized, '?')) {
                $parts = parse_url($normalized);
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $query);
                    if (!empty($query['file'])) {
                        $normalized = (string) $query['file'];
                    } else {
                        $this->log('Not a direct file url, skip', ['url' => $originalUrl]);
                        continue;
                    }
                }
            }

            // -------------------------------------------------
            // 2) Fragment entfernen (#...)
            // -------------------------------------------------
            $normalized = strtok($normalized, '#');

            // -------------------------------------------------
            // 3) URL-Decoding
            // -------------------------------------------------
            $normalized = rawurldecode($normalized);

            // -------------------------------------------------
            // 4) Nur lokale files/… zulassen
            // -------------------------------------------------
            $normalized = ltrim($normalized, '/');
            if (!str_starts_with($normalized, 'files/')) {
                $this->log('Not in files/, skip', ['url' => $originalUrl]);
                continue;
            }

            $absolutePath = TL_ROOT . '/' . $normalized;

            if (!is_file($absolutePath)) {
                $this->log('File missing, skip', [
                    'url'  => $originalUrl,
                    'path' => $absolutePath,
                ]);
                continue;
            }

            $mtime    = filemtime($absolutePath) ?: 0;
            $checksum = md5($normalized . '|' . $mtime);

            // -------------------------------------------------
            // 5) Unveränderte Dateien überspringen
            // -------------------------------------------------
            if ($file['checksum'] === $checksum && !empty($file['text'])) {
                $this->log('Skip unchanged file', ['url' => $normalized]);
                continue;
            }

            if ($dryRun) {
                $output->writeln('[DRY-RUN] Would parse: ' . $normalized);
                continue;
            }

            // -------------------------------------------------
            // 6) Content-Type anhand Dateiendung
            // -------------------------------------------------
            $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

            $mimeType = match ($ext) {
                'pdf'  => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                default => null,
            };

            if ($mimeType === null) {
                $this->log('Unsupported file type, skip', ['url' => $normalized]);
                continue;
            }

            // -------------------------------------------------
            // 7) Tika-Parsing
            // -------------------------------------------------
            try {
                $this->log('Parsing file', ['url' => $normalized]);

                $response = $client->request(
                    'PUT',
                    $tikaUrl . '/tika/main',
                    [
                        'headers' => [
                            'Accept'       => 'text/plain',
                            'Content-Type' => $mimeType,
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
                    'url'   => $normalized,
                    'chars' => mb_strlen($text),
                ]);

            } catch (\Throwable $e) {
                $this->log('Parse failed', [
                    'url'   => $normalized,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->log('Parser finished');
        return Command::SUCCESS;
    }

    /**
     * Einheitliches Logging
     */
    private function log(string $message, array $context = []): void
    {
        $ctx = $context
            ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        error_log('[MeilisearchFilesParse] ' . $message . $ctx);
    }
}