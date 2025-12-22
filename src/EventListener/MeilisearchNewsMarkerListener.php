<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\NewsModel;
use Contao\Template;

class MeilisearchNewsMarkerListener
{
    public function onParseTemplate(Template $template): void
    {
        // Nur News-Reader
        if ($template->getName() !== 'mod_newsreader') {
            return;
        }

        if (
            !isset($GLOBALS['objArticle']) ||
            !$GLOBALS['objArticle'] instanceof NewsModel
        ) {
            return;
        }

        // 🔥 News vollständig laden (inkl. Custom-Felder)
        $news = NewsModel::findByPk($GLOBALS['objArticle']->id);

        if ($news === null) {
            return;
        }

        $GLOBALS['MEILISEARCH_MARKERS']['news'] = [
            'priority' => (int) ($news->priority ?? 0),
            'keywords' => trim((string) ($news->keywords ?? '')),
        ];
    }
}