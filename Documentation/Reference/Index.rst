.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

Reference
---------
::

    ##################################################################################
    # Example TS configuration for XML import module                                 #
    ##################################################################################

    ## define a standard XPATH lib (using cobj_xpath) for easy extraction of values ##

    lib.xpath = XPATH
    lib.xpath {
        source.field = xml
        return = string
        resultObj {
            cObjNum = 1
            1.current = 1
        }
    }

    ## configure the backend module ##

    mod.web_xmlimport {

        # basic configuration of the module
        general {
            cacheLifetime = # lifetime of the extracted record cache in seconds (default 3600)
            debug = 0 # if true shows debug information in backend
            noEdit = 0 # make extracted records editable in preview
            noBatchImport = 0 # deactivate the batch import submodule
            displayImportButton = 1 # as it says...
            displayReloadButton = 1  # as it says...
            submitForm {
                noUpload = 0
                noFileSelection = 0
                noGetFromUrl = 0
                limitOptions {
                    option1 = 10
                    option2 = 20
                    option3 = 50
                    option4 = 100
                    option5 = 250
                    option6 = 500
                }
            }
            recordBrowser {
                enable = 1
                stepSize = 10
            }
            limit = # a hard limit of how many records will get extracted from XML
            cssFile = EXT:my_extension/Resources/Public/CSS/preview.css  # this css file will be included for the backend preview
            # see: https://www.php.net/manual/de/libxml.constants.php
            libxml {
                bigLines = 0
                compact = 0
                parseHuge = 0
                pedantic = 0
                xInclude = 0
            }
        }

        # sets import files and the node that represents the main database record(s)
        source {

            file = # either a standard file to import
            directory = /path/in/your/fileadmin/ # or a directory
            url = # or an http url to retrieve the XML from

            registerNamespace = tei|http://www.tei-c.org/ns/1.0 # example how to register a TEI namespace

            entryNode.cObject = TEXT
            entryNode.cObject.value = # XPATH expression that retrieves "record" nodes

            reloadEntryNode = # XPATH expression triggered if the reload button is clicked in BE preview
        }

        # configures the target tables into which XML data from the record nodes will be imported
        destination {

            # tablename of the first table in the import sequence
            table_one {

                # identifier(s) uniquely identify a record - mandatory for UPDATE scenarios !
                identifiers = fieldname1, fieldname2, ...

                # if any of the following fields contains ###UID###, ###UID:tablename### or ###DB:table:field:value###
                # markers during extraction/import, a check by is made and if true the uid of the found record
                # record replaces the marker
                markerFields = uid

                # fields to be excluded from validation against the $TCA
                dontValidateFields = pid, uid, debug

                # fields to be excluded from the import if the extraction results in an empty field value
                skipIfEmptyFields = fieldname1, fieldname2

                # stdWrap capabilities for the record preview; useful to generate hyperlinks from <link> tags etc.;
                # doesn't touch the extracted data; the BE preview always has two registers set to the current fieldname
                # and it's value, namely CURRENT_PREVIEW_FIELD and CURRENT_PREVIEW_VALUE
                fieldPreviewStdWrap {

                    # example using a TS CASE object and the fieldnames as key
                    cObject = CASE
                    cObject {

                        key.data = register : CURRENT_PREVIEW_FIELD

                        default = TEXT
                        default.data = register : CURRENT_PREVIEW_VALUE

                        uid = TEXT
                        uid {
                            value = <span class="new">NEW</span>
                            override {
                                cObject = TEXT
                                cObject.dataWrap = <span class="db">{register:CURRENT_PREVIEW_VALUE}</span>
                                if.value = ###UID###
                                if.equals.data = register : CURRENT_PREVIEW_VALUE
                                if.negate = 1
                            }
                        }

                    }
                }

                # the TypoScript / cObject based fieldname => value mappings for table one
                fields {

                    # cf. markerFields and identifiers above; replaced with the record's uid during import it the record already exists
                    uid.value = ###UID###

                    # sets the pid of the current backend page for the record using TypoScript
                    pid.data = page : uid

                    # TypoScript based extraction of values for field1
                    fieldname1.cObject < lib.xpath
                    fieldname1.cObject.expression = # XPATH expression

                    # TypoScript based extraction of values for field2
                    fieldname2.cObject < lib.xpath
                    fieldname2.cObject.expression = # XPATH expression

                    # example for generating debug output
                    debug.debugData = 1

                }
            }

            # second table for which records should get importet (from the *same* XML record)
            table_two {

                # if example for tables: only import stuff for this table if the following TypoScript statement returns true
                if.isTrue.cObject < lib.xpath
                if.isTrue.cObject {
                    expression = # XPATH expression
                    return = count
                }

                identifiers = # see above

                markerFields = parent_record # see above and below

                dontValidateFields = # see above

                fields {

                    uid.value = ###UID###

                    pid.data = page:uid

                    # example for pointing to a parent record from a child record using a marker
                    parent_record.value = ###UID:table_one###

                    # applying a userFunc to a field during extraction
                    fieldname1.cObject = USER
                    fieldname1.cObject.userFunc = VENDOR\Package\Class->myFieldProcessingFunc

                    # using a TypoScript register and a CONTENT object in a COA
                    fieldname2.cObject = COA
                    fieldname2.cObject {

                        10 = LOAD_REGISTER
                        10 {

                            myRegister.cObject = CONTENT
                            myRegister.cObject {
                                table = table_three
                                select {
                                    pidInList.data = page:uid
                                    where = # something
                                }
                                renderObj = TEXT
                                renderObj.field = uid
                                stdWrap.intval = 1
                            }
                        }

                        20 = TEXT
                        20.data = register : myRegister

                    }

                    # conditional extraction of a field using the register from the field before
                    fieldname3.cObject < lib.xpath
                    fieldname3.cObject.expression = # XPATH expression
                    fieldname3.if.isTrue.data = register : myRegister

                    # processing an extracted field value with a postUserFunc
                    fieldname4.cObject < lib.xpath
                    fieldname4.cObject.expression = # XPATH expression
                    fieldname4.stdWrap.postUserFunc = VENDOR\Package\Class->myPostUserFunc

                }
            }

            # third table for import; using a table userFunc to extract records from XML
            table_three {

                # example for accessing an arrayified version of the parsed XML which is available
                # in cObj->data during extraction
                if.isTrue.data = TSFE:cObj|data|text|front|div|list|0|item|7|@attributes|n

                identifiers = uid

                markerFields = uid

                dontValidateTablename = 1 # a tablename must not be validated

                dontValidateFields = pid, uid, debug

                recordUserObject.userFunc = VENDOR\Package\Class->myRecordUserFunc
            }

            # this time a TypoScript based recordNode is used to generate multiple records/rows for a table during XML extraction
            # this will also set the RECORD_NODE_ITERATION register internaly
            table_four {

                dontValidateFields = uid, pid

                identifiers = name

                markerFields = uid

                recordNode = # XPATH expression (with stdWrap capabilities)

                fields {
                }
            }

            # configuring an MM join table for import of joins between table one and table two
            table_mm {

                dontValidateFields = uid_foreign, uid_local, tablenames

                identifiers = uid_foreign, uid_local

                markerFields = uid_local, uid_foreign

                fields {

                    uid_local.value = ###UID:table_one###

                    uid_foreign.value = ###UID:table_two###

                    tablenames.value = my_tablename

                }

                # this needs explanation...
                MM {
                    tablenamesField = tablenames
                    purgeExistingRelations = 1
                    uidToTableMapping {
                        table_one {
                            uidLocalTable = table_one
                            uidForeignTable = table_two
                        }
                    }
                }
            }

        }
    }

    ##################################################################################
    # Available Hooks                                                                #
    ##################################################################################

    Register the Hooks in your ext_localconf.php below:

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['xmlimport/mod1/index.php']['xmlimportHookClass'][] = '/path/to/file.php';

    ## Extraction ##

    $hookObj->preProcessXMLData($xmlData, $this->pObj->conf['importConfiguration'], $this);

    $hookObj->postProcessXMLData($xmlData, $this->pObj->conf['importConfiguration'], $this);

    ## Preview of extracted data ##

    $hookObj->displaySingleRecordHook($data, $this);

    ## Import ##

    $hookObj->preImportHook($data, $this);

    $hookObj->postImportHookAfterSingleRow($data, $datamap, $this->newUids, $this);

    $hookObj->postImportHookAfterAllRows($data, $this->newUids, $this);
