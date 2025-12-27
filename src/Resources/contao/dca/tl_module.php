<?php

$GLOBALS['TL_DCA']['tl_module']['palettes']['meilisearch_search'] =
    '{title_legend},name,type;
     {search_legend},meiliLimit;
     {protected_legend:hide},protected;
     {expert_legend:hide},cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['meiliLimit'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['meiliLimit'],
    'inputType' => 'text',
    'default' => 50,
    'eval' => [
        'rgxp' => 'digit',
        'mandatory' => true,
        'tl_class' => 'w50',
    ],
    'sql' => "int(10) unsigned NOT NULL default 50",
];