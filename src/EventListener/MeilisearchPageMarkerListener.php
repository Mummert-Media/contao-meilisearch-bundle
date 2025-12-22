<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
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
        $lines = ['MEILISEARCH'];

        // ======================
        // PAGE
        // ======================
        if ((int) $page->priority > 0) {
            $lines[] = 'page.priority=' . (int) $page->priority;
        }

        if (!empty($page->keywords)) {
            $lines[] = 'page.keywords=' . trim($page->keywords);
        }

        if (!empty($page->searchimage)) {
            $lines[] = 'page.searchimage=' . StringUtil::binToUuid($page->searchimage);
        }

        // ======================
        // EVENT
        // ======================
        if (!empty($GLOBALS['MEILISEARCH_MARKERS']['event'])) {
            $event = $GLOBALS['MEILISEARCH_MARKERS']['event'];

            if (!empty($event['priority'])) {
                $lines[] = 'event.priority=' . $event['priority'];
            }

            if (!empty($event['keywords'])) {
                $lines[] = 'event.keywords=' . $event['keywords'];
            }
        }

        // ======================
        // NEWS (später)
        // ======================
        if (!empty($GLOBALS['MEILISEARCH_MARKERS']['news'])) {
            $news = $GLOBALS['MEILISEARCH_MARKERS']['news'];

            if (!empty($news['priority'])) {
                $lines[] = 'news.priority=' . $news['priority'];
            }

            if (!empty($news['keywords'])) {
                $lines[] = 'news.keywords=' . $news['keywords'];
            }
        }

        if (count($lines) === 1) {
            return $buffer;
        }

        $marker = "\n<!--\n" . implode("\n", $lines) . "\n-->\n";

        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}