<?php
if (!defined ('TYPO3_MODE')) {
    die ('Access denied.');
}

// Register cache
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_xmltool_recordcache'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_xmltool_recordcache'] = array();
}

// register scheduler task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Digicademy\Xmltool\Task\XmlExtractImportTask::class] = array(
    'extension' => 'xmltool',
    'title' => 'LLL:EXT:' . 'xmltool' . '/Resources/Private/Language/locallang_db.xlf:xmlExtractImportTask.title',
    'description' => 'LLL:EXT:' . 'xmltool' . '/Resources/Private/Language/locallang_db.xlf:xmlExtractImportTask.description',
);
