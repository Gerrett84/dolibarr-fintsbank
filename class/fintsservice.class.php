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
 * \brief      FinTS connection and sync service
 */

// Load php-fints library if available
$autoloadPath = dol_buildpath('/fintsbank/vendor/autoload.php', 0);
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

dol_include_once('/fintsbank/class/fintsaccount.class.php');
dol_include_once('/fintsbank/class/fintstransaction.class.php');

use Fhp\FinTs;
use Fhp\Model\SEPAAccount;
use Fhp\Action\GetStatementOfAccount;
use Fhp\BaseAction;

/**
 * Class FintsService - Handles FinTS connections and synchronization
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
     * @var string Current action state (serialized)
     */
    private $actionState;

    /**
     * @var string Error message
     */
    public $error = '';

    /**
     * @var array Error messages
     */
    public $errors = array();

    /**
     * @var string TAN challenge (image data for photoTAN)
     */
    public $tanChallenge;

    /**
     * @var string TAN challenge type
     */
    public $tanChallengeType;

    /**
     * @var string TAN instructions
     */
    public $tanInstructions;

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

        // Validate inputs before calling library
        if (empty($account->fints_url)) {
            $this->error = 'FinTS URL is not configured';
            return false;
        }
        if (empty($account->bank_code) || !preg_match('/^[0-9]{8}$/', $account->bank_code)) {
            $this->error = 'Invalid bank code (BLZ). Must be 8 digits. Current: ' . $account->bank_code;
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
            // Log connection attempt (for debugging)
            error_log("FinTS: Connecting to " . $account->fints_url . " with bank code " . $account->bank_code);

            // Create FinTS instance
            $this->fints = FinTs::new(
                $account->fints_url,
                $account->bank_code,
                $account->username,
                $pin,
                $account->customer_id ?: $account->username
            );

            return true;
        } catch (\InvalidArgumentException $e) {
            $this->error = 'Configuration error: ' . $e->getMessage();
            error_log("FinTS InvalidArgumentException: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->error = 'Connection error: ' . $e->getMessage();
            error_log("FinTS Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
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
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * Start fetching bank statements (initiates TAN process)
     *
     * @param string $pin User's banking PIN
     * @param DateTime $fromDate Start date
     * @param DateTime $toDate End date (optional)
     * @return array Status array with 'needsTan', 'tanChallenge', etc.
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

            // Get SEPA accounts
            $sepaAccounts = $this->getSepaAccounts();
            if (!$sepaAccounts || count($sepaAccounts) == 0) {
                return array('success' => false, 'error' => 'No accounts found at bank');
            }

            // Find matching account by IBAN or account number
            $targetAccount = null;
            foreach ($sepaAccounts as $sepaAccount) {
                if ($this->account->iban && $sepaAccount->getIban() === $this->account->iban) {
                    $targetAccount = $sepaAccount;
                    break;
                }
                if ($this->account->account_number && $sepaAccount->getAccountNumber() === $this->account->account_number) {
                    $targetAccount = $sepaAccount;
                    break;
                }
            }

            // If no match, use first account
            if (!$targetAccount) {
                $targetAccount = $sepaAccounts[0];
            }

            // Create statement request
            if (!$toDate) {
                $toDate = new DateTime();
            }

            $action = GetStatementOfAccount::create($targetAccount, $fromDate, $toDate);

            // Execute action (may require TAN)
            $this->fints->execute($action);

            // Check if TAN is needed
            if ($action->needsTan()) {
                // Store state for later continuation
                $this->saveState($action, $pin);

                // Get TAN challenge
                $tanRequest = $action->getTanRequest();
                $challenge = $tanRequest->getChallenge();
                $challengeHhdUc = $tanRequest->getChallengeHhdUc();

                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                    'challenge' => $challenge,
                );

                // For photoTAN, the challenge contains image data
                if ($challengeHhdUc) {
                    // HHD UC challenge (for photoTAN/chipTAN)
                    $result['challengeImage'] = base64_encode($challengeHhdUc);
                    $result['challengeType'] = 'phototan';
                }

                return $result;
            }

            // No TAN needed - process results directly
            return $this->processStatementResult($action);

        } catch (Exception $e) {
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
            // Restore state
            $stateData = $this->loadState();
            if (!$stateData) {
                return array('success' => false, 'error' => 'Session expired. Please start again.');
            }

            // Reinitialize connection
            if (!$this->initConnection($this->account, $stateData['pin'])) {
                return array('success' => false, 'error' => $this->error);
            }

            // Restore action from persisted state
            $action = $this->fints->restoreAction($stateData['actionState']);

            // Submit TAN
            $this->fints->submitTan($action, $tan);

            // Check if more TANs needed (shouldn't happen normally)
            if ($action->needsTan()) {
                return array(
                    'success' => true,
                    'needsTan' => true,
                    'error' => 'Additional TAN required'
                );
            }

            // Clear state
            $this->clearState();

            // Process results
            return $this->processStatementResult($action);

        } catch (Exception $e) {
            $this->clearState();
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Process statement results and import transactions
     *
     * @param GetStatementOfAccount $action The completed action
     * @return array Status array
     */
    private function processStatementResult($action)
    {
        try {
            $statements = $action->getStatements();
            $imported = 0;
            $skipped = 0;

            foreach ($statements as $statement) {
                foreach ($statement->getTransactions() as $transaction) {
                    // Create transaction record
                    $fintsTransaction = new FintsTransaction($this->db);
                    $fintsTransaction->fk_fintsbank_account = $this->account->id;

                    // Generate unique transaction ID
                    $fintsTransaction->transaction_id = md5(
                        $transaction->getBookingDate()->format('Y-m-d') .
                        $transaction->getAmount() .
                        $transaction->getDescription1() .
                        $transaction->getAccountNumber()
                    );

                    // Check if already exists
                    if ($fintsTransaction->fetchByTransactionId($fintsTransaction->transaction_id, $this->account->id)) {
                        $skipped++;
                        continue;
                    }

                    // Fill transaction data
                    $fintsTransaction->booking_date = $transaction->getBookingDate()->getTimestamp();
                    $fintsTransaction->value_date = $transaction->getValutaDate() ? $transaction->getValutaDate()->getTimestamp() : null;
                    $fintsTransaction->amount = $transaction->getAmount();
                    $fintsTransaction->currency = 'EUR';
                    $fintsTransaction->name = $transaction->getName();
                    $fintsTransaction->iban = $transaction->getAccountNumber(); // Could be IBAN
                    $fintsTransaction->bic = $transaction->getBankCode();
                    $fintsTransaction->description = $transaction->getDescription1() . ' ' . $transaction->getDescription2();
                    $fintsTransaction->booking_text = $transaction->getBookingText();
                    $fintsTransaction->end_to_end_id = $transaction->getEndToEndID();

                    // Store raw data
                    $fintsTransaction->raw_data = json_encode(array(
                        'description1' => $transaction->getDescription1(),
                        'description2' => $transaction->getDescription2(),
                        'primanota' => $transaction->getPN(),
                    ));

                    $result = $fintsTransaction->create();
                    if ($result > 0) {
                        $imported++;
                    }
                }
            }

            // Update last sync time
            $this->account->updateLastSync();

            return array(
                'success' => true,
                'needsTan' => false,
                'imported' => $imported,
                'skipped' => $skipped,
                'message' => sprintf('%d transactions imported, %d already existed', $imported, $skipped)
            );

        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Save state to session for TAN continuation
     *
     * @param BaseAction $action The current action
     * @param string $pin The PIN (needed for reconnection)
     */
    private function saveState($action, $pin)
    {
        $_SESSION[self::SESSION_KEY] = array(
            'accountId' => $this->account->id,
            'actionState' => $this->fints->persistAction($action),
            'pin' => $pin, // Note: In production, consider more secure storage
            'timestamp' => time()
        );
    }

    /**
     * Load state from session
     *
     * @return array|null State data or null if expired/not found
     */
    private function loadState()
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return null;
        }

        $state = $_SESSION[self::SESSION_KEY];

        // Check if expired (5 minutes)
        if (time() - $state['timestamp'] > 300) {
            $this->clearState();
            return null;
        }

        // Load account
        $this->account = new FintsAccount($this->db);
        if ($this->account->fetch($state['accountId']) <= 0) {
            return null;
        }

        return $state;
    }

    /**
     * Clear saved state
     */
    private function clearState()
    {
        unset($_SESSION[self::SESSION_KEY]);
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
