<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;

class MeilisearchIndexService
{
    private Client $client;
    private string $indexName;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
    ) {}

    /**
     * Entry point for command & cron
     */
    public function run(): void
    {
        // Contao vollständig initialisieren (CLI & Cron!)
        $this->framework->initialize();

        $host = (string) Config::get('meilisearch_host');
        $apiKey = (string) Config::get('meilisearch_api_write');
        $this->indexName = (string) Config::get('meilisearch_index');

        if ($host === '' || $this->indexName === '') {
            throw new \RuntimeException('Meilisearch is not configured in tl_settings.');
        }

        $this->client = new Client($host, $apiKey);
        $index = $this->client->index($this->indexName);

        // 🔑 PRIMARY KEY EINMALIG FESTLEGEN
        try {
            $index->updateSettings([
                'primaryKey' => 'id',
            ]);
        } catch (\Throwable) {
            // bewusst ignorieren (Index existiert evtl. noch nicht oder Key ist bereits gesetzt)
        }

        // 1. kompletten Index löschen (Settings bleiben erhalten!)
        $index->deleteAllDocuments();

        // 2. tl_search indexieren
        $this->indexTlSearch($index);

        // 3. tl_search_pdf indexieren
        $this->indexTlSearchPdf($index);
    }

    private function indexTlSearch($index): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_search');
        if (!$rows) {
            return;
        }

        $documents = [];

        foreach ($rows as $row) {
            $type = $this->detectTypeFromMeta($row['meta'] ?? null);

            $documents[] = [
                'id'        => $type . '_' . $row['id'],
                'type'      => $type,
                'title'     => $row['title'],
                'text'      => $row['text'],
                'url'       => $row['url'],
                'protected' => (bool) $row['protected'],
                'checksum'  => $row['checksum'],
                'keywords'  => (string) ($row['keywords'] ?? ''),
                'priority'  => (int) ($row['priority'] ?? 0),
            ];
        }

        $index->addDocuments($documents);
    }

    private function indexTlSearchPdf($index): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_search_pdf'
        );

        if (!$rows) {
            return;
        }

        $documents = [];

        foreach ($rows as $row) {
            $fileType = in_array($row['type'], ['pdf', 'docx', 'xlsx', 'pptx'], true)
                ? $row['type']
                : 'pdf';

            $documents[] = [
                'id'       => $fileType . '_' . $row['id'],
                'type'     => $fileType,
                'title'    => $row['title'],
                'text'     => $row['text'],   // ✅ korrekt
                'url'      => $row['url'],
                'checksum' => $row['checksum'],
            ];
        }

        $index->addDocuments($documents);
    }
    private function detectTypeFromMeta(?string $meta): string
    {
        if (!$meta) {
            return 'page';
        }

        $data = json_decode($meta, true);
        if (!is_array($data)) {
            return 'page';
        }

        foreach ($data as $entry) {
            if (!isset($entry['@type'])) {
                continue;
            }

            if ($entry['@type'] === 'https://schema.org/Event') {
                return 'event';
            }

            if ($entry['@type'] === 'https://schema.org/NewsArticle') {
                return 'news';
            }
        }

        return 'page';
    }
}