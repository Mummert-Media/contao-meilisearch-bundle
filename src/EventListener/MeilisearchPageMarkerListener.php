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

                // UUID-String oder BINARY(16)
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
         * JSON-LD AUSWERTEN (ohne Regex-Fallen)
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

                            // ✅ STARTDATE (Unix Timestamp)
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
         * FINALE SEARCHIMAGE-ENTSCHEIDUNG
         * =====================
         */
        $finalSearchImageUuid = null;

        if (!empty($data['event']['searchimage'])) {
            $finalSearchImageUuid = $data['event']['searchimage'];
        } elseif (!empty($data['news']['searchimage'])) {
            $finalSearchImageUuid = $data['news']['searchimage'];
        } elseif ($pageImageUuid) {
            $finalSearchImageUuid = $pageImageUuid;
        } else {
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
         * META-SPAN (ALLES REIN!)
         * =====================
         */
        $metaParts = [];

        // PAGE
        if (!empty($data['page']['priority'])) {
            $metaParts[] = 'page_priority=' . (int) $data['page']['priority'];
        }
        if (!empty($data['page']['keywords'])) {
            $metaParts[] = 'page_keywords=' . (string) $data['page']['keywords'];
        }
        if (!empty($data['page']['searchimage'])) {
            $metaParts[] = 'page_searchimage=' . (string) $data['page']['searchimage'];
        }

        // EVENT
        if (!empty($data['event']['priority'])) {
            $metaParts[] = 'event_priority=' . (int) $data['event']['priority'];
        }
        if (!empty($data['event']['keywords'])) {
            $metaParts[] = 'event_keywords=' . (string) $data['event']['keywords'];
        }
        if (!empty($data['event']['searchimage'])) {
            $metaParts[] = 'event_searchimage=' . (string) $data['event']['searchimage'];
        }
        if (!empty($data['event']['startDate'])) {
            $metaParts[] = 'event_startDate=' . (int) $data['event']['startDate'];
        }

        $metaText = 'MEILISEARCH_META ' . implode(' | ', $metaParts);

        $hiddenMeta =
            "\n<span class=\"meilisearch-meta\" style=\"display:none !important\">" .
            "⟦MEILISEARCH_META⟧ " .
            htmlspecialchars(implode(' | ', $metaParts), ENT_QUOTES) .
            " ⟦/MEILISEARCH_META⟧" .
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