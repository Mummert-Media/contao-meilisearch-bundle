<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\NewsModel;
use Contao\Template;

$GLOBALS['MEILISEARCH_MARKERS']['event']['debug'] = 'event_listener_called';
class MeilisearchNewsMarkerListener
{
    public function onParseFrontendTemplate(string $buffer, string $template): string
    {
        if ($template !== 'mod_newsreader') {
            return $buffer;
        }

        if (!isset($GLOBALS['news']) || !$GLOBALS['news'] instanceof NewsModel) {
            return $buffer;
        }

        $news = $GLOBALS['news'];

        $GLOBALS['MEILISEARCH_MARKERS']['news'] = [
            'priority' => (int) ($news->priority ?? 0),
            'keywords' => trim((string) ($news->keywords ?? '')),
        ];

        return $buffer;
    }
}