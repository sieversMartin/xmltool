<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

return array(
    'ctrl' => array(
        'title' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xml:tx_xmltool_domain_model_schedulerjob',
        'label' => 'name',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'delete' => 'deleted',
        'enablecolumns' => array(
            'disabled' => 'hidden'
        ),
        'type' => 'type',
        'typeicon_column' => 'type',
        'typeicon_classes' => [
            'default' => 'tx-xmltool-schedulerjob',
            '1' => 'tx-xmltool-schedulerjob-extract',
            '2' => 'tx-xmltool-schedulerjob-import',
        ],
    ),
    'interface' => array(
        'showRecordFieldList' => 'type,name,description,configuration,set_import_job',
    ),
    'types' => array(
        '1' => array(
            'showitem' => 'hidden,type,name,description,configuration,set_import_job'
        ),
        '2' => array(
            'showitem' => 'hidden,type,name,description,configuration'
        ),
    ),
    'palettes' => array(
    ),
    'columns' => array(
        'hidden' => array(
            'exclude' => 1,
            'label' => 'LLL:EXT:lang/locallang_general.xml:LGL.hidden',
            'config' => array(
                'type' => 'check'
            )
        ),
        'type' => array(
            'label' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.type',
            'config' => array(
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => array(
                    array(
                        'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.type.I.1',
                        '1'
                    ),
                    array(
                        'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.type.I.2',
                        '2'
                    ),
                ),
                'size' => 1,
                'maxitems' => 1,
            )
        ),
        'name' => array(
            'label' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.name',
            'config' => array(
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim,required'
            )
        ),
        'description' => array(
            'label' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.description',
            'config' => array(
                'type' => 'text',
                'cols' => '30',
                'rows' => '3',
            ),
        ),
        'configuration' => array(
            'label' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.configuration',
            'config' => array(
                'type' => 'text',
                'cols' => '30',
                'rows' => '5',
            ),
        ),
        'set_import_job' => array(
            'exclude' => 1,
            'label' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_db.xlf:tx_xmltool_domain_model_schedulerjob.set_import_job',
            'config' => array(
                'type' => 'check'
            )
        ),
    ),
);
