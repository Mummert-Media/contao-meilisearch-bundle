<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;

class MeilisearchEventMarkerListener
{
    public function onParseFrontendTemplate(string $buffer, string $template): string
    {
        if ($template !== 'mod_eventreader') {
            return $buffer;
        }

        if (!isset($GLOBALS['event']) || !$GLOBALS['event'] instanceof CalendarEventsModel) {
            return $buffer;
        }

        $event = $GLOBALS['event'];

        $GLOBALS['MEILISEARCH_MARKERS']['event'] = [
            'priority' => (int) ($event->priority ?? 0),
            'keywords' => trim((string) ($event->keywords ?? '')),
        ];

        return $buffer;
    }
}