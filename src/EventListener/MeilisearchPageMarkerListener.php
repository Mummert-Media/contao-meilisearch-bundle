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

        // ✅ Vorhandene Marker (event/news) übernehmen
        $markers = $GLOBALS['MEILISEARCH_MARKERS'] ?? [];

        $page = $GLOBALS['objPage'];

        $priority = (int) ($page->priority ?? 0);
        $keywords = trim((string) ($page->keywords ?? ''));

        // ✅ searchimage: Page → Fallback
        $searchImageUuid = null;

        if (!empty($page->searchimage)) {
            $searchImageUuid = StringUtil::binToUuid($page->searchimage);
        }

        if (!$searchImageUuid) {
            $fallback = Config::get('meilisearch_fallback_image');
            if ($fallback) {
                // tl_settings speichert varbinary(16) → UUID
                $searchImageUuid = StringUtil::binToUuid($fallback);
            }
        }

        // ✅ Page-Marker NUR ergänzen, nicht alles überschreiben
        $markers['page'] = [
            'priority'    => $priority > 0 ? $priority : null,
            'keywords'    => $keywords !== '' ? $keywords : null,
            'searchimage' => $searchImageUuid ?: null,
        ];

        // ✅ Ausgabe bauen
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