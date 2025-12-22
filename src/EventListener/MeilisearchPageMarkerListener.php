<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class MeilisearchPageMarkerListener
{
    public function onOutputFrontendTemplate(string $buffer, string $template): string
    {
        $debug = [];

        if (preg_match(
            '#\{[^}]*"@type"\s*:\s*"Event"[^}]*\}#s',
            $buffer,
            $eventBlock
        )) {
            $debug[] = 'context=event';

            // ✅ KORREKT für deinen Buffer
            if (preg_match(
                '#\\\/schema\\\/events\\\/(\d+)#',
                $eventBlock[0],
                $m
            )) {
                $debug[] = 'event.id=' . $m[1];
            } else {
                $debug[] = 'event.id=NOT_FOUND';
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