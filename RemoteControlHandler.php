<?php
/**
 * Handler for extendRemoteControl Plugin for LimeSurvey : add yours functions here
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2015-2016 Denis Chenu <http://sondages.pro>
 * @license GPL v3
 * @version 1.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class RemoteControlHandler extends remotecontrol_handle
{
    /**
     * @inheritdoc
     * Disable webroute else json returned can be broken
     */
    public function __construct(AdminController $controller)
    {
        /* Deactivate web log */
        foreach (Yii::app()->log->routes as $route) {
            $route->enabled = $route->enabled && !($route instanceOf CWebLogRoute);
        }
        parent::__construct($controller);
    }
    /**
     * RPC Routine to get information on user from extendRemoteControl plugin
     *
     * @access public
     * @param string $sSessionKey Auth credentials
     * @return array The information on user (except password)
     */
    public function get_me($sSessionKey)
    {
        if ($this->_checkSessionKey($sSessionKey))
        {
            $oUser=User::model()->find("uid=:uid",array(":uid"=>Yii::app()->session['loginID']));
            if($oUser) // We have surely one, else no sessionkey ....
            {
                $aReturn=$oUser->attributes;
                unset($aReturn['password']);
                return $aReturn;
            }
        }
    }

    /**
     * RPC Routine to get global permission of the actual user
     *
     * @access public
     * @param string $sSessionKey Auth credentials
     * @param string $sPermission string Name of the permission - see function getGlobalPermissions
     * @param $sCRUD string The permission detailsyou want to check on: 'create','read','update','delete','import' or 'export'
     * @return bool True if user has the permission
     * @return boolean
     */
    public function hasGlobalPermission($sSessionKey,$sPermission,$sCRUD='read')
    {
        $this->_checkSessionKey($sSessionKey);
        return array(
            'permission'=>Permission::model()->hasGlobalPermission($sPermission,$sCRUD)
        );
    }

    /**
     * RPC Routine to get survey permission of the actual user
     *
     * @access public
     * @param string $sSessionKey Auth credentials
     * @param $iSurveyID integer The survey ID
     * @param $sPermission string Name of the permission
     * @param $sCRUD string The permission detail you want to check on: 'create','read','update','delete','import' or 'export'
     * @return array 'permission' => True if user has the permission
     */
    public function hasSurveyPermission($sSessionKey,$iSurveyID, $sPermission, $sCRUD='read')
    {
        $this->_checkSessionKey($sSessionKey);
        return array(
            'permission'=>\Permission::model()->hasSurveyPermission($iSurveyID, $sPermission, $sCRUD),
        );
    }

    /**
     * @param PclZip $zip
     * @param string $name
     * @param string $full_name
     */
    private function _addToZip($zip, $name, $full_name)
    {
        $zip->add(
            array(
                array(
                    PCLZIP_ATT_FILE_NAME => $name,
                    PCLZIP_ATT_FILE_NEW_FULL_NAME => $full_name
                )
            )
        );
    }

    /**
     * RPC Routine to export survey archive with structure, tokens and responses
     *
     * @param $sSessionKey
     * @param int $iSurveyID
     * @return string|array string in case of success, array if error
     * @throws CException
     */
    public function export_survey($sSessionKey, $iSurveyID)
    {
        if (!$this->_checkSessionKey($sSessionKey)) return array('status' => 'Invalid sessionkey');

        $iSurveyID = (int) $iSurveyID;
        $oSurvey = Survey::model()->findByPk($iSurveyID);
        if (!isset($oSurvey))
            return array('status' => 'Error: Invalid survey ID');

        $aSurveyInfo = getSurveyInfo($iSurveyID);

        $sTempDir = Yii::app()->getConfig("tempdir");

        $aZIPFileName = $sTempDir . DIRECTORY_SEPARATOR . randomChars(30);
        $sLSSFileName = $sTempDir . DIRECTORY_SEPARATOR . randomChars(30);
        $sLSRFileName = $sTempDir . DIRECTORY_SEPARATOR . randomChars(30);
        $sLSTFileName = $sTempDir . DIRECTORY_SEPARATOR . randomChars(30);
        $sLSIFileName = $sTempDir . DIRECTORY_SEPARATOR . randomChars(30);

        Yii::import('application.libraries.admin.pclzip', TRUE);
        $zip = new PclZip($aZIPFileName);

        Yii::app()->loadHelper('export');
        file_put_contents($sLSSFileName, surveyGetXMLData($iSurveyID));

        $this->_addToZip($zip, $sLSSFileName, 'survey_' . $iSurveyID . '.lss');

        unlink($sLSSFileName);

        if ( $aSurveyInfo['active'] == 'Y' )
        {
            getXMLDataSingleTable($iSurveyID, 'survey_' . $iSurveyID, 'Responses', 'responses', $sLSRFileName, FALSE);
            $this->_addToZip($zip, $sLSRFileName, 'survey_' . $iSurveyID . '_responses.lsr');
            unlink($sLSRFileName);
        }

        if ( tableExists('{{tokens_' . $iSurveyID . '}}') )
        {
            getXMLDataSingleTable($iSurveyID, 'tokens_' . $iSurveyID, 'Tokens', 'tokens', $sLSTFileName);
            $this->_addToZip($zip, $sLSTFileName, 'survey_' . $iSurveyID . '_tokens.lst');
            unlink($sLSTFileName);
        }

        if ( tableExists('{{survey_' . $iSurveyID . '_timings}}') )
        {
            getXMLDataSingleTable($iSurveyID, 'survey_' . $iSurveyID . '_timings', 'Timings', 'timings', $sLSIFileName);
            $this->_addToZip($zip, $sLSIFileName, 'survey_' . $iSurveyID . '_timings.lsi');
            unlink($sLSIFileName);
        }

        if ( is_file($aZIPFileName) )
        {
            $handle = fopen($aZIPFileName, 'rb');
            $fsize = filesize($aZIPFileName);
            $content = fread($handle, $fsize);
            return(base64_encode($content));
        }
    }

    /**
     * Update a response in a given survey.
     * Routine supports only single response updates.
     * Response to update will be identified either by the response id, or the token if response id is missing.
     * Routine is only applicable for active surveys with alloweditaftercompletion = Y.
     *
     * @access public
     * @param string $sSessionKey Auth credentials
     * @param int $iSurveyID Id of the Survey to update response
     * @param struct $aResponseData The actual response
     * @return mixed TRUE(bool) on success. errormessage on error
     */
    public function update_response($sSessionKey, $iSurveyID, $aResponseData)
    {

        if (!$this->_checkSessionKey($sSessionKey)) return array('status' => 'Invalid sessionkey');

        $iSurveyID=(int)$iSurveyID;
        $oSurvey=Survey::model()->findByPk($iSurveyID);
        if (is_null($oSurvey))
        {
            return array('Status' => 'Error: Invalid survey ID');
        }
        if ($oSurvey->getAttribute('active') !== 'Y') {
            return array('Status' => 'Error: Survey is not active.');
        }

        $previousStatus = $oSurvey->getAttribute('alloweditaftercompletion');

        if ($previousStatus !== 'Y') {
            $oSurvey->setAttribute('alloweditaftercompletion', 'Y');
        }

        if (Permission::model()->hasSurveyPermission($iSurveyID, 'responses', 'update')) {
            if (!Yii::app()->db->schema->getTable('{{survey_' . $iSurveyID . '}}')) {
                $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
                return array('Status' => 'Error: No survey response table');
            }

            if (
                !isset($aResponseData['id'])
                && ! isset($aResponseData['token'])
            ) {
                $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
                return array('Status' => 'Error: Missing response identifier (id|token).');
            }

            SurveyDynamic::sid($iSurveyID);
            $oSurveyDynamic = new SurveyDynamic;

            if (isset($aResponseData['id'])) {
                $aResponses = $oSurveyDynamic->findAllByPk((int)$aResponseData['id']);
            } else {
                $aResponses = $oSurveyDynamic->findAllByAttributes(array('token' => $aResponseData['token']));
            }

            if(empty($aResponses)) {
                $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
                return array('Status' => 'Error: No matching Response.');
            }
            if(count($aResponses) > 1) {
                $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
                return array('Status' => 'Error: More then one matching response, updateing multiple responses at once is not supported.');
            }

            $aBasicDestinationFields=$oSurveyDynamic->tableSchema->columnNames;
            $aInvalidFields= array_diff_key($aResponseData, array_flip($aBasicDestinationFields));
            if(count($aInvalidFields) > 0) {
                $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
                return array('Status' => 'Error: Invalid Column names supplied: ' . implode(', ', array_keys($aInvalidFields)));
            }

            unset($aResponseData['token']);

            foreach ($aResponseData as $sAtributeName => $value) {
                $aResponses[0]->setAttribute($sAtributeName, $value);
            }

            $bResult = $aResponses[0]->save(true);

            $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
            if ($bResult) {
                return $bResult;
            } else {
                return array('Status' => 'Unable to edit response');
            }
        } else {
            $oSurvey->setAttribute('alloweditaftercompletion', $previousStatus);
            return array('Status' => 'No permission');
        }
    }

    public function delete_responses($sSessionKey, $iSurveyID, $ResponseId)
    {
        if (!$this->_checkSessionKey($sSessionKey)) return array('status' => 'Invalid sessionkey');

        $iSurveyID = (int) $iSurveyID;

        $aResponseId = (is_array($ResponseId))?$ResponseId:array($ResponseId);

        $errors = 0;
        $timingErrors = 0;

        if (Permission::model()->hasSurveyPermission($iSurveyID, 'responses', 'delete'))
        {
            foreach ($aResponseId as $iResponseID) {
                $beforeDataEntryDelete = new PluginEvent('beforeDataEntryDelete');
                $beforeDataEntryDelete->set('iSurveyID', $iSurveyID);
                $beforeDataEntryDelete->set('iResponseID', $iResponseID);
                App()->getPluginManager()->dispatchEvent($beforeDataEntryDelete);

                $response = Response::model($iSurveyID)->findByPk($iResponseID);
                if ($response) {
                    $result = $response->delete(true);
                } else {
                    $errors++;
                }

                if (!$result) {
                    $errors++;
                } else {
                    $oSurvey = Survey::model()->findByPk($iSurveyID);
                    // TODO (from \responses::actionDelete) : add it to response delete (maybe test if timing table exist)
                    if ($oSurvey->savetimings == "Y") {
                        $result = SurveyTimingDynamic::model($iSurveyID)->deleteByPk($iResponseID);
                        if (!$result) {
                            $timingErrors++;
                        }
                    }
                }

            }

            if ($errors == 0 && $timingErrors == 0) {
                return array('status' => 'OK');
            } else {
                return array('status' => 'Error: during response deletion');
            }
        } else {
            return array('status' => 'No permission');
        }

    }

}
