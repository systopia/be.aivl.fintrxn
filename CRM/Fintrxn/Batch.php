<?php
/**
 * Class for specific AIVL Batach processing
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 3 May 2017
 * @license AGPL-3.0
 */
class CRM_Fintrxn_Batch {

  private $_batchId = NULL;

  /**
   * CRM_Fintrxn_Batch constructor.
   *
   * @param $batchId
   * @throws Exception when 0 or more than 1 batches found, or when error from API
   */
  function __construct($batchId) {
    // check if batch actually exists, otherwise throw exception
    try {
      $count = civicrm_api3('Batch', 'getcount', array('id' => $batchId,));
      if ($count != 1) {
        throw new Exception(ts('Could not find a single batch with id '.$batchId.' in '.__METHOD__.'Found '.$count
          .' batches. Contact your system administrator'));
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new Exception(ts('Could not find a valid accounting batch in '.__METHOD__
        .', contact your system administrator. Error from API Batch getcount: '.$ex->getMessage()));
    }
    $this->_batchId = $batchId;
  }

  /**
   * Method to get the financial transactions in the batch
   *
   * @return array
   */
  public function getFinancialTransactionIds() {
    $result = array();
    if (!empty($this->_batchId)) {
      try {
        $entityBatches = civicrm_api3('EntityBatch', 'get', array(
          'batch_id' => $this->_batchId,
          'options' => array('limit' => 0,),));
        foreach ($entityBatches['values'] as $entityBatch) {
          if ($entityBatch['entity_table'] == 'civicrm_financial_trxn') {
            $result[] = $entityBatch['entity_id'];
          }
        }
      } catch (CiviCRM_API3_Exception $ex) {
        // do nothing
      }
    }
    return $result;
  }
}