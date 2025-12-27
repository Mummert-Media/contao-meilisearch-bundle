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
        $this->framework->initialize();

        $host = (string) Config::get('meilisearch_host');
        $apiKey = (string) Config::get('meilisearch_api_write');
        $this->indexName = (string) Config::get('meilisearch_index');

        if ($host === '' || $this->indexName === '') {
            throw new \RuntimeException('Meilisearch is not configured in tl_settings.');
        }

        $this->client = new Client($host, $apiKey);
        $index = $this->client->index($this->indexName);

        try {
            $index->updateSettings(['primaryKey' => 'id']);
        } catch (\Throwable) {}

        $this->ensureIndexSettings($index);

        $index->deleteAllDocuments();

        $this->indexTlSearch($index);
        $this->indexTlSearchPdf($index);
    }

    private function ensureIndexSettings(Indexes $index): void
    {
        $index->updateSettings([
            'searchableAttributes' => ['title', 'keywords', 'text'],
            'sortableAttributes'   => ['priority', 'startDate'],
        ]);
    }

    /**
     * ⛔ MEILISEARCH_META aus Text entfernen
     */
    private function stripMeilisearchMeta(string $text): string
    {
        $text = preg_replace(
            '/⟦MEILISEARCH_META⟧.*?⟦\/MEILISEARCH_META⟧/su',
            '',
            $text
        );

        // Text normalisieren
        $text = preg_replace('/\s{2,}/u', ' ', $text);
        $text = preg_replace('/\n{2,}/u', "\n", $text);

        return trim($text);
    }

    /**
     * startDate aus schema.org Event extrahieren
     */
    private function extractEventStartDate(?string $meta): ?int
    {
        if (!$meta) {
            return null;
        }

        $data = json_decode($meta, true);
        if (!is_array($data)) {
            return null;
        }

        foreach ($data as $entry) {
            if (($entry['@type'] ?? null) !== 'https://schema.org/Event') {
                continue;
            }

            if (!empty($entry['https://schema.org/startDate'])) {
                return strtotime($entry['https://schema.org/startDate']) ?: null;
            }

            if (!empty($entry['startDate'])) {
                return strtotime($entry['startDate']) ?: null;
            }
        }

        return null;
    }

    /**
     * tl_search indexieren
     */
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

            $eventStart = null;
            if ($type === 'event') {
                $eventStart = $this->extractEventStartDate($row['meta'] ?? null);
                if (!$indexPastEvents && $eventStart !== null && $eventStart < $today) {
                    continue;
                }
            }

            $cleanText = $this->stripMeilisearchMeta((string) $row['text']);

            $doc = [
                'id'        => $type . '_' . $row['id'],
                'type'      => $type,
                'title'     => $row['title'],
                'text'      => $cleanText,
                'url'       => $row['url'],
                'protected' => (bool) $row['protected'],
                'checksum'  => $row['checksum'],
                'keywords'  => (string) ($row['keywords'] ?? ''),
                'priority'  => (int) ($row['priority'] ?? 0),
            ];

            if ($eventStart !== null) {
                $doc['startDate'] = $eventStart;
            }

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

    /**
     * tl_search_pdf indexieren
     */
    private function indexTlSearchPdf(Indexes $index): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_search_pdf');
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
                'text'     => $this->stripMeilisearchMeta((string) $row['text']),
                'url'      => $row['url'],
                'checksum' => $row['checksum'],
                'poster'   => self::FILETYPE_ICON_MAP[$fileType]
                    ?? self::FILETYPE_ICON_MAP['pdf'],
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
            if (($entry['@type'] ?? null) === 'https://schema.org/Event') {
                return 'event';
            }
            if (($entry['@type'] ?? null) === 'https://schema.org/NewsArticle') {
                return 'news';
            }
        }

        return 'page';
    }
}