<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/sync.php
 * \ingroup    fintsbank
 * \brief      AJAX handler for bank sync
 */

// Dolibarr AJAX configuration - must be before main.inc.php
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1'); // Disable token renewal for AJAX
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

// Disable output buffering for real-time response
if (ob_get_level()) ob_end_clean();

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = include "../../../../main.inc.php";
}
if (!$res) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => 'Include of main fails'));
    exit;
}

dol_include_once('/fintsbank/class/fintsaccount.class.php');
dol_include_once('/fintsbank/class/fintsservice.class.php');

// Set JSON response header
header('Content-Type: application/json');

// Security check
if (!$user->rights->fintsbank->sync) {
    echo json_encode(array('success' => false, 'error' => 'Access denied'));
    exit;
}

// CSRF check - with NOTOKENRENEWAL, check against newtoken (not rotated)
$token = GETPOST('token', 'alpha');
$sessionToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : (isset($_SESSION['token']) ? $_SESSION['token'] : '');
if (empty($token) || $token != $sessionToken) {
    dol_syslog("FinTS CSRF check failed: received=$token, session=$sessionToken", LOG_WARNING);
    echo json_encode(array('success' => false, 'error' => 'Invalid security token'));
    exit;
}

$action = GETPOST('action', 'aZ09');

// Check library
if (!FintsService::isLibraryAvailable()) {
    echo json_encode(array('success' => false, 'error' => 'php-fints library not installed'));
    exit;
}

$service = new FintsService($db);

switch ($action) {
    case 'start':
        // Start sync process
        $id = GETPOST('id', 'int');
        // Get PIN directly from POST to avoid any sanitization
        $pin = isset($_POST['pin']) ? $_POST['pin'] : '';
        $syncFrom = GETPOST('sync_from', 'alpha');

        if (!$id) {
            echo json_encode(array('success' => false, 'error' => 'No account selected'));
            exit;
        }

        if (!$pin) {
            echo json_encode(array('success' => false, 'error' => 'PIN required'));
            exit;
        }

        // Load account
        $account = new FintsAccount($db);
        if ($account->fetch($id) <= 0) {
            echo json_encode(array('success' => false, 'error' => 'Account not found'));
            exit;
        }

        // Debug: Log account details (remove in production)
        error_log("FinTS Sync - Account: " . $account->bank_code . ", URL: " . $account->fints_url . ", User: " . $account->username);

        // Set sync date
        try {
            $fromDate = new DateTime($syncFrom ?: '-30 days');
        } catch (Exception $e) {
            $fromDate = new DateTime('-30 days');
        }

        // Start sync
        $service->setAccount($account);
        $result = $service->startSync($pin, $fromDate);

        echo json_encode($result);
        break;

    case 'tan':
        // Submit TAN
        $tan = GETPOST('tan', 'alpha');

        if (!$tan) {
            echo json_encode(array('success' => false, 'error' => 'TAN required'));
            exit;
        }

        $result = $service->submitTan($tan);
        echo json_encode($result);
        break;

    default:
        echo json_encode(array('success' => false, 'error' => 'Unknown action'));
}

$db->close();
