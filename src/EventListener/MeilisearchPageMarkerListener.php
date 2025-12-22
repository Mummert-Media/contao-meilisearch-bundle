<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

use Contao\Config;
use Contao\PageModel;
use Contao\StringUtil;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        // Nur im Frontend
        if (!isset($GLOBALS['objPage']) || !$GLOBALS['objPage'] instanceof PageModel) {
            return $buffer;
        }

        // 🔹 Marker aus vorherigen Listenern übernehmen
        $markers = $GLOBALS['MEILISEARCH_MARKERS'] ?? [];

        $page = $GLOBALS['objPage'];

        // --- Page-Daten ---
        $pagePriority = (int) ($page->priority ?? 0);
        $pageKeywords = trim((string) ($page->keywords ?? ''));

        // --- Page searchimage → Fallback ---
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

        // Page-Marker nur ergänzen (niemals löschen)
        $markers['page'] = array_merge(
            $markers['page'] ?? [],
            [
                'priority'    => $pagePriority > 0 ? $pagePriority : null,
                'keywords'    => $pageKeywords !== '' ? $pageKeywords : null,
                'searchimage' => $searchImageUuid ?: null,
            ]
        );

        // 🔹 Ausgabe bauen
        $lines = ['MEILISEARCH'];

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

        // Wenn nichts drinsteht → nichts anhängen
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