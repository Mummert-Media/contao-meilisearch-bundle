<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$dca = &$GLOBALS['TL_DCA']['tl_calendar_events'];

if (isset($dca['palettes']['default'])) {
    PaletteManipulator::create()
        ->addLegend('meilisearch_legend', 'pal_expert_legend', PaletteManipulator::POSITION_AFTER)
        ->addField('priority', 'meilisearch_legend')
        ->addField('keywords', 'meilisearch_legend')
        ->applyToPalette('default', 'tl_calendar_events');
}

/**
 * Priority
 */
$dca['fields']['priority'] = [
    'inputType' => 'select',
    'options'   => [1, 2, 3],
    'reference' => &$GLOBALS['TL_LANG']['MSC']['meilisearch_priority'],
    'default'   => 2,
    'eval'      => ['tl_class' => 'w50'],
    'sql'       => "int(1) NOT NULL default '2'"
];

/**
 * Keywords
 */
$dca['fields']['keywords'] = [
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'maxlength' => 255],
    'sql'       => "varchar(255) NOT NULL default ''"
];