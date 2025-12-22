<?php

namespace MummertMedia\ContaoMeilisearchBundle\Service;

use Contao\Database;

class SearchDataProvider
{
    public function getSearchData(array $set): ?array
    {
        return match ($set['type'] ?? null) {
            'page'     => $this->getPageSearchData((int) ($set['pageId'] ?? 0)),
            'news'     => $this->getNewsSearchData((int) ($set['newsId'] ?? 0)),
            'calendar' => $this->getEventSearchData((int) ($set['eventId'] ?? 0)),
            default    => null,
        };
    }

    private function getPageSearchData(int $pageId): ?array
    {
        if ($pageId <= 0) {
            return null;
        }

        $row = Database::getInstance()
            ->prepare('SELECT priority, keywords FROM tl_page WHERE id=?')
            ->execute($pageId)
            ->fetchAssoc();

        return $row ? [
            'priority' => (int) $row['priority'],
            'keywords' => (string) $row['keywords'],
        ] : null;
    }

    private function getNewsSearchData(int $newsId): ?array
    {
        if ($newsId <= 0) {
            return null;
        }

        $row = Database::getInstance()
            ->prepare('SELECT priority, keywords FROM tl_news WHERE id=?')
            ->execute($newsId)
            ->fetchAssoc();

        return $row ? [
            'priority' => (int) $row['priority'],
            'keywords' => (string) $row['keywords'],
        ] : null;
    }

    private function getEventSearchData(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }

        $row = Database::getInstance()
            ->prepare('SELECT priority, keywords FROM tl_calendar_events WHERE id=?')
            ->execute($eventId)
            ->fetchAssoc();

        return $row ? [
            'priority' => (int) $row['priority'],
            'keywords' => (string) $row['keywords'],
        ] : null;
    }
}