<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\IndexPageListener;
use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchPageMarkerListener;

$GLOBALS['TL_HOOKS']['generatePage'][] = [
    MeilisearchPageMarkerListener::class,
    'onGeneratePage'
];

$GLOBALS['TL_HOOKS']['indexPage'][] = [
    IndexPageListener::class,
    'onIndexPage'
];