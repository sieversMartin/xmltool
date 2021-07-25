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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

class XmlimportModuleController
{

    /**
     * Loaded with the global array $MCONF which holds some module configuration from the conf.php file of backend modules.
     *
     * @see init()
     * @var array
     */
    public $MCONF = [];

    /**
     * The integer value of the GET/POST var, 'id'. Used for submodules to the 'Web' module (page id)
     *
     * @see init()
     * @var int
     */
    public $id;

    /**
     * The value of GET/POST var, 'CMD'
     *
     * @see init()
     * @var mixed
     */
    public $CMD;

    /**
     * A WHERE clause for selection records from the pages table based on read-permissions of the current backend user.
     *
     * @see init()
     * @var string
     */
    public $perms_clause;

    /**
     * The module menu items array. Each key represents a key for which values can range between the items in the array of that key.
     *
     * @see init()
     * @var array
     */
    public $MOD_MENU = [
        'function' => []
    ];

    /**
     * Current settings for the keys of the MOD_MENU array
     *
     * @see $MOD_MENU
     * @var array
     */
    public $MOD_SETTINGS = [];

    /**
     * Module TSconfig based on PAGE TSconfig / USER TSconfig
     *
     * @see menuConfig()
     * @var array
     */
    public $modTSconfig;

    /**
     * If type is 'ses' then the data is stored as session-lasting data. This means that it'll be wiped out the next time the user logs in.
     * Can be set from extension classes of this class before the init() function is called.
     *
     * @see menuConfig(), \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     * @var string
     */
    public $modMenu_type = '';

    /**
     * dontValidateList can be used to list variables that should not be checked if their value is found in the MOD_MENU array. Used for dynamically generated menus.
     * Can be set from extension classes of this class before the init() function is called.
     *
     * @see menuConfig(), \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     * @var string
     */
    public $modMenu_dontValidateList = '';

    /**
     * List of default values from $MOD_MENU to set in the output array (only if the values from MOD_MENU are not arrays)
     * Can be set from extension classes of this class before the init() function is called.
     *
     * @see menuConfig(), \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()
     * @var string
     */
    public $modMenu_setDefaultList = '';

    /**
     * Contains module configuration parts from TBE_MODULES_EXT if found
     *
     * @see handleExternalFunctionValue()
     * @var array
     */
    public $extClassConf;

    /**
     * Generally used for accumulating the output content of backend modules
     *
     * @var string
     */
    public $content = '';

    /**
     * @var PageRenderer
     */
    protected $pageRenderer;

    /**
     * Configuration for the module
     * @var array
     */
    public $conf = [];

    /**
     * Incoming parameters
     * @var array
     */
    public $params = [];

    /**
     * Current record for import
     * @var array
     */
    public $currentRecord = [];

    /**
     * New uids for records generated during import
     * @var array
     */
    public $newUids = [];

    /**
     * Array with classnames for hooks
     * @var array
     */
    public $hookObjectsArr = [];

    /**
     * Array with all index values from XML
     * @var array
     */
    public $keys = [];

    /**
     * Previous index in import stack
     * @var integer
     */
    public $prevKey;

    /**
     * Current index in import stack
     * @var integer
     */
    public $currentKey;

    /**
     * Next index in import stack
     * @var integer
     */
    public $nextKey;

    /**
     * Collected error messages for display in module
     * @var array
     */
    public $errorMsgs;

    /**
     * @var CacheManager
     */
    protected $cacheManager;

    /**
     * @var \TYPO3\CMS\Core\Cache\CacheFactory
     */
    protected $cacheFactory;

    /**
     * @var mixed
     */
    protected $cacheInstance;

    /**
     * @var Registry
     */
    public $registry;

    /**
     * @var FlashMessageService
     */
    public $flashMessageService;

    /**
     * @var \TYPO3\CMS\Core\Messaging\FlashMessageQueue
     */
    public $messageQueue;

    /**
     * @var UriBuilder
     */
    public $uriBuilder;

    /**
     * @var array
     */
    public $pageinfo;

    /**
     * Document Template Object
     *
     * @var \TYPO3\CMS\Backend\Template\DocumentTemplate
     * @deprecated
     */
    public $doc;

    /**
     * @var \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    public $backendUser;

    /**
     * @var \TYPO3\CMS\Lang\LanguageService
     */
    public $languageService;

    /**
     * The name of the module
     *
     * @var string
     */
    protected $moduleName = 'web_xmlimport';

    /**
     * ModuleTemplate Container
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * Constructor
     */
    public function __construct()
    {
        // initialize module, language service and template
        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        $this->languageService = $GLOBALS['LANG'];
        $this->languageService->includeLLFile('EXT:xmltool/Resources/Private/Language/locallang_mod_web_xmlimport.xlf');
        $this->backendUser = $GLOBALS['BE_USER'];
        $this->MCONF = array('name' => $this->moduleName);
        $this->uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        // initialize cache
        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);

        // initialize registry
        $this->initializeRegistry();

        // flash messages
        $this->flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $this->messageQueue = $this->flashMessageService->getMessageQueueByIdentifier();
    }


    ####### MODULE INITIALIZATION AND OUTPUT ###########


    /**
     * Initialize module header etc and call extObjContent function
     *
     * @return void
     */
    public function main()
    {
        // We leave this here because of dependencies to submodules
        $this->doc = GeneralUtility::makeInstance(DocumentTemplate::class);

        // The page will show only if there is a valid page and if this page
        // may be viewed by the user
        $this->pageinfo = BackendUtility::readPageAccess($this->id, $this->perms_clause);
        if ($this->pageinfo) {
            $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($this->pageinfo);
        }
        $access = is_array($this->pageinfo);
        if ($this->id && $access || $this->backendUser->user['admin'] && !$this->id) {

            if ($this->backendUser->user['admin'] && !$this->id) {
                $this->pageinfo = array('title' => '[root-level]', 'uid' => 0, 'pid' => 0);
            }
            // JavaScript
            $this->moduleTemplate->addJavaScriptCode(
                'WebFuncInLineJS',
                'if (top.fsMod) top.fsMod.recentIds["web"] = ' . (int)$this->id . ';
                 function jumpToUrl(URL) {
                 window.location.href = URL;
                 return false;
                 }
                 '
            );

            // include CSS and custom CSS files for BE module
            $relPath = str_replace(\TYPO3\CMS\Core\Core\Environment::getPublicPath(), '', ExtensionManagementUtility::extPath('xmltool'));
            $this->addStyleSheet('web_xmlimport_module', $relPath . 'Resources/Public/CSS/web_xmlimport.css');
            if ($this->conf['cssFile']) {
                $cssFile = str_replace(\TYPO3\CMS\Core\Core\Environment::getPublicPath(), '', $this->conf['cssFile']);
                $this->addStyleSheet('web_xmlimport_user', $cssFile);
            }

            // Setting up the context sensitive menu:
            $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/ClickMenu');

            if (!$this->conf['importConfiguration'] || !$this->conf['entryNode']) $missingconfig = 1;

            if (!$this->id) {
                $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.idFirst'), '', FlashMessage::WARNING, FALSE);
                $this->messageQueue->addMessage($message);
            } elseif ($this->id && $missingconfig) {
                $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.importConfigurationError'), '', FlashMessage::ERROR, FALSE);
                $this->messageQueue->addMessage($message);
            // Render module content:
            } else {
                // draw module form

                $this->content .=
                    '<form action="' . htmlspecialchars($this->uriBuilder->buildUriFromRoute($this->moduleName, array('id' => $this->id))) .
                    '" method="post" enctype="multipart/form-data" name="' . htmlspecialchars($this->moduleName) .
                    '" id="' . htmlspecialchars($this->moduleName) . '" class="form-inline form-inline-spaced">';

                $this->extObjContent();
                // Setting up the buttons and markers for docheader
                $this->getButtons();
                $this->generateMenu();
                $this->content .= '</form>';
            }

        } else {
            // If no access or if ID == zero
            $this->content = $this->doc->header($this->languageService->getLL('title'));

            $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.idFirst'), '', FlashMessage::WARNING, FALSE);
            $this->messageQueue->addMessage($message);
        }
    }

    /**
     * Print module content (from $this->content)
     *
     * @return void
     * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
     */
    public function printContent()
    {
        GeneralUtility::logDeprecatedFunction();
        $this->content = $this->doc->insertStylesAndJS($this->content);
        echo $this->content;
    }

    /**
     * Injects the request object for the current request or subrequest
     * Then checks for module functions that have hooked in, and renders menu etc.
     *
     * @param ServerRequestInterface $request the current request
     * @return Response the response with the content
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $GLOBALS['SOBE'] = $this;
        $this->init();

        // initialize configuration
        $this->initializeConfiguration();

        // Checking for first level external objects
        $this->checkExtObj();

        // Checking second level external objects
        $this->checkSubExtObj();
        $this->main();

        $this->moduleTemplate->setContent($this->content);

        // compatibility switch v9/v10
        $TYPO3_version = VersionNumberUtility::convertVersionStringToArray(VersionNumberUtility::getCurrentTypo3Version());
        if ($TYPO3_version['version_main'] > 9) {
            $responseFactory = GeneralUtility::makeInstance(ResponseFactoryInterface::class);
            $response = $responseFactory->createResponse();
        }

        $response->getBody()->write($this->moduleTemplate->renderContent());

        return $response;
    }

    /**
     * Create the panel of buttons for submitting the form or otherwise perform operations.
     */
    protected function getButtons()
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();

        // CSH
        $cshButton = $buttonBar->makeHelpButton()
            ->setModuleName('_MOD_web_xmlimport')
            ->setFieldName('');
        $buttonBar->addButton($cshButton, ButtonBar::BUTTON_POSITION_LEFT, 0);

        // LIST RECORDS
        $listButton = $buttonBar->makeLinkButton()
            ->setHref($this->uriBuilder->buildUriFromRoute('web_list', ['id' => $this->id]))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-system-list-open', Icon::SIZE_SMALL))
            ->setTitle('Record list');
        $buttonBar->addButton($listButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $page = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->select(
                ['*'], // fields
                'pages', // from
                [ 'uid' => (int)$this->id ] // where
            )->fetch();

        $localCalcPerms = $this->backendUser->calcPerms($page);
        if ($localCalcPerms & Permission::PAGE_EDIT && !empty($this->id) && ($this->backendUser->user['admin'] || !$page['editlock'])) {
            $params = '&edit[pages][' . $this->id . ']=edit';
            $onClick = htmlspecialchars(BackendUtility::editOnClick($params, ''));
            $editButton = $buttonBar->makeLinkButton()
                ->setHref('#')
                ->setOnClick($onClick)
                ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-page-open', Icon::SIZE_SMALL))
                ->setTitle('Edit page');
            $buttonBar->addButton($editButton, ButtonBar::BUTTON_POSITION_LEFT, 1);
        }
    }

    /**
     * Generate the ModuleMenu
     */
    protected function generateMenu()
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('WebXmlimportJumpMenu');
        foreach ($this->MOD_MENU['function'] as $controller => $title) {
            $item = $menu
                ->makeMenuItem()
                ->setHref(
                    $this->uriBuilder->buildUriFromRoute(
                        $this->moduleName,
                        [
                            'id' => $this->id,
                            'SET' => [
                                'function' => $controller
                            ]
                        ]
                    )
                )
                ->setTitle($title);
            if ($controller === $this->MOD_SETTINGS['function']) {
                $item->setActive(true);
            }
            $menu->addMenuItem($item);
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * Returns the ModuleTemplate container
     * This is used by PageLayoutView.php
     *
     * @return ModuleTemplate
     */
    public function getModuleTemplate()
    {
        return $this->moduleTemplate;
    }


    ####### CACHE & REGISTRY ###########


    /**
     * Initializes the registry that will hold the keys to the extracted/cached XML records
     */
    protected function initializeRegistry()
    {
        $this->registry = GeneralUtility::makeInstance(Registry::class);
    }

    /**
     * Initialize cache instance to be ready to use; taken from http://wiki.typo3.org/Caching_framework
     *
     * @return void
     */
    protected function initializeCache()
    {
        try {
            $this->cacheInstance = $this->cacheManager->getCache('tx_xmltool_recordcache');
        } catch (\TYPO3\CMS\Core\Exception $e) {
            $this->cacheInstance = $this->cacheFactory->create(
                'tx_xmltool_recordcache',
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_xmltool_recordcache']['frontend'],
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_xmltool_recordcache']['backend'],
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_xmltool_recordcache']['options']
            );
        }
    }

    /**
     * Inserts extracted XML records into cache
     *
     * @param array $data
     */
    public function cacheInsertAll($data=array())
    {

        if (is_array($data) && count($data) > 0) {

            // clear any remaining import cache for the page
            $this->cacheRemoveAll();

            // write all extracted records to extension cache as single entries - this avoids huge data packets
            foreach ($data as $key => $value) {
                $cacheIdentifier = sha1('web_xmlimport_'.$this->id.'_'.$key);
                $entry = $value;
                $tags = array(0 => 'web_xmlimport_' . $this->id);
                $lifetime = $this->conf['cacheLifetime'];
                $this->cacheManager->getCache('tx_xmltool_recordcache')->set($cacheIdentifier, $entry, $tags, $lifetime);
            }

            // store $this->keys to system registry for later retrival & record access
            $this->keys = array_keys($data);
            array_unshift($this->keys, 'x');
            unset($this->keys[0]);
            $this->registry->set('web_xmlimport', $this->id, $this->keys);

        } else {
            throw new \TYPO3\CMS\Core\Exception('No records submitted for storage into cache', 1327927770);
        }
    }

    /**
     * Extracts a single extracted record into cache
     *
     * @param $key
     * @param $record
     */
    public function cacheInsertSingle($key, $record)
    {
        // changed to allow single record caching in XmlExtraction->extractRecordsFromXML() line 306
        // $cacheIdentifier = sha1('web_xmlimport_'.$this->id.'_'.$this->keys[$key]);
        $cacheIdentifier = sha1('web_xmlimport_'.$this->id.'_'.$key);
        $tags = array(0 => 'web_xmlimport_'.$this->id);
        $lifetime = $this->conf['cacheLifetime'];
        $this->cacheManager->getCache('tx_xmltool_recordcache')->set($cacheIdentifier, $record, $tags, $lifetime);
    }

    /**
     * Removes all extracted records from cache
     */
    public function cacheRemoveAll()
    {
        // generally collect all cache garbage
        $this->cacheManager->getCache('tx_xmltool_recordcache')->collectGarbage();
        // remove all records for a certain pid
        $tag = 'web_xmlimport_' . $this->id;
        $this->cacheManager->getCache('tx_xmltool_recordcache')->flushByTag($tag);
        $this->registry->remove('web_xmlimport', $this->id);
    }

    /**
     * Removes a single record from cache
     *
     * @param $key
     */
    public function cacheRemoveSingle($key)
    {
        $cacheIdentifier = sha1('web_xmlimport_'.$this->id.'_'.$this->keys[$key]);
        $this->cacheManager->getCache('tx_xmltool_recordcache')->remove($cacheIdentifier);
    }

    /**
     * Fully cleans the registry and the record cache
     */
    public function cacheLoadAndCleanRegistry()
    {
        // check if there is a valid entry in the sys registry for the current page
        $this->keys = $this->registry->get('web_xmlimport', $this->id);
        // check if there is a valid record cache for the current page
        $cacheIdentifier = sha1('web_xmlimport_'.$this->id.'_'.$this->keys[1]);
        $cacheTrue = $this->cacheManager->getCache('tx_xmltool_recordcache')->get($cacheIdentifier);
        // if there is no valid cache present, purge the key registry and the cache
        if (is_array($cacheTrue) === FALSE) {
            $this->registry->remove('web_xmlimport', $this->id);
            $this->keys = '';
            $this->cacheRemoveAll();
        }
    }


    ####### INITIALIZE CONFIGURATION ###########


    /**
     * Initializes the TypoScript configuration
     */
    public function initializeConfiguration()
    {

        // get module settings: XML
        if ($this->modTSconfig['properties']['source.']['entryNode']) $this->conf['entryNode'] = $this->modTSconfig['properties']['source.']['entryNode'];
        if ($this->modTSconfig['properties']['source.']['entryNode.']) {
            $this->conf['entryNode'] = array();
            $this->conf['entryNode']['content'] = $this->modTSconfig['properties']['source.']['entryNode'];
            $this->conf['entryNode']['conf'] = $this->modTSconfig['properties']['source.']['entryNode.'];
        }

        // get module settings: reloadEntryNode
        if ($this->modTSconfig['properties']['source.']['reloadEntryNode']) $this->conf['reloadEntryNode'] = $this->modTSconfig['properties']['source.']['reloadEntryNode'];
        if ($this->modTSconfig['properties']['source.']['reloadEntryNode.']) {
            $this->conf['reloadEntryNode'] = array();
            $this->conf['reloadEntryNode']['content'] = $this->modTSconfig['properties']['source.']['reloadEntryNode'];
            $this->conf['reloadEntryNode']['conf'] = $this->modTSconfig['properties']['source.']['reloadEntryNode.'];
        }

        // set possible namespace
        if ($this->modTSconfig['properties']['source.']['registerNamespace']) $this->conf['registerNamespace'] = $this->modTSconfig['properties']['source.']['registerNamespace'];

        // get import tables/fields configuration and match it to $TCA
        if (is_array($this->modTSconfig['properties']['destination.'])) {
            $this->conf['importConfiguration'] = $this->validateImportConfiguration($this->modTSconfig['properties']['destination.']);
        } else {
            $this->conf['importConfiguration'] = '';
        }

        // get params
        $this->params['action'] = (int) GeneralUtility::_GP('action');
        $this->params['function'] = (int) $this->MOD_SETTINGS['function'];
        $this->params['key'] = (int) GeneralUtility::_GP('key');
        $this->params['postVars'] = GeneralUtility::_POST();
        $this->params['getVars'] = GeneralUtility::_GET();
        $this->params['flush'] = (int) GeneralUtility::_GET('flush');

        // module cmd to execute
        if (is_array(GeneralUtility::_GP('cmd')) && count(GeneralUtility::_GP('cmd')) == 1) {
            $this->params['cmd'] = (string) key(GeneralUtility::_GP('cmd'));
        } else {
            $this->params['cmd'] = (string) GeneralUtility::_GP('cmd');
        }

        // directories & files
        if ($this->modTSconfig['properties']['source.']['directory']) $this->conf['directory'] = $this->modTSconfig['properties']['source.']['directory'];
        $this->conf['directory'] = GeneralUtility::getFileAbsFileName($this->conf['directory']);
        if (GeneralUtility::getFileAbsFileName($this->modTSconfig['properties']['source.']['file'])) $this->conf['file'] = $this->modTSconfig['properties']['source.']['file'];
        if ($this->modTSconfig['properties']['source.']['url']) $this->conf['file'] = $this->modTSconfig['properties']['source.']['url'];
        if (GeneralUtility::getFileAbsFileName($this->modTSconfig['properties']['general.']['cssFile'])) $this->conf['cssFile'] = GeneralUtility::getFileAbsFileName($this->modTSconfig['properties']['general.']['cssFile']);

        // general settings
        if ((int) $this->modTSconfig['properties']['general.']['noEdit'] == 1) $this->conf['noEdit'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['debug'] == 1) $this->conf['debug'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['displayImportButton'] == 1) $this->conf['displayImportButton'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['displayReloadButton'] == 1) $this->conf['displayReloadButton'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['noBatchImport'] == 1) $this->conf['noBatchImport'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['submitForm.']['noUpload'] == 1) $this->conf['submitForm']['noUpload'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['submitForm.']['noFileSelection'] == 1) $this->conf['submitForm']['noFileSelection'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['submitForm.']['noGetFromUrl'] == 1) $this->conf['submitForm']['noGetFromUrl'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['recordBrowser.']['enable']) $this->conf['recordBrowser']['enable'] = 1;
        if ((int) $this->modTSconfig['properties']['general.']['recordBrowser.']['stepSize']) $this->conf['recordBrowser']['stepSize'] = (int) $this->modTSconfig['properties']['general.']['recordBrowser.']['stepSize'];
        if (is_array($this->modTSconfig['properties']['general.']['submitForm.']['limitOptions.'])) $this->conf['limitOptions'] = $this->modTSconfig['properties']['general.']['submitForm.']['limitOptions.'];
        if ((int) GeneralUtility::_POST('limit') > 0) {
            $this->conf['limit'] = (int) GeneralUtility::_POST('limit');
        } elseif ((int) $this->modTSconfig['properties']['general.']['limit'] > 0) {
            $this->conf['limit'] = (int) $this->modTSconfig['properties']['general.']['limit'];
        }

        // libxml configuration options
        if (is_array($this->modTSconfig['properties']['general.']['libxml.'])) $this->conf['libxml'] = $this->modTSconfig['properties']['general.']['libxml.'];

        // cache lifetime
        if (isset($this->modTSconfig['properties']['general.']['cacheLifetime'])) {
            $this->conf['cacheLifetime'] = (int) $this->modTSconfig['properties']['general.']['cacheLifetime'];
        } else {
            $this->conf['cacheLifetime'] = (int) 3600;
        }

        // register hook objects (if any)
        $this->hookObjectsArr = array();
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['xmlimport/mod1/index.php']['xmlimportHookClass'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['xmlimport/mod1/index.php']['xmlimportHookClass'] as $classRef) {
                $this->hookObjectsArr[] = &GeneralUtility::getUserObj($classRef);
            }
        }
    }


    ####### DATA STORAGE & RETRIVAL ###########


    /**
     * Matches the submitted array to the $TCA of the target tables.
     * Any levels/keys/values that are not in the $TCA field list are removed
     * (unless specified otherwise with dontValidateTablename and dontValidateFields).
     *
     * @param $configuration
     *
     * @return mixed
     */
    protected function validateImportConfiguration($configuration)
    {

        if (is_array($configuration) && count($configuration) > 0) {

            foreach ($configuration as $key => $value) {

                $table = substr($key, 0, -1);

                if (($value['dontValidateTablename'] == 1) || isset($GLOBALS['TCA'][$table])) {

                    if (isset($value['recordUserObject.'])) continue;

                    if (count($value['fields.']) > 0) {

                        // identifier check
                        if (isset($value['identifiers'])) {
                            $configuration[$key]['identifiers'] = GeneralUtility::trimExplode(',', $value['identifiers'], 1);
                            foreach ($configuration[$key]['identifiers'] as $index => $identifier) {
                                if (array_key_exists($identifier, $value['fields.']) || array_key_exists($identifier.'.', $value['fields.'])) {
                                    continue;
                                } else {
                                    $message = GeneralUtility::makeInstance(FlashMessage::class, htmlspecialchars($this->languageService->getLL('errmsg.identifierFieldError').$table.'.'.$identifier), '', FlashMessage::ERROR, FALSE);
                                    $this->messageQueue->addMessage($message);

                                    unset($configuration[$key]['identifiers'][$index]);
                                }
                            }
                        }

                        // field check
                        foreach ($value['fields.'] as $name => $conf) {

                            $fieldname = substr($name, 0, -1);

                            if ((array_key_exists($fieldname, (array)$GLOBALS['TCA'][$table]['columns'])) === FALSE && strpos($value['dontValidateFields'], $fieldname) === FALSE) {
                                // if the field is not configured, take it out
                                unset($configuration[$key]['fields.'][$name]);
                                // put warning into flash message queue
                                $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.fieldNotConfigured').$table.'.'.$fieldname, '', FlashMessage::WARNING, FALSE);
                                $this->messageQueue->addMessage($message);
                            }
                        }

                        // no fields defined for the table, take the table out of the configuration
                    } else {
                        // if the table is not configured, take it out
                        unset($configuration[$key]);
                        // put warning into flash message queue
                        $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.noFieldsConfigured').$table, '', FlashMessage::WARNING, FALSE);
                        $this->messageQueue->addMessage($message);
                    }

                } else {
                    // if the table is not configured, take it out
                    unset($configuration[$key]);
                    // put warning into flash message queue
                    $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.tableNotConfigured').$table, '', FlashMessage::WARNING, FALSE);
                    $this->messageQueue->addMessage($message);
                }
            }
        }

        if (empty($configuration)) {
            // put error into flash message queue
            $message = GeneralUtility::makeInstance(FlashMessage::class, $this->languageService->getLL('errmsg.importConfigurationError'), '', FlashMessage::ERROR, FALSE);
            $this->messageQueue->addMessage($message);
        }

        return $configuration;
    }

    /**
     * Fetches record either from cache or from source and sets it to $this->currentRecord.
     *
     * @param int $currentKey
     */
    public function getData($currentKey = 0) {

        // if there is an entry in the system registry for the current page, get the according record from cache
        if (($this->keys = $this->registry->get('web_xmlimport', $this->id)) && (int) $currentKey > 0) {

            // calculate current position in import stack
            if (count($this->keys) > 1) {
                $this->currentKey = $currentKey;
                (($this->currentKey-1) > 0) ? $this->prevKey = $this->currentKey-1 : $this->prevKey = FALSE;
                (($this->currentKey+1) <= count($this->keys)) ? $this->nextKey = $this->currentKey+1 : $this->nextKey = FALSE;
            } else {
                $this->currentKey = 1;
            }

            // get record from cache
            $cacheIdentifier = sha1('web_xmlimport_'.$this->id.'_'.$this->keys[$this->currentKey]);
            $this->currentRecord = $this->cacheManager->getCache('tx_xmltool_recordcache')->get($cacheIdentifier);

            // check for any relations to the record that may exist in DB
            $this->processMarkerFields();
        }

        // debug output
        if ($this->conf['debug'] == 1) debug($this->currentRecord, '$this->currentRecord');
    }

    /**
     * Stores current record to cache
     *
     * @param int $currentKey
     * @throws
     */
    public function setData($currentKey = 0)
    {
        // insert single record to cache
        if ((int) $currentKey > 0) {
            $this->cacheInsertSingle($currentKey, $this->currentRecord);
            // issue error
        } else {
            throw new \TYPO3\CMS\Core\Exception('Lost or no record cache key. The current record could not be stored', 1327927107);
        }
    }


    ####### DB FUNCTIONS ###########



    /**
     * If any fields contains the UID or UID:table markers, make a check by identifiers if the record exists.
     * This becomes important in scenarios where cached records contain relations to records that already have been imported.
     */
    public function processMarkerFields()
    {

        foreach ($this->currentRecord as $tablename => $records) {

            // exclude the XML source field
            if ($tablename === '###XML###') continue;

            if (isset($this->conf['importConfiguration'][$tablename.'.']['markerFields'])) {

                $markerFields = GeneralUtility::trimExplode(',', $this->conf['importConfiguration'][$tablename.'.']['markerFields'], 1);

                foreach ($markerFields as $fieldname) {
                    foreach ($records as $key => $record) {

                        $uid = 0;
                        $identifiers = '';
                        $foreignTable = '';
                        $foreignIdentifiers = '';
                        $foreignRecord = array();
                        $foreignUID = 0;

                        if (preg_match_all('/###UID:.*?###/', $record[$fieldname], $matches)) {
                            if ($matches[0]) {
                                foreach ($matches[0] as $match) {
                                    $foreignTable = substr($match, 7, -3);
                                    if (is_array($this->currentRecord[$foreignTable][$key]) && count($this->currentRecord[$foreignTable][$key]) > 1) {
                                        $foreignUID = $this->currentRecord[$foreignTable][$key]['uid'];
                                    } else {
                                        $foreignUID = $this->currentRecord[$foreignTable][0]['uid'];
                                    }
                                    if ($foreignUID > 0) {
                                        $value = str_replace($match, $foreignUID, $this->currentRecord[$tablename][$key][$fieldname]);
                                        $this->currentRecord[$tablename][$key][$fieldname] = $value;
                                    }
                                }
                            }
                        }

                        if (preg_match_all('/###DB:.*?###/', $record[$fieldname], $matches)) {
                            if ($matches[0]) {
                                $this->currentRecord[$tablename][$key][$fieldname] =
                                    $this->resolveDBMarkerMatches($this->currentRecord[$tablename][$key][$fieldname], $matches[0]);
                            }
                        }

                        // if the field contains the UID keyword, check if it exists in DB using the identifiers of the current table
                        if ($record[$fieldname] === '###UID###') {

                            if (is_array($this->conf['importConfiguration'][$tablename.'.']['identifiers'])) {
                                $identifiers = $this->conf['importConfiguration'][$tablename.'.']['identifiers'];
                            } else {
                                $identifiers = GeneralUtility::trimExplode(',', $this->conf['importConfiguration'][$tablename.'.']['identifiers'], 1);
                            }

                            if (isset($identifiers)) {
                                $uid = $this->getUidByIdentifiers($key, $this->currentRecord[$tablename][$key], $tablename, $identifiers);
                                if ($uid > 0) {
                                    $this->currentRecord[$tablename][$key][$fieldname] = $uid;
                                }
                            }
                        }

                    }
                }

                // if a uid has been found RECACHE
                $this->cacheInsertSingle($this->currentKey, $this->currentRecord);
            }
        }
    }

    /**
     * Resolves markes of type "DB:"
     *
     * @param $fieldValue
     * @param $matches
     *
     * @return array|mixed|string|string[]|null
     */
    protected function resolveDBMarkerMatches($fieldValue, $matches)
    {
        if (is_array($matches)) {
            foreach ($matches as $match) {
                $parts = GeneralUtility::trimExplode(':', $match, TRUE);
                $foreignUID = $this->getUidByIdentifiers(0, array($parts[2] => substr($parts[3], 0, -3)), $parts[1], array(0 => $parts[2]));
                if ($foreignUID > 0) {
                    $fieldValue = str_replace($match, $foreignUID, $fieldValue);
                }
            }
        }
        return $fieldValue;
    }

    /**
     * Retrieve (or try to retrieve) an existing record uid by given revord identifiers
     *
     * @param $index
     * @param $record
     * @param $table
     * @param $identifiers
     *
     * @return false|mixed
     */
    protected function getUidByIdentifiers($index, $record, $table, $identifiers) {

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);

        foreach ($identifiers as $identifier) {
            // all identifier fields MUST contain a value, otherwise return FALSE immediately
            if ($record[$identifier] !== '') {
                // if the current identifier is in itself a pointer to another record resolve it's value (taking multiple extracted records into account by $index)
                if (preg_match('/###UID:.*?###/', $record[$identifier], $matches)) {

                    $foreignTableName = substr($matches[0], 7, -3);
                    $foreignTableRecords = $this->currentRecord[$foreignTableName];

                    if (is_array($foreignTableRecords) && count($foreignTableRecords) > 1) {
                        $record[$identifier] = $this->currentRecord[$foreignTableName][$index]['uid'];
                    } elseif (is_array($foreignTableRecords) && count($foreignTableRecords) == 1) {
                        $record[$identifier] = $this->currentRecord[$foreignTableName][0]['uid'];
                    } else {
                        return FALSE;
                    }

                    if ($record[$identifier] < 1) return FALSE;
                }

                $queryBuilder->andWhere($queryBuilder->expr()->eq(
                    $identifier, $queryBuilder->createNamedParameter($record[$identifier])
                ));

            } else {
                return FALSE;
            }
        }

        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->execute()
            ->fetch();

        if (is_array($row) && count($row) > 0)  {
            return $row['uid'];
        } else {
            return FALSE;
        }
    }

    /**
     * Inserts/updates the record. Uses TCEmain. Contains a hook "performImportHook" for final data manipulation before the XML record is imported
     *
     * @param $data
     */
    public function performImport($data) {

        // final data manipulation hook before import
        if (count($this->hookObjectsArr) > 0) {
            foreach ($this->hookObjectsArr as $hookObj)	{
                if (method_exists($hookObj,'preImportHook')) {
                    $hookObj->preImportHook($data, $this);
                }
            }
        }

        // clean any remaing uids from last records
        $this->newUids = array();

        // walk through each extracted table and import it's records
        foreach ($data as $table => $records) {

            // skip the XML source field
            if ($table == '###XML###') {

                continue;

            // import MM records using custom logic
            } elseif (is_array($this->conf['importConfiguration'][$table . '.']['MM.'])) {

                // select the right MM import configuration
                $MMconf = $this->conf['importConfiguration'][$table . '.']['MM.'];
                $tablenamesField = $MMconf['tablenamesField'];

                foreach ($records as $index => $fields) {

                    if ($tablenamesField && array_key_exists($tablenamesField, $fields) && is_array($MMconf['uidToTableMapping.'][$fields[$tablenamesField] . '.'])) {
                        $MMimportConfiguration = $MMconf['uidToTableMapping.'][$fields[$tablenamesField] . '.'];
                        $tableNamesFieldValue = $fields[$tablenamesField];
                        if (!$tableNamesFieldValue) {
                            $message = GeneralUtility::makeInstance(FlashMessage::class, htmlspecialchars($this->languageService->getLL('errmsg.tableNamesFieldValueContainsNoValue') . ':' . $tablenamesField), '', FlashMessage::ERROR, FALSE);
                            $this->messageQueue->addMessage($message);
                        }
                    } else {
                        $MMimportConfiguration = reset($MMconf['uidToTableMapping.']);
                    }

                    if (array_key_exists($MMimportConfiguration['uidLocalTable'], $GLOBALS['TCA']) && array_key_exists($MMimportConfiguration['uidForeignTable'], $GLOBALS['TCA'])) {

                        // If any field within the current record is still set to '###UID:table###', check if there is a uid stored in the newUids array
                        // This get's important in a scenario where several DB records are created from one XML record in one run
                        foreach ($fields as $key => $value) {
                            if (preg_match_all('/###UID:.*?###/', $value, $matches)) {
                                if ($matches[0]) {
                                    foreach ($matches[0] as $match) {
                                        $tableToRelate = substr($match, 7, -3);
                                        if ($this->newUids[$tableToRelate] > 0) {
                                            $fields[$key] = str_replace($match, $this->newUids[$tableToRelate], $fields[$key]);
                                        }
                                    }
                                }
                            }
                            if (preg_match_all('/###DB:.*?###/', $value, $matches)) {
                                if ($matches[0]) {
                                    $fields[$key] = $this->resolveDBMarkerMatches($fields[$key], $matches[0]);
                                }
                            }
                        }

                        // select on the foreign table for uid_local if a record exists
                        $localRecord = GeneralUtility::makeInstance(ConnectionPool::class)
                            ->getConnectionForTable($MMimportConfiguration['uidLocalTable'])
                            ->select(
                                ['uid'], // fields
                                $MMimportConfiguration['uidLocalTable'], // from
                                [ 'uid' => (int)$fields['uid_local'] ] // where
                            )->fetch();

                        // select on the foreign table for uid_foreign if a record exists
                        $foreignRecord = GeneralUtility::makeInstance(ConnectionPool::class)
                            ->getConnectionForTable($MMimportConfiguration['uidForeignTable'])
                            ->select(
                                ['uid'], // fields
                                $MMimportConfiguration['uidForeignTable'], // from
                                [ 'uid' => (int)$fields['uid_foreign'] ] // where
                            )->fetch();

                        // only if both exist continue
                        if (
                            array_key_exists('uid', $localRecord) &&
                            $localRecord['uid'] > 0 &&
                            array_key_exists('uid', $foreignRecord) &&
                            $foreignRecord['uid'] > 0
                        ) {

                            $additionalWhere = '1 = 1';

                            // add tablenames field if set
                            if ($tablenamesField) {
                                // @TODO: Doctrine DBAL migration
                                $MMtableColumns = $GLOBALS['TYPO3_DB']->admin_get_fields($table);
                                if (is_array($MMtableColumns[$tablenamesField])) {
                                    // @TODO: Doctrine DBAL migration
                                    $additionalWhere .= ' AND ' . $tablenamesField . ' = ' . $GLOBALS['TYPO3_DB']->fullQuoteStr($tableNamesFieldValue, $table);
                                }
                            }

                            // always fetch from uid_local
                            $additionalWhere .= ' AND uid_local = ' . (int) $fields['uid_local'];

                            // select on the MM table using the value/column specified in the identifierField directive
                            // @TODO: Doctrine DBAL migration
                            $existingRelations = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('*', $table, $additionalWhere);

                            // purge existing relations, but only on the first iteration
                            if ($MMconf['purgeExistingRelations'] && $index == 0) {
                                // @TODO: Doctrine DBAL migration
                                $GLOBALS['TYPO3_DB']->exec_DELETEquery($table, $additionalWhere);
                                unset($existingRelations);
                            }

                            // check if a row exists that is equal to the current field/value combination
                            if (is_array($existingRelations) && count($existingRelations) > 0) {
                                foreach ($existingRelations as $key => $relation) {
                                    if ($relation['uid_local'] == $fields['uid_local']
                                        && $relation['uid_foreign'] == $fields['uid_foreign']
                                        && $relation['tablenames'] == $fields['tablenames']) {
                                        $relationExists = TRUE;
                                    } else {
                                        $relationExists = false;
                                    }
                                }
                            }

                            if ($relationExists === TRUE) {
                                continue;
                                // otherwise issue an insert statement
                            } else {
                                // @TODO: Doctrine DBAL migration
                                $GLOBALS['TYPO3_DB']->exec_INSERTquery($table, $fields);
                                // and update the reference index
                                $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
                                $referenceIndex->updateRefIndexTable($MMimportConfiguration['uidLocalTable'], $fields['uid_local']);
                                $referenceIndex->updateRefIndexTable($MMimportConfiguration['uidForeignTable'], $fields['uid_foreign']);
                            }
                        } else {
                            $message = GeneralUtility::makeInstance(FlashMessage::class, htmlspecialchars($this->languageService->getLL('errmsg.relatedRecordsDontExist') . ':' . $tablenamesField), '', FlashMessage::ERROR, FALSE);
                            $this->messageQueue->addMessage($message);
                        }
                    } else {
                        $message = GeneralUtility::makeInstance(FlashMessage::class, htmlspecialchars($this->languageService->getLL('errmsg.relatedTablesNotInTCA') . ':' . $tablenamesField), '', FlashMessage::ERROR, FALSE);
                        $this->messageQueue->addMessage($message);
                    }
                }

            // import TCA configured records using TCEmain
            } else {

                foreach ($records as $index => $fields) {

                    // if a uid is set in the field array, clean it - TCEmain expects this as key and not as field in the datamap
                    $uid = 0;
                    $new = 0;
                    if (array_key_exists('uid', $fields)) {
                        $uid = (int) $fields['uid'];
                        unset($fields['uid']);
                    }

                    // if a uid existed, no other record identifiers are needed - just ensure that the record really exists in DB
                    if ($uid > 0) {

                        $row = GeneralUtility::makeInstance(ConnectionPool::class)
                            ->getConnectionForTable($table)
                            ->select(
                                ['uid'], // fields
                                $table, // from
                                [ 'uid' => (int)$uid ] // where
                            )->fetch();

                        if (is_array($row) && count($row) > 0) $uid = $row['uid'];

                        // otherwise use the specified identifiers for testing if the record already exists in DB
                    } elseif (count($this->conf['importConfiguration'][$table.'.']['identifiers']) > 0) {
                        if (is_array($this->conf['importConfiguration'][$table.'.']['identifiers'])) {
                            $identifiers = $this->conf['importConfiguration'][$table.'.']['identifiers'];
                        } else {
                            $identifiers = GeneralUtility::trimExplode(',', $this->conf['importConfiguration'][$table.'.']['identifiers'], 1);
                        }
                        $uid = $this->getUidByIdentifiers($index, $fields, $table, $identifiers);
                    }

                    // if there is no $uid by now, the record has to be considered as new
                    if (!$uid) {
                        $uid = 'NEW_'.uniqid('');
                        $new = 1;
                    }

                    // set some internal values if the according fields exist for the current table
                    // @TODO: Doctrine DBAL migration
                    $fieldsInDB = $GLOBALS['TYPO3_DB']->admin_get_fields($table);
                    $tstamp = time();

                    // last update field
                    if (array_key_exists('tstamp', $fieldsInDB)) $fields['tstamp'] = $tstamp;

                    // creation date
                    if ($new && array_key_exists('crdate', $fieldsInDB)) $fields['crdate'] = $tstamp;

                    // creation user
                    if ($new && array_key_exists('cruser_id', $fieldsInDB)) $fields['cruser_id'] = $GLOBALS['BE_USER']->user['uid'];

                    // If any field within the current record is still set to '###UID:table###', check if there is a uid stored in the newUids array
                    // This get's important in a scenario where several DB records are created from one XML record in one run
                    foreach ($fields as $key => $value) {
                        if (preg_match_all('/###UID:.*?###/', $value, $matches)) {
                            if ($matches[0]) {
                                foreach ($matches[0] as $match) {
                                    $tableToRelate = substr($match, 7, -3);
                                    if ($this->newUids[$tableToRelate] > 0) {
                                        $fields[$key] = str_replace($match, $this->newUids[$tableToRelate], $fields[$key]);
                                    }
                                }
                            }
                        }
                        if (preg_match_all('/###DB:.*?###/', $value, $matches)) {
                            if ($matches[0]) {
                                $fields[$key] = $this->resolveDBMarkerMatches($fields[$key], $matches[0]);
                            }
                        }
                    }

                    // build datamap for import/update
                    $datamap = array(
                        $table => array(
                            $uid => $fields,
                        )
                    );

                    // initialize TCEmain
                    $tce = GeneralUtility::makeInstance(DataHandler::class);
                    $tce->start($datamap, null);
                    $tce->process_datamap();

                    // check for errors
                    if (count($tce->errorLog) != 0) $this->errorMsgs = $tce->errorLog;

                    // retrieve and store any new uids that have been created in this run
                    if ($new) {
                        $this->newUids[$table] = $tce->substNEWwithIDs[$uid];
                    }
                }
            }

            // hook for actions after a single row operation
            if (count($this->hookObjectsArr) > 0) {
                foreach ($this->hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj,'postImportHookAfterSingleRow')) {
                        $hookObj->postImportHookAfterSingleRow($data, $datamap, $this->newUids, $this);
                    }
                }
            }

        }

        // hook for actions after all database operations have finished
        if (count($this->hookObjectsArr) > 0) {
            foreach ($this->hookObjectsArr as $hookObj) {
                if (method_exists($hookObj,'postImportHookAfterAllRows')) {
                    $hookObj->postImportHookAfterAllRows($data, $this->newUids, $this);
                }
            }
        }

    }


    ####### TYPO3 BE MODULE FUNCTIONS ###########


    /**
     * Initializes the backend module by setting internal variables, initializing the menu.
     *
     * @see menuConfig()
     */
    public function init()
    {
        // Name might be set from outside
        if (!$this->MCONF['name']) {
            $this->MCONF = $GLOBALS['MCONF'];
        }
        $this->id = (int)GeneralUtility::_GP('id');
        $this->CMD = GeneralUtility::_GP('CMD');
        $this->perms_clause = $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW);
        $this->menuConfig();
        $this->handleExternalFunctionValue();
    }

    /**
     * Initializes the internal MOD_MENU array setting and unsetting items based on various conditions. It also merges in external menu items from the global array TBE_MODULES_EXT (see mergeExternalItems())
     * Then MOD_SETTINGS array is cleaned up (see \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData()) so it contains only valid values. It's also updated with any SET[] values submitted.
     * Also loads the modTSconfig internal variable.
     *
     * @see init(), $MOD_MENU, $MOD_SETTINGS, \TYPO3\CMS\Backend\Utility\BackendUtility::getModuleData(), mergeExternalItems()
     */
    public function menuConfig()
    {
        // Page / user TSconfig settings and blinding of menu-items
        $this->modTSconfig['properties'] = BackendUtility::getPagesTSconfig($this->id)['mod.'][$this->MCONF['name'] . '.'] ?? [];
        $this->MOD_MENU['function'] = $this->mergeExternalItems($this->MCONF['name'], 'function', $this->MOD_MENU['function']);
        $blindActions = $this->modTSconfig['properties']['menu.']['function.'] ?? [];
        foreach ($blindActions as $key => $value) {
            if (!$value && array_key_exists($key, $this->MOD_MENU['function'])) {
                unset($this->MOD_MENU['function'][$key]);
            }
        }
        $this->MOD_SETTINGS = BackendUtility::getModuleData($this->MOD_MENU, GeneralUtility::_GP('SET'), $this->MCONF['name'], $this->modMenu_type, $this->modMenu_dontValidateList, $this->modMenu_setDefaultList);
    }

    /**
     * Merges menu items from global array $TBE_MODULES_EXT
     *
     * @param string $modName Module name for which to find value
     * @param string $menuKey Menu key, eg. 'function' for the function menu.
     * @param array $menuArr The part of a MOD_MENU array to work on.
     * @return array Modified array part.
     * @internal
     * @see \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::insertModuleFunction(), menuConfig()
     */
    public function mergeExternalItems($modName, $menuKey, $menuArr)
    {
        $mergeArray = $GLOBALS['TBE_MODULES_EXT'][$modName]['MOD_MENU'][$menuKey];
        if (is_array($mergeArray)) {
            foreach ($mergeArray as $k => $v) {
                if (((string)$v['ws'] === '' || $this->getBackendUser()->workspace === 0 && GeneralUtility::inList($v['ws'], 'online')) || $this->getBackendUser()->workspace === -1 && GeneralUtility::inList($v['ws'], 'offline') || $this->getBackendUser()->workspace > 0 && GeneralUtility::inList($v['ws'], 'custom')) {
                    $menuArr[$k] = $this->getLanguageService()->sL($v['title']);
                }
            }
        }
        return $menuArr;
    }

    /**
     * Loads $this->extClassConf with the configuration for the CURRENT function of the menu.
     *
     * @param string $MM_key The key to MOD_MENU for which to fetch configuration. 'function' is default since it is first and foremost used to get information per "extension object" (I think that is what its called)
     * @param string $MS_value The value-key to fetch from the config array. If NULL (default) MOD_SETTINGS[$MM_key] will be used. This is useful if you want to force another function than the one defined in MOD_SETTINGS[function]. Call this in init() function of your Script Class: handleExternalFunctionValue('function', $forcedSubModKey)
     * @see getExternalItemConfig(), init()
     */
    public function handleExternalFunctionValue($MM_key = 'function', $MS_value = null)
    {
        if ($MS_value === null) {
            $MS_value = $this->MOD_SETTINGS[$MM_key];
        }
        $this->extClassConf = $this->getExternalItemConfig($this->MCONF['name'], $MM_key, $MS_value);
    }

    /**
     * Returns configuration values from the global variable $TBE_MODULES_EXT for the module given.
     * For example if the module is named "web_info" and the "function" key ($menuKey) of MOD_SETTINGS is "stat" ($value) then you will have the values of $TBE_MODULES_EXT['webinfo']['MOD_MENU']['function']['stat'] returned.
     *
     * @param string $modName Module name
     * @param string $menuKey Menu key, eg. "function" for the function menu. See $this->MOD_MENU
     * @param string $value Optionally the value-key to fetch from the array that would otherwise have been returned if this value was not set. Look source...
     * @return mixed The value from the TBE_MODULES_EXT array.
     * @see handleExternalFunctionValue()
     */
    public function getExternalItemConfig($modName, $menuKey, $value = '')
    {
        if (isset($GLOBALS['TBE_MODULES_EXT'][$modName])) {
            return (string)$value !== '' ? $GLOBALS['TBE_MODULES_EXT'][$modName]['MOD_MENU'][$menuKey][$value] : $GLOBALS['TBE_MODULES_EXT'][$modName]['MOD_MENU'][$menuKey];
        }
        return null;
    }

    /**
     * Creates an instance of the class found in $this->extClassConf['name'] in $this->extObj if any (this should hold three keys, "name", "path" and "title" if a "Function menu module" tries to connect...)
     * This value in extClassConf might be set by an extension (in an ext_tables/ext_localconf file) which thus "connects" to a module.
     * The array $this->extClassConf is set in handleExternalFunctionValue() based on the value of MOD_SETTINGS[function]
     * If an instance is created it is initiated with $this passed as value and $this->extClassConf as second argument. Further the $this->MOD_SETTING is cleaned up again after calling the init function.
     *
     * @see handleExternalFunctionValue(), \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::insertModuleFunction(), $extObj
     */
    public function checkExtObj()
    {
        if (is_array($this->extClassConf) && $this->extClassConf['name']) {
            $this->extObj = GeneralUtility::makeInstance($this->extClassConf['name']);
            $this->extObj->init($this, $this->extClassConf);
            // Re-write:
            $this->MOD_SETTINGS = BackendUtility::getModuleData($this->MOD_MENU, GeneralUtility::_GP('SET'), $this->MCONF['name'], $this->modMenu_type, $this->modMenu_dontValidateList, $this->modMenu_setDefaultList);
        }
    }

    /**
     * Calls the checkExtObj function in sub module if present.
     */
    public function checkSubExtObj()
    {
        if (is_object($this->extObj)) {
            $this->extObj->checkExtObj();
        }
    }

    /**
     * Calls the 'header' function inside the "Function menu module" if present.
     * A header function might be needed to add JavaScript or other stuff in the head. This can't be done in the main function because the head is already written.
     */
    public function extObjHeader()
    {
        if (is_callable([$this->extObj, 'head'])) {
            $this->extObj->head();
        }
    }

    /**
     * Calls the 'main' function inside the "Function menu module" if present
     */
    public function extObjContent()
    {
        if ($this->extObj === null) {
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                $this->getLanguageService()->sL('LLL:EXT:backend/Resources/Private/Language/locallang.xlf:no_modules_registered'),
                $this->getLanguageService()->getLL('title'),
                FlashMessage::ERROR
            );
            /** @var \TYPO3\CMS\Core\Messaging\FlashMessageService $flashMessageService */
            $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
            /** @var \TYPO3\CMS\Core\Messaging\FlashMessageQueue $defaultFlashMessageQueue */
            $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
            $defaultFlashMessageQueue->enqueue($flashMessage);
        } else {
            $this->extObj->pObj = $this;
            if (is_callable([$this->extObj, 'main'])) {
                $this->content .= $this->extObj->main();
            }
        }
    }

    /**
     * Return the content of the 'main' function inside the "Function menu module" if present
     *
     * @return string
     */
    public function getExtObjContent()
    {
        $savedContent = $this->content;
        $this->content = '';
        $this->extObjContent();
        $newContent = $this->content;
        $this->content = $savedContent;
        return $newContent;
    }

    /**
     * Returns the Language Service
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns the Backend User
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
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

    /**
     * Insert additional style sheet link
     *
     * @param string $key some key identifying the style sheet
     * @param string $href uri to the style sheet file
     * @param string $title value for the title attribute of the link element
     * @param string $relation value for the rel attribute of the link element
     * @deprecated since TYPO3 v9.4, will be removed in TYPO3 v10.0
     * @see PageRenderer::addCssFile()
     */
    public function addStyleSheet($key, $href, $title = '', $relation = 'stylesheet')
    {
        $pageRenderer = $this->getPageRenderer();
        $pageRenderer->addCssFile($href, $relation, 'screen', $title);
    }

}
