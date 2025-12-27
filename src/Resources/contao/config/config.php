<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\MeilisearchPageMarkerListener;


$GLOBALS['TL_HOOKS']['outputFrontendTemplate'][] = [
    MeilisearchPageMarkerListener::class,
    'onOutputFrontendTemplate',
];

