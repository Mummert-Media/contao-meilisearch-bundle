<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\IndexPageListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchPageMarkerListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchNewsMarkerListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchEventMarkerListener;

$GLOBALS['TL_HOOKS']['outputFrontendTemplate'][] = [
    MeilisearchPageMarkerListener::class,
    'onOutputFrontendTemplate',
];

$GLOBALS['TL_HOOKS']['indexPage'][] = [
    IndexPageListener::class,
    'onIndexPage'
];

$GLOBALS['TL_HOOKS']['parseFrontendTemplate'][] = [
    MeilisearchNewsMarkerListener::class,
    'onParseFrontendTemplate',
];

$GLOBALS['TL_HOOKS']['parseFrontendTemplate'][] = [
    MeilisearchEventMarkerListener::class,
    'onParseFrontendTemplate',
];

$GLOBALS['MEILISEARCH_MARKERS'] = [];