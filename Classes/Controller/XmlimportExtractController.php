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

use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\TypoScript\ExtendedTemplateService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems;
use TYPO3\CMS\Backend\Form\FormResultCompiler;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use Digicademy\Xmltool\Utility\BackendTsfe;
use Digicademy\Xmltool\Utility\XmlExtraction;
use TYPO3\CMS\Backend\Routing\UriBuilder;

class XmlimportExtractController
{
    /**
     * @var XmlimportModuleController
     */
    public $extObj;

    /**
     * Can be hardcoded to the name of a locallang.xlf file (from the same directory as the class file) to use/load
     * and is included / added to $GLOBALS['LOCAL_LANG']
     *
     * @see init()
     * @var string
     */
    public $localLangFile = '';

    /**
     * Contains module configuration parts from TBE_MODULES_EXT if found
     *
     * @see handleExternalFunctionValue()
     * @var array
     */
    public $extClassConf;

    /**
     * If this value is set it points to a key in the TBE_MODULES_EXT array (not on the top level..) where another classname/filepath/title can be defined for sub-subfunctions.
     * This is a little hard to explain, so see it in action; it used in the extension 'func_wizards' in order to provide yet a layer of interfacing with the backend module.
     * The extension 'func_wizards' has this description: 'Adds the 'Wizards' item to the function menu in Web>Func. This is just a framework for wizard extensions.' - so as you can see it is designed to allow further connectivity - 'level 2'
     *
     * @see handleExternalFunctionValue(), \TYPO3\CMS\FuncWizards\Controller\WebFunctionWizardsBaseController
     * @var string
     */
    public $function_key = '';

    /**
     * @var PageRenderer
     */
    protected $pageRenderer;

    /**
     * @var \Digicademy\Xmltool\Controller\XmlimportModuleController
     */
    public $pObj;

    /**
     * @var \TYPO3\CMS\Core\Imaging\IconFactory
     */
    protected $iconFactory;

    /**
     * @var UriBuilder
     */
    public $uriBuilder;

    /**
     * XmlimportExtractController constructor.
     */
    public function __construct()
    {
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
    }

    /**
     * Main entry point of the extraction submodule
     *
     * @return string
     */
    public function main()
    {
        $output = '';

        if ($this->pObj->params['flush'] === 1) $this->pObj->cacheRemoveAll();

        $this->pObj->cacheLoadAndCleanRegistry();

        switch ($this->pObj->params['action']) {
            // extraction and preview
            case 1:

                // if records have been extracted $this->pObj->keys will be filled - decide on cmd what to do
                if (is_array($this->pObj->keys) === TRUE && array_key_exists('cmd', $this->pObj->params) === TRUE) {

                    switch ($this->pObj->params['cmd']) {

                        // import happens as soon as the import button is clicked
                        case 'import':
                                $output .= $this->importAction();
                            break;

                        // edit happens when an edit link is clicked or when editet content is resubmitted
                        case 'edit':
                                $output .= $this->editAction();
                            break;

                        // current record will be re-extracted from XML stored within the record data (NOT from file/source...)
                        case 'reload':
                                $output .= $this->reloadAction();
                            break;

                        // default happens when prev/next is clicked
                        default:
                                $output .= $this->previewAction();
                            break;
                    }

                    // if $this->pObj->keys is not filled but the param signals XML submission, perform an extraction
                } elseif (is_array($this->pObj->keys) === FALSE && $this->pObj->params['postVars']['submitForm'] == '1') {

                    $output .= $this->extractAction();

                    // no valid keys and records cache exist, display warning
                } else {
                    $message = GeneralUtility::makeInstance(FlashMessage::class, $this->pObj->languageService->getLL('errmsg.cacheHasExpired'), '', FlashMessage::WARNING, FALSE);
                    $this->pObj->messageQueue->addMessage($message);
                    $output .= $this->showSubmitForm();
                }

                break;

            // show the form for data submission or a note that there are still records to be imported
            default:

                // $output .= $this->pObj->languageService->getLL('importSingle');

                if ($this->pObj->keys) {
                    $output .= $this->showImportNotice();
                } else {
                    $output .= $this->showSubmitForm();
                }

                break;
        }

        return $output;
    }


    ####### ACTIONS ###########


    /**
     * Retrieves XML from file, upload or URL and extracts records based on TypoScript configuration
     *
     * @return string
     */
    public function extractAction()
    {
        $output = '';

        // get records from XML source (this only happens once)
        $xmlExtraction = GeneralUtility::makeInstance(XmlExtraction::class, $this->pObj);
        $extraction = $xmlExtraction->readXMLFromFile();

        // set current record to the first extracted record
        if ($extraction === TRUE) {
            $this->pObj->getData(1);
            if ($this->pObj->currentRecord) {
                // display record
                $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>
                            <p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';

                // buttons top
                if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
                if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

                // show
                $output .= $this->displaySingleRecord($this->pObj->currentRecord);

                // hidden fields
                $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

                // buttons bottom
                if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
                if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();
            }
        }

        return $output;
    }

    /**
     * Imports extracted records into database
     *
     * @return string
     */
    protected function importAction()
    {
        $output = '';

        // fetch data
        $this->pObj->getData($this->pObj->params['key']);

        // import the record, destroy the current element in stack and to move on to the next record
        $this->pObj->performImport($this->pObj->currentRecord);

        // remove record from cache
        $this->pObj->cacheRemoveSingle($this->pObj->params['key']);

        // should any errors occur during import, keep current record identifier
        $recordIdentifier = $this->pObj->keys[$this->pObj->params['key']];

        // update the $key index
        unset($this->pObj->keys[$this->pObj->params['key']]);
        $this->pObj->keys = array_values($this->pObj->keys);
        array_unshift($this->pObj->keys, 'x');
        unset($this->pObj->keys[0]);

        /* make sure that $this->nexKey doesn't point beyond remaining key stack; example: if there are three remaining records left
         * and record two is imported above, then only two records remain but $this->pObj->nextKey still points to 3
         */
        if (!$this->pObj->errorMsgs && $this->pObj->nextKey > count($this->pObj->keys)) $this->pObj->nextKey = '';

        // update key registry
        $this->pObj->registry->set('web_xmlimport', $this->pObj->id, $this->pObj->keys);

        // check that the cache lifetime is still valid
        $this->pObj->cacheLoadAndCleanRegistry();
        if (is_array($this->pObj->keys) === FALSE) {
            $message = GeneralUtility::makeInstance(FlashMessage::class, $this->pObj->languageService->getLL('errmsg.cacheHasExpired'), '', FlashMessage::WARNING, FALSE);
            $this->pObj->messageQueue->addMessage($message);
            $output .= $this->showSubmitForm();
            return $output;
        }

        // if there are TCEMAIN errors, turn back the changes from above and display the imported record again and the error messages
        if (is_array($this->pObj->errorMsgs) && count($this->pObj->errorMsgs) > 0) {

            // insert the imported element identifier back into the import stack
            if (count($this->pObj->keys) > 0) {
                $reindex = array();
                foreach ($this->pObj->keys as $k => $v) {
                    if ($k == $this->pObj->params['key']) $reindex[] = $recordIdentifier;
                    $reindex[] = $v;
                }
                // if the array key doesnt't exist, import direction was moving backwards and it has to be appended to the end of the stack
                if (array_key_exists($this->pObj->params['key'], $reindex) === FALSE) $reindex[$this->pObj->params['key']] = $recordIdentifier;
                array_unshift($reindex, 'x');
                unset($reindex[0]);
                $this->pObj->keys = $reindex;
                // if this is the last remaining record in the stack, make sure that $this->pObj->keys is set at all
            } else {
                $this->pObj->keys[1] = $recordIdentifier;
            }

            // now update registry and cache
            $this->pObj->registry->set('web_xmlimport', $this->pObj->id, $this->pObj->keys);
            $this->pObj->cacheInsertSingle($this->pObj->params['key'], $this->pObj->currentRecord);

            // display the errors from TCEMAIN
            foreach ($this->pObj->errorMsgs as $error) {
                $message = GeneralUtility::makeInstance(FlashMessage::class, $error, '', FlashMessage::ERROR, FALSE);
                $this->pObj->messageQueue->addMessage($message);
            }

            // re-fetch the record for display
            $this->pObj->getData($this->pObj->params['key']);

            // prepare to show it again
            $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>
                        <p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';

            // buttons top
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

            // show
            $output .= $this->displaySingleRecord($this->pObj->currentRecord);

            // insert hidden fields
            $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

            // record successfully imported, show next record
        } else {

            // display infos
            $importCount = count($this->pObj->keys);
            $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>';

            // stop if the last record has been imported
            if (!$importCount) {
                $output .= '<p>'.$this->pObj->languageService->getLL('importComplete').'</p>';
                return $output;
            }

            // buttons top
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

            // get the next record for display
            if ($this->pObj->params['key'] === 1) {
                // begin of import stack - next key stays at 1
                $this->pObj->getData(1);
                $output .= '<p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';
                $output .= $this->displaySingleRecord($this->pObj->currentRecord);
            } elseif ($this->pObj->nextKey) {
                // somewhere in between - show the next record in stack (which will now be at the same position in the key stack as the imported record)
                $this->pObj->getData($this->pObj->currentKey);
                $output .= '<p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';
                $output .= $this->displaySingleRecord($this->pObj->currentRecord);
                // end of import stack - show previous record
            } else {
                $this->pObj->getData($this->pObj->prevKey);
                $output .= '<p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';
                $output .= $this->displaySingleRecord($this->pObj->currentRecord);
            }

            // hidden fields
            $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

            // buttons bottom
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();
        }

        return $output;
    }

    /**
     * Provides edit functionality for extracted records in preview module
     *
     * @return string
     */
    protected function editAction()
    {
        $output = '';

        // fetch data
        $this->pObj->getData($this->pObj->params['key']);

        // case when edited content is resubmitted
        if ($this->pObj->params['postVars']['cmd'] == 'edit') {

            // assign the edited value to the record : $t = table, $i = index, $f = field
            $t = key($this->pObj->params['postVars']['data']);
            $i = key($this->pObj->params['postVars']['data'][$t]);
            $f = key($this->pObj->params['postVars']['data'][$t][$i]);

            /* RTE transformations before sending to cache - in case RTE was loaded, post data will contain the '_TRANSFORM_' flag first
             * for this stuff check t3lib_tcemain line 1154ff. & 2443ff., t3lib_rteapi line 147ff. */
            // @TODO: this might have to be refactored

            if (strpos($f, '_TRANSFORM_') !== FALSE) {
                $f = str_replace('_TRANSFORM_', '', $f);
                $dataToTransform = $this->pObj->params['postVars']['data'][$t][$i][$f];
                $currentRecord = array($f => $dataToTransform);

                // get RTE config
                $types_fieldConfig = BackendUtility::getTCAtypes($t, $currentRecord, 1);
                $theTypeString = BackendUtility::getTCAtypeValue($t, $currentRecord);
                $RTEsetup = $GLOBALS['BE_USER']->getTSConfig('RTE', BackendUtility::getPagesTSconfig($this->pObj->id));
                $thisConfig = BackendUtility::RTEsetup($RTEsetup['properties'], $t, $f, $theTypeString);

                // Get RTE object and do transformation
                $RTEobj = BackendUtility::RTEgetObj();
                if (is_object($RTEobj)) {
                    $RTErelPath = '';
                    $this->pObj->currentRecord[$t][$i][$f] = $RTEobj->transformContent('db', $dataToTransform, $t, $f, $currentRecord, $types_fieldConfig[$f]['spec'], $thisConfig, $RTErelPath, $this->pObj->id);
                } else {
                    debug('NO RTE OBJECT FOUND!');
                }

                // submitted data from non RTE fields
            } else {
                $this->pObj->currentRecord[$t][$i][$f] = $this->pObj->params['postVars']['data'][$t][$i][$f];
            }

            // count
            $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>
                        <p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';

            // buttons top
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

            // show changed record
            $output .= $this->displaySingleRecord($this->pObj->currentRecord);

            // hidden fields
            $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

            // buttons bottom
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

            // recache with changes
            $this->pObj->setData($this->pObj->currentKey);

        // case when a field is displayed in edit mode (without import/reload buttons)
        } else {

            // count
            $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>
                        <p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';

            $output .= $this->displaySingleRecord($this->pObj->currentRecord);

            // hidden fields
            $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

        }

        return $output;
    }

    /**
     * Reloads an extracted record from XML cache reapplying the TypoScript configuration
     * (helpful in development/debugging scenarios)
     *
     * @return string
     */
    protected function reloadAction()
    {
        $output = '';

        // fetch data to get the original XML of the record
        $this->pObj->getData($this->pObj->params['key']);

        // reprocess current record
        $xmlExtraction = GeneralUtility::makeInstance(XmlExtraction::class, $this->pObj);
        (is_array($this->pObj->conf['reloadEntryNode']) || $this->pObj->conf['reloadEntryNode']) ? $reloadEntryNode = $this->pObj->conf['reloadEntryNode'] : $reloadEntryNode = $this->pObj->conf['entryNode'];
        $xmlExtraction->processXMLdata($this->pObj->currentRecord['###XML###'][0]['source'], $reloadEntryNode, 'reload');

        // reload the newly extracted and cached record
        $this->pObj->getData($this->pObj->currentKey);

        // display processed record
        $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>
                    <p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';

        // buttons top
        if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
        if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

        // show
        $output .= $this->displaySingleRecord($this->pObj->currentRecord);

        // hidden fields
        $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

        // buttons bottom
        if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
        if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

        return $output;
    }

    /**
     * Provides a fully configurable backend preview of extracted XML records
     *
     * @return string
     */
    protected function previewAction()
    {
        $output = '';

        // fetch data
        $this->pObj->getData($this->pObj->params['key']);

        if ($this->pObj->currentRecord) {
            // display record
            $output .= '<p><strong>'.$this->pObj->languageService->getLL('remainingRecords').' '.count($this->pObj->keys).'</strong></p>
                        <p><strong>'.$this->pObj->languageService->getLL('currentPosition').' '.$this->pObj->currentKey.'</strong></p>';

            // buttons top
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();

            // show
            $output .= $this->displaySingleRecord($this->pObj->currentRecord);

            // hidden fields
            $output .= $this->displayInsertHiddenFields($this->pObj->currentKey);

            // buttons bottom
            if ($this->pObj->conf['displayImportButton']) $output .= $this->displayImportButton();
            if ($this->pObj->conf['displayReloadButton']) $output .= $this->displayReloadButton();
        }

        return $output;
    }


    ####### DISPLAY FUNCTIONS ###########


    /**
     * Displays notices on import
     *
     * @return string
     */
    protected function showImportNotice()
    {
        $output = '<p>' . sprintf($this->pObj->languageService->getLL('queueCount'), count($this->pObj->keys)) . '</p>
                   <p>' . $this->pObj->languageService->getLL('wouldYouLike') . '</p>
                   <p>
                        <a class="btn btn-default" href="' . $this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => 1)) . '">' . $this->pObj->languageService->getLL('goOn') . '</a>
                        <a class="btn btn-default" href="' . $this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'flush' => 1)) . '">' . $this->pObj->languageService->getLL('flushQueue') . '</a>
                   </p>';

        return $output;
    }

    /**
     * Displays the submit form for the XMl file/data
     *
     * @return string
     */
    protected function showSubmitForm()
    {
        // start form
        $output = '
            <fieldset class="file_selection">
            <legend>'.$this->pObj->languageService->getLL('submitLegend').'</legend>';

        // upload field
        if (!$this->pObj->conf['submitForm']['noUpload']) {
            $output .= '
            <div>
            <label for="upload_file">'.$this->pObj->languageService->getLL('uploadFile').'</label>
            <input class="form-control form-control-adapt" type="file" id="upload_file" name="upload_file" size="50" />
            </div>';
        }

        // get from url
        if (!$this->pObj->conf['submitForm']['noGetFromUrl']) {
            $output .= '
            <div>
            <label for="url_file">'.$this->pObj->languageService->getLL('urlFile').'</label><br/>
            <input class="form-control form-control-adapt" type="text" id="url_file" name="url_file" size="80" />
            </div>';
        }

        // files from fileadmin
        $filesToSelect = GeneralUtility::getAllFilesAndFoldersInPath(array(), $this->pObj->conf['directory'], 'xml');
        if ($filesToSelect && !$this->pObj->conf['submitForm']['noFileSelection']) {
            $output .= '
            <div>
            <label for="select_file">'.$this->pObj->languageService->getLL('selectFile').'<br/> ('.$this->pObj->languageService->getLL('directoryName').$this->pObj->conf['directory'].')</label>
            <br/><select class="form-control form-control-adapt" name="select_file" id="select_file">
            <option value="0">-</option>';
            foreach ($filesToSelect as $file) {
                $output .= '
            <option value="'.$file.'">'.substr($file, strrpos($file, '/')+1).'</option>';
            }
            $output .= '
            </select>
            </div>';
        }

        // file set from TS
        if ($this->pObj->modTSconfig['properties']['source.']['file'] || $this->pObj->modTSconfig['properties']['source.']['url']) {
            $output .= '
            <div>
            <p>'.$this->pObj->languageService->getLL('standardFile').$this->pObj->conf['file'].'</p>
            </div>';
        }

        // what to do
        $output .= '
            <div>
            <label for="action" style="display:none">'.$this->pObj->languageService->getLL('action').'</label>
            <select name="action" id="action" style="display:none;"> f
            <option value="1">'.$this->pObj->languageService->getLL('action1').'</option>';
        $output .= '
            </select>
            <label for="limit">'.$this->pObj->languageService->getLL('limit').'</label>
            <select class="form-control form-control-adapt" name="limit" id="limit">
            <option value="0">'.$this->pObj->languageService->getLL('noLimit').'</option>';
        if (is_array($this->pObj->conf['limitOptions'])) {
            foreach ($this->pObj->conf['limitOptions'] as $option) {
                if ((int) $option > 0) $output .= '<option value="'.$option.'">'.$option.'</option>';
            }}
        $output .= '
            </select>
            </div>
            <div>';

        // submit button
        $output .= '
            <input type="hidden" name="key" id="key" value="1" />
            <input type="hidden" name="submitForm" id="submitForm" value="1" />
            <input type="submit" class="btn btn-default" onclick="return checkall();" value="'.$this->pObj->languageService->getLL('submitButton').'" />
            </div>
            </fieldset>
            ';

        return $output;
    }

    /**
     * Displays an import button below the record preview.
     *
     * @return string
     */
    protected function displayImportButton() {
        $output = '
            <input type="submit" class="btn btn-default" name="cmd[import]" value="'.$this->pObj->languageService->getLL('importButton').'" />';
        return $output;
    }

    /**
     * Displays a reload button below the record preview
     *
     * @return string
     */
    protected function displayReloadButton() {
        $output = '
            <input type="submit" class="btn btn-default" name="cmd[reload]" value="'.$this->pObj->languageService->getLL('reloadButton').'" />';
        return $output;
    }

    /**
     * Inserts hidden fields to the backend module for passing state to actions
     *
     * @param $key
     *
     * @return string
     */
    protected function displayInsertHiddenFields($key) {
        $output = '
            <input type="hidden" name="action" value="1" />
            <input type="hidden" name="key" id="key" value="'. (int) $key.'" />';
        return $output;
    }

    /**
     * Displays the current record for import from the array stack
     *
     * @param $data
     *
     * @return string
     */
    protected function displaySingleRecord($data) {

        $output = '';

        // data display hook
        if (count($this->pObj->hookObjectsArr) > 0) {
            foreach ($this->pObj->hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'displaySingleRecordHook')) {
                    $hookObj->displaySingleRecordHook($data, $this);
                }
            }
        }

        // record browser
        if (count($this->pObj->keys) > 1 && $this->pObj->conf['recordBrowser']['enable']) {
            $steps = $this->pObj->conf['recordBrowser']['stepSize'];
            $output .= '<nav class="pagination-wrap"><ul id="web_xmlimport_recordbrowser" class="pagination pagination-block">';
            reset($this->pObj->keys);
            $output .= '<li><a href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => key($this->pObj->keys))).'">'.$this->pObj->languageService->getLL('begin').'</a></li>';
            ($this->pObj->prevKey) ? $output .= '<li><a href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => $this->pObj->prevKey)).'">'.$this->pObj->languageService->getLL('prev').'</a></li>' : $output .= '<li class="disabled"><span>'.$this->pObj->languageService->getLL('prev').'</span></li>';
            foreach ($this->pObj->keys as $k => $v) {
                if ($k % $this->pObj->conf['recordBrowser']['stepSize'] == false) {
                    $output .= '<li><a href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => $k)).'">'.$steps.'</a></li>';
                    $steps = $steps + $this->pObj->conf['recordBrowser']['stepSize'];
                }
            }
            ($this->pObj->nextKey) ? $output .= '<li><a href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => $this->pObj->nextKey)).'">'.$this->pObj->languageService->getLL('next').'</a></li>' : $output .= '<li class="disabled"><span>'.$this->pObj->languageService->getLL('next').'</span></li>';;
            end($this->pObj->keys);
            $output .= '<li><a href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => key($this->pObj->keys))).'">'.$this->pObj->languageService->getLL('end').'</a></li>';
            $output .= '</ul></nav>';
        }

        // render import content
        foreach ($data as $table => $records) {

            // don't display the xml source
            if ($table == '###XML###') continue;

            $tableLabel = $this->pObj->languageService->sL($GLOBALS['TCA'][$table]['ctrl']['title']);
            if (!$tableLabel) $tableLabel = $table;

            $output .= '<table id="'.$table.'" class="table table-striped table-hover web_xmlimport_table" cellpadding="0" cellspacing="0" border="0">
                        <thead>
                        <tr>
                            <th colspan="3">'.$tableLabel.' ('.$table.')</td>
                        </tr>
                        <tr>
                            <th>'.$this->pObj->languageService->getLL('fieldname').'</th>
                            <th>'.$this->pObj->languageService->getLL('value').'</th>';

            if (!$this->pObj->conf['noEdit']) $output .= '<th>'.$this->pObj->languageService->getLL('correction').'</th>';

            $output .= '</tr></thead><tbody>';

            // needed for edit mode
            reset($this->pObj->currentRecord);

            // build records
            foreach ($records as $index => $record) {

                // also provide the "original" record - the current one might have been modified for display by the hook above
                // needed for getting the right values in edit mode
                $originalRecord = $this->pObj->currentRecord[$table][$index];

                if (count($records) > 1) $output .=  '<tr><td colspan="3"><strong>'.$this->pObj->languageService->getLL('record').' '.$index.'<strong></td>';

                foreach ($record as $field => $value) {

                    $fieldLabel = $this->pObj->languageService->sL(BackendUtility::getItemLabel($table, $field, '[|]'));
                    if (!$fieldLabel) $fieldLabel = '['.$field.']';

                    // label column
                    $output .= '<tr class="db_list_normal" id="row_'.$field.'"><td class="col1">'.$fieldLabel.'</td>';

                    // either we're in edit mode for the field
                    if ($this->pObj->params['getVars']['cmd'] == 'edit' && $this->pObj->params['getVars']['field'] == $table.'-'.$field.'-'.$index) {

                        // swap uid - a little trick since TCEforms normally expects uid values for field generation
                        $originalRecord['uid'] = $index;

                        // preparing the data array for the new FormEngine
                        $data = array(
                            'command' => 'edit',
                            'renderType' => 'singleFieldContainer',
                            'recordTitle' => $field,
                            'tableName' => $table,
                            'fieldName' => $field,
                            'vanillaUid' => $originalRecord['uid'],
                            'databaseRow' => $originalRecord,
                            'processedTca' => $GLOBALS['TCA'][$table],
                            'rootline' => array(),
                            'inlineStructure' => array(),
                            'pageTsConfig' => array(),
                            'userTsConfig' => array(),
                            'recordTypeValue' => 1,
                            'parentPageRow' => array(),
                            'fieldListToRender' => $field,
                            'elementBaseName' => '['. $table .']['. $originalRecord['uid'] .']['. $field .']',
                            'parameterArray' => array(
                                'fieldConf' => $GLOBALS['TCA'][$table]['columns'][$field],
                                'fieldTSConfig' => array(),
                                'itemFormElName' => 'data['. $table .']['. $originalRecord['uid'] .']['. $field .']',
                                'itemFormElID' => 'data_' . $table . '_'. $originalRecord['uid'] .'_'. $field .'',
                                'itemFormElValue' => $value,
                                'fieldChangeFunc' => array(
                                   'TBE_EDITOR_fieldChanged' => 'TBE_EDITOR.fieldChanged(\''. $table .'\',\''. $originalRecord['uid'] .'\',\''. $field .'\',\'data['. $table .']['. $originalRecord['uid'] .']['. $field .']\');',
                                   'alert' => ''
                                )
                            )
                        );

                        $tcaSelectItems = GeneralUtility::makeInstance(TcaSelectItems::class);
                        $data = $tcaSelectItems->addData($data);

                        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);
                        $resultArray = $nodeFactory->create($data)->render();

                        $formResultCompiler = GeneralUtility::makeInstance(FormResultCompiler::class);
                        $formResultCompiler->mergeResult($resultArray);

                        // note: $formResultCompiler->getPageRenderer()->backPath = ''; @see: Digicademy\Xmltool\Hooks\Backend\PageRenderer
                        // set to '' by \Digicademy\Xmltool\Hooks\Backend\PageRenderer->executePreRenderHook

                        $output .= $formResultCompiler->JStop();
                        $output .= '<td class="col2" id="field-'.$field.'">
                                        '.$resultArray['html'].'
                                        <input type="hidden" name="action" value="1" />
                                        <input type="hidden" name="cmd" value="edit" />
                                        <input type="hidden" name="key" id="key" value="'.$this->pObj->currentKey.'" />
                                    </td>';
                        $output .= $formResultCompiler->printNeededJSFunctions();

                        // edit column
                        $output .= '<td class="col3"><input type="submit" class="btn btn-default" value="'.$this->pObj->languageService->getLL('submitCorrection').'" /></td></tr>';

                        // reset the fake id
                        unset($originalRecord['uid']);

                        // @TODO: check use of this setting
                        $edit = 1;

                        // or just display the row
                    } else {

                        // stdWrap for field value preview
                        if (is_array($this->pObj->conf['importConfiguration'][$table.'.']['fieldPreviewStdWrap.'])) {
                            if (is_object($GLOBALS['TSFE']) === FALSE) {
                                $backendTsfe = GeneralUtility::makeInstance(BackendTsfe::class);
                                $backendTsfe->buildTsfe($this->pObj->id);
                            }
                            // set registers for direct field/value access from TypoScript
                            $GLOBALS['TSFE']->register['CURRENT_PREVIEW_FIELD'] = $field;
                            $GLOBALS['TSFE']->register['CURRENT_PREVIEW_VALUE'] = $value;
                            // set cObj->data to current record
                            $GLOBALS['TSFE']->cObj->data = $record;
                            // pass value through stdWrap
                            $value = $GLOBALS['TSFE']->cObj->stdWrap($value, $this->pObj->conf['importConfiguration'][$table.'.']['fieldPreviewStdWrap.']);
                        }

                        // value column
                        $output .= '<td class="col2">'.$value.'</td>';

                        // edit column - preparation to make it editable through TCEFORMS
                        $fieldtype = $GLOBALS['TCA'][$table]['columns'][$field]['config']['type'];
                        $output .= '<td class="col3">';
                        $editlink = '';
                        if ($this->pObj->backendUser->user['admin'] || $GLOBALS['BE_USER']->check('non_exclude_fields', $table.':'.$field)) $editlink .= '
                        <a href="'.$this->uriBuilder->buildUriFromRoute('web_xmlimport', array('id' => (int) $this->pObj->id, 'action' => 1, 'key' => $this->pObj->currentKey, 'cmd' => 'edit', 'field' => $table.'-'.$field.'-'.$index)).'">
                            '.$this->iconFactory->getIcon('actions-open', 'small').'
                        </a>';
                        if ($this->pObj->conf['noEdit'] || $fieldtype == 'none' || $fieldtype == 'passthrough' || $fieldtype === NULL) $editlink = '';
                        $output .= $editlink.'</td></tr>';
                    }

                    // needed for edit mode
                    next($this->pObj->currentRecord);
                }
            }

            // finish table
            $output .= '</tbody></table>';
        }

        return $output;
    }


    ####### TYPO3 BE MODULE FUNCTIONS ###########


    /**
     * Initialize the object
     *
     * @param \object $pObj A reference to the parent (calling) object
     * @throws \RuntimeException
     * @see \TYPO3\CMS\Backend\Module\BaseScriptClass::checkExtObj()
     */
    public function init($pObj)
    {
        $this->pObj = $pObj;
        // Local lang:
        if (!empty($this->localLangFile)) {
            $this->getLanguageService()->includeLLFile($this->localLangFile);
        }
        // Setting MOD_MENU items as we need them for logging:
        $this->pObj->MOD_MENU = array_merge($this->pObj->MOD_MENU, $this->modMenu());
    }

    /**
     * If $this->function_key is set (which means there are two levels of object connectivity) then
     * $this->extClassConf is loaded with the TBE_MODULES_EXT configuration for that sub-sub-module
     *
     * @see $function_key, \TYPO3\CMS\FuncWizards\Controller\WebFunctionWizardsBaseController::init()
     */
    public function handleExternalFunctionValue()
    {
        // Must clean first to make sure the correct key is set...
        $this->pObj->MOD_SETTINGS = BackendUtility::getModuleData($this->pObj->MOD_MENU, GeneralUtility::_GP('SET'), $this->pObj->MCONF['name']);
        if ($this->function_key) {
            $this->extClassConf = $this->pObj->getExternalItemConfig($this->pObj->MCONF['name'], $this->function_key, $this->pObj->MOD_SETTINGS[$this->function_key]);
        }
    }

    /**
     * Same as \TYPO3\CMS\Backend\Module\BaseScriptClass::checkExtObj()
     *
     * @see \TYPO3\CMS\Backend\Module\BaseScriptClass::checkExtObj()
     */
    public function checkExtObj()
    {
        if (is_array($this->extClassConf) && $this->extClassConf['name']) {
            $this->extObj = GeneralUtility::makeInstance($this->extClassConf['name']);
            $this->extObj->init($this->pObj, $this->extClassConf);
            // Re-write:
            $this->pObj->MOD_SETTINGS = BackendUtility::getModuleData($this->pObj->MOD_MENU, GeneralUtility::_GP('SET'), $this->pObj->MCONF['name']);
        }
    }

    /**
     * Calls the main function inside ANOTHER sub-submodule which might exist.
     */
    public function extObjContent()
    {
        if (is_object($this->extObj)) {
            return $this->extObj->main();
        }
    }

    /**
     * Dummy function - but is used to set up additional menu items for this submodule.
     *
     * @return array A MOD_MENU array which will be merged together with the one from the parent object
     * @see init(), \TYPO3\CMS\Frontend\Controller\PageInformationController::modMenu()
     */
    public function modMenu()
    {
        return [];
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return DocumentTemplate
     */
    protected function getDocumentTemplate()
    {
        return $GLOBALS['TBE_TEMPLATE'];
    }

    /**
     * @return PageRenderer
     */
    protected function getPageRenderer()
    {
        if ($this->pageRenderer === null) {
            $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        }

        return $this->pageRenderer;
    }
}
