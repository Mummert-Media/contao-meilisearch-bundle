<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\System;

/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_host'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'rgxp' => 'url',
        'tl_class' => 'w50',
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'tl_class' => 'w50',
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_api'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'tl_class' => 'w50',
        'hideInput' => true,
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_imagesize'] = [
    'inputType' => 'select',
    'options_callback' => static function () {
        $db = System::getContainer()->get('database_connection');
        $rows = $db->fetchAllAssociative('SELECT id, name FROM tl_image_size ORDER BY name');

        $options = ['' => '-'];
        foreach ($rows as $row) {
            $options[$row['id']] = $row['name'] . ' (ID ' . $row['id'] . ')';
        }

        return $options;
    },
    'eval' => [
        'tl_class' => 'w50',
        'chosen' => true,
        'includeBlankOption' => true,
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index_past_events'] = [
    'inputType' => 'checkbox',
    'eval'      => [
        'tl_class' => 'w50 clr',
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_fallback_image'] = [
    'inputType' => 'fileTree',
    'eval' => [
        'filesOnly' => true,
        'fieldType' => 'radio',
        'tl_class' => 'w50',
    ],
    'sql' => "varbinary(16) NULL",
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index_pdfs'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_pdfs'],
    'inputType' => 'checkbox',
    'eval'      => [
        'tl_class' => 'w50',
    ],
    'sql'       => "char(1) NOT NULL default '1'",
];

/**
 * Palette
 */
PaletteManipulator::create()
    ->addLegend('meilisearch_legend', null, PaletteManipulator::POSITION_AFTER, true)
    ->addField('meilisearch_host', 'meilisearch_legend')
    ->addField('meilisearch_index', 'meilisearch_legend')
    ->addField('meilisearch_api', 'meilisearch_legend')
    ->addField('meilisearch_imagesize', 'meilisearch_legend')
    ->addField('meilisearch_fallback_image', 'meilisearch_legend')
    ->addField('meilisearch_index_past_events', 'meilisearch_legend')
    ->addField('meilisearch_index_pdfs', 'meilisearch_legend')
    ->applyToPalette('default', 'tl_settings');