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
         * PAGE (Basis)
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
            }
        }

        /*
         * =====================
         * SCHEMA.ORG BLÖCKE
         * =====================
         */
        preg_match_all(
            '#<script type="application/ld\+json">\s*(\{.*?\})\s*</script>#s',
            $buffer,
            $jsonBlocks
        );

        foreach ($jsonBlocks[1] as $json) {

            /*
             * =====================
             * EVENT
             * =====================
             */
            if (preg_match('#"@type"\s*:\s*"Event"#', $json)) {

                if (preg_match('#\\\/schema\\\/events\\\/(\d+)#', $json, $m)) {
                    $event = CalendarEventsModel::findByPk((int) $m[1]);

                    if ($event !== null) {
                        if (!empty($event->priority)) {
                            $lines[] = 'event.priority=' . (int) $event->priority;
                        }

                        if (!empty($event->keywords)) {
                            $lines[] = 'event.keywords=' . trim((string) $event->keywords);
                        }

                        if ($event->addImage && !empty($event->singleSRC)) {
                            $lines[] = 'event.searchimage=' . StringUtil::binToUuid($event->singleSRC);
                        }
                    }
                }

                if (preg_match('#"startDate"\s*:\s*"([^"]+)"#', $json, $dm)) {
                    $lines[] = 'event.date=' . $dm[1];
                }
            }

            /*
             * =====================
             * NEWS
             * =====================
             */
            if (preg_match('#"@type"\s*:\s*"NewsArticle"#', $json)) {

                if (preg_match('#\\\/schema\\\/news\\\/(\d+)#', $json, $m)) {
                    $news = NewsModel::findByPk((int) $m[1]);

                    if ($news !== null) {
                        if (!empty($news->priority)) {
                            $lines[] = 'news.priority=' . (int) $news->priority;
                        }

                        if (!empty($news->keywords)) {
                            $lines[] = 'news.keywords=' . trim((string) $news->keywords);
                        }

                        if ($news->addImage && !empty($news->singleSRC)) {
                            $lines[] = 'news.searchimage=' . StringUtil::binToUuid($news->singleSRC);
                        }
                    }
                }

                if (preg_match('#"datePublished"\s*:\s*"([^"]+)"#', $json, $dm)) {
                    $lines[] = 'news.date=' . $dm[1];
                }
            }
        }

        /*
         * =====================
         * PAGE IMAGE FALLBACK
         * =====================
         */
        if ($pageImageUuid) {
            $lines[] = 'page.searchimage=' . $pageImageUuid;
        } else {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                $lines[] = 'page.searchimage=' . StringUtil::binToUuid($fallback);
            }
        }

        if (count($lines) === 1) {
            return $buffer;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", array_unique($lines)) .
            "\n-->\n";

        return str_contains($buffer, '</body>')
            ? str_replace('</body>', $marker . '</body>', $buffer)
            : $buffer . $marker;
    }
}