<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Template;

class MeilisearchEventMarkerListener
{
    public function onParseTemplate(Template $template): void
    {
        if ($template->getName() !== 'mod_eventreader') {
            return;
        }

        if (
            !isset($GLOBALS['objEvent']) ||
            !$GLOBALS['objEvent'] instanceof CalendarEventsModel
        ) {
            return;
        }

        $event = CalendarEventsModel::findByPk($GLOBALS['objEvent']->id);

        if ($event === null) {
            return;
        }

        $GLOBALS['MEILISEARCH_MARKERS']['event'] = [
            'priority' => (int) ($event->priority ?? 0),
            'keywords' => trim((string) ($event->keywords ?? '')),
        ];
    }
}