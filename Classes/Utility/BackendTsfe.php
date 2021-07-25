<?php

namespace Digicademy\Xmltool\Utility;

/***************************************************************
 *  Copyright notice
 *
 *  Torsten Schrade <Torsten.Schrade@adwmainz.de>, Academy of Sciences and Literature | Mainz
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\TypoScript\ExtendedTemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

class BackendTsfe
{

    /**
     * Creates an instance of TSFE in the backend; needed for working with TypoScript in the backend
     *
     * @param int $pageId   The current import page
     */
    public function buildTsfe($pageId = 1)
    {

        // begin
        if (!is_object($GLOBALS['TT'])) {
            $GLOBALS['TT'] = GeneralUtility::makeInstance(TimeTracker::class);
            $GLOBALS['TT']->start();
        }

        if (!is_object($GLOBALS['TSFE']) && $pageId) {

            $uri = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\Uri::class, '/');
            $siteLanguage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\Entity\SiteLanguage::class, 0, 'en_US.UTF-8', $uri, []);

            // builds TSFE object
            $GLOBALS['TSFE'] = GeneralUtility::makeInstance(TypoScriptFrontendController::class,
            null, $pageId, $siteLanguage, ['id' => $pageId, 'type' => 0]);

            // builds sub objects
            $GLOBALS['TSFE']->tmpl = GeneralUtility::makeInstance(ExtendedTemplateService::class);
            $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);

            // set page record to TSFE regardless of pagetype (also include sys_folders for import)
            $page = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('pages')
                ->select(
                    ['*'], // fields
                    'pages', // from
                    [ 'uid' => (int)$pageId ] // where
                )->fetch();

            $GLOBALS['TSFE']->page = $page;

            // init template
            $GLOBALS['TSFE']->tmpl->tt_track = 0; // Do not log time-performance information

            // generates the constants/config + hierarchy info for the template
            $rootlineUtility = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Utility\RootlineUtility::class, $pageId);
            $rootLine = $rootlineUtility->get();

            $template_uid = null;
            $GLOBALS['TSFE']->tmpl->loaded = 1;

            // builds a cObj
            $GLOBALS['TSFE']->newCObj();
        }
    }
}
