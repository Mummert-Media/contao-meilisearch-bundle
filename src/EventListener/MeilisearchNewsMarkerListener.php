<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\NewsModel;

class MeilisearchNewsMarkerListener
{
    public function onParseFrontendTemplate(string $buffer, string $template): string
    {
        if ($template !== 'mod_newsreader') {
            return $buffer;
        }

        if (
            !isset($GLOBALS['objNews']) ||
            !$GLOBALS['objNews'] instanceof NewsModel
        ) {
            return $buffer;
        }

        // News vollständig nachladen (Custom-Felder!)
        $news = NewsModel::findByPk($GLOBALS['objNews']->id);

        if ($news === null) {
            return $buffer;
        }

        $GLOBALS['MEILISEARCH_MARKERS']['news'] = [
            'priority' => (int) ($news->priority ?? 0),
            'keywords' => trim((string) ($news->keywords ?? '')),
        ];

        return $buffer;
    }
}