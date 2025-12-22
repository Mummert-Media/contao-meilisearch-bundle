<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Template;

class MeilisearchEventMarkerListener
{
    public function onParseTemplate(Template $template): void
    {
        // DEBUG: Immer sichtbar, sobald parseTemplate überhaupt läuft
        $GLOBALS['MEILISEARCH_MARKERS']['event']['debug_hook'] = 'parseTemplate_called:' . $template->getName();

        if ($template->getName() !== 'mod_eventreader') {
            return;
        }

        // DEBUG: Was ist im Template vorhanden?
        $GLOBALS['MEILISEARCH_MARKERS']['event']['debug_has_event_prop'] = isset($template->event) ? 'yes' : 'no';

        if (!isset($template->event) || !$template->event instanceof CalendarEventsModel) {
            return;
        }

        $event = $template->event;

        $GLOBALS['MEILISEARCH_MARKERS']['event'] = array_merge(
            $GLOBALS['MEILISEARCH_MARKERS']['event'] ?? [],
            [
                'priority' => (int) ($event->priority ?? 0),
                'keywords' => trim((string) ($event->keywords ?? '')),
            ]
        );
    }
}