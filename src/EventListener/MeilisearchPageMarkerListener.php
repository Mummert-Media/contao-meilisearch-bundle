<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\FilesModel;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\StringUtil;

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

            if (!empty($page->searchimage)) {
                $raw = (string) $page->searchimage;

                if (preg_match('/^[a-f0-9-]{36}$/i', $raw)) {
                    $pageImageUuid = $raw;
                } else {
                    try {
                        $pageImageUuid = StringUtil::binToUuid($raw);
                    } catch (\Throwable) {
                        // ignorieren
                    }
                }
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
                if (preg_match('#\\/schema\\/events\\/(\\d+)#', $json, $m)) {
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
                            $data['event']['searchimage'] = StringUtil::binToUuid($event->singleSRC);
                        }

                        // ✅ START / END DATE (Unix Timestamp)
                        if (!empty($event->startDate)) {
                            $data['event']['startDate'] = (int) $event->startDate;
                        }

                        if (!empty($event->endDate)) {
                            $data['event']['endDate'] = (int) $event->endDate;
                        }
                    }
                }
            }

            /*
             * NEWS
             */
            if (preg_match('#"@type"\s*:\s*"NewsArticle"#', $json)) {
                if (preg_match('#\\/schema\\/news\\/(\\d+)#', $json, $m)) {
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

        // 1) EVENT > NEWS
        if (!empty($data['event']['searchimage'])) {
            $finalSearchImageUuid = $data['event']['searchimage'];
        } elseif (!empty($data['news']['searchimage'])) {
            $finalSearchImageUuid = $data['news']['searchimage'];
        }

        // 2) CUSTOM SEARCHIMAGE (Markup)
        if (
            $finalSearchImageUuid === null
            && preg_match('#data-searchimage-uuid="([a-f0-9\-]{36})"#i', $buffer, $m)
            && FilesModel::findByUuid($m[1]) !== null
        ) {
            $finalSearchImageUuid = $m[1];
        }

        // 3) PAGE SEARCHIMAGE
        if ($finalSearchImageUuid === null && $pageImageUuid) {
            $finalSearchImageUuid = $pageImageUuid;
        }

        // 4) FALLBACK (tl_settings)
        if ($finalSearchImageUuid === null) {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                $finalSearchImageUuid = $fallback;
            }
        }

        if ($finalSearchImageUuid !== null) {
            $data['page'] ??= [];
            $data['page']['searchimage'] = $finalSearchImageUuid;
        }

        if ($data === []) {
            return $buffer;
        }

        /*
         * =====================
         * META-SPAN (Checksum-relevant!)
         * =====================
         */
        $metaParts = [];

        // PAGE
        if (isset($data['page']['priority'])) {
            $metaParts[] = 'page_priority=' . (int) $data['page']['priority'];
        }
        if (!empty($data['page']['keywords'])) {
            $metaParts[] = 'page_keywords=' . (string) $data['page']['keywords'];
        }
        if (!empty($data['page']['searchimage'])) {
            $metaParts[] = 'page_searchimage=' . (string) $data['page']['searchimage'];
        }

        // EVENT
        if (!empty($data['event']['startDate'])) {
            $metaParts[] = 'event_startDate=' . (int) $data['event']['startDate'];
        }
        if (!empty($data['event']['endDate'])) {
            $metaParts[] = 'event_endDate=' . (int) $data['event']['endDate'];
        }
        if (!empty($data['event']['priority'])) {
            $metaParts[] = 'event_priority=' . (int) $data['event']['priority'];
        }
        if (!empty($data['event']['keywords'])) {
            $metaParts[] = 'event_keywords=' . (string) $data['event']['keywords'];
        }

        $metaText = 'MEILISEARCH_META ' . ($metaParts ? implode(' | ', $metaParts) : 'present');

        $hiddenMeta =
            "\n<span class=\"meilisearch-meta\" style=\"display:none !important\">" .
            htmlspecialchars($metaText, ENT_QUOTES) .
            "</span>\n";

        /*
         * =====================
         * JSON-MARKER
         * =====================
         */
        $marker =
            "\n<!--\nMEILISEARCH_JSON\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
            "\n-->\n";

        $injection = $hiddenMeta . $marker;

        return str_contains($buffer, '</body>')
            ? str_replace('</body>', $injection . '</body>', $buffer)
            : $buffer . $injection;
    }
}