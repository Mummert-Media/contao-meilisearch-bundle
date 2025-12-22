<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Template;

class MeilisearchEventMarkerListener
{
    public function onParseTemplate(Template $template): void
    {
        // ✅ Nur Event-Reader
        if ($template->getName() !== 'mod_eventreader') {
            return;
        }

        // ✅ Event kommt DIREKT aus dem Template
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

        // ✅ In globale Marker schreiben (merge-fähig)
        $GLOBALS['MEILISEARCH_MARKERS']['event'] = [
            'priority' => $priority > 0 ? $priority : null,
            'keywords' => $keywords !== '' ? $keywords : null,
        ];
    }
}