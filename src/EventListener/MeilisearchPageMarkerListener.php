<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\CalendarEventsModel;
use Contao\NewsModel;
use Contao\StringUtil;
use Contao\Config;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        if (!in_array($template, ['fe_page', 'fe_custom'], true)) {
            return $buffer;
        }

        $lines = ['MEILISEARCH'];

        /*
         * =====================
         * PAGE (Basis + Fallback)
         * =====================
         */
        $pageImageUuid = null;

        if (isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof PageModel) {
            $page = $GLOBALS['objPage'];

            if (!empty($page->priority)) {
                $lines[] = 'page.priority=' . (int) $page->priority;
            }

            if (!empty($page->keywords)) {
                $lines[] = 'page.keywords=' . trim((string) $page->keywords);
            }

            if (!empty($page->searchimage)) {
                $pageImageUuid = StringUtil::binToUuid($page->searchimage);
                $lines[] = 'page.searchimage=' . $pageImageUuid;
            }
        }

        // Globales Fallback (nur wenn Page kein Bild hat)
        if (!$pageImageUuid) {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                $pageImageUuid = StringUtil::binToUuid($fallback);
                $lines[] = 'page.searchimage=' . $pageImageUuid;
            }
        }

        /*
         * =====================
         * EVENT (schema.org)
         * =====================
         */
        if (
            preg_match('#"@type"\s*:\s*"Event"#', $buffer) &&
            preg_match('#\\\/schema\\\/events\\\/(\d+)#', $buffer, $m)
        ) {
            $event = CalendarEventsModel::findByPk((int) $m[1]);

            if ($event !== null) {
                if (!empty($event->priority)) {
                    $lines[] = 'event.priority=' . (int) $event->priority;
                }

                if (!empty($event->keywords)) {
                    $lines[] = 'event.keywords=' . trim((string) $event->keywords);
                }

                // 🔥 Event-Bild überschreibt Page-Bild
                if ($event->addImage && !empty($event->singleSRC)) {
                    $lines[] = 'event.searchimage=' . StringUtil::binToUuid($event->singleSRC);
                }
            }
        }

        /*
         * =====================
         * NEWS (schema.org)
         * =====================
         */
        if (
            preg_match('#"@type"\s*:\s*"NewsArticle"#', $buffer) &&
            preg_match('#\\\/schema\\\/news\\\/(\d+)#', $buffer, $m)
        ) {
            $news = NewsModel::findByPk((int) $m[1]);

            if ($news !== null) {
                if (!empty($news->priority)) {
                    $lines[] = 'news.priority=' . (int) $news->priority;
                }

                if (!empty($news->keywords)) {
                    $lines[] = 'news.keywords=' . trim((string) $news->keywords);
                }

                // 🔥 News-Bild überschreibt Page-Bild
                if ($news->addImage && !empty($news->singleSRC)) {
                    $lines[] = 'news.searchimage=' . StringUtil::binToUuid($news->singleSRC);
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

        return str_contains($buffer, '</body>')
            ? str_replace('</body>', $marker . '</body>', $buffer)
            : $buffer . $marker;
    }
}