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

        $data  = [];
        $debug = [];

        $debug[] = 'Listener gestartet';
        $debug[] = 'Template: ' . $template;

        /*
         * =====================
         * PAGE (Basisdaten)
         * =====================
         */
        $pageImageUuid = null;

        if (isset($GLOBALS['objPage']) && $GLOBALS['objPage'] instanceof PageModel) {
            $page = $GLOBALS['objPage'];

            $debug[] = 'Page gefunden (ID: ' . $page->id . ')';
            $debug[] = 'Page priority: ' . var_export($page->priority, true);
            $debug[] = 'Page keywords: ' . var_export($page->keywords, true);
            $debug[] = 'Page searchimage (raw): ' . var_export($page->searchimage, true);

            $data['page'] = [];

            if (!empty($page->priority)) {
                $data['page']['priority'] = (int) $page->priority;
            }

            if (!empty($page->keywords)) {
                $data['page']['keywords'] = trim((string) $page->keywords);
            }

            // tl_page.searchimage ist UUID-String
            if (!empty($page->searchimage)) {
                $pageImageUuid = StringUtil::binToUuid($page->searchimage);
                $debug[] = 'Page searchimage UUID gesetzt: ' . $pageImageUuid;
            }

        } else {
            $debug[] = 'objPage nicht gesetzt oder falscher Typ';
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

        $debug[] = 'Gefundene JSON-LD Blöcke: ' . count($jsonBlocks[1]);

        foreach ($jsonBlocks[1] as $json) {

            /*
             * EVENT
             */
            if (preg_match('#"@type"\s*:\s*"Event"#', $json)) {
                $debug[] = 'JSON-LD Typ: Event';

                if (preg_match('#\\\/schema\\\/events\\\/(\d+)#', $json, $m)) {
                    $eventId = (int) $m[1];
                    $debug[] = 'Event-ID aus JSON-LD: ' . $eventId;

                    $event = CalendarEventsModel::findByPk($eventId);

                    if ($event !== null) {
                        $debug[] = 'Event geladen';

                        $data['event'] = [];

                        if (!empty($event->priority)) {
                            $data['event']['priority'] = (int) $event->priority;
                        }

                        if (!empty($event->keywords)) {
                            $data['event']['keywords'] = trim((string) $event->keywords);
                        }

                        if ($event->addImage && !empty($event->singleSRC)) {
                            $uuid = StringUtil::binToUuid($event->singleSRC);
                            $data['event']['searchimage'] = $uuid;
                            $debug[] = 'Event searchimage UUID: ' . $uuid;
                        } else {
                            $debug[] = 'Event hat kein Bild';
                        }
                    } else {
                        $debug[] = 'Event nicht gefunden';
                    }
                }
            }

            /*
             * NEWS
             */
            if (preg_match('#"@type"\s*:\s*"NewsArticle"#', $json)) {
                $debug[] = 'JSON-LD Typ: NewsArticle';

                if (preg_match('#\\\/schema\\\/news\\\/(\d+)#', $json, $m)) {
                    $newsId = (int) $m[1];
                    $debug[] = 'News-ID aus JSON-LD: ' . $newsId;

                    $news = NewsModel::findByPk($newsId);

                    if ($news !== null) {
                        $debug[] = 'News geladen';

                        $data['news'] = [];

                        if (!empty($news->priority)) {
                            $data['news']['priority'] = (int) $news->priority;
                        }

                        if (!empty($news->keywords)) {
                            $data['news']['keywords'] = trim((string) $news->keywords);
                        }

                        if ($news->addImage && !empty($news->singleSRC)) {
                            $uuid = StringUtil::binToUuid($news->singleSRC);
                            $data['news']['searchimage'] = $uuid;
                            $debug[] = 'News searchimage UUID: ' . $uuid;
                        } else {
                            $debug[] = 'News hat kein Bild';
                        }
                    } else {
                        $debug[] = 'News nicht gefunden';
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
            $debug[] = 'Searchimage aus Event übernommen';
        } elseif (!empty($data['news']['searchimage'])) {
            $finalSearchImageUuid = $data['news']['searchimage'];
            $debug[] = 'Searchimage aus News übernommen';
        }

        // 2. CUSTOM SEARCHIMAGE (Markup)
        if (
            $finalSearchImageUuid === null
            && preg_match('#data-searchimage-uuid="([a-f0-9\-]{36})"#i', $buffer, $m)
        ) {
            $debug[] = 'Custom searchimage UUID gefunden: ' . $m[1];

            if (FilesModel::findByUuid($m[1]) !== null) {
                $finalSearchImageUuid = $m[1];
                $debug[] = 'Custom searchimage existiert in tl_files';
            } else {
                $debug[] = 'Custom searchimage existiert NICHT in tl_files';
            }
        } else {
            $debug[] = 'Kein Custom searchimage im Markup';
        }

        // 3. PAGE SEARCHIMAGE
        if ($finalSearchImageUuid === null && $pageImageUuid) {
            $finalSearchImageUuid = $pageImageUuid;
            $debug[] = 'Searchimage aus Page übernommen';
        }

        // 4. FALLBACK (tl_settings)
        if ($finalSearchImageUuid === null) {
            $fallback = Config::get('meilisearch_fallback_image');
            $debug[] = 'Fallback raw: ' . var_export($fallback, true);

            if ($fallback) {
                $finalSearchImageUuid = StringUtil::binToUuid($fallback);
                $debug[] = 'Fallback UUID: ' . $finalSearchImageUuid;
            }
        }

        if ($finalSearchImageUuid !== null) {
            $data['page']['searchimage'] = $finalSearchImageUuid;
        }

        $debug[] = 'Finale searchimage UUID: ' . var_export($finalSearchImageUuid, true);
        $debug[] = 'Finales Data-Array: ' . json_encode($data);

        if ($data === []) {
            $debug[] = 'Data leer – trotzdem Marker ausgeben (Debug aktiv)';
        }

        /*
         * =====================
         * MARKER AUSGEBEN
         * =====================
         */
        $marker =
            "\n<!--\n" .
            "MEILISEARCH_JSON\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n" .
            "DEBUG\n" .
            implode("\n", $debug) .
            "\n-->\n";

        return str_contains($buffer, '</body>')
            ? str_replace('</body>', $marker . '</body>', $buffer)
            : $buffer . $marker;
    }
}