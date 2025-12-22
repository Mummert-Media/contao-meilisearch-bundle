<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\LayoutModel;
use Contao\PageRegular;

class MeilisearchPageMarkerListener
{
    public function onGeneratePage(
        PageModel $page,
        LayoutModel $layout,
        PageRegular $pageRegular
    ): void {
        $priority = (int) $page->priority;
        $keywords = trim((string) $page->keywords);

        // nichts gesetzt → nichts schreiben
        if ($priority <= 0 && $keywords === '') {
            return;
        }

        $lines = [];
        $lines[] = 'MEILISEARCH';

        if ($priority > 0) {
            $lines[] = 'page.priority=' . $priority;
        }

        if ($keywords !== '') {
            $lines[] = 'page.keywords=' . $keywords;
        }

        $comment =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        // 🔥 HTML-Ausgabe am Ende erweitern (Contao 5 korrekt)
        $pageRegular->Template->output .= $comment;
    }
}