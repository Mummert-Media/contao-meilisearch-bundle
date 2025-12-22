<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        $debug = [];

        // 🔍 Event erkennen
        if (preg_match('#"@type"\s*:\s*"Event"#', $buffer)) {
            $debug[] = 'context=event';

            if (preg_match('#"#/schema/events/(\d+)"#', $buffer, $m)) {
                $debug[] = 'event.id=' . $m[1];
            } else {
                $debug[] = 'event.id=NOT_FOUND';
            }
        }

        // 🔍 News erkennen (vorbereitet)
        if (preg_match('#"@type"\s*:\s*"NewsArticle"#', $buffer)) {
            $debug[] = 'context=news';

            if (preg_match('#"#/schema/news/(\d+)"#', $buffer, $m)) {
                $debug[] = 'news.id=' . $m[1];
            } else {
                $debug[] = 'news.id=NOT_FOUND';
            }
        }

        if (empty($debug)) {
            return $buffer;
        }

        $marker =
            "\n<!--\n" .
            "MEILISEARCH DEBUG\n" .
            implode("\n", $debug) .
            "\n-->\n";

        return str_replace('</body>', $marker . '</body>', $buffer);
    }
}