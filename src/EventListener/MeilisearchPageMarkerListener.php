<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\PageModel;
use Contao\StringUtil;
use Contao\Config;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        // Nur Frontend-Seiten
        if (!isset($GLOBALS['objPage']) || !$GLOBALS['objPage'] instanceof PageModel) {
            return $buffer;
        }

        $markers = $GLOBALS['MEILISEARCH_MARKERS'] ?? [];

        $page = $GLOBALS['objPage'];

        /*
         * PAGE-DATEN (immer vorhanden)
         */
        $priority = (int) ($page->priority ?? 0);
        $keywords = trim((string) ($page->keywords ?? ''));

        // searchimage: Page → Fallback
        $searchImageUuid = null;

        if (!empty($page->searchimage)) {
            $searchImageUuid = StringUtil::binToUuid($page->searchimage);
        }

        if (!$searchImageUuid) {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                $searchImageUuid = StringUtil::binToUuid($fallback);
            }
        }

        // Page-Marker sammeln
        $markers['page'] = [
            'priority'    => $priority > 0 ? $priority : null,
            'keywords'    => $keywords !== '' ? $keywords : null,
            'searchimage' => $searchImageUuid,
        ];

        /*
         * MARKER AUFBAUEN
         */
        $lines = [];
        $lines[] = 'MEILISEARCH';

        foreach ($markers as $scope => $data) {
            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $key => $value) {
                if ($value === null || $value === '' || $value === 0) {
                    continue;
                }

                $lines[] = $scope . '.' . $key . '=' . $value;
            }
        }

        // Wenn wirklich nichts da ist → nichts anhängen
        if (count($lines) === 1) {
            return $buffer;
        }

        $marker =
            "\n<!--\n" .
            implode("\n", $lines) .
            "\n-->\n";

        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}