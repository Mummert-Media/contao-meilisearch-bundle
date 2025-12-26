<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\CalendarEventsModel;
use Contao\NewsModel;
use Contao\StringUtil;
use Contao\Config;
use Contao\FilesModel;

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
         * PAGE (Basisdaten)
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

            // tl_page.searchimage ist bereits UUID-STRING
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
                if (preg_match('#\\\/schema\\\/events\\\/(\d+)#', $json, $m)) {
                    $event = CalendarEventsModel::findByPk((int) $m[1]);

                    if ($event !== null) {
                        $data['event'] = [];

                        if (!empty($event->priority)) {
                            $data['event']['priority'] = (int) $event->priority;
                        }

                        if (!empty($event->keywords)) {
                            $data['event']['keywords'] = trim((string) $event->keywords);
                        }

                        if ($event->addImage && !empty($event->singleSRC)) {
                            // singleSRC ist BINARY → binToUuid korrekt
                            $data['event']['searchimage'] = StringUtil::binToUuid($event->singleSRC);
                        }
                    }
                }
            }

            /*
             * NEWS
             */
            if (preg_match('#"@type"\s*:\s*"NewsArticle"#', $json)) {
                if (preg_match('#\\\/schema\\\/news\\\/(\d+)#', $json, $m)) {
                    $news = NewsModel::findByPk((int) $m[1]);

                    if ($news !== null) {
                        $data['news'] = [];

                        if (!empty($news->priority)) {
                            $data['news']['priority'] = (int) $news->priority;
                        }

                        if (!empty($news->keywords)) {
                            $data['news']['keywords'] = trim((string) $news->keywords);
                        }

                        if ($news->addImage && !empty($news->singleSRC)) {
                            // singleSRC ist BINARY → binToUuid korrekt
                            $data['news']['searchimage'] = StringUtil::binToUuid($news->singleSRC);
                        }
                    }
                }
            }
        }

        /*
         * =====================
         * FINALE SEARCHIMAGE-ENTSCHEIDUNG
         * =====================
         */
        $finalSearchImageUuid = null;

        // 1. EVENT > NEWS
        if (!empty($data['event']['searchimage'])) {
            $finalSearchImageUuid = $data['event']['searchimage'];
        } elseif (!empty($data['news']['searchimage'])) {
            $finalSearchImageUuid = $data['news']['searchimage'];
        }

        // 2. CUSTOM SEARCHIMAGE (Markup)
        if (
            $finalSearchImageUuid === null
            && preg_match('#data-searchimage-uuid="([a-f0-9\-]{36})"#i', $buffer, $m)
            && FilesModel::findByUuid($m[1]) !== null
        ) {
            $finalSearchImageUuid = $m[1];
        }

        // 3. PAGE SEARCHIMAGE
        if ($finalSearchImageUuid === null && $pageImageUuid) {
            $finalSearchImageUuid = $pageImageUuid;
        }

        // 4. FALLBACK (tl_settings)
        if ($finalSearchImageUuid === null) {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                // fallback ist BINARY → binToUuid korrekt
                $finalSearchImageUuid = StringUtil::binToUuid($fallback);
            }
        }

        if ($finalSearchImageUuid !== null) {
            $data['page']['searchimage'] = $finalSearchImageUuid;
        }

        if ($data === []) {
            return $buffer;
        }

        /*
         * =====================
         * MARKER AUSGEBEN
         * =====================
         */
        $marker =
            "\n<!--\nMEILISEARCH_JSON\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
            "\n-->\n";

        return str_contains($buffer, '</body>')
            ? str_replace('</body>', $marker . '</body>', $buffer)
            : $buffer . $marker;
    }
}