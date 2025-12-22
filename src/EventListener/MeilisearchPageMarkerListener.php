<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\NewsModel;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        $lines = ['MEILISEARCH'];

        // =====================
        // EVENT (schema.org)
        // =====================
        if (preg_match(
            '#\{[^}]*"@type"\s*:\s*"Event"[^}]*\}#s',
            $buffer,
            $eventBlock
        )) {
            if (preg_match('#"/schema/events/(\d+)"#', $eventBlock[0], $m)) {
                $event = CalendarEventsModel::findByPk((int) $m[1]);
                if ($event) {
                    $lines[] = 'event.priority=' . (int) $event->priority;
                    $lines[] = 'event.keywords=' . trim((string) $event->keywords);
                }
            }
        }

        // =====================
        // NEWS (schema.org)
        // =====================
        if (preg_match(
            '#\{[^}]*"@type"\s*:\s*"NewsArticle"[^}]*\}#s',
            $buffer,
            $newsBlock
        )) {
            if (preg_match('#"/schema/news/(\d+)"#', $newsBlock[0], $m)) {
                $news = NewsModel::findByPk((int) $m[1]);
                if ($news) {
                    $lines[] = 'news.priority=' . (int) $news->priority;
                    $lines[] = 'news.keywords=' . trim((string) $news->keywords);
                }
            }
        }

        if (count($lines) === 1) {
            return $buffer;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}