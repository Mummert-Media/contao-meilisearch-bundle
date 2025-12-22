<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\IndexPageListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchPageMarkerListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchEventContextListener;


$GLOBALS['TL_HOOKS']['outputFrontendTemplate'][] = [
    MeilisearchPageMarkerListener::class,
    'onOutputFrontendTemplate',
];

$GLOBALS['TL_HOOKS']['indexPage'][] = [
    IndexPageListener::class,
    'onIndexPage'
];

$GLOBALS['TL_HOOKS']['parseFrontendTemplate'][] = [
    MeilisearchEventContextListener::class,
    'onParseFrontendTemplate',
];

if (!isset($GLOBALS['MEILISEARCH_MARKERS'])) {
    $GLOBALS['MEILISEARCH_MARKERS'] = [];
}