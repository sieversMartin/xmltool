<?php
namespace Digicademy\Xmltool\Hooks\Backend;

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

class PageRenderer
{
    /**
     * Resets $backPath of PageRenderer in the context of the edit function of the xmlimport module.
     * $formResultCompiler->getPageRenderer()->backPath is protected but must be reset, otherwise 'typo3/' is prepended
     * to all JS files included with $formResultCompiler->JStop() and $formResultCompiler->printNeededJSFunctions()
     *
     * @param $params
     * @param $pObj
     */
    public function executePreRenderHook(&$params, &$pObj) {
        // making sure this only happens in the BE context of the xmlimport module / using the presence of it's CSS file as identifier
        if (is_array($params['cssFiles']['../typo3conf/ext/xmltool/Resources/Public/CSS/web_xmlimport.css'])) {
            $pObj->backPath = '';
        }
    }
}
