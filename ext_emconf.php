<?php

$EM_CONF[$_EXTKEY] = array(
    'title' => 'XML Tool',
    'description' => 'A flexible tool for XML imports into TYPO3',
    'category' => 'Digitale Akademie',
    'author' => 'Torsten Schrade',
    'author_email' => 'Torsten.Schrade@adwmainz.de',
    'author_company' => 'Academy of Sciences and Literature | Mainz',
    'shy' => '',
    'priority' => '',
    'module' => '',
    'state' => 'stable',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'modify_tables' => '',
    'clearCacheOnLoad' => 0,
    'lockType' => '',
    'version' => '1.0.0',
    'constraints' => array(
        'depends' => array(
            'typo3' => '9.5.0-10.4.99',
            'typo3db_legacy' => ''
        ),
        'conflicts' => array(),
        'suggests' => array(
            'cobj_xpath' => '',
            'cobj_xslt' => '',
        ),
    ),
);
