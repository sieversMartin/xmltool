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

use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class XmlExtraction
{

    /**
     * @var \Digicademy\Xmltool\Controller\XmlimportModuleController
     */
    public $pObj;

    /**
     * XmlExtraction constructor.
     *
     * @param $pObj
     */
    public function __construct($pObj)
    {
        $this->pObj = $pObj;
    }

    /**
     * Reads XML from file, upload or URL
     *
     * @return bool
     */
    public function readXMLFromFile()
    {

        // get file either from submit or selection, tsconfig option is already in the background
        if ($_FILES['upload_file']['name']) {

            // file allowed?
            if (GeneralUtility::verifyFilenameAgainstDenyPattern($_FILES['upload_file']['name']) && $_FILES['upload_file']['type'] == 'text/xml') {

                // mv into typo3temp
                $this->pObj->conf['file'] = GeneralUtility::upload_to_tempfile($_FILES['upload_file']['tmp_name']);

                // not allowed!
            } else {
                unset($this->pObj->conf['file']);
                $message = GeneralUtility::makeInstance(FlashMessage::class,
                    $this->pObj->languageService->getLL('errmsg.forbiddenFile'), '', FlashMessage::ERROR, false);
                $this->pObj->messageQueue->addMessage($message);

            }

            // get file from URL
        } elseif (GeneralUtility::isValidUrl($this->pObj->params['postVars']['url_file']) === true) {
            $this->pObj->conf['file'] = $this->pObj->params['postVars']['url_file'];
            $validUrlFile = true;

            // get file from selection
        } elseif ($this->pObj->params['postVars']['select_file']) {
            $this->pObj->conf['file'] = $this->pObj->params['postVars']['select_file'];
        }

        // no file
        if (!$this->pObj->conf['file'] && !$this->pObj->modTSconfig['properties']['source.']['url'] && !$this->pObj->modTSconfig['properties']['source.']['file']) {
            $message = GeneralUtility::makeInstance(FlashMessage::class,
                $this->pObj->languageService->getLL('errmsg.noFile'), '', FlashMessage::ERROR, false);
            $this->pObj->messageQueue->addMessage($message);
        }

        // extraction: settings from source.url OR source.file take precedence over submitted form data
        if (GeneralUtility::isValidUrl($this->pObj->modTSconfig['properties']['source.']['url']) || $validUrlFile) {
            $fileContents = GeneralUtility::getURL($this->pObj->conf['file']);
        } else {
            $fileContents = GeneralUtility::getURL(GeneralUtility::getFileAbsFileName($this->pObj->conf['file']));
        }

        $result = $this->processXMLdata($fileContents);

        // unlink upload file
        if (strpos($this->pObj->conf['file'], 'typo3temp')) {
            GeneralUtility::unlink_tempfile($this->pObj->conf['file']);
        }

        // cache data if extraction worked
        if ($result) {
            // changed to just store the record keys into registry and not the fully extraacted array
            // save memory for large extraction tasks
            // $this->pObj->cacheInsertAll($result);
            $this->pObj->registry->set('web_xmlimport', $this->pObj->id, $result);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Extracts all specified tables/fieldnames from the submitted XMl data structure and converts them into a multidimensional array.
     * Contains two hooks for working on the extracted array: preProcessXMLData and postProcessXMLData.
     *
     * @param string          XML data
     * @param string/array    entry node
     * @param string          action that calls this method
     *
     * @return array          Multidimensional array with all extracted "records" and their associative key/value pairs
     */
    public function processXMLdata($xmlData, $entryNode = '', $callingAction = 'extract')
    {

        if (!$entryNode) {
            $entryNode = $this->pObj->conf['entryNode'];
        }

        // if there is data
        if ($xmlData != '') {

            // pre process hook before extraction
            if (count($this->pObj->hookObjectsArr) > 0) {
                foreach ($this->pObj->hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj, 'preProcessXMLData')) {
                        $hookObj->preProcessXMLData($xmlData, $this->pObj->conf['importConfiguration'], $this);
                    }
                }
            }

            // extract values from XML into a tables/fields array
            $xmlArray = $this->extractRecordsFromXML($xmlData, $entryNode, $this->pObj->conf['importConfiguration'],
                $callingAction);

            // if the XML conversion worked
            if (is_array($xmlArray)) {

                return $xmlArray;

            } else {
                // error messages already in queue from extraction function
                return [];
            }

            // no file found
        } else {

            // issue error
            $message = GeneralUtility::makeInstance(FlashMessage::class,
                $this->pObj->languageService->getLL('errmsg.noData'), '', FlashMessage::ERROR, false);
            $this->pObj->messageQueue->addMessage($message);

            return [];
        }
    }

    /**
     * Core function for TypoScript based XML extractions
     *
     * @param $xmlString
     * @param $entryNode
     * @param $configuration
     * @param $callingAction
     *
     * @return array
     */
    protected function extractRecordsFromXML($xmlString, $entryNode, $configuration, $callingAction)
    {

        // initialize result array
        $xmlArray = array();

        // initialize xml to process
        libxml_use_internal_errors(true);

        // possibly set specific constants for libxml parser configuration
        if (is_array($this->pObj->conf['libxml'])) {
            $options =
                (($this->pObj->conf['libxml']['bigLines'] == 1) ? LIBXML_BIGLINES : 0) |
                (($this->pObj->conf['libxml']['compact'] == 1) ? LIBXML_COMPACT : 0) |
                (($this->pObj->conf['libxml']['parseHuge'] == 1) ? LIBXML_PARSEHUGE : 0) |
                (($this->pObj->conf['libxml']['pedantic'] == 1) ? LIBXML_PEDANTIC : 0) |
                (($this->pObj->conf['libxml']['xInclude'] == 1) ? LIBXML_XINCLUDE : 0);
        } else {
            $options = 0;
        }

        $xml2Process = simplexml_load_string(
            $xmlString,
            'SimpleXMLElement',
            $options
        );

        if ($xml2Process instanceof \SimpleXMLElement) {

            // prepare for stdWrap supported field extraction
            $backendTsfe = GeneralUtility::makeInstance(BackendTsfe::class);
            $backendTsfe->buildTsfe($this->pObj->id);

            // register namespaces
            $xml2Process = $this->registerNamespace($xml2Process);

            // set method to retrieve the entry point
            (is_array($entryNode) && count($entryNode) > 0) ? $expression = $GLOBALS['TSFE']->cObj->stdWrap($entryNode['content'],
                $entryNode['conf']) : $expression = '//' . $entryNode;

            // fire XPATH
            $xmlRecords = $xml2Process->xpath($expression);

            // if the query had at least one match (depending on libxml XPATH might return empty array of false
            if ($xmlRecords) {

                // loop through all XML 'records'
                $i = 1;
                foreach ($xmlRecords as $index => $record) {

                    // break if extraction limit is reached
                    if ($this->pObj->conf['limit'] > 0 && $index == $this->pObj->conf['limit']) {
                        break;
                    }

                    // store simpleXML as array into $cObj->data; array conversion from soloman at http://www.php.net/manual/en/book.simplexml.php
                    $json = json_encode($record);
                    $GLOBALS['TSFE']->cObj->data = json_decode($json, true);
                    $GLOBALS['TSFE']->cObj->data['xml'] = $record->asXML();

                    // move on to extract the tables/fields
                    $extractedRecord = array();
                    foreach ($configuration as $tables => $settings) {

                        $tablename = substr($tables, 0, -1);

                        /* for each configured table it is possible to extract multiple records. What is considered a record
                         * can either be specified as a tagname or as xpath query. It has to be ensured that there is at least one iteration
                         * for all specified fields, otherwise foreach below would fail. Proceed as follows:
                         * - first of all check if a userFunc should be called to process the XML of the current record
                         * - otherwise the whole extraction is handled from TypoScript with the following steps:
                         * - if a tag is set in .recordNode, ask for this
                         * - if a xpath query is defined in .recordNode.expression, use this instead
                         * - regardless of the query the result will always be set to at least 1 to ensure field extraction
                         */
                        if ($settings['recordUserObject.']) {
                            $extractedRecord[$tablename] = $GLOBALS['TSFE']->cObj->callUserFunction(
                                $settings['recordUserObject.']['userFunc'],
                                $settings['recordUserObject.'],
                                $GLOBALS['TSFE']->cObj->data['xml']
                            );
                        } else {

                            $recordQuery = '';
                            $recordQueryResult = array();

                            // if recordNode was not set at all, set result to 1 - otherwise set XPATH expression as $recordQuery
                            ($settings['recordNode']) ? $recordQuery = $settings['recordNode'] : $recordQueryResult[0] = 1;

                            // if stdWrap is used, override the query
                            if ($settings['recordNode.']) {
                                $recordQuery = $GLOBALS['TSFE']->cObj->stdWrap($settings['recordNode'],
                                    $settings['recordNode.']);
                            }

                            // now do the XPATH query on the current $recordXML if a $recordQuery was defined
                            if ($recordQuery) {
                                $recordXML = simplexml_load_string($GLOBALS['TSFE']->cObj->data['xml']);
                                $recordXML = $this->registerNamespace($recordXML);
                                $recordQueryResult = $recordXML->xpath($recordQuery);
                            }

                            // no result from xpath query ? issue an error and set result to 1
                            if (!is_array($recordQueryResult) && false == $recordQueryResult) {
                                $recordQueryResult[0] = 1;
                                $message = GeneralUtility::makeInstance(FlashMessage::class,
                                    $this->pObj->languageService->getLL('errmsg.recordNodeNotFound'), '',
                                    FlashMessage::ERROR, false);
                                $this->pObj->messageQueue->addMessage($message);
                            }

                            $skipIfEmptyFields = array();
                            if ($configuration[$tables]['skipIfEmptyFields']) {
                                $skipIfEmptyFields = GeneralUtility::trimExplode(',',
                                    $configuration[$tables]['skipIfEmptyFields']);
                            }

                            // now extract the records/fields for the current table
                            foreach ($recordQueryResult as $key => $result) {

                                $GLOBALS['TSFE']->register['RECORD_NODE_ITERATION'] = $key;

                                foreach ($settings['fields.'] as $field => $value) {
                                    $fieldname = substr($field, 0, -1);
                                    $content = $GLOBALS['TSFE']->cObj->cObjGetSingle('TEXT', $value);
                                    if (!$content && in_array($fieldname, $skipIfEmptyFields)) {
                                        continue;
                                    } else {
                                        $extractedRecord[$tablename][$key][$fieldname] = $content;
                                    }
                                }
                            }
                        }

                        // .if TypoScript implementation for each table - remove the current table from this record completely if .if returns false
                        // has to be done here to take into account any TSFE values that might have been created during field extraction
                        if (is_array($settings['if.'])) {
                            $checkIf = $GLOBALS['TSFE']->cObj->checkIf($settings['if.']);
                            if ($checkIf == false) {
                                unset($extractedRecord[$tablename]);
                            }
                        }

                    }

                    // set the XML data for later reloading of the record - remove any xml header set by asXML, we just want the record XML
                    $recordXML = trim(str_replace('<?xml version="1.0"?>', '', $record->asXML()));
                    $extractedRecord['###XML###'][0]['source'] = $recordXML;

                    // put the extracted record into cache
                    // $xmlArray[$i] = $extractedRecord;
                    // changed by @schradt on 2018-10-24: new strategy is to directly cache each extracted record
                    // and just return the key full key array => save memory for large extraction jobs

                    switch ($callingAction) {
                        case 'reload':
                            $xmlArray[$this->pObj->currentKey] = $this->pObj->currentKey;
                            $this->pObj->cacheInsertSingle($this->pObj->currentKey, $extractedRecord);
                            break;
                        default:
                            $xmlArray[$i] = $i;
                            $this->pObj->cacheInsertSingle($i, $extractedRecord);
                            break;
                    }

                    $i++;
                }
            } else {
                // issue error
                $message = GeneralUtility::makeInstance(FlashMessage::class,
                    $this->pObj->languageService->getLL('errmsg.entryNodeNotFound'), '', FlashMessage::ERROR, false);
                $this->pObj->messageQueue->addMessage($message);
            }


            // handle XML errors
        } else {
            $errors = libxml_get_errors();
            foreach ($errors as $error) {
                switch ($error->level) {
                    case LIBXML_ERR_WARNING:
                        $messageText = 'XML Warning ' . $error->code . ': ' . trim($error->message) . ' / Line: ' . $error->line . ' / Column: ' . $error->column;
                        $message = GeneralUtility::makeInstance(FlashMessage::class, $messageText, '',
                            FlashMessage::WARNING, false);
                        $this->pObj->messageQueue->addMessage($message);
                        break;
                    case LIBXML_ERR_ERROR:
                    case LIBXML_ERR_FATAL:
                        $messageText = 'XML Error ' . $error->code . ': ' . trim($error->message) . ' / Line: ' . $error->line . ' / Column: ' . $error->column;
                        $message = GeneralUtility::makeInstance(FlashMessage::class, $messageText, '',
                            FlashMessage::ERROR, false);
                        $this->pObj->messageQueue->addMessage($message);
                        break;
                }
            }
            libxml_clear_errors();
            $xmlArray = array();
        }

        return $xmlArray;
    }

    /**
     * Registers a namespace for the XPath query on the entryNode
     *
     * @param \SimpleXMLElement $xml2Process
     *
     * @return \SimpleXMLElement
     */
    protected function registerNamespace(\SimpleXMLElement $xml2Process)
    {

        if ($this->pObj->conf['registerNamespace']) {
            $namespace = GeneralUtility::trimExplode('|', $this->pObj->conf['registerNamespace'], 1);
            if (count($namespace) == 2 && GeneralUtility::isValidUrl($namespace[1])) {
                $xml2Process->registerXPathNamespace($namespace[0], $namespace[1]);
            }
        }

        return $xml2Process;
    }
}
