<?php
/**
 * Class for specific AIVL Campaign processing
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 20 April 2017
 * @license AGPL-3.0
 */
class CRM_Fintrxn_Campaign {
  /**
   * Method to process validateForm hook
   *
   * @param $fields
   * @param & $errors
   * @param $form
   */
  public static function validateForm($fields, &$errors, $form) {
    $formAction = $form->getVar('_action');
    if ($formAction != CRM_Core_Action::DELETE) {
      $config = CRM_Fintrxn_Configuration::singleton();
      $mandatories = array(
        $config->getCocoaProfitLossCustomField('id'),
        $config->getCocoaCodeAcquisitionCustomField('id'),
        $config->getCocoaCodeFollowCustomField('id'),);
      foreach ($mandatories as $mandatory) {
        foreach ($fields as $fieldKey => $fieldValue) {
          if (substr($fieldKey, 0, 7) == 'custom_') {
            $parts = explode('_', $fieldKey);
            if (isset($parts[1]) && $parts[1] == $mandatory) {
              $mandatoryPresent = TRUE;
              if (empty($fieldValue)) {
                $errors[$fieldKey] = ts('This is a required field, you can not leave it empty!');
              }
            }
          }
        }
      }
    }
  }

  /**
   * Method to process buildForm hook
   *
   * @param & $form
   */
  public static function buildForm(&$form) {
    if (isset($form->_groupTree)) {
      $config = CRM_Fintrxn_Configuration::singleton();
      $cocoaCustomGroupId = $config->getCocoaCustomGroup('id');
      foreach ($form->_groupTree as $groupId => $groupData) {
        if ($groupId == $cocoaCustomGroupId) {
          $customFields = self::getCustomFieldIdsAndDefaults();
          foreach ($customFields as $customFieldId => $customFieldDefault) {
            $defaults[$customFieldId] = $customFieldDefault;
          }
          $form->setDefaults($defaults);
        }
      }
    }
  }

  /**
   * Method to get the custom field ids and defaults for cocoa codes
   *
   * @return array
   */
  private static function getCustomFieldIdsAndDefaults() {
    $config = CRM_Fintrxn_Configuration::singleton();
    return array(
      'custom_'.$config->getCocoaProfitLossCustomField('id').'_-1' => CRM_Fintrxn_Utils::getDefaultProfitLossCocoaCode(),
      'custom_'.$config->getCocoaCodeAcquisitionCustomField('id').'_-1' => CRM_Fintrxn_Utils::getDefaultAcquisitionYearCocoaCode(),
      'custom_'.$config->getCocoaCodeFollowCustomField('id').'_-1' => CRM_Fintrxn_Utils::getDefaultFollowingYearsCocoaCode(),
    );
  }
}