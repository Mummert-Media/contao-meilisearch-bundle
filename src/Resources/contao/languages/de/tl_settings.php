<?php

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_legend'] = 'Meilisearch Einstellungen';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_host'][0] = 'Meilisearch URL';
$GLOBALS['TL_LANG']['tl_settings']['meilisearch_host'][1] = 'URL der Meilisearch Instanz (z. B. https://search.domain.tld).';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index'][0] = 'Meilisearch Index';
$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index'][1] = 'Index in der Meilisearch Instanz.';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_api_write'][0] = 'API Write Key';
$GLOBALS['TL_LANG']['tl_settings']['meilisearch_api_write'][1] = 'API-Schlüssel für den Schreib-Zugriff auf Meilisearch.';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_api_search'][0] = 'API Search Key';
$GLOBALS['TL_LANG']['tl_settings']['meilisearch_api_search'][1] = 'API-Schlüssel für den Suche-Zugriff auf Meilisearch.';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_imagesize'][0] = 'Bildgröße für Vorschaubilder';
$GLOBALS['TL_LANG']['tl_settings']['meilisearch_imagesize'][1] = 'Bildgröße aus den Contao-Bildgrößen (tl_image_size).';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_fallback_image'] = [
    'Fallback-Bild für die Suche',
    'Dieses Bild wird verwendet, wenn für eine Seite, News oder ein Event kein Suchbild gesetzt ist.',
];

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_past_events'][0]
    = 'Abgelaufene Events indexieren';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_past_events'][1]
    = 'Vergangene Kalender-Events werden ebenfalls in Meilisearch indexiert.';

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_pdfs'] = [
    'PDFs indexieren',
    'Aktiviert die Indexierung von PDF-Dateien für die Suche.',
];

$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_office']
    = ['Office-Dateien indexieren', 'DOCX, XLSX und PPTX in die Suche aufnehmen.'];