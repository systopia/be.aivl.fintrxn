<?php
/*-------------------------------------------------------+
| Custom Financial Transaction Generator                 |
| Copyright (C) 2017 AIVL                                |
| Author: B. Endres (endres@systopia.de)                 |
|         E. Hommel (erik.hommel@civicoop.org)           |
+--------------------------------------------------------+
| License: AGPLv3, see LICENSE file                      |
+--------------------------------------------------------*/


/**
 * Generator for custom financial transactions
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @license AGPL-3.0
 */
class CRM_Fintrxn_Generator {

  // there can only be zero or one generator at any time
  protected static $_singleton = NULL;
  protected static $_lookupCache = array();

  // variables
  protected $_config = NULL;
  protected $_contributionId = NULL;
  protected $_operation = NULL;
  protected $_preContributionData = NULL;
  protected $_oldContributionData = NULL;
  protected $_newContributionData = NULL;
  protected $_changes = NULL;

  /**
   * CRM_Fintrxn_Generator constructor, storing the old values of the contribution
   *
   * @param $operation
   * @param $contributionId
   * @param $oldValues
   */
  public function __construct($operation, $contributionId, $values) {
    $this->_config = CRM_Fintrxn_Configuration::singleton();
    $this->_contributionId = $contributionId;
    $this->_preContributionData = $values;
    $this->_operation = $operation;
    if ($this->_operation == 'create') {
      $this->_oldContributionData = array();
      $this->_newContributionData = $values;
    } elseif ($this->_operation == 'edit') {
      $this->_oldContributionData = civicrm_api3('Contribution', 'getsingle', array('id' => $values['id']));
      $this->_newContributionData = $values;

      // fixes (don't even ask...)
      if ( empty($this->_oldContributionData['campaign_id'])
        && !empty($this->_oldContributionData['contribution_campaign_id'])) {
        $this->_oldContributionData['campaign_id'] = $this->_oldContributionData['contribution_campaign_id'];
      }
    } else {
      error_log("OPERATION '{$operation}' was ignored.");
    }
  }

  /**
   * create a new generator, overwriting an existing one if there is one
   * (expects to be called from a civicrm_pre hook, receiving the old values of the contribution before
   *  the operation is saved in the database)
   *
   * @param $operation
   * @param $oldValues
   * @param $contributionId
   */
  public static function create($operation, $values, $contributionId) {
    // error_log("CREATE $operation/$contributionId: " . json_encode($values));
    self::$_singleton = new CRM_Fintrxn_Generator($operation, $contributionId, $values);
  }

  /**
   * trigger the calculation of financial transactions
   * (expects to be called from the civicrm_post hook, receiving the new values in the ref object)
   *
   * @param $operation
   * @param $contributionId
   * @param $objectRef
   */
  public static function generate($operation, $contributionId, $objectRef) {
    // todo if the class has not initialized itself, error should be logged?
    if (self::$_singleton != NULL) {
      $newValues = array();
      if ($objectRef) {
        // convert ref object into array newValues
        foreach ($objectRef as $key => $value) {
          if (substr($key, 0, 1) != '_') {
            $newValues[$key] = $value;
          }
        }
      }
      self::$_singleton->generateFinancialTrxns($operation, $contributionId, $newValues);
    }
  }

  /**
   * main dispatcher function
   * will determine what the changes are, based on the incoming new values and the old values in the class
   *
   * @param $operation
   * @param $contributionId
   * @param $newValues
   */
  public function generateFinancialTrxns($operation, $contributionId, $newValues) {
    // error_log("GENERATE $operation/$contributionId: " . json_encode($newValues));

    // first some security checks to make sure we are actually checking the same contribution and comparing the
    // correct old values and new values
    if ($operation != $this->_operation
        || ($this->_contributionId && ($this->_contributionId != $contributionId))) {
      // something's gone wrong here because operation or contributionId is not the same as during construct
      // TODO : more meaningfull message
      error_log("FINTRXN ERROR: interleaved calls in ".__METHOD__.", this shouldn't happen!");
      return;
    }

    // will calculate the changes between the new values from the post hook and the old values from the pre hook, stored
    // in the class instance
    $this->calculateChanges($newValues);

    // switch based on case, which is determined by comparing the old and new values and the changes
    $cases = $this->calculateCases();
    error_log("CASES: " . json_encode($cases));

    foreach ($cases as $case) {
      switch ($case) {
        case 'incoming':
          $trxData = $this->createTransactionData($this->_newContributionData);
          $trxData['from_financial_account_id'] = $this->getIncomingFinancialAccountID($this->_newContributionData, $this->_preContributionData);
          $trxData['to_financial_account_id'] = $this->getFinancialAccountID($this->_newContributionData);
          $this->writeFinancialTrxn($trxData);
          break;

        case 'rebooking':
          $trxData = $this->createTransactionData($this->_newContributionData);
          $fromAccount = $this->getFinancialAccountID($this->_oldContributionData);
          $toAccount = $this->getFinancialAccountID($this->_newContributionData);

          // create first double entry booking
          $trxData['to_financial_account_id'] = $toAccount;
          $trxData['from_financial_account_id'] = $fromAccount;
          $this->writeFinancialTrxn($trxData);

          // create second double entry booking
          $trxData['to_financial_account_id'] = $fromAccount;
          $trxData['from_financial_account_id'] = $toAccount;
          $trxData['total_amount'] = -$trxData['total_amount'];
          $this->writeFinancialTrxn($trxData);
          break;

        case 'amount correction':
          // TODO:
          break;

        case 'receive date correction':
          // TODO: ask Bruno
          break;

        case 'refund date correction':
          // TODO: ask Bruno
          break;

        case 'outgoing':
          $trxData = $this->createTransactionData($this->_newContributionData);
          $trxData['from_financial_account_id'] = $this->getFinancialAccountID($this->_oldContributionData);
          $trxData['to_financial_account_id'] = $this->getOutgoingFinancialAccountID($this->_newContributionData);
          $this->writeFinancialTrxn($trxData);
          break;

        default:
          error_log("FINTRXN ERROR: unknown case $case");
          break;
      }
    }
  }

  /**
   * create a template for a financial transaction based on contribution data
   *  It will be missing the fields:
   *       from_financial_account_id
   *       to_financial_account_id
   *
   * @param $contributionData
   * @param $date
   * @return array
   */
  protected function createTransactionData($contributionData, $date = NULL) {
    if ($date===NULL) $date = date('YmHdis');

    return array(
      'trxn_date'             => $date,
      'total_amount'          => CRM_Utils_Array::value('total_amount', $contributionData),
      'fee_amount'            => CRM_Utils_Array::value('fee_amount', $contributionData),
      'net_amount'            => CRM_Utils_Array::value('net_amount', $contributionData, CRM_Utils_Array::value('total_amount', $contributionData)),
      'currency'              => CRM_Utils_Array::value('currency', $contributionData),
      'trxn_id'               => CRM_Utils_Array::value('trxn_id', $contributionData),
      'trxn_result_code'      => '',
      'status_id'             => CRM_Utils_Array::value('contribution_status_id', $contributionData),
      'payment_processor_id'  => CRM_Utils_Array::value('payment_processor_id', $contributionData),
      'payment_instrument_id' => CRM_Utils_Array::value('payment_instrument_id', $contributionData),
      'check_number'          => CRM_Utils_Array::value('check_number', $contributionData),
    );
  }

  /**
   * will create a given financial transaction in the DB
   *
   * @param $data
   */
  protected function writeFinancialTrxn($data) {
    // TODO: Erik: use CRM_Fintrxn_FinancialTransaction / CRM_Fintrxn_EntityFinancialTransaction
    error_log("WOULD WRITE TO civicrm_financial_trxn: " . json_encode($data));
  }

  /**
   * Calculate the accounting case here based on the comparison between the old values from the pre hook (stored
   * in the class instance) and the new values from the post hook (also stored in the class instance)
   *
   * @return string 'incoming', 'outgoing', 'rebooking' or 'ignored'
   */
  protected function calculateCases() {
    $cases = array();
    if (in_array('contribution_status_id', $this->_changes)) {
      // contribution status change -> this
      $oldStatus = CRM_Utils_Array::value('contribution_status_id', $this->_oldContributionData);
      $newStatus = CRM_Utils_Array::value('contribution_status_id', $this->_newContributionData);

      error_log("STATUS CHANGED FROM {$oldStatus} TO {$newStatus}");
      // whenever a contribution is set TO 'completed' (including newly created ones)
      //  this is treated as an incoming transaction
      if ( !$this->_config->isCompleted($oldStatus)
         && $this->_config->isCompleted($newStatus)) {
        // incoming is always just one
        //$cases[] = 'incoming';
        return array('incoming');

      // whenever a contribution is set AWAY from status 'completed' (except newly created ones)
      //  this is treated as an incoming transaction
      } elseif ($this->_config->isCompleted($oldStatus)
            && !$this->_config->isCompleted($newStatus)
            && !$this->_config->isNew($this->_changes)) {
        // outgoing is always just one
        //$cases[] = 'outgoing';
        return array('outgoing');
      }
    }

    if ($this->_config->isAccountRelevant($this->_changes)) {
      $cases[] = 'rebooking';
    }

    if ($this->_config->isAmountChange($this->_changes)) {
      $cases[] = 'amount correction';
    }

    if (  in_array('receive_date', $this->_changes)
       && $this->_config->hasTransactions($this->_newContributionData)) {
      $cases[] = 'receive date correction';
    }

    if (in_array('refund_date', $this->_changes) && $this->_config->isReturned($newStatus)) {
      $cases[] = 'refund date correction';
    }

    return $cases;
  }

  /**
   * populate the $this->changes data
   * and fill the $this->new_contribution_data
   */
  protected function calculateChanges($updatedValues) {
    // first, update the new contribution data with the values
    foreach ($updatedValues as $key => $value) {
      if ($value !== NULL) {
        $this->_newContributionData[$key] = $value;
      }
    }

    // then, copy the old data values to the new ones, if they haven't changed
    foreach ($this->_oldContributionData as $key => $value) {
      if (!isset($this->_newContributionData[$key])) {
        $this->_newContributionData[$key] = $value;
      }
    }

    // finally calculate the changes
    $this->_changes = array();
    foreach ($this->_newContributionData as $key => $value) {
      if ($this->_newContributionData[$key] != CRM_Utils_Array::value($key, $this->_oldContributionData)) {
        $this->_changes[] = $key;
      }
    }
  }


  /**
   * look up incoming financial account id based on
   * the incoming bank account
   */
  protected function getIncomingFinancialAccountID($contribution_data, $fallback_contribution_data) {
    $incoming_bank_account_key = $this->_config->getIncomingBankAccountKey();
    if (!empty($contribution_data[$incoming_bank_account_key])) {
      $iban = $contribution_data[$incoming_bank_account_key];
    }
    if (empty($iban) && !empty($fallback_contribution_data[$incoming_bank_account_key])) {
      $iban = $fallback_contribution_data[$incoming_bank_account_key];
    }

    if (!empty($iban)) {
      // lookup account id
      $account = $this->cachedLookup('FinancialAccount',array(
        'name'              => $iban,
        // TODO: enable: 'account_type_code' => 'AIVLINC'
        'return'            => 'id'));

      if (empty($account['id'])) {
        throw new Exception("INC financial account for IBAN '{$iban}' not found.", 1);
      } else {
        return $account['id'];
      }
    }
  }

  /**
   * look up incoming financial account id based on
   * the incoming bank account
   */
  protected function getOutgoingFinancialAccountID($contributionData) {
    $refund_bank_account_key = $this->_config->getRefundBankAccountKey();
    if (!empty($contributionData[$refund_bank_account_key])) {
      $iban = $contributionData[$refund_bank_account_key];

      // lookup account id
      $account = $this->cachedLookup('FinancialAccount',array(
        'name'              => $iban,
        // TODO: enable: 'account_type_code' => 'AIVLINC'
        'return'            => 'id'));

      if (empty($account['id'])) {
        throw new Exception("INC financial account for IBAN '{$iban}' not found.", 1);
      } else {
        return $account['id'];
      }
    }
  }



  /**
   * calculate the (target) financial account ID of the given contribution
   *
   * @param $contributionData
   * @return mixed
   */
  protected function getFinancialAccountID($contributionData) {
    // TODO: check with accounting/databeheer
    if (empty($contributionData['campaign_id'])) {
      // TODO: there SHOULD be a fallback account
      error_log("FINTRXN ERROR: contribution has no campaign!");
      $accountingCode = '0000';
    } else {
      // get the COCOA codes from the campaign
      $campaign = $this->cachedLookup('Campaign', array(
        'id' => $contributionData['campaign_id'],
        'return' => $this->_config->getCocoaFieldList()));

      // if the contribution year is the acquisition year, use custom_85, otherwise custom_86
      // TODO: check with Ilja what the new situation is to be, expect always use custom_85
      $accountingCode = $this->_config->getCocoaValue($campaign, $contributionData['receive_date']);
    }

    // lookup account id
    $account = $this->cachedLookup('FinancialAccount',array(
      'accounting_code' => $accountingCode,
      'return' => 'id'));

    if (empty($account['id'])) {
      // TODO: should create financial accounts on the fly (if available)
      return NULL;
    } else {
      return $account['id'];
    }
  }


  /**
   * Cached API lookup
   *
   * @param $entity
   * @param $selector
   * @return mixed
   */
  protected function cachedLookup($entity, $selector) {
    // error_log("LOOKUP: $entity " . json_encode($selector));
    $cacheKey = sha1($entity.json_encode($selector));
    if (array_key_exists($cacheKey, self::$_lookupCache)) {
      return self::$_lookupCache[$cacheKey];
    } else {
      try {
        $result = civicrm_api3($entity, 'getsingle', $selector);
        self::$_lookupCache[$cacheKey] = $result;
        // error_log("RESULT: " . json_encode($result));
        return $result;
      } catch (Exception $e) {
        // not uniquely identified
        self::$_lookupCache[$cacheKey] = NULL;
        return NULL;
      }
    }
  }
}