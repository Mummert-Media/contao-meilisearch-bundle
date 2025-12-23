<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchPageMarkerListener;


$GLOBALS['TL_HOOKS']['outputFrontendTemplate'][] = [
    MeilisearchPageMarkerListener::class,
    'onOutputFrontendTemplate',
];



use MummertMedia\ContaoMeilisearchBundle\EventListener\IndexPageListener;

$GLOBALS['TL_HOOKS']['indexPage'][] = [
    IndexPageListener::class,
    'onIndexPage',
];

