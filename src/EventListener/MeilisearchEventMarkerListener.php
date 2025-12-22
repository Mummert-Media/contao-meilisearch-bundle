<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;

class MeilisearchEventMarkerListener
{
    public function onParseFrontendTemplate(string $buffer, string $template): string
    {
        // Nur Event-Reader
        if ($template !== 'mod_eventreader') {
            return $buffer;
        }

        // Contao 5: objEvent!
        if (
            !isset($GLOBALS['objEvent']) ||
            !$GLOBALS['objEvent'] instanceof CalendarEventsModel
        ) {
            return $buffer;
        }

        $event = $GLOBALS['objEvent'];

        $GLOBALS['MEILISEARCH_MARKERS']['event'] = [
            'priority' => (int) ($event->priority ?? 0),
            'keywords' => trim((string) ($event->keywords ?? '')),
        ];

        return $buffer;
    }
}