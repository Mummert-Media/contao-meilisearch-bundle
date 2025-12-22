<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Template;

class MeilisearchEventMarkerListener
{
    public function onParseTemplate(Template $template): void
    {
        // 🔥 Event-Detailseite erkennen
        if (
            !isset($template->event) ||
            !$template->event instanceof CalendarEventsModel
        ) {
            return;
        }

        $event = $template->event;

        $priority = (int) ($event->priority ?? 0);
        $keywords = trim((string) ($event->keywords ?? ''));

        if ($priority <= 0 && $keywords === '') {
            return;
        }

        // ✅ Marker setzen (merge-sicher)
        $GLOBALS['MEILISEARCH_MARKERS']['event'] = array_merge(
            $GLOBALS['MEILISEARCH_MARKERS']['event'] ?? [],
            [
                'priority' => $priority > 0 ? $priority : null,
                'keywords' => $keywords !== '' ? $keywords : null,
            ]
        );
    }
}