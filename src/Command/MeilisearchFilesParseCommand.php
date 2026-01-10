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
            ->setDescription('Parse indexed files via Apache Tika and enrich tl_search_files')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum number of files to check per run'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not send files to Tika'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();
        $this->log('Parser gestartet');

        $dryRun = (bool) $input->getOption('dry-run');

        $limitOption = $input->getOption('limit');
        $limit = $limitOption !== null ? max(1, (int) $limitOption) : null;

        $tikaUrl = rtrim((string) ($GLOBALS['TL_CONFIG']['meilisearch_tika_url'] ?? ''), '/');
        if ($tikaUrl === '') {
            $output->writeln('<error>Tika URL not configured</error>');
            return Command::FAILURE;
        }

        $db = Database::getInstance();

        $sql = "SELECT * FROM tl_search_files ORDER BY tstamp ASC";
        if ($limit !== null) {
            $sql .= " LIMIT " . (int) $limit;
        }

        $files = $db->query($sql)->fetchAllAssoc();

        if (!$files) {
            $this->log('No files to parse');
            return Command::SUCCESS;
        }

        $client = HttpClient::create([
            'timeout' => 180,
        ]);

        foreach ($files as $file) {

            $originalUrl   = (string) $file['url'];
            $existingTitle = trim((string) ($file['title'] ?? ''));
            $normalized    = $originalUrl;

            // -------------------------------------------------
            // Normalize URL
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

            $normalized = strtok($normalized, '#');
            $normalized = rawurldecode($normalized);
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
            // Skip unchanged
            // -------------------------------------------------
            if ($file['checksum'] === $checksum && !empty($file['text'])) {
                continue;
            }

            if ($dryRun) {
                $output->writeln('[DRY-RUN] Would parse: ' . $normalized);
                continue;
            }

            // -------------------------------------------------
            // MIME-Type
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
            // Tika BODY (roher Plaintext)
            // -------------------------------------------------
            try {
                $this->log('Parsing file', ['url' => $normalized]);

                $bodyResponse = $client->request(
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

                $text = trim((string) $bodyResponse->getContent(false));

            } catch (\Throwable $e) {
                $this->log('Body parse failed', [
                    'url'   => $normalized,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // -------------------------------------------------
            // TITLE: keep existing editor-defined title
            // -------------------------------------------------
            $title = $existingTitle !== '' ? $existingTitle : null;

            // -------------------------------------------------
            // Tika METADATA (Title) – only if no existing title
            // -------------------------------------------------
            if ($title === null) {
                try {
                    $metaResponse = $client->request(
                        'PUT',
                        $tikaUrl . '/meta',
                        [
                            'headers' => [
                                'Accept'       => 'application/json',
                                'Content-Type' => $mimeType,
                            ],
                            'body' => fopen($absolutePath, 'rb'),
                        ]
                    );

                    $meta = json_decode($metaResponse->getContent(false), true);

                    $rawTitle =
                        $meta['dc:title'][0]
                        ?? $meta['pdf:docinfo:title'][0]
                        ?? null;

                    if ($rawTitle) {
                        $title = html_entity_decode(
                            $rawTitle,
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        );
                    }

                } catch (\Throwable) {
                    // Metadata optional
                }
            }

            // -------------------------------------------------
            // TITLE → ASCII SAFE (only if newly generated)
            // -------------------------------------------------
            if ($existingTitle === '' && $title) {
                $title = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
                $title = preg_replace('/\s+/', ' ', $title);
                $title = trim($title);
            }

            // -------------------------------------------------
            // FALLBACK: Dateiname (only if still empty)
            // -------------------------------------------------
            if (!$title || strlen($title) < 5) {
                $title = pathinfo($normalized, PATHINFO_FILENAME);
                $title = str_replace(['_', '-'], ' ', $title);
                $title = preg_replace('/\s+/', ' ', $title);
                $title = trim($title);
            }

            // -------------------------------------------------
            // Store result
            // -------------------------------------------------
            $db->prepare(
                "UPDATE tl_search_files
                 SET text = ?, title = ?, checksum = ?, file_mtime = ?, tstamp = ?
                 WHERE id = ?"
            )->execute(
                $text,
                $title,
                $checksum,
                $mtime,
                time(),
                $file['id']
            );

            $this->log('File parsed', [
                'url'   => $normalized,
                'chars' => mb_strlen($text),
                'title' => $title,
            ]);
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