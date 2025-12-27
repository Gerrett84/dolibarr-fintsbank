<?php
/* Debug script for FinTS connection - DELETE AFTER USE */

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
        echo "  Parameters:\n";
        echo "    URL: '" . $acc->fints_url . "'\n";
        echo "    BLZ: '" . $acc->bank_code . "'\n";
        echo "    Username: '" . $acc->username . "'\n";
        echo "    Customer ID: '" . ($acc->customer_id ?: $acc->username) . "'\n\n";

        $fints = \Fhp\FinTs::new(
            $acc->fints_url,
            $acc->bank_code,
            $acc->username,
            'TESTPIN123', // Dummy PIN
            $acc->customer_id ?: $acc->username // Customer ID
        );
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
    }
}

// Check 4: PHP version and extensions
echo "\n=== 4. PHP Environment ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "OpenSSL: " . (extension_loaded('openssl') ? "✓ Loaded" : "✗ Missing") . "\n";
echo "cURL: " . (extension_loaded('curl') ? "✓ Loaded" : "✗ Missing") . "\n";
echo "mbstring: " . (extension_loaded('mbstring') ? "✓ Loaded" : "✗ Missing") . "\n";
echo "iconv: " . (extension_loaded('iconv') ? "✓ Loaded" : "✗ Missing") . "\n";

echo "\n</pre>";
echo "<p><strong>After debugging, delete this file!</strong></p>";
