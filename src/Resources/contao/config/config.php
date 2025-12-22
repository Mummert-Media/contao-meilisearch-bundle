<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\IndexPageListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchPageMarkerListener;

$GLOBALS['TL_HOOKS']['outputFrontendTemplate'][] = [
    MeilisearchPageMarkerListener::class,
    'onOutputFrontendTemplate',
];

$GLOBALS['TL_HOOKS']['indexPage'][] = [
    IndexPageListener::class,
    'onIndexPage'
];