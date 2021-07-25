<?php

namespace Digicademy\Xmltool\Task;

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

use Digicademy\Xmltool\Controller\XmlimportBatchimportController;
use Digicademy\Xmltool\Controller\XmlimportModuleController;
use Digicademy\Xmltool\Utility\XmlExtraction;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class XmlExtractImportTask extends AbstractTask
{

    /**
     * Executes the scheduler job
     *
     * @return boolean
     * @throws
     */
    public function execute()
    {
        $executionResult = true;

        $this->performExtractJobs();
        $this->performImportJobs();

        return $executionResult;
    }

    /**
     * Performs the XML extraction during job execution
     *
     * @return void
     * @throws
     */
    private function performExtractJobs()
    {

        // @TODO: migrate to Doctrine DBAL
        $extractJobRows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
            '*',
            'tx_xmltool_domain_model_schedulerjob',
            'type=1 AND hidden=0 AND deleted=0',
            '',
            'pid,sorting ASC'
        );

        if ($extractJobRows) {

            $extractJobs = [];
            foreach ($extractJobRows as $row) {
                $extractJobs[$row['pid']][] = $row;
            }

            foreach ($extractJobs as $pid => $jobs) {
                foreach ($jobs as $job) {

                    $pObj = GeneralUtility::makeInstance(XmlimportModuleController::class);
                    $pObj->id = $pid;

                    $pObj->modTSconfig = $this->getMergedModTSconfig($pid, $job);
                    $pObj->initializeConfiguration();

                    // in scheduler mode we need to set a source file non interactively
                    // this can either be a file pointer or a url (no directory selection here)
                    if ($pObj->modTSconfig['properties']['source.']['file']) {
                        $pObj->conf['file'] = $pObj->modTSconfig['properties']['source.']['file'];
                    } elseif ($pObj->modTSconfig['properties']['source.']['url']) {
                        $pObj->conf['file'] = $pObj->modTSconfig['properties']['source.']['url'];
                    } else {
                        throw new \TYPO3\CMS\Core\Exception('No XML source (either file or url) available for job with uid' . (int)$job['uid'], 1540639633);
                    }

                    $pObj->cacheLoadAndCleanRegistry();

                    $xmlExtraction = GeneralUtility::makeInstance(XmlExtraction::class, $pObj);
                    $xmlExtraction->readXMLFromFile();

                    if ($job['set_import_job']) {
                        $this->performImport($pid, $job);
                    }
                }
            }
        }
    }

    /**
     * Performs a pipeline of XML import jobs
     *
     * @return void
     */
    private function performImportJobs()
    {
        // @TODO: migrate to Doctrine DBAL
        $importJobRows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
            '*',
            'tx_xmltool_domain_model_schedulerjob',
            'type=2 AND hidden=0 AND deleted=0',
            '',
            'pid,sorting ASC'
        );

        if ($importJobRows) {

            $importJobs = [];
            foreach ($importJobRows as $row) {
                $importJobs[$row['pid']][] = $row;
            }

            foreach ($importJobs as $pid => $jobs) {
                foreach ($jobs as $job) {

                    $this->performImport($pid, $job);

                    // @TODO: migrate to Doctrine DBAL
                    $GLOBALS['TYPO3_DB']->exec_DELETEquery(
                        'tx_xmltool_domain_model_schedulerjob',
                        'uid=' . (int)$job['uid']
                    );

                }
            }
        }
    }

    /**
     * Performs a single XML import job
     *
     * @param $pid
     * @param $job
     */
    private function performImport($pid, $job)
    {
        $pObj = GeneralUtility::makeInstance(XmlimportModuleController::class);
        $pObj->id = $pid;

        $pObj->modTSconfig = $this->getMergedModTSconfig($pid, $job);

        $pObj->initializeConfiguration();
        $pObj->cacheLoadAndCleanRegistry();

        $batchImportController = GeneralUtility::makeInstance(XmlimportBatchimportController::class);
        $batchImportController->pObj = $pObj;
        $batchImportController->batchImportAction();
    }

    /**
     * Merges TSConfig for extraction/import from page and scheduler job record
     *
     * @param integer $pid
     * @param array   $job
     *
     * @return array
     */
    private function getMergedModTSconfig($pid, $job)
    {
        $rootline = BackendUtility::BEgetRootLine($pid, '', true);

        if ($job['configuration']) $rootline[] = ['TSconfig' => $job['configuration']];

        krsort($rootline);

        $pageTSconfig = BackendUtility::getPagesTSconfig($pid, $rootline);

        $modTSconfig = [
            'value' => null,
            'properties' => $pageTSconfig['mod.']['web_xmlimport.']
        ];

        return $modTSconfig;
    }

}
