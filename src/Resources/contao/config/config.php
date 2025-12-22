<?php

use MummertMedia\ContaoMeilisearchBundle\EventListener\IndexPageListener;

$GLOBALS['TL_HOOKS']['indexPage'][] = [IndexPageListener::class, 'onIndexPage'];