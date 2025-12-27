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
 * \brief      FinTS connection and sync service (php-fints 3.x compatible)
 */

// Load php-fints library if available
$autoloadPath = dol_buildpath('/fintsbank/vendor/autoload.php', 0);
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

dol_include_once('/fintsbank/class/fintsaccount.class.php');
dol_include_once('/fintsbank/class/fintstransaction.class.php');

use Fhp\FinTs;
use Fhp\Options\FinTsOptions;
use Fhp\Options\Credentials;

/**
 * Class FintsService - Handles FinTS connections and synchronization (php-fints 3.x)
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
     * @param string|null $persistedInstance Persisted instance data
     * @return bool Success
     */
    public function initConnection($account, $pin, $persistedInstance = null)
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

            // Create FinTS options (php-fints 3.x API)
            $options = new FinTsOptions();
            $options->url = $account->fints_url;
            $options->bankCode = $account->bank_code;
            $options->productName = !empty($account->product_name) ? $account->product_name : '0F4CA8A225AC9799E6BE3F334';
            $options->productVersion = '1.0';

            // Create credentials
            $credentials = Credentials::create($account->username, $pin);

            // Create FinTS instance
            if ($persistedInstance) {
                $this->fints = FinTs::new($options, $credentials, $persistedInstance);
            } else {
                $this->fints = FinTs::new($options, $credentials);
            }

            return true;
        } catch (\Exception $e) {
            $this->error = 'Connection error: ' . $e->getMessage();
            error_log("FinTS Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the FinTs instance
     *
     * @return FinTs|null
     */
    public function getFinTs()
    {
        return $this->fints;
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
            $getSepaAccounts = \Fhp\Action\GetSEPAAccounts::create();
            $this->fints->execute($getSepaAccounts);

            if ($getSepaAccounts->needsTan()) {
                $this->error = 'TAN required for account list';
                return false;
            }

            return $getSepaAccounts->getAccounts();
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
            // Get persisted instance from session if available
            $persistedInstance = isset($_SESSION[self::SESSION_KEY . '_persisted'])
                ? $_SESSION[self::SESSION_KEY . '_persisted']
                : null;

            // Initialize connection
            if (!$this->initConnection($this->account, $pin, $persistedInstance)) {
                return array('success' => false, 'error' => $this->error);
            }

            // Store credentials for TAN continuation
            $_SESSION[self::SESSION_KEY . '_pin'] = $pin;
            $_SESSION[self::SESSION_KEY . '_account_id'] = $this->account->id;
            $_SESSION[self::SESSION_KEY . '_from_date'] = $fromDate->format('Y-m-d');
            $_SESSION[self::SESSION_KEY . '_to_date'] = $toDate ? $toDate->format('Y-m-d') : null;

            // Login first
            $login = $this->fints->login();
            if ($login->needsTan()) {
                // TAN required for login - return challenge
                $_SESSION[self::SESSION_KEY . '_persisted'] = $this->fints->persist();

                $tanRequest = $login->getTanRequest();
                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'step' => 'login',
                    'challenge' => $tanRequest->getChallenge(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                );

                $challengeHhdUc = $tanRequest->getChallengeHhdUc();
                if ($challengeHhdUc) {
                    $result['challengeImage'] = base64_encode($challengeHhdUc);
                    $result['challengeType'] = 'phototan';
                }

                return $result;
            }

            // Get SEPA accounts
            $getSepaAccounts = \Fhp\Action\GetSEPAAccounts::create();
            $this->fints->execute($getSepaAccounts);

            if ($getSepaAccounts->needsTan()) {
                return array('success' => false, 'error' => 'TAN required for account list - not supported yet');
            }

            $sepaAccounts = $getSepaAccounts->getAccounts();
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

            // Store selected account IBAN
            $_SESSION[self::SESSION_KEY . '_target_iban'] = $targetAccount->getIban();

            // Get statements
            if (!$toDate) {
                $toDate = new \DateTime();
            }

            $getStatement = \Fhp\Action\GetStatementOfAccount::create($targetAccount, $fromDate, $toDate);
            $this->fints->execute($getStatement);

            if ($getStatement->needsTan()) {
                // TAN required - persist state and return challenge
                $_SESSION[self::SESSION_KEY . '_persisted'] = $this->fints->persist();
                $_SESSION[self::SESSION_KEY . '_action'] = serialize($getStatement);

                $tanRequest = $getStatement->getTanRequest();
                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'challenge' => $tanRequest->getChallenge(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                );

                // Check for photoTAN/chipTAN image
                $challengeHhdUc = $tanRequest->getChallengeHhdUc();
                if ($challengeHhdUc) {
                    $result['challengeImage'] = base64_encode($challengeHhdUc);
                    $result['challengeType'] = 'phototan';
                }

                return $result;
            }

            // No TAN needed - process statements
            $statements = $getStatement->getStatements();
            return $this->processStatements($statements);

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
            $persistedInstance = isset($_SESSION[self::SESSION_KEY . '_persisted']) ? $_SESSION[self::SESSION_KEY . '_persisted'] : null;
            $serializedAction = isset($_SESSION[self::SESSION_KEY . '_action']) ? $_SESSION[self::SESSION_KEY . '_action'] : null;

            if (!$pin || !$accountId || !$persistedInstance || !$serializedAction) {
                return array('success' => false, 'error' => 'Session expired. Please start again.');
            }

            // Load account
            $this->account = new FintsAccount($this->db);
            if ($this->account->fetch($accountId) <= 0) {
                return array('success' => false, 'error' => 'Account not found');
            }

            // Reinitialize connection with persisted state
            if (!$this->initConnection($this->account, $pin, $persistedInstance)) {
                return array('success' => false, 'error' => $this->error);
            }

            // Restore action
            $getStatement = unserialize($serializedAction);

            // Submit TAN
            $this->fints->submitTan($getStatement, $tan);

            if ($getStatement->needsTan()) {
                // Another TAN required
                $_SESSION[self::SESSION_KEY . '_persisted'] = $this->fints->persist();
                $_SESSION[self::SESSION_KEY . '_action'] = serialize($getStatement);

                $tanRequest = $getStatement->getTanRequest();
                return array(
                    'success' => true,
                    'needsTan' => true,
                    'challenge' => $tanRequest->getChallenge(),
                    'error' => 'Additional TAN required'
                );
            }

            // Clear session
            $this->clearState();

            // Process statements
            $statements = $getStatement->getStatements();
            return $this->processStatements($statements);

        } catch (\Exception $e) {
            $this->clearState();
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Process statements and import transactions
     *
     * @param array $statements Statement data
     * @return array Status array
     */
    private function processStatements($statements)
    {
        $imported = 0;
        $skipped = 0;

        try {
            if (!$statements || count($statements) == 0) {
                return array(
                    'success' => true,
                    'needsTan' => false,
                    'imported' => 0,
                    'skipped' => 0,
                    'message' => 'No statements returned'
                );
            }

            foreach ($statements as $statement) {
                foreach ($statement->getTransactions() as $transaction) {
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
     * Clear saved state
     */
    private function clearState()
    {
        unset($_SESSION[self::SESSION_KEY . '_pin']);
        unset($_SESSION[self::SESSION_KEY . '_account_id']);
        unset($_SESSION[self::SESSION_KEY . '_persisted']);
        unset($_SESSION[self::SESSION_KEY . '_action']);
        unset($_SESSION[self::SESSION_KEY . '_from_date']);
        unset($_SESSION[self::SESSION_KEY . '_to_date']);
        unset($_SESSION[self::SESSION_KEY . '_target_iban']);
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
