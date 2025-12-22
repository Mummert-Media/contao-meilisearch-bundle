<?php

namespace MummertMedia\ContaoMeilisearchBundle\EventListener;

class IndexPageListener
{
    /**
     * Wird bei jeder Indexierung aufgerufen
     */
    public function onIndexPage(string $content, array &$data, array &$set): void
    {
        // absolut eindeutiger Beweis
        error_log('### MEILI TEST LISTENER CALLED ###');

        // optional: Kontext anzeigen
        error_log('### MEILI SET: ' . json_encode($set));
    }
}