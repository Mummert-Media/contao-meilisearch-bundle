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

        $data = [];

        /*
         * =====================
         * PAGE
         * =====================
         */
        $pageImageUuid = null;

        if (isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof PageModel) {
            $page = $GLOBALS['objPage'];

            $data['page'] = [];

            if (!empty($page->priority)) {
                $data['page']['priority'] = (int) $page->priority;
            }

            if (!empty($page->keywords)) {
                $data['page']['keywords'] = trim((string) $page->keywords);
            }

            if (!empty($page->searchimage)) {
                $pageImageUuid = $page->searchimage;
            }
        }

        /*
         * =====================
         * SCHEMA.ORG JSON-LD
         * =====================
         */
        preg_match_all(
            '#<script type="application/ld\+json">\s*(\{.*?\})\s*</script>#s',
            $buffer,
            $jsonBlocks
        );

        foreach ($jsonBlocks[1] as $json) {

            /*
             * EVENT
             */
            if (preg_match('#"@type"\s*:\s*"Event"#', $json)) {
                $data['event'] ??= [];

                if (preg_match('#\\\/schema\\\/events\\\/(\d+)#', $json, $m)) {
                    $event = CalendarEventsModel::findByPk((int) $m[1]);

                    if ($event !== null) {
                        if (!empty($event->priority)) {
                            $data['event']['priority'] = (int) $event->priority;
                        }

                        if (!empty($event->keywords)) {
                            $data['event']['keywords'] = trim((string) $event->keywords);
                        }

                        if ($event->addImage && !empty($event->singleSRC)) {
                            $data['event']['searchimage'] = StringUtil::binToUuid($event->singleSRC);
                        }
                    }
                }

                if (preg_match('#"startDate"\s*:\s*"([^"]+)"#', $json, $dm)) {
                    $data['event']['date'] = $dm[1];
                }
            }

            /*
             * NEWS
             */
            if (preg_match('#"@type"\s*:\s*"NewsArticle"#', $json)) {
                $data['news'] ??= [];

                if (preg_match('#\\\/schema\\\/news\\\/(\d+)#', $json, $m)) {
                    $news = NewsModel::findByPk((int) $m[1]);

                    if ($news !== null) {
                        if (!empty($news->priority)) {
                            $data['news']['priority'] = (int) $news->priority;
                        }

                        if (!empty($news->keywords)) {
                            $data['news']['keywords'] = trim((string) $news->keywords);
                        }

                        if ($news->addImage && !empty($news->singleSRC)) {
                            $data['news']['searchimage'] = StringUtil::binToUuid($news->singleSRC);
                        }
                    }
                }

                if (preg_match('#"datePublished"\s*:\s*"([^"]+)"#', $json, $dm)) {
                    $data['news']['date'] = $dm[1];
                }
            }
        }

        /*
         * CUSTOM SEARCHIMAGE (Markup)
         */
        if (
            preg_match('#data-searchimage-uuid="([a-f0-9\-]{36})"#i', $buffer, $m)
        ) {
            $data['custom']['searchimage'] = $m[1];
        }

        /*
         * PAGE IMAGE FALLBACK
         */
        if ($pageImageUuid) {
            $data['page']['searchimage'] = $pageImageUuid;
        } else {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                $data['page']['searchimage'] = StringUtil::binToUuid($fallback);
            }
        }

        if ($data === []) {
            return $buffer;
        }

        $marker =
            "\n<!--\nMEILISEARCH_JSON\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
            "\n-->\n";

        return str_contains($buffer, '</body>')
            ? str_replace('</body>', $marker . '</body>', $buffer)
            : $buffer . $marker;
    }
}