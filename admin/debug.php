<?php
/* Debug script for FinTS connection - DELETE AFTER USE */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load Dolibarr environment FIRST (it handles sessions)
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

// Option to clear session data
if (isset($_GET['clear'])) {
    unset($_SESSION['fints_debug_state']);
    unset($_SESSION['fints_debug_login']);
    unset($_SESSION['fints_debug_pin']);
    echo "<p style='color:green;'>✓ Session-Daten gelöscht!</p>";
    echo "<p><a href='?'>Weiter ohne Parameter</a></p>";
}

// Show if session has stored data
$hasSessionData = isset($_SESSION['fints_debug_state']) || isset($_SESSION['fints_debug_login']);

echo "<h1>FinTS Bank Debug</h1>";

if ($hasSessionData) {
    echo "<p style='color:orange;'>⚠ Es sind noch Session-Daten gespeichert. <a href='?clear=1'>Session löschen</a></p>";
}

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

// Check if we're submitting a TAN for an existing session
if (isset($_GET['tan']) && !empty($_GET['tan']) && isset($_SESSION['fints_debug_state'])) {
    echo "Submitting TAN for existing session...\n";
    flush();

    try {
        $acc = $accounts[0];
        $testPin = $_SESSION['fints_debug_pin'];
        $productName = !empty($acc->product_name) ? $acc->product_name : '0F4CA8A225AC9799E6BE3F334';

        $options = new \Fhp\Options\FinTsOptions();
        $options->url = $acc->fints_url;
        $options->bankCode = $acc->bank_code;
        $options->productName = $productName;
        $options->productVersion = '1.0';

        $credentials = \Fhp\Options\Credentials::create($acc->username, $testPin);

        // Restore persisted state
        $fints = \Fhp\FinTs::new($options, $credentials, $_SESSION['fints_debug_state']);
        echo "✓ FinTs session restored\n";

        // Restore the login action
        $login = unserialize($_SESSION['fints_debug_login']);

        // Submit TAN
        echo "Submitting TAN: " . $_GET['tan'] . "\n";
        $fints->submitTan($login, $_GET['tan']);

        if ($login->needsTan()) {
            echo "⚠ Another TAN required!\n";
            // Save state again
            $_SESSION['fints_debug_state'] = $fints->persist();
            $_SESSION['fints_debug_login'] = serialize($login);
        } else {
            echo "✓ TAN accepted, login complete!\n";

            // Clear session state
            unset($_SESSION['fints_debug_state']);
            unset($_SESSION['fints_debug_login']);
            unset($_SESSION['fints_debug_pin']);

            // Now get SEPA accounts
            echo "\nGetting SEPA accounts...\n";
            $getSepaAccounts = \Fhp\Action\GetSEPAAccounts::create();
            $fints->execute($getSepaAccounts);

            if ($getSepaAccounts->needsTan()) {
                echo "⚠ TAN required for account list!\n";
            } else {
                $sepaAccounts = $getSepaAccounts->getAccounts();
                echo "✓ Got " . count($sepaAccounts) . " SEPA account(s):\n";
                foreach ($sepaAccounts as $sepa) {
                    echo "  - IBAN: " . $sepa->getIban() . "\n";
                    echo "    Account: " . $sepa->getAccountNumber() . "\n";
                }
            }
        }

        $fints->close();
        echo "\n✓ Connection closed\n";

    } catch (\Throwable $t) {
        echo "✗ Error: " . get_class($t) . "\n";
        echo "  Message: " . $t->getMessage() . "\n";
        // Clear session on error
        unset($_SESSION['fints_debug_state']);
        unset($_SESSION['fints_debug_login']);
        unset($_SESSION['fints_debug_pin']);
    }

} elseif (isset($_GET['pin']) && !empty($_GET['pin']) && count($accounts) > 0) {
    $acc = $accounts[0];
    $testPin = $_GET['pin'];
    echo "Testing LIVE connection with real PIN...\n";
    flush();

    // Clear any old session state
    unset($_SESSION['fints_debug_state']);
    unset($_SESSION['fints_debug_login']);
    unset($_SESSION['fints_debug_pin']);

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
        $mode999 = null;
        $modePhoto = null;
        foreach ($tanModes as $tanMode) {
            $id = $tanMode->getId();
            $name = $tanMode->getName();
            echo "  - " . $id . ": " . $name . "\n";

            // Track special modes
            if ($id == 999) {
                $mode999 = $tanMode;
                echo "    ^ Einschritt-Verfahren (keine TAN für Leseoperationen)\n";
            }
            if (stripos($name, 'photo') !== false) {
                $modePhoto = $tanMode;
            }
            if ($selectedMode === null) {
                $selectedMode = $tanMode;
            }
        }

        // Check if user wants to try mode 999
        if (isset($_GET['mode']) && $_GET['mode'] == '999' && $mode999) {
            $selectedMode = $mode999;
            echo "\n⚡ Using mode 999 (Einschritt) as requested\n";
        } elseif (isset($_GET['mode']) && $_GET['mode'] == 'skip') {
            $selectedMode = null;
            echo "\n⚡ Skipping TAN mode selection as requested\n";
        } elseif ($modePhoto) {
            $selectedMode = $modePhoto;
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
        } else {
            echo "\n⚠ No TAN mode selected - will use bank default\n";
        }

        // Step 2: Login
        echo "\nLogging in...\n";
        flush();
        $login = $fints->login();
        if ($login->needsTan()) {
            echo "⚠ TAN required for login!\n";
            $tanRequest = $login->getTanRequest();

            // Debug: Show all TAN request info
            echo "\n  === TAN REQUEST DETAILS ===\n";
            echo "  Challenge Text: " . $tanRequest->getChallenge() . "\n";
            echo "  TAN Medium Name: " . ($tanRequest->getTanMediumName() ?: '(none)') . "\n";

            // Check for decoupled mode
            if (method_exists($tanRequest, 'isDecoupled')) {
                echo "  Is Decoupled (Push): " . ($tanRequest->isDecoupled() ? 'YES' : 'NO') . "\n";
            }

            // Check for HHD/UC challenge (photoTAN image)
            $challengeHhdUcRaw = $tanRequest->getChallengeHhdUc();
            // Convert Bin object to string if needed
            if ($challengeHhdUcRaw instanceof \Fhp\Syntax\Bin) {
                $challengeHhdUc = $challengeHhdUcRaw->getData();
            } else {
                $challengeHhdUc = $challengeHhdUcRaw;
            }
            echo "  Has HHD/UC Challenge: " . ($challengeHhdUc ? 'YES (' . strlen($challengeHhdUc) . ' bytes)' : 'NO') . "\n";

            // Check for flicker code
            if (method_exists($tanRequest, 'getChallengeFlicker')) {
                $flicker = $tanRequest->getChallengeFlicker();
                echo "  Has Flicker Code: " . ($flicker ? 'YES' : 'NO') . "\n";
            }

            echo "  ============================\n\n";

            if ($challengeHhdUc && strlen($challengeHhdUc) > 0) {
                echo "  PhotoTAN/HHD Data available (" . strlen($challengeHhdUc) . " bytes)\n";

                // Save raw data to file for analysis
                $tmpFile = '/tmp/phototan_challenge_' . time() . '.bin';
                file_put_contents($tmpFile, $challengeHhdUc);
                echo "  Saved raw data to: " . $tmpFile . "\n";

                // Try to parse using TanRequestChallengeImage format:
                // 2 bytes: MIME type length (big-endian)
                // N bytes: MIME type string
                // 2 bytes: Image data length (big-endian)
                // M bytes: Image data
                $mimeType = null;
                $imageData = null;
                $parseError = null;

                try {
                    $offset = 0;
                    $dataLen = strlen($challengeHhdUc);

                    echo "  Trying TanRequestChallengeImage format parsing...\n";

                    // Read MIME type length (2 bytes, big-endian)
                    if ($dataLen >= 2) {
                        $mimeTypeLen = ord($challengeHhdUc[0]) * 256 + ord($challengeHhdUc[1]);
                        echo "  MIME type length: " . $mimeTypeLen . "\n";

                        if ($mimeTypeLen > 0 && $mimeTypeLen < 50 && $dataLen >= 2 + $mimeTypeLen) {
                            $mimeType = substr($challengeHhdUc, 2, $mimeTypeLen);
                            echo "  MIME type: '" . $mimeType . "'\n";

                            // Check if MIME type looks valid
                            if (strpos($mimeType, 'image/') === 0) {
                                $offset = 2 + $mimeTypeLen;

                                // Read image data length (2 bytes, big-endian)
                                if ($dataLen >= $offset + 2) {
                                    $imageLen = ord($challengeHhdUc[$offset]) * 256 + ord($challengeHhdUc[$offset + 1]);
                                    echo "  Image data length: " . $imageLen . "\n";

                                    $offset += 2;
                                    $remainingBytes = $dataLen - $offset;
                                    echo "  Remaining bytes: " . $remainingBytes . "\n";

                                    if ($remainingBytes >= $imageLen && $imageLen > 0) {
                                        $imageData = substr($challengeHhdUc, $offset, $imageLen);
                                        echo "  ✓ Successfully parsed image data!\n";
                                    } else {
                                        // Maybe length field is 4 bytes or uses remaining data
                                        $imageData = substr($challengeHhdUc, $offset);
                                        echo "  Using all remaining data as image (" . strlen($imageData) . " bytes)\n";
                                    }
                                }
                            } else {
                                echo "  MIME type doesn't look like an image type\n";
                            }
                        } else {
                            echo "  MIME type length invalid or too large\n";
                        }
                    }
                } catch (\Exception $e) {
                    $parseError = $e->getMessage();
                    echo "  Parse error: " . $parseError . "\n";
                }

                // If structured parsing failed, try direct image detection
                if (!$imageData) {
                    echo "  Falling back to raw image detection...\n";

                    // Check raw magic bytes
                    $magic = substr($challengeHhdUc, 0, 16);
                    $magicHex = bin2hex($magic);
                    echo "  First 16 bytes (hex): " . $magicHex . "\n";

                    // Try getimagesizefromstring on raw data
                    $imgInfo = @getimagesizefromstring($challengeHhdUc);
                    if ($imgInfo !== false) {
                        echo "  Raw data is a valid image: " . $imgInfo[0] . "x" . $imgInfo[1] . " " . $imgInfo['mime'] . "\n";
                        $mimeType = $imgInfo['mime'];
                        $imageData = $challengeHhdUc;
                    } else {
                        // Check magic bytes for known formats
                        if (substr($magicHex, 0, 16) === '89504e470d0a1a0a') {
                            $mimeType = 'image/png';
                            $imageData = $challengeHhdUc;
                        } elseif (substr($magicHex, 0, 4) === 'ffd8') {
                            $mimeType = 'image/jpeg';
                            $imageData = $challengeHhdUc;
                        }
                    }
                }

                // Close pre tag for HTML output
                echo "</pre>\n";

                if ($imageData && $mimeType) {
                    // Verify the extracted image data
                    $verifyInfo = @getimagesizefromstring($imageData);
                    if ($verifyInfo !== false) {
                        echo '<p style="color:green;">✓ Image verified: ' . $verifyInfo[0] . 'x' . $verifyInfo[1] . ' ' . $verifyInfo['mime'] . '</p>';
                    }

                    echo '<div style="border:2px solid #000; padding:10px; margin:10px 0; background:#fff; display:inline-block;">';
                    echo '<p><strong>Scannen Sie dieses Bild mit Ihrer photoTAN-App:</strong></p>';
                    echo '<img src="data:' . htmlspecialchars($mimeType) . ';base64,' . base64_encode($imageData) . '" alt="PhotoTAN Challenge" style="max-width:400px; display:block;" />';
                    echo '</div>';
                } else {
                    echo '<div style="border:2px solid red; padding:10px; margin:10px 0; background:#fff;">';
                    echo '<p><strong>⚠ Konnte Bilddaten nicht extrahieren</strong></p>';
                    echo '<p>Die Challenge-Daten haben ein unbekanntes Format.</p>';
                    echo '<p>Raw hex (first 100 chars): <code>' . substr(bin2hex($challengeHhdUc), 0, 100) . '</code></p>';
                    echo '<p>Raw data saved to: ' . $tmpFile . '</p>';
                    echo '<p>Prüfen Sie die Datei mit: <code>file ' . $tmpFile . ' && xxd ' . $tmpFile . ' | head</code></p>';
                    echo '</div>';
                }
                echo "\n<pre>\n";
            }

            // Check if this is a decoupled (push) TAN
            $isDecoupled = method_exists($tanRequest, 'isDecoupled') && $tanRequest->isDecoupled();

            if ($isDecoupled || isset($_GET['check'])) {
                // Decoupled TAN - check if user confirmed in app
                echo "  📱 Push-TAN: Bitte in der photoTAN-App bestätigen...\n";
                flush();

                // Poll for confirmation (max 60 seconds)
                $maxWait = 60;
                $interval = 3;
                $confirmed = false;

                for ($i = 0; $i < $maxWait / $interval; $i++) {
                    echo "  Warte auf Bestätigung... (" . ($i * $interval) . "s)\n";
                    flush();

                    if ($fints->checkDecoupledSubmission($login)) {
                        $confirmed = true;
                        break;
                    }
                    sleep($interval);
                }

                if ($confirmed) {
                    echo "✓ Push-TAN bestätigt, Login erfolgreich!\n";
                } else {
                    echo "✗ Timeout - keine Bestätigung erhalten\n";
                    $fints->close();
                    echo "\n</pre>";
                    echo '<p><strong>Timeout! Bitte erneut versuchen und in der App bestätigen.</strong></p>';
                    exit;
                }
            } else {
                // Save state for TAN submission
                $_SESSION['fints_debug_state'] = $fints->persist();
                $_SESSION['fints_debug_login'] = serialize($login);
                $_SESSION['fints_debug_pin'] = $testPin;

                echo "\n  📱 Scannen Sie das Bild mit Ihrer photoTAN-App!\n";
                echo "  ➡ Geben Sie die TAN ein: ?tan=IHRE_TAN\n";
                echo "  ➡ Oder bei Push-TAN: ?check=1\n";
                echo "\n  Session gespeichert - Sie können jetzt die TAN eingeben.\n";

                echo "\n</pre>";
                echo '<div style="border:2px solid blue; padding:10px; margin:10px; background:#f0f8ff;">';
                echo '<p><strong>Nächster Schritt:</strong></p>';
                echo '<form method="get" action="">';
                echo '<label>TAN eingeben: <input type="text" name="tan" size="10" autofocus /></label>';
                echo ' <button type="submit">Absenden</button>';
                echo '</form>';
                echo '<p style="font-size:12px; color:#666;">Oder URL: ?tan=IHRE_TAN</p>';
                echo '</div>';
                exit;
            }
        } else {
            echo "✓ Login successful (no TAN needed)\n";
        }

        // Step 3: Get SEPA accounts
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
