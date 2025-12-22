<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        // Nur Frontend-Seiten
        if (!isset($GLOBALS['objPage']) || !$GLOBALS['objPage'] instanceof PageModel) {
            return $buffer;
        }

        $page = $GLOBALS['objPage'];

        // Werte aus tl_page
        $priority = (int) ($page->priority ?? 0);
        $keywords = trim((string) ($page->keywords ?? ''));

        // Wenn nichts gesetzt ist, nichts tun
        if ($priority <= 0 && $keywords === '') {
            return $buffer;
        }

        // Strukturierter Marker (maschinenlesbar)
        $lines = [];
        $lines[] = 'MEILISEARCH';
        if ($priority > 0) {
            $lines[] = 'page.priority=' . $priority;
        }
        if ($keywords !== '') {
            $lines[] = 'page.keywords=' . $keywords;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        // Marker sicher vor </body> einfügen
        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}