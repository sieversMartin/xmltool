<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

if (TYPO3_MODE === 'BE') {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'web',
        'xmlimport',
        '',
        '',
        array(
            'routeTarget' => \Digicademy\Xmltool\Controller\XmlimportModuleController::class . '::mainAction',
            'access' => 'user,group',
            'name' => 'web_xmlimport',
            'icon' => 'EXT:xmltool/Resources/Public/Icons/module-xmlimport.svg',
            'labels' => array(
                'll_ref' => 'LLL:EXT:xmltool/Resources/Private/Language/locallang_mod_web_xmlimport.xlf',
            ),
        )
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::insertModuleFunction(
        'web_xmlimport',
        \Digicademy\Xmltool\Controller\XmlimportExtractController::class,
        null,
        'LLL:EXT:xmltool/Resources/Private/Language/locallang_mod_web_xmlimport.xlf:extract_function'
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::insertModuleFunction(
        'web_xmlimport',
        \Digicademy\Xmltool\Controller\XmlimportBatchimportController::class,
        null,
        'LLL:EXT:xmltool/Resources/Private/Language/locallang_mod_web_xmlimport.xlf:batchimport_function'
    );
}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_xmltool_domain_model_schedulerjob');

$iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
   \TYPO3\CMS\Core\Imaging\IconRegistry::class
);

$iconRegistry->registerIcon(
   'tx-xmltool-schedulerjob',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:xmltool/Resources/Public/Icons/schedulerjob.svg']
);

$iconRegistry->registerIcon(
   'tx-xmltool-schedulerjob-extract',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:xmltool/Resources/Public/Icons/schedulerjob_extract.svg']
);

$iconRegistry->registerIcon(
   'tx-xmltool-schedulerjob-import',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:xmltool/Resources/Public/Icons/schedulerjob_import.svg']
);
