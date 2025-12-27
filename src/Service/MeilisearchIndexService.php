<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

class MeilisearchIndexService
{
    private Client $client;
    private string $indexName;

    /**
     * Statische Icons für Datei-Typen (Bundle-Assets)
     */
    private const FILETYPE_ICON_MAP = [
        'pdf'  => '/bundles/contaomeilisearch/icons/filetype-pdf.svg',
        'docx' => '/bundles/contaomeilisearch/icons/filetype-docx.svg',
        'xlsx' => '/bundles/contaomeilisearch/icons/filetype-xlsx.svg',
        'pptx' => '/bundles/contaomeilisearch/icons/filetype-pptx.svg',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly MeilisearchImageHelper $imageHelper,
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

        // ✅ INDEX-SETTINGS SICHERSTELLEN
        $this->ensureIndexSettings($index);

        // 1. kompletten Index löschen (Settings bleiben erhalten!)
        $index->deleteAllDocuments();

        // 2. tl_search indexieren
        $this->indexTlSearch($index);

        // 3. tl_search_pdf indexieren
        $this->indexTlSearchPdf($index);
    }

    /**
     * Relevanz- & Sortierlogik für Meilisearch
     */
    private function ensureIndexSettings(Indexes $index): void
    {
        $index->updateSettings([
            'searchableAttributes' => [
                'title',
                'keywords',
                'text',
            ],
            'sortableAttributes' => [
                'priority',
                'startDate',
            ],
        ]);
    }

    private function indexTlSearch(Indexes $index): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_search');
        if (!$rows) {
            return;
        }

        $indexPastEvents = (bool) Config::get('meilisearch_index_past_events');
        $today = strtotime('today');

        $documents = [];

        foreach ($rows as $row) {
            $type = $this->detectTypeFromMeta($row['meta'] ?? null);

            // 🛑 VERGANGENE EVENTS FILTERN
            if ($type === 'event' && !$indexPastEvents) {
                if (!empty($row['startDate'])) {
                    $eventStart = (int) $row['startDate'];

                    if ($eventStart < $today) {
                        continue; // ⛔ Event überspringen
                    }
                }
            }

            $doc = [
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

            // 📅 startDate nur für Events übernehmen
            if ($type === 'event' && !empty($row['startDate'])) {
                $doc['startDate'] = (int) $row['startDate'];
            }

            // 🖼️ Bild aus UUID erzeugen
            if (!empty($row['imagepath'])) {
                $imagePath = $this->imageHelper->resolveImagePath($row['imagepath']);
                if ($imagePath !== null) {
                    $doc['poster'] = $imagePath;
                }
            }

            $documents[] = $doc;
        }

        if ($documents !== []) {
            $index->addDocuments($documents);
        }
    }

    private function indexTlSearchPdf(Indexes $index): void
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

            $doc = [
                'id'       => $fileType . '_' . $row['id'],
                'type'     => $fileType,
                'title'    => $row['title'],
                'text'     => $row['text'],
                'url'      => $row['url'],
                'checksum' => $row['checksum'],
            ];

            // 🖼️ Icon als Poster setzen (Bundle-Asset)
            $doc['poster'] = self::FILETYPE_ICON_MAP[$fileType]
                ?? self::FILETYPE_ICON_MAP['pdf'];

            $documents[] = $doc;
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