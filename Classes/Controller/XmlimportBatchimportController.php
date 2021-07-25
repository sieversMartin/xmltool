<?php
namespace Digicademy\Xmltool\Controller;

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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

class XmlimportBatchimportController extends XmlimportModuleController
{
    /**
     * @var \Digicademy\Xmltool\Controller\XmlimportModuleController
     */
    public $pObj;

    /**
     * Main entry point of the batch import submodule
     *
     * @return string
     */
    public function main()
    {
        $output = '';

        if ($this->pObj->params['flush'] === 1) $this->pObj->cacheRemoveAll();

        $this->pObj->cacheLoadAndCleanRegistry();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_xmltool_domain_model_schedulerjob');
        $importJobOnPage = $queryBuilder
           ->select('uid')
           ->from('tx_xmltool_domain_model_schedulerjob')
           ->where(
               $queryBuilder->expr()->eq('type', 2),
               $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter((int)$this->pObj->id, \PDO::PARAM_INT))
           )
           ->execute()
           ->fetch();

        if ($this->pObj->conf['noBatchImport'] == 1) {
            $output .= '<p>' . $this->pObj->languageService->getLL('batchImportDeactivated') . '</p>';
        } elseif ($importJobOnPage) {
            $output .= '<p>' . $this->pObj->languageService->getLL('scheduledJobActive') . '</p>';
        } else {
            switch ($this->pObj->params['action']) {
                case 1:
                    $output .= $this->batchImportAction();
                    break;

                case 2:
                    $output .= $this->scheduleForImportAction();
                    break;

                default:
                    $output .= $this->displayNoticeAction();
                    break;
            }
        }
        return $output;
    }

    /**
     * Performs a batch import of extracted records
     *
     * @return string
     */
    public function batchImportAction()
    {
        $output = '';

        if ($this->pObj->keys) {

            foreach($this->pObj->keys as $key => $records) {

                // fetch data
                $this->pObj->getData($key);

                // import the record, destroy the current element in stack and to move on to the next record
                $this->pObj->performImport($this->pObj->currentRecord);

                // @TODO: error handling

                // remove record from cache
                $this->pObj->cacheRemoveSingle($key);

                unset($this->pObj->keys[$key]);
            }

            // import complete, remove registry
            $this->pObj->cacheLoadAndCleanRegistry();

            $output .= '<p>'.$this->pObj->languageService->getLL('importComplete').'</p>';
        }

        return $output;
    }

    /**
     * Schedules a queue of extracted records for import with the next scheduler run
     *
     * @return string
     */
    protected function scheduleForImportAction()
    {
        $output = '';

        $uid = 'NEW_'.uniqid('');
        $datamap = array(
            'tx_xmltool_domain_model_schedulerjob' => array(
                $uid => [
                    'pid' => $this->pObj->id,
                    'type' => 2,
                    'hidden' => 0,
                    'configuration' => '',
                    'name' => 'Import job set on ' . date('l jS \of F Y h:i:s A')
                ],
            )
        );

        // create job via TCEmain
        $tce = GeneralUtility::makeInstance(DataHandler::class);
        $tce->start($datamap, null);
        $tce->process_datamap();

        $output .= '<p>'.$this->pObj->languageService->getLL('importJobScheduled').'</p>';

        return $output;
    }

    /**
     * Displays notices in the top area of the module
     *
     * @return string
     */
    protected function displayNoticeAction()
    {
        $output = '';

        if ($this->pObj->keys) {

            $output .= '<p>'.sprintf($this->pObj->languageService->getLL('queueCount'), count($this->pObj->keys)).'</p>
                        <p>'.$this->pObj->languageService->getLL('wouldYouLike').'</p>
                        <p>
                            <a class="btn btn-default" href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'function' => 2, 'action' => 1, 'key' => 1)).'">'.$this->pObj->languageService->getLL('doBatchImport').'</a>
                            <a class="btn btn-default" href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'function' => 2, 'action' => 2, 'key' => 1)).'">'.$this->pObj->languageService->getLL('scheduleForImport').'</a>
                            <a class="btn btn-default" href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'flush' => 1)) . '">' . $this->pObj->languageService->getLL('flushQueue') . '</a>
                        </p>';

        } else {
            $output .= '<p>'.$this->pObj->languageService->getLL('pleasePerformExtractionFirst').'</p>';
        }

        return $output;
    }
}
