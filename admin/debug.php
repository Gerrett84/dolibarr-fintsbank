<?php
/* Debug script for FinTS connection - DELETE AFTER USE */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Security check
if (!$user->admin) {
    accessforbidden();
}

dol_include_once('/fintsbank/class/fintsaccount.class.php');

echo "<h1>FinTS Bank Debug</h1>";
echo "<pre>";

// Check 1: php-fints library
echo "=== 1. php-fints Library ===\n";
$autoloadPath = dol_buildpath('/fintsbank/vendor/autoload.php', 0);
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    echo "✓ vendor/autoload.php found\n";

    if (class_exists('Fhp\FinTs')) {
        echo "✓ Fhp\FinTs class available\n";
    } else {
        echo "✗ Fhp\FinTs class NOT found\n";
    }
} else {
    echo "✗ vendor/autoload.php NOT found at: $autoloadPath\n";
    echo "  Run: composer install\n";
}

// Check 2: Account configuration
echo "\n=== 2. Account Configuration ===\n";
$fintsaccount = new FintsAccount($db);
$accounts = $fintsaccount->fetchAll();

if (count($accounts) > 0) {
    foreach ($accounts as $acc) {
        echo "Account ID: " . $acc->id . "\n";
        echo "  BLZ: '" . $acc->bank_code . "' (length: " . strlen($acc->bank_code) . ")\n";
        echo "  BLZ valid (8 digits): " . (preg_match('/^[0-9]{8}$/', $acc->bank_code) ? "✓ Yes" : "✗ No") . "\n";
        echo "  FinTS URL: '" . $acc->fints_url . "'\n";
        echo "  URL valid: " . (filter_var($acc->fints_url, FILTER_VALIDATE_URL) ? "✓ Yes" : "✗ No") . "\n";
        echo "  Username: '" . $acc->username . "'\n";
        echo "  Username contains special chars: " . (preg_match('/[^a-zA-Z0-9]/', $acc->username) ? "⚠ Yes" : "✓ No") . "\n";
        echo "  IBAN: '" . $acc->iban . "'\n";
        echo "  Product Name: '" . $acc->product_name . "'\n";
        echo "\n";
    }
} else {
    echo "No accounts configured.\n";
}

// Check 3: Test FinTS connection (without PIN)
echo "=== 3. FinTS Connection Test ===\n";

if (class_exists('Fhp\FinTs') && count($accounts) > 0) {
    $acc = $accounts[0];
    echo "Testing with account: " . $acc->bank_code . "\n\n";

    // Test with dummy PIN to see validation errors
    try {
        echo "Attempting FinTs::new()...\n";
        flush();
        echo "  Parameters:\n";
        echo "    URL: '" . $acc->fints_url . "'\n";
        echo "    BLZ: '" . $acc->bank_code . "'\n";
        echo "    Username: '" . $acc->username . "'\n";
        echo "    Customer ID: '" . ($acc->customer_id ?: $acc->username) . "'\n\n";
        flush();

        // php-fints 3.x API - FinTsOptions and Credentials
        $productName = !empty($acc->product_name) ? $acc->product_name : '0F4CA8A225AC9799E6BE3F334';

        $options = new \Fhp\Options\FinTsOptions();
        $options->url = $acc->fints_url;
        $options->bankCode = $acc->bank_code;
        $options->productName = $productName;
        $options->productVersion = '1.0';

        $credentials = \Fhp\Options\Credentials::create($acc->username, 'TESTPIN123');
        $fints = \Fhp\FinTs::new($options, $credentials);
        echo "✓ FinTs object created successfully (validation passed)\n";
        echo "  Note: This doesn't mean the PIN is correct, just that the format is valid.\n";
    } catch (\InvalidArgumentException $e) {
        echo "✗ InvalidArgumentException: " . $e->getMessage() . "\n";
        echo "  This usually means one of the parameters has an invalid format.\n";
        echo "\n  ⚠ WICHTIG: Bei der Commerzbank ist der Benutzername typischerweise:\n";
        echo "    - Ihre Teilnehmernummer (numerisch, z.B. 1234567890)\n";
        echo "    - NICHT Ihr E-Mail oder Domain!\n";
        echo "\n  Pruefen Sie Ihre Zugangsdaten im Commerzbank Online-Banking.\n";
    } catch (\Exception $e) {
        echo "✗ Exception: " . get_class($e) . "\n";
        echo "  Message: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "  Trace:\n" . $e->getTraceAsString() . "\n";
    } catch (\Throwable $t) {
        echo "✗ Error/Throwable: " . get_class($t) . "\n";
        echo "  Message: " . $t->getMessage() . "\n";
        echo "  File: " . $t->getFile() . ":" . $t->getLine() . "\n";
        echo "  Trace:\n" . $t->getTraceAsString() . "\n";
    }
}

// Check 4: PHP version and extensions
echo "\n=== 4. PHP Environment ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "OpenSSL: " . (extension_loaded('openssl') ? "✓ Loaded" : "✗ Missing") . "\n";
echo "cURL: " . (extension_loaded('curl') ? "✓ Loaded" : "✗ Missing") . "\n";
echo "mbstring: " . (extension_loaded('mbstring') ? "✓ Loaded" : "✗ Missing") . "\n";
echo "iconv: " . (extension_loaded('iconv') ? "✓ Loaded" : "✗ Missing") . "\n";

// Check 5: php-fints version
echo "\n=== 5. php-fints Library Details ===\n";
$composerLock = dol_buildpath('/fintsbank/composer.lock', 0);
if (file_exists($composerLock)) {
    $lock = json_decode(file_get_contents($composerLock), true);
    if ($lock && isset($lock['packages'])) {
        foreach ($lock['packages'] as $pkg) {
            if ($pkg['name'] === 'nemiah/php-fints') {
                echo "php-fints version: " . $pkg['version'] . "\n";
                break;
            }
        }
    }
}

// Check 6: Test with actual PIN (if provided via GET for testing only)
echo "\n=== 6. Live Connection Test ===\n";
if (isset($_GET['pin']) && !empty($_GET['pin']) && count($accounts) > 0) {
    $acc = $accounts[0];
    $testPin = $_GET['pin'];
    echo "Testing LIVE connection with real PIN...\n";
    flush();

    try {
        // php-fints 3.x API - FinTsOptions and Credentials
        $productName = !empty($acc->product_name) ? $acc->product_name : '0F4CA8A225AC9799E6BE3F334';
        echo "  Using product name: " . $productName . "\n";

        $options = new \Fhp\Options\FinTsOptions();
        $options->url = $acc->fints_url;
        $options->bankCode = $acc->bank_code;
        $options->productName = $productName;
        $options->productVersion = '1.0';

        $credentials = \Fhp\Options\Credentials::create($acc->username, $testPin);
        $fints = \Fhp\FinTs::new($options, $credentials);
        echo "✓ FinTs object created\n";

        // Step 1: Get and select TAN mode
        echo "\nAvailable TAN modes:\n";
        $tanModes = $fints->getTanModes();
        $selectedMode = null;
        foreach ($tanModes as $tanMode) {
            echo "  - " . $tanMode->getId() . ": " . $tanMode->getName() . "\n";
            // Select photoTAN or first available
            if ($selectedMode === null || stripos($tanMode->getName(), 'photo') !== false) {
                $selectedMode = $tanMode;
            }
        }

        if ($selectedMode) {
            echo "\nSelecting TAN mode: " . $selectedMode->getId() . " (" . $selectedMode->getName() . ")\n";

            // Check if TAN medium is needed
            if ($selectedMode->needsTanMedium()) {
                echo "  TAN medium required, getting media list...\n";
                $tanMedia = $fints->getTanMedia($selectedMode);
                if (!empty($tanMedia)) {
                    echo "  Available TAN media:\n";
                    foreach ($tanMedia as $medium) {
                        echo "    - " . $medium->getName() . "\n";
                    }
                    // Select first medium
                    $fints->selectTanMode($selectedMode, $tanMedia[0]->getName());
                    echo "  Selected TAN medium: " . $tanMedia[0]->getName() . "\n";
                } else {
                    $fints->selectTanMode($selectedMode);
                }
            } else {
                $fints->selectTanMode($selectedMode);
            }
            echo "✓ TAN mode selected\n";
        }

        // Step 2: Login
        echo "\nLogging in...\n";
        flush();
        $login = $fints->login();
        if ($login->needsTan()) {
            echo "⚠ TAN required for login!\n";
            $tanRequest = $login->getTanRequest();
            echo "  Challenge: " . $tanRequest->getChallenge() . "\n";
            if ($tanRequest->getChallengeHhdUc()) {
                echo "  PhotoTAN available\n";
            }
        } else {
            echo "✓ Login successful (no TAN needed)\n";
        }

        // Step 2: Get SEPA accounts
        echo "\nGetting SEPA accounts...\n";
        flush();
        $getSepaAccounts = \Fhp\Action\GetSEPAAccounts::create();
        $fints->execute($getSepaAccounts);

        if ($getSepaAccounts->needsTan()) {
            echo "⚠ TAN required for account list!\n";
            $tanRequest = $getSepaAccounts->getTanRequest();
            echo "  Challenge: " . $tanRequest->getChallenge() . "\n";
        } else {
            $sepaAccounts = $getSepaAccounts->getAccounts();
            echo "✓ Got " . count($sepaAccounts) . " SEPA account(s):\n";
            foreach ($sepaAccounts as $sepa) {
                echo "  - IBAN: " . $sepa->getIban() . "\n";
                echo "    Account: " . $sepa->getAccountNumber() . "\n";
            }
        }

        // Close connection
        $fints->close();
        echo "\n✓ Connection closed\n";

    } catch (\Throwable $t) {
        echo "✗ Error: " . get_class($t) . "\n";
        echo "  Message: " . $t->getMessage() . "\n";
        echo "  File: " . $t->getFile() . ":" . $t->getLine() . "\n";
        echo "  Trace:\n" . $t->getTraceAsString() . "\n";
    }
} else {
    echo "To test with real PIN, add ?pin=YOUR_PIN to the URL\n";
    echo "⚠ WARNING: Only use this for debugging! PIN will be in browser history!\n";
}

echo "\n</pre>";
echo "<p><strong>After debugging, delete this file!</strong></p>";
