<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\PageModel;
use Contao\StringUtil;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        if (!isset($GLOBALS['objPage']) || !$GLOBALS['objPage'] instanceof PageModel) {
            return $buffer;
        }

        $lines = ['MEILISEARCH'];
        $page  = $GLOBALS['objPage'];

        /* ================= PAGE ================= */

        if ((int) $page->priority > 0) {
            $lines[] = 'page.priority=' . (int) $page->priority;
        }

        if (trim((string) $page->keywords) !== '') {
            $lines[] = 'page.keywords=' . trim((string) $page->keywords);
        }

        $searchImageUuid = null;

        if (!empty($page->searchimage)) {
            $searchImageUuid = StringUtil::binToUuid($page->searchimage);
        } elseif ($fallback = Config::get('meilisearch_fallback_image')) {
            $searchImageUuid = StringUtil::binToUuid($fallback);
        }

        if ($searchImageUuid) {
            $lines[] = 'page.searchimage=' . $searchImageUuid;
        }

        /* ================= EVENT ================= */

        if (preg_match('/<!--\s*MEILI_EVENT id=(\d+)\s*-->/', $buffer, $m)) {
            $eventId = (int) $m[1];
            $event   = CalendarEventsModel::findByPk($eventId);

            if ($event !== null) {
                if ((int) $event->priority > 0) {
                    $lines[] = 'event.priority=' . (int) $event->priority;
                }

                if (trim((string) $event->keywords) !== '') {
                    $lines[] = 'event.keywords=' . trim((string) $event->keywords);
                }
            }
        }

        /* ================= OUTPUT ================= */

        if (count($lines) === 1) {
            return $buffer;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}