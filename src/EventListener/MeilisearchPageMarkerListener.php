<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\StringUtil;

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

        // 🔹 searchimage (UUID)
        $searchImageUuid = null;
        if (!empty($page->searchimage)) {
            // falls binär → UUID-String
            $searchImageUuid = StringUtil::binToUuid($page->searchimage);
        }

        if ($priority <= 0 && $keywords === '' && !$searchImageUuid) {
            return $buffer;
        }

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