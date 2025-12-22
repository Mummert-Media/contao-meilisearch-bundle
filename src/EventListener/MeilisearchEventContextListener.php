<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\CalendarEventsModel;
use Contao\Template;

class MeilisearchEventContextListener
{
    public function onParseFrontendTemplate(string $buffer, string $template): string
    {
        // Nur Event-Reader-Modul
        if ($template !== 'mod_eventreader') {
            return $buffer;
        }

        // Event kommt IM Template an
        if (
            isset($GLOBALS['objEvent']) &&
            $GLOBALS['objEvent'] instanceof CalendarEventsModel
        ) {
            $eventId = (int) $GLOBALS['objEvent']->id;

            // Minimaler, stabiler Marker
            $marker = "\n<!-- MEILI_EVENT id={$eventId} -->\n";

            return $buffer . $marker;
        }

        return $buffer;
    }
}