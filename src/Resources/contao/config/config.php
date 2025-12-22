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

$GLOBALS['TL_HOOKS']['parseTemplate'][] = [
    MeilisearchNewsMarkerListener::class,
    'onParseTemplate',
];

$GLOBALS['TL_HOOKS']['parseTemplate'][] = [
    MeilisearchEventMarkerListener::class,
    'onParseTemplate',
];

$GLOBALS['MEILISEARCH_MARKERS'] = [];