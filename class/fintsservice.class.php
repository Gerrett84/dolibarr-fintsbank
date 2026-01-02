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

            // Select TAN mode first (required for php-fints 3.x)
            $tanModes = $this->fints->getTanModes();
            if (empty($tanModes)) {
                return array('success' => false, 'error' => 'No TAN modes available from bank');
            }

            // Use stored TAN mode or select first available
            $selectedTanMode = null;
            $storedTanModeId = isset($_SESSION[self::SESSION_KEY . '_tan_mode']) ? $_SESSION[self::SESSION_KEY . '_tan_mode'] : null;

            if ($storedTanModeId) {
                foreach ($tanModes as $mode) {
                    if ($mode->getId() == $storedTanModeId) {
                        $selectedTanMode = $mode;
                        break;
                    }
                }
            }

            // If no stored mode or not found, use first available (or prefer photoTAN/pushTAN)
            if (!$selectedTanMode) {
                foreach ($tanModes as $mode) {
                    $modeName = strtolower($mode->getName());
                    if (strpos($modeName, 'photo') !== false || strpos($modeName, 'push') !== false || strpos($modeName, 'app') !== false) {
                        $selectedTanMode = $mode;
                        break;
                    }
                }
                if (!$selectedTanMode) {
                    $selectedTanMode = $tanModes[0];
                }
            }

            $this->fints->selectTanMode($selectedTanMode);
            $_SESSION[self::SESSION_KEY . '_tan_mode'] = $selectedTanMode->getId();

            error_log("FinTS: Selected TAN mode: " . $selectedTanMode->getName());

            // Login first
            $login = $this->fints->login();
            if ($login->needsTan()) {
                // TAN required for login - return challenge
                $_SESSION[self::SESSION_KEY . '_persisted'] = $this->fints->persist();
                $_SESSION[self::SESSION_KEY . '_action'] = serialize($login);
                $_SESSION[self::SESSION_KEY . '_step'] = 'login';

                $tanRequest = $login->getTanRequest();
                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'step' => 'login',
                    'challenge' => $tanRequest->getChallenge(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                );

                // Extract and encode challenge image
                $challengeHhdUc = $tanRequest->getChallengeHhdUc();
                $imageInfo = $this->extractChallengeImage($challengeHhdUc);
                if ($imageInfo) {
                    $result['challengeImage'] = base64_encode($imageInfo['imageData']);
                    $result['challengeMimeType'] = $imageInfo['mimeType'];
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
                $_SESSION[self::SESSION_KEY . '_step'] = 'statement';

                $tanRequest = $getStatement->getTanRequest();
                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'challenge' => $tanRequest->getChallenge(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                );

                // Extract and encode challenge image
                $challengeHhdUc = $tanRequest->getChallengeHhdUc();
                $imageInfo = $this->extractChallengeImage($challengeHhdUc);
                if ($imageInfo) {
                    $result['challengeImage'] = base64_encode($imageInfo['imageData']);
                    $result['challengeMimeType'] = $imageInfo['mimeType'];
                    $result['challengeType'] = 'phototan';
                }

                return $result;
            }

            // No TAN needed - process statements
            $statement = $getStatement->getStatement();
            return $this->processStatements($statement ? array($statement) : array());

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
            $step = isset($_SESSION[self::SESSION_KEY . '_step']) ? $_SESSION[self::SESSION_KEY . '_step'] : null;

            if (!$pin || !$accountId || !$persistedInstance || !$serializedAction) {
                return array('success' => false, 'error' => 'Session expired. Please start again.');
            }

            // Load account
            $this->account = new FintsAccount($this->db);
            if ($this->account->fetch($accountId) <= 0) {
                return array('success' => false, 'error' => 'Account not found');
            }

            // Reinitialize connection with persisted state
            // Note: Do NOT call getTanModes() or selectTanMode() here!
            // The persisted state already contains the TAN mode selection.
            // Calling these methods would start a new dialog and break the signature.
            if (!$this->initConnection($this->account, $pin, $persistedInstance)) {
                return array('success' => false, 'error' => $this->error);
            }

            // Restore action
            $action = unserialize($serializedAction);

            // Submit TAN
            $this->fints->submitTan($action, $tan);

            if ($action->needsTan()) {
                // Another TAN required
                $_SESSION[self::SESSION_KEY . '_persisted'] = $this->fints->persist();
                $_SESSION[self::SESSION_KEY . '_action'] = serialize($action);

                $tanRequest = $action->getTanRequest();
                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'challenge' => $tanRequest->getChallenge(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                );

                // Extract and encode challenge image
                $challengeHhdUc = $tanRequest->getChallengeHhdUc();
                $imageInfo = $this->extractChallengeImage($challengeHhdUc);
                if ($imageInfo) {
                    $result['challengeImage'] = base64_encode($imageInfo['imageData']);
                    $result['challengeMimeType'] = $imageInfo['mimeType'];
                    $result['challengeType'] = 'phototan';
                }

                return $result;
            }

            // TAN accepted - continue based on step
            if ($step === 'login') {
                // Login complete, now continue with getting accounts and statements
                return $this->continueAfterLogin();
            }

            // Statement step - process the results
            $this->clearState();
            $statement = $action->getStatement();
            return $this->processStatements($statement ? array($statement) : array());

        } catch (\Exception $e) {
            $this->clearState();
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Continue sync after login TAN is submitted
     *
     * @return array Status array
     */
    private function continueAfterLogin()
    {
        try {
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

            // Get date range from session
            $fromDateStr = isset($_SESSION[self::SESSION_KEY . '_from_date']) ? $_SESSION[self::SESSION_KEY . '_from_date'] : '-30 days';
            $toDateStr = isset($_SESSION[self::SESSION_KEY . '_to_date']) ? $_SESSION[self::SESSION_KEY . '_to_date'] : null;

            $fromDate = new \DateTime($fromDateStr);
            $toDate = $toDateStr ? new \DateTime($toDateStr) : new \DateTime();

            // Get statements
            $getStatement = \Fhp\Action\GetStatementOfAccount::create($targetAccount, $fromDate, $toDate);
            $this->fints->execute($getStatement);

            if ($getStatement->needsTan()) {
                // TAN required - persist state and return challenge
                $_SESSION[self::SESSION_KEY . '_persisted'] = $this->fints->persist();
                $_SESSION[self::SESSION_KEY . '_action'] = serialize($getStatement);
                $_SESSION[self::SESSION_KEY . '_step'] = 'statement';

                $tanRequest = $getStatement->getTanRequest();
                $result = array(
                    'success' => true,
                    'needsTan' => true,
                    'challenge' => $tanRequest->getChallenge(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                );

                // Extract and encode challenge image
                $challengeHhdUc = $tanRequest->getChallengeHhdUc();
                $imageInfo = $this->extractChallengeImage($challengeHhdUc);
                if ($imageInfo) {
                    $result['challengeImage'] = base64_encode($imageInfo['imageData']);
                    $result['challengeMimeType'] = $imageInfo['mimeType'];
                    $result['challengeType'] = 'phototan';
                }

                return $result;
            }

            // No TAN needed - process statements
            $this->clearState();
            $statement = $getStatement->getStatement();
            return $this->processStatements($statement ? array($statement) : array());

        } catch (\Exception $e) {
            $this->clearState();
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Process statements and import transactions
     *
     * @param array $statementOfAccounts Array of StatementOfAccount objects
     * @return array Status array
     */
    private function processStatements($statementOfAccounts)
    {
        $imported = 0;
        $skipped = 0;

        try {
            if (!$statementOfAccounts || count($statementOfAccounts) == 0) {
                return array(
                    'success' => true,
                    'needsTan' => false,
                    'imported' => 0,
                    'skipped' => 0,
                    'message' => 'No statements returned'
                );
            }

            // StatementOfAccount contains Statements (daily), each Statement contains Transactions
            foreach ($statementOfAccounts as $statementOfAccount) {
                // Get daily statements from the StatementOfAccount
                foreach ($statementOfAccount->getStatements() as $statement) {
                    // Get transactions from each daily statement
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

                        // Get amount with correct sign based on credit/debit
                        $amount = $transaction->getAmount();
                        $creditDebit = $transaction->getCreditDebit();
                        // D = Debit (outgoing), C = Credit (incoming)
                        // RD = Reversal Debit, RC = Reversal Credit
                        if ($creditDebit == 'D' || $creditDebit == 'RC') {
                            $amount = -abs($amount); // Outgoing = negative
                        } else {
                            $amount = abs($amount); // Incoming = positive
                        }
                        $fintsTransaction->amount = $amount;
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
        unset($_SESSION[self::SESSION_KEY . '_tan_mode']);
        unset($_SESSION[self::SESSION_KEY . '_step']);
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

    /**
     * Extract image data from FinTS challenge
     *
     * The challenge data format is:
     * - 2 bytes: MIME type length (big-endian)
     * - N bytes: MIME type string
     * - 2 bytes: Image data length (big-endian)
     * - M bytes: Image data
     *
     * @param mixed $challengeHhdUc Raw challenge data (may be Bin object)
     * @return array|null Array with 'mimeType' and 'imageData', or null on failure
     */
    private function extractChallengeImage($challengeHhdUc)
    {
        if (!$challengeHhdUc) {
            return null;
        }

        // Convert Bin object to string if needed
        if ($challengeHhdUc instanceof \Fhp\Syntax\Bin) {
            $rawData = $challengeHhdUc->getData();
        } else {
            $rawData = $challengeHhdUc;
        }

        if (empty($rawData)) {
            return null;
        }

        $mimeType = null;
        $imageData = null;

        // Try to parse structured format
        try {
            $dataLen = strlen($rawData);

            if ($dataLen >= 2) {
                // Read MIME type length (2 bytes, big-endian)
                $mimeTypeLen = ord($rawData[0]) * 256 + ord($rawData[1]);

                if ($mimeTypeLen > 0 && $mimeTypeLen < 50 && $dataLen >= 2 + $mimeTypeLen) {
                    $mimeType = substr($rawData, 2, $mimeTypeLen);

                    // Check if MIME type looks valid
                    if (strpos($mimeType, 'image/') === 0) {
                        $offset = 2 + $mimeTypeLen;

                        // Read image data length (2 bytes, big-endian)
                        if ($dataLen >= $offset + 2) {
                            $imageLen = ord($rawData[$offset]) * 256 + ord($rawData[$offset + 1]);
                            $offset += 2;
                            $remainingBytes = $dataLen - $offset;

                            if ($remainingBytes >= $imageLen && $imageLen > 0) {
                                $imageData = substr($rawData, $offset, $imageLen);
                            } else {
                                // Use all remaining data as image
                                $imageData = substr($rawData, $offset);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("FinTS: Challenge parse error: " . $e->getMessage());
        }

        // If structured parsing failed, try direct image detection
        if (!$imageData) {
            // Check raw magic bytes
            $magic = substr($rawData, 0, 8);

            // PNG magic: 89 50 4E 47 0D 0A 1A 0A
            if (substr($magic, 0, 8) === "\x89PNG\r\n\x1a\n") {
                $mimeType = 'image/png';
                $imageData = $rawData;
            }
            // JPEG magic: FF D8
            elseif (substr($magic, 0, 2) === "\xff\xd8") {
                $mimeType = 'image/jpeg';
                $imageData = $rawData;
            }
            // Try getimagesizefromstring as fallback
            else {
                $imgInfo = @getimagesizefromstring($rawData);
                if ($imgInfo !== false) {
                    $mimeType = $imgInfo['mime'];
                    $imageData = $rawData;
                }
            }
        }

        if ($imageData && $mimeType) {
            return array(
                'mimeType' => $mimeType,
                'imageData' => $imageData
            );
        }

        return null;
    }
}
