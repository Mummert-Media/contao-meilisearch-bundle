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
                try {
                    $pageImageUuid = StringUtil::binToUuid((string) $page->searchimage);
                } catch (\Throwable) {}
            }
        }

        /*
         * =====================
         * JSON-LD (SAUBER!)
         * =====================
         */
        preg_match_all(
            '#<script type="application/ld\+json">\s*(.*?)\s*</script>#s',
            $buffer,
            $matches
        );

        foreach ($matches[1] as $jsonRaw) {
            $json = json_decode($jsonRaw, true);
            if (!is_array($json)) {
                continue;
            }

            $graph = $json['@graph'] ?? [];
            if (!is_array($graph)) {
                continue;
            }

            foreach ($graph as $entry) {

                /*
                 * EVENT
                 */
                if (($entry['@type'] ?? null) === 'Event' && !empty($entry['@id'])) {
                    if (preg_match('#/schema/events/(\d+)#', $entry['@id'], $m)) {
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
                if (($entry['@type'] ?? null) === 'NewsArticle' && !empty($entry['@id'])) {
                    if (preg_match('#/schema/news/(\d+)#', $entry['@id'], $m)) {
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
        }

        /*
         * =====================
         * SEARCHIMAGE
         * =====================
         */
        $finalSearchImageUuid =
            $data['event']['searchimage']
            ?? $data['news']['searchimage']
            ?? $pageImageUuid
            ?? Config::get('meilisearch_fallback_image');

        if ($finalSearchImageUuid) {
            $data['page']['searchimage'] = $finalSearchImageUuid;
        }

        if ($data === []) {
            return $buffer;
        }

        /*
         * =====================
         * META-SPAN (Checksum!)
         * =====================
         */
        $meta = [];

        if (!empty($data['event']['startDate'])) {
            $meta[] = 'event_startDate=' . $data['event']['startDate'];
        }
        if (!empty($data['event']['endDate'])) {
            $meta[] = 'event_endDate=' . $data['event']['endDate'];
        }

        $hidden =
            "\n<span class=\"meilisearch-meta\" style=\"display:none !important\">" .
            'MEILISEARCH_META ' . implode(' | ', $meta) .
            "</span>\n";

        $marker =
            "\n<!--\nMEILISEARCH_JSON\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
            "\n-->\n";

        return str_replace('</body>', $hidden . $marker . '</body>', $buffer);
    }
}