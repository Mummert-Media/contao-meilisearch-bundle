<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;

class MeilisearchIndexService
{
    private Client $client;
    private string $indexName;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $host = Config::get('meilisearch_host');
        $apiKey = Config::get('meilisearch_api_write');
        $this->indexName = Config::get('meilisearch_index');

        $this->client = new Client($host, $apiKey);
    }

    /**
     * Entry point for command & cron
     */
    public function run(): void
    {
        $index = $this->client->index($this->indexName);

        // 1. kompletten Index löschen
        $index->deleteAllDocuments();

        // 2. tl_search indexieren
        $this->indexTlSearch($index);

        // 3. tl_search_pdf indexieren
        $this->indexTlSearchPdf($index);
    }

    /**
     * Indexiert Seiten, Events und News aus tl_search
     */
    private function indexTlSearch($index): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_search'
        );

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
                'pid'       => $row['pid'],
                'checksum'  => $row['checksum'],
            ];
        }

        $index->addDocuments($documents);
    }

    /**
     * Indexiert PDFs aus tl_search_pdf
     */
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
            $fileType = $row['type'] ?: 'pdf';

            $documents[] = [
                'id'       => $fileType . '_' . $row['id'],
                'type'     => $fileType,
                'title'    => $row['title'],
                'text'     => $row['content'],
                'url'      => $row['url'],
                'pid'      => $row['pid'],
                'checksum' => $row['checksum'],
                'filesrc'  => $row['filesrc'],
            ];
        }

        $index->addDocuments($documents);
    }

    /**
     * Robuste Typ-Erkennung ausschließlich über tl_search.meta
     *
     * @return page|event|news
     */
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

            switch ($entry['@type']) {
                case 'https://schema.org/Event':
                    return 'event';

                case 'https://schema.org/NewsArticle':
                    return 'news';
            }
        }

        return 'page';
    }
}