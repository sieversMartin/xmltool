# XMLTool

## About

This TYPO3 extension adds an import module for any sort of XML to the TYPO3 backend.
Imports can be configured per page using pure TypoScript. If used in combination with the 
extensions [cobj_xpath](http://typo3.org/extensions/repository/view/cobj_xpath) and
[cobj_xslt](http://typo3.org/extensions/repository/view/cobj_xpath) the xmltool provides very flexible
import pipelines. Please have a look at the example configuration in the documentation folder of this
extension.

Main features:

* Fully configurable XML import pipelines per backend page (based on TSConfig/TypoScript)
* Extract and import XML records from directories in fileadmin, uploaded files and/or URLs/APIs
* XML extraction and preview module to check records before import
* XML batch imports
* Scheduler job for time based XML extractions/imports
* Hooks for fine grained customization of imports

## TYPO3 compatibility

| Version     | TYPO3      | PHP       | Support                                 |
| ----------- | ---------- | ----------|---------------------------------------- |
| 1.0         | 9.5 - 10.4 | 7.4       | Features, Bugfixes, Security Updates    |

## Research Software Engineering

This software is licensed under the terms of the GNU General Public License v2
as published by the Free Software Foundation.

Author: <a href="https://orcid.org/0000-0002-0953-2818">Torsten Schrade</a> | <a href="http://www.adwmainz.de">Academy of Sciences and Literature | Mainz</a>
