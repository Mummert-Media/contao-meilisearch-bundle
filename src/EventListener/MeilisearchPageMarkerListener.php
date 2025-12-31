<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\StringUtil;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        if (!in_array($template, ['fe_page', 'fe_page_indexing'], true)) {
            return $buffer;
        }

        $data = [];

        /*
         * =====================
         * CONTENT-BILD (TOP PRIORITÄT)
         * =====================
         */
        $contentImageUuid = null;

        if (preg_match('#<main\b[^>]*>(.*?)</main>#si', $buffer, $m)) {
            $mainHtml = $m[1];

            if (preg_match(
                '#meilisearch-uuid=["\']([a-f0-9-]{36})["\']#i',
                $mainHtml,
                $mm
            )) {
                $contentImageUuid = $mm[1];
            }
        }

        /*
         * =====================
         * KEYWORDS AUS FRONTEND (Catalog Manager)
         * =====================
         */
        $frontendKeywords = [];

        if (preg_match(
            '#<div[^>]+id=["\']keywords["\'][^>]+meilisearch-keywords=["\']([^"\']+)["\']#i',
            $buffer,
            $m
        )) {
            $frontendKeywords = preg_split('/\s+/', trim($m[1]));
        }

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
                    } catch (\Throwable) {}
                }
            }
        }

        /*
         * =====================
         * JSON-LD AUSWERTEN
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
         * KEYWORDS ZUSAMMENFÜHREN
         * =====================
         */
        $allKeywords = [];

        if (!empty($data['page']['keywords'])) {
            $allKeywords = array_merge(
                $allKeywords,
                preg_split('/\s+/', $data['page']['keywords'])
            );
        }

        if (!empty($data['event']['keywords'])) {
            $allKeywords = array_merge(
                $allKeywords,
                preg_split('/\s+/', $data['event']['keywords'])
            );
        }

        if (!empty($data['news']['keywords'])) {
            $allKeywords = array_merge(
                $allKeywords,
                preg_split('/\s+/', $data['news']['keywords'])
            );
        }

        if (!empty($frontendKeywords)) {
            $allKeywords = array_merge($allKeywords, $frontendKeywords);
        }

        $allKeywords = array_unique(
            array_filter(
                array_map('trim', $allKeywords)
            )
        );

        if ($allKeywords !== []) {
            $data['page']['keywords'] = implode(' ', $allKeywords);
        }

        /*
         * =====================
         * FINALE SEARCHIMAGE-ENTSCHEIDUNG
         * =====================
         */
        $finalSearchImageUuid = null;

        if ($contentImageUuid !== null) {
            $finalSearchImageUuid = $contentImageUuid;
        }
        elseif (!empty($data['event']['searchimage'])) {
            $finalSearchImageUuid = $data['event']['searchimage'];
        }
        elseif (!empty($data['news']['searchimage'])) {
            $finalSearchImageUuid = $data['news']['searchimage'];
        }
        elseif ($pageImageUuid) {
            $finalSearchImageUuid = $pageImageUuid;
        }
        else {
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
         * META-SPAN
         * =====================
         */
        $metaParts = [];

        if (!empty($data['page']['priority'])) {
            $metaParts[] = 'page_priority=' . $data['page']['priority'];
        }
        if (!empty($data['page']['keywords'])) {
            $metaParts[] = 'page_keywords=' . $data['page']['keywords'];
        }
        if (!empty($data['page']['searchimage'])) {
            $metaParts[] = 'page_searchimage=' . $data['page']['searchimage'];
        }
        if ($contentImageUuid) {
            $metaParts[] = 'content_searchimage=' . $contentImageUuid;
        }
        if (!empty($data['event']['startDate'])) {
            $metaParts[] = 'event_startDate=' . $data['event']['startDate'];
        }

        $hiddenMeta =
            "\n<span class=\"meilisearch-meta\" style=\"display:none !important\">" .
            "⟦MEILISEARCH_META⟧ " .
            htmlspecialchars(implode(' | ', $metaParts), ENT_QUOTES) .
            " ⟦/MEILISEARCH_META⟧" .
            "</span>\n";

        $marker =
            "\n<!--\nMEILISEARCH_JSON\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
            "\n-->\n";

        $injection = $hiddenMeta . $marker;

        return str_contains($buffer, '</main>')
            ? str_replace('</main>', $injection . '</main>', $buffer)
            : $buffer . $injection;
    }
}