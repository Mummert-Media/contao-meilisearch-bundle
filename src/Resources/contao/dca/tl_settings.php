<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\System;

/**
 * -------------------------------------------------
 * Fields
 * -------------------------------------------------
 */

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_host'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'rgxp'      => 'url',
        'tl_class'  => 'w50',
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'tl_class'  => 'w50',
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_api_write'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'tl_class'  => 'w50',
        'hideInput' => true,
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_api_search'] = [
    'inputType' => 'text',
    'eval' => [
        'mandatory' => true,
        'tl_class'  => 'w50',
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
        'tl_class'            => 'w50',
        'chosen'              => true,
        'includeBlankOption'  => true,
    ],
    'sql' => "int(10) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_fallback_image'] = [
    'inputType' => 'fileTree',
    'eval' => [
        'filesOnly' => true,
        'fieldType' => 'radio',
        'tl_class'  => 'w50',
    ],
    'sql' => "varbinary(16) NULL",
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index_past_events'] = [
    'inputType' => 'checkbox',
    'eval' => [
        'tl_class' => 'w50 clr',
    ],
];

/**
 * PDF / Office Indexierung
 */

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index_pdfs'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_pdfs'],
    'inputType' => 'checkbox',
    'eval' => [
        'tl_class'       => 'w50',
        'submitOnChange' => true,
    ],
    'sql' => "char(1) NOT NULL default '1'",
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_index_office'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['meilisearch_index_office'],
    'inputType' => 'checkbox',
    'eval' => [
        'tl_class'       => 'w50',
        'submitOnChange' => true,
    ],
    'sql' => "char(1) NOT NULL default '0'",
];

/**
 * Tika URL (nur sichtbar wenn PDFs oder Office aktiv)
 */

$GLOBALS['TL_DCA']['tl_settings']['fields']['meilisearch_tika_url'] = [
    'inputType' => 'text',
    'eval' => [
        'rgxp'      => 'url',
        'mandatory' => true,
        'tl_class'  => 'w50 clr',
    ],
];

/**
 * -------------------------------------------------
 * Selector / Subpalettes
 * -------------------------------------------------
 */

$GLOBALS['TL_DCA']['tl_settings']['palettes']['__selector__'][] = 'meilisearch_index_pdfs';
$GLOBALS['TL_DCA']['tl_settings']['palettes']['__selector__'][] = 'meilisearch_index_office';

$GLOBALS['TL_DCA']['tl_settings']['subpalettes']['meilisearch_index_pdfs']
    = 'meilisearch_tika_url';

$GLOBALS['TL_DCA']['tl_settings']['subpalettes']['meilisearch_index_office']
    = 'meilisearch_tika_url';

/**
 * -------------------------------------------------
 * Palette
 * -------------------------------------------------
 */

PaletteManipulator::create()
    ->addLegend('meilisearch_legend', null, PaletteManipulator::POSITION_AFTER, true)
    ->addField('meilisearch_host', 'meilisearch_legend')
    ->addField('meilisearch_index', 'meilisearch_legend')
    ->addField('meilisearch_api_write', 'meilisearch_legend')
    ->addField('meilisearch_api_search', 'meilisearch_legend')
    ->addField('meilisearch_imagesize', 'meilisearch_legend')
    ->addField('meilisearch_fallback_image', 'meilisearch_legend')
    ->addField('meilisearch_index_past_events', 'meilisearch_legend')
    ->addField('meilisearch_index_pdfs', 'meilisearch_legend')
    ->addField('meilisearch_index_office', 'meilisearch_legend')
    ->applyToPalette('default', 'tl_settings');

/**
 * -------------------------------------------------
 * Optional: Absicherung beim Speichern
 * -------------------------------------------------
 */

$GLOBALS['TL_DCA']['tl_settings']['config']['onsubmit_callback'][] = static function () {
    $indexPdf    = (bool) \Contao\Config::get('meilisearch_index_pdfs');
    $indexOffice = (bool) \Contao\Config::get('meilisearch_index_office');
    $tikaUrl     = \Contao\Config::get('meilisearch_tika_url');

    if (($indexPdf || $indexOffice) && !$tikaUrl) {
        throw new \RuntimeException(
            'Die Tika-URL ist erforderlich, wenn PDF- oder Office-Indexierung aktiviert ist.'
        );
    }
};