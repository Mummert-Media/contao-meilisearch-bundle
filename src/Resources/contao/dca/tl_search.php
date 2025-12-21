<?php

$GLOBALS['TL_DCA']['tl_meilisearch'] = [
    'config' => [
        'dataContainer' => 'Table',
        'sql' => [
            'keys' => [
                'id'  => 'primary',
                'pid' => 'unique',
            ],
        ],
    ],

    'fields' => [
        'id' => [
            'sql' => "int(10) unsigned NOT NULL auto_increment",
        ],
        'pid' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'source' => [
            'sql' => "varchar(32) NOT NULL default ''",
        ],
        'priority' => [
            'sql' => "int(1) NOT NULL default 2",
        ],
        'keywords' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'imagepath' => [
            'sql' => "varchar(512) NOT NULL default ''",
        ],
        'startDate' => [
            'sql' => "bigint(20) NOT NULL default 0",
        ],
        'checksum' => [
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
    ],
];