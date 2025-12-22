<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\StringUtil;
use Contao\Config;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        if (!isset($GLOBALS['objPage']) || !$GLOBALS['objPage'] instanceof PageModel) {
            return $buffer;
        }

        $page = $GLOBALS['objPage'];

        $priority = (int) ($page->priority ?? 0);
        $keywords = trim((string) ($page->keywords ?? ''));

        // 🔹 searchimage (Page → Fallback)
        $searchImageUuid = null;

        // 1. Page-spezifisch
        if (!empty($page->searchimage)) {
            $searchImageUuid = StringUtil::binToUuid($page->searchimage);
        }

        // 2. Fallback aus tl_settings
        if (!$searchImageUuid) {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                $searchImageUuid = StringUtil::binToUuid($fallback);
            }
        }

        // Wenn wirklich GAR nichts vorhanden ist → nichts tun
        if ($priority <= 0 && $keywords === '' && !$searchImageUuid) {
            return $buffer;
        }

        // 🔹 Marker aufbauen
        $lines = [];
        $lines[] = 'MEILISEARCH';

        if ($priority > 0) {
            $lines[] = 'page.priority=' . $priority;
        }

        if ($keywords !== '') {
            $lines[] = 'page.keywords=' . $keywords;
        }

        if ($searchImageUuid) {
            $lines[] = 'page.searchimage=' . $searchImageUuid;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}