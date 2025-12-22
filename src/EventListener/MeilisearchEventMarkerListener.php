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

        if (
            !isset($GLOBALS['objEvent']) ||
            !$GLOBALS['objEvent'] instanceof CalendarEventsModel
        ) {
            return $buffer;
        }

        // 🔥 Event vollständig aus DB laden
        $event = CalendarEventsModel::findByPk($GLOBALS['objEvent']->id);

        if ($event === null) {
            return $buffer;
        }

        $GLOBALS['MEILISEARCH_MARKERS']['event'] = [
            'priority' => (int) ($event->priority ?? 0),
            'keywords' => trim((string) ($event->keywords ?? '')),
        ];

        return $buffer;
    }
}