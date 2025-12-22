<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\CalendarEventsModel;
use Contao\NewsModel;
use Contao\StringUtil;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        // Nur Haupt-Frontend-Templates
        if (!in_array($template, ['fe_page', 'fe_custom'], true)) {
            return $buffer;
        }

        $lines = ['MEILISEARCH'];

        /*
         * =====================
         * PAGE (tl_page)
         * =====================
         */
        if (isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof PageModel) {
            $page = $GLOBALS['objPage'];

            if (!empty($page->priority)) {
                $lines[] = 'page.priority=' . (int) $page->priority;
            }

            if (!empty($page->keywords)) {
                $lines[] = 'page.keywords=' . trim((string) $page->keywords);
            }

            if (!empty($page->searchimage)) {
                $lines[] = 'page.searchimage=' . StringUtil::binToUuid($page->searchimage);
            }
        }

        /*
         * =====================
         * EVENT (schema.org)
         * =====================
         */
        if (
            preg_match(
                '#\{[^}]*"@type"\s*:\s*"Event"[^}]*\}#s',
                $buffer,
                $eventBlock
            )
            && preg_match(
                '#\\\/schema\\\/events\\\/(\d+)#',
                $eventBlock[0],
                $m
            )
        ) {
            $event = CalendarEventsModel::findByPk((int) $m[1]);

            if ($event !== null) {
                if (!empty($event->priority)) {
                    $lines[] = 'event.priority=' . (int) $event->priority;
                }

                if (!empty($event->keywords)) {
                    $lines[] = 'event.keywords=' . trim((string) $event->keywords);
                }
            }
        }

        /*
         * =====================
         * NEWS (schema.org)
         * =====================
         */
        if (
            preg_match(
                '#\{[^}]*"@type"\s*:\s*"NewsArticle"[^}]*\}#s',
                $buffer,
                $newsBlock
            )
            && preg_match(
                '#\\\/schema\\\/news\\\/(\d+)#',
                $newsBlock[0],
                $m
            )
        ) {
            $news = NewsModel::findByPk((int) $m[1]);

            if ($news !== null) {
                if (!empty($news->priority)) {
                    $lines[] = 'news.priority=' . (int) $news->priority;
                }

                if (!empty($news->keywords)) {
                    $lines[] = 'news.keywords=' . trim((string) $news->keywords);
                }
            }
        }

        // Nichts Relevantes → nichts einfügen
        if (count($lines) === 1) {
            return $buffer;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        // Robust einfügen
        if (str_contains($buffer, '</body>')) {
            return str_replace('</body>', $marker . '</body>', $buffer);
        }

        return $buffer . $marker;
    }
}