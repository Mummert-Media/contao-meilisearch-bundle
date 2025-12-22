<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;

class MeilisearchPageMarkerListener
{
    public function onGeneratePage(PageModel $page, string $layout, array &$pageData): void
    {
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

        // HTML am Ende anhängen
        $pageData['output'] .= $comment;
    }
}