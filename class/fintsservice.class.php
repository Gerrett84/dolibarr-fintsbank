<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/fintsservice.class.php
 * \ingroup    fintsbank
 * \brief      FinTS connection and sync service (php-fints 2.1 compatible)
 */

// Load php-fints library if available
$autoloadPath = dol_buildpath('/fintsbank/vendor/autoload.php', 0);
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

dol_include_once('/fintsbank/class/fintsaccount.class.php');
dol_include_once('/fintsbank/class/fintstransaction.class.php');

use Fhp\FinTs;
use Fhp\Dialog\Exception\TANRequiredException;

/**
 * Class FintsService - Handles FinTS connections and synchronization (php-fints 2.1)
 */
class FintsService
{
    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var FintsAccount Account configuration
     */
    private $account;

    /**
     * @var FinTs FinTS connection
     */
    private $fints;

    /**
     * @var string Error message
     */
    public $error = '';

    /**
     * @var array Error messages
     */
    public $errors = array();

    /**
     * Session key for storing FinTS state
     */
    const SESSION_KEY = 'fintsbank_state';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Check if php-fints library is available
     *
     * @return bool
     */
    public static function isLibraryAvailable()
    {
        return class_exists('Fhp\FinTs');
    }

    /**
     * Initialize FinTS connection
     *
     * @param FintsAccount $account Account configuration
     * @param string $pin User's banking PIN
     * @return bool Success
     */
    public function initConnection($account, $pin)
    {
        if (!self::isLibraryAvailable()) {
            $this->error = 'php-fints library not installed';
            return false;
        }

        $this->account = $account;

        // Validate inputs
        if (empty($account->fints_url)) {
            $this->error = 'FinTS URL is not configured';
            return false;
        }
        if (empty($account->bank_code) || !preg_match('/^[0-9]{8}$/', $account->bank_code)) {
            $this->error = 'Invalid bank code (BLZ). Must be 8 digits.';
            return false;
        }
        if (empty($account->username)) {
            $this->error = 'Username is not configured';
            return false;
        }
        if (empty($pin)) {
            $this->error = 'PIN is required';
            return false;
        }

        try {
            error_log("FinTS: Connecting to " . $account->fints_url);

            // Product name - use configured or fallback
            $productName = !empty($account->product_name) ? $account->product_name : '9FA6681DDE0E03F8CAB8';
            $productVersion = '1.0.0';

            // Create FinTS instance (php-fints 2.1 API)
            $this->fints = new FinTs(
                $account->fints_url,
                $account->bank_code,
                $account->username,
                $pin,
                $productName,
                $productVersion
            );

            return true;
        } catch (\Exception $e) {
            $this->error = 'Connection error: ' . $e->getMessage();
            error_log("FinTS Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get available SEPA accounts from bank
     *
     * @return array|false Array of SEPAAccount or false on error
     */
    public function getSepaAccounts()
    {
        if (!$this->fints) {
            $this->error = 'Not connected';
            return false;
        }

        try {
            $accounts = $this->fints->getSEPAAccounts();
            return $accounts;
        } catch (TANRequiredException $e) {
            // TAN required for account list - store state
            $this->saveState('getSEPAAccounts', $e);
            $this->error = 'TAN_REQUIRED';
            return false;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * Start fetching bank statements
     *
     * @param string $pin User's banking PIN
     * @param \DateTime $fromDate Start date
     * @param \DateTime $toDate End date (optional)
     * @return array Status array
     */
    public function startSync($pin, $fromDate, $toDate = null)
    {
        if (!$this->account) {
            return array('success' => false, 'error' => 'No account configured');
        }

        if (!self::isLibraryAvailable()) {
            return array('success' => false, 'error' => 'php-fints library not installed');
        }

        try {
            // Initialize connection
            if (!$this->initConnection($this->account, $pin)) {
                return array('success' => false, 'error' => $this->error);
            }

            // Store PIN for TAN continuation
            $_SESSION[self::SESSION_KEY . '_pin'] = $pin;
            $_SESSION[self::SESSION_KEY . '_account_id'] = $this->account->id;

            // Get SEPA accounts
            $sepaAccounts = $this->fints->getSEPAAccounts();
            if (!$sepaAccounts || count($sepaAccounts) == 0) {
                return array('success' => false, 'error' => 'No accounts found at bank');
            }

            // Find matching account
            $targetAccount = null;
            foreach ($sepaAccounts as $sepaAccount) {
                if ($this->account->iban && $sepaAccount->getIban() === $this->account->iban) {
                    $targetAccount = $sepaAccount;
                    break;
                }
            }
            if (!$targetAccount) {
                $targetAccount = $sepaAccounts[0];
            }

            // Get statements
            if (!$toDate) {
                $toDate = new \DateTime();
            }

            $statements = $this->fints->getStatementOfAccount($targetAccount, $fromDate, $toDate);

            // Process statements
            return $this->processStatements($statements);

        } catch (TANRequiredException $e) {
            // TAN required - return challenge info
            $response = $e->getResponse();

            $result = array(
                'success' => true,
                'needsTan' => true,
                'tanToken' => $e->getTANToken(),
                'challenge' => $response->getTanChallenge(),
                'tanMediumName' => $e->getTanMediaName(),
            );

            // Check for photoTAN/chipTAN image
            $challengeHHD = $response->getTanChallengeHHD();
            if ($challengeHHD) {
                $result['challengeImage'] = base64_encode($challengeHHD);
                $result['challengeType'] = 'phototan';
            }

            // Store state for continuation
            $_SESSION[self::SESSION_KEY . '_tan_token'] = $e->getTANToken();

            return $result;

        } catch (\Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Submit TAN and continue sync
     *
     * @param string $tan The TAN entered by user
     * @return array Status array
     */
    public function submitTan($tan)
    {
        try {
            // Load stored state
            $pin = isset($_SESSION[self::SESSION_KEY . '_pin']) ? $_SESSION[self::SESSION_KEY . '_pin'] : null;
            $accountId = isset($_SESSION[self::SESSION_KEY . '_account_id']) ? $_SESSION[self::SESSION_KEY . '_account_id'] : null;
            $tanToken = isset($_SESSION[self::SESSION_KEY . '_tan_token']) ? $_SESSION[self::SESSION_KEY . '_tan_token'] : null;

            if (!$pin || !$accountId || !$tanToken) {
                return array('success' => false, 'error' => 'Session expired. Please start again.');
            }

            // Load account
            $this->account = new FintsAccount($this->db);
            if ($this->account->fetch($accountId) <= 0) {
                return array('success' => false, 'error' => 'Account not found');
            }

            // Reinitialize connection
            if (!$this->initConnection($this->account, $pin)) {
                return array('success' => false, 'error' => $this->error);
            }

            // Submit TAN
            $result = $this->fints->submitTanForToken($tanToken, $tan);

            // Clear session
            $this->clearState();

            // Process result (should contain statement data)
            if ($result instanceof \Fhp\Model\StatementOfAccount\StatementOfAccount) {
                return $this->processStatements($result);
            }

            return array(
                'success' => true,
                'needsTan' => false,
                'message' => 'TAN submitted successfully'
            );

        } catch (TANRequiredException $e) {
            // Another TAN required
            $response = $e->getResponse();
            $_SESSION[self::SESSION_KEY . '_tan_token'] = $e->getTANToken();

            return array(
                'success' => true,
                'needsTan' => true,
                'tanToken' => $e->getTANToken(),
                'challenge' => $response->getTanChallenge(),
                'error' => 'Additional TAN required'
            );

        } catch (\Exception $e) {
            $this->clearState();
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Process statements and import transactions
     *
     * @param mixed $statements Statement data
     * @return array Status array
     */
    private function processStatements($statements)
    {
        $imported = 0;
        $skipped = 0;

        try {
            if (!$statements) {
                return array(
                    'success' => true,
                    'needsTan' => false,
                    'imported' => 0,
                    'skipped' => 0,
                    'message' => 'No statements returned'
                );
            }

            // Handle different return types
            $statementList = is_array($statements) ? $statements : array($statements);

            foreach ($statementList as $statement) {
                if (!method_exists($statement, 'getStatements')) {
                    continue;
                }

                foreach ($statement->getStatements() as $stmtDay) {
                    if (!method_exists($stmtDay, 'getTransactions')) {
                        continue;
                    }

                    foreach ($stmtDay->getTransactions() as $transaction) {
                        $fintsTransaction = new FintsTransaction($this->db);
                        $fintsTransaction->fk_fintsbank_account = $this->account->id;

                        // Generate unique ID
                        $fintsTransaction->transaction_id = md5(
                            $transaction->getBookingDate()->format('Y-m-d') .
                            $transaction->getAmount() .
                            $transaction->getDescription1() .
                            $transaction->getAccountNumber()
                        );

                        // Check if exists
                        if ($fintsTransaction->fetchByTransactionId($fintsTransaction->transaction_id, $this->account->id)) {
                            $skipped++;
                            continue;
                        }

                        // Fill data
                        $fintsTransaction->booking_date = $transaction->getBookingDate()->getTimestamp();
                        $fintsTransaction->value_date = $transaction->getValutaDate() ? $transaction->getValutaDate()->getTimestamp() : null;
                        $fintsTransaction->amount = $transaction->getAmount();
                        $fintsTransaction->currency = 'EUR';
                        $fintsTransaction->name = $transaction->getName();
                        $fintsTransaction->iban = $transaction->getAccountNumber();
                        $fintsTransaction->bic = $transaction->getBankCode();
                        $fintsTransaction->description = $transaction->getDescription1() . ' ' . $transaction->getDescription2();
                        $fintsTransaction->booking_text = $transaction->getBookingText();
                        $fintsTransaction->end_to_end_id = $transaction->getEndToEndID();

                        if ($fintsTransaction->create() > 0) {
                            $imported++;
                        }
                    }
                }
            }

            // Update last sync
            $this->account->updateLastSync();

            return array(
                'success' => true,
                'needsTan' => false,
                'imported' => $imported,
                'skipped' => $skipped,
                'message' => sprintf('%d Transaktionen importiert, %d bereits vorhanden', $imported, $skipped)
            );

        } catch (\Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Save state for TAN continuation
     */
    private function saveState($action, $exception)
    {
        $_SESSION[self::SESSION_KEY . '_action'] = $action;
        $_SESSION[self::SESSION_KEY . '_tan_token'] = $exception->getTANToken();
    }

    /**
     * Clear saved state
     */
    private function clearState()
    {
        unset($_SESSION[self::SESSION_KEY . '_pin']);
        unset($_SESSION[self::SESSION_KEY . '_account_id']);
        unset($_SESSION[self::SESSION_KEY . '_tan_token']);
        unset($_SESSION[self::SESSION_KEY . '_action']);
    }

    /**
     * Set account for operations
     *
     * @param FintsAccount $account
     */
    public function setAccount($account)
    {
        $this->account = $account;
    }
}
