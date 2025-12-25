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
                'url'      => 'index',
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

        /*
         * Absolute oder normalisierte PDF-URL
         * z. B. /files/pdf/foo.pdf
         */
        'url' => [
            'sql' => "varchar(1024) NOT NULL default ''",
        ],

        /*
         * Linktext oder Dateiname
         */
        'title' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],

        /*
         * Geparster PDF-Text
         */
        'text' => [
            'sql' => "mediumtext NULL",
        ],

        /*
         * md5(url + filemtime)
         * → erkennt Änderungen zuverlässig
         */
        'checksum' => [
            'sql' => "char(32) NOT NULL default ''",
        ],

        /*
         * Herkunftsseite (tl_page.id)
         * → Cleanup / Referenz
         */
        'page_id' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],

        /*
         * Dateizeitstempel der PDF
         * → optional, aber extrem hilfreich
         */
        'file_mtime' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
    ],
];