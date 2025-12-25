<?php

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_search_pdf'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id'       => 'primary',
                'checksum' => 'unique',
                'page_id'  => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => [
            'sql' => "int(10) unsigned NOT NULL auto_increment",
        ],

        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],

        'url' => [
            'sql' => "varchar(1024) NOT NULL default ''",
        ],

        'title' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],

        'text' => [
            'sql' => "mediumtext NULL",
        ],

        'checksum' => [
            'sql' => "char(32) NOT NULL default ''",
        ],

        'page_id' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
    ],
];