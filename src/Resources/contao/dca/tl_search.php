<?php

declare(strict_types=1);

$GLOBALS['TL_DCA']['tl_search']['fields']['keywords'] = [
    'label'     => ['Keywords', 'Suchbegriffe für die Indexierung'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'maxlength' => 255],
    'sql'       => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_search']['fields']['priority'] = [
    'label'     => ['Priorität', 'Priorität für die Suchergebnisse'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => [1, 2, 3],
    'eval'      => ['tl_class' => 'w50'],
    'sql'       => "int(1) NOT NULL default '2'",
];

$GLOBALS['TL_DCA']['tl_search']['fields']['imagepath'] = [
    'label'     => ['Image Path', 'Speichert den Pfad des Bildes'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['maxlength' => 512],
    'sql'       => "varchar(512) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_search']['fields']['startDate'] = [
    'label'     => ['Startdatum', 'Startdatum für die Suchergebnisse (Unix-Timestamp)'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'rgxp' => 'digit'],
    'sql'       => "bigint(20) NOT NULL default '0'",
];