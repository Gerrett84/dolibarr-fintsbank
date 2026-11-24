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

// This endpoint must always answer with clean JSON. PHP notices, warnings and
// deprecations (e.g. from php-fints on PHP 8.4+) are logged instead of being
// printed, and any stray output is dropped before the JSON is sent.
ini_set('display_errors', '0');
ob_start();

function fintsSyncLog($message)
{
    if (function_exists('dol_syslog')) {
        dol_syslog('FinTS sync: '.$message, LOG_WARNING);
    } else {
        error_log('FinTS sync: '.$message);
    }
}

function fintsSyncErrorHandler($errno, $errstr, $errfile, $errline)
{
    if (!(error_reporting() & $errno)) {
        return false; // suppressed with @
    }
    fintsSyncLog("PHP error $errno: $errstr in $errfile:$errline");
    return true; // handled: never print it into the JSON response
}

/**
 * Send a JSON response, dropping any output produced before it.
 *
 * @param array $data Response data
 * @return void
 */
function fintsJsonOut($data)
{
    while (ob_get_level() > 0) {
        $noise = ob_get_clean();
        if (trim((string) $noise) !== '') {
            fintsSyncLog('Discarded output before JSON: '.substr(strip_tags($noise), 0, 500));
        }
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    global $db;
    if (is_object($db)) {
        $db->close();
    }
    exit;
}

function fintsSyncShutdownHandler()
{
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        fintsSyncLog("Fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(array(
            'success' => false,
            'error' => "Fatal Error: {$error['message']} in {$error['file']}:{$error['line']}"
        ));
    }
}

set_error_handler('fintsSyncErrorHandler');
register_shutdown_function('fintsSyncShutdownHandler');

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
    fintsJsonOut(array('success' => false, 'error' => 'Include of main fails'));
}

ini_set('display_errors', '0');
set_error_handler('fintsSyncErrorHandler');

dol_include_once('/fintsbank/class/fintsaccount.class.php');
dol_include_once('/fintsbank/class/fintsservice.class.php');

// Security check
if (!$user->rights->fintsbank->sync) {
    fintsJsonOut(array('success' => false, 'error' => 'Access denied'));
}

// CSRF check - with NOTOKENRENEWAL, check against newtoken (not rotated)
$token = GETPOST('token', 'alpha');
$sessionToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : (isset($_SESSION['token']) ? $_SESSION['token'] : '');
if (empty($token) || $token != $sessionToken) {
    dol_syslog("FinTS CSRF check failed: received=$token, session=$sessionToken", LOG_WARNING);
    fintsJsonOut(array('success' => false, 'error' => 'Invalid security token'));
}

$action = GETPOST('action', 'aZ09');

// Check library
if (!FintsService::isLibraryAvailable()) {
    fintsJsonOut(array('success' => false, 'error' => 'php-fints library not installed'));
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
            fintsJsonOut(array('success' => false, 'error' => 'No account selected'));
        }

        if (!$pin) {
            fintsJsonOut(array('success' => false, 'error' => 'PIN required'));
        }

        // Load account
        $account = new FintsAccount($db);
        if ($account->fetch($id) <= 0) {
            fintsJsonOut(array('success' => false, 'error' => 'Account not found'));
        }

        // Set sync date
        try {
            $fromDate = new DateTime($syncFrom ?: '-30 days');
        } catch (Exception $e) {
            $fromDate = new DateTime('-30 days');
        }

        // Start sync
        $service->setAccount($account);
        $result = $service->startSync($pin, $fromDate);

        fintsJsonOut($result);
        break;

    case 'tan':
        // Submit TAN
        $tan = GETPOST('tan', 'alpha');

        if (!$tan) {
            fintsJsonOut(array('success' => false, 'error' => 'TAN required'));
        }

        $result = $service->submitTan($tan);
        fintsJsonOut($result);
        break;

    default:
        fintsJsonOut(array('success' => false, 'error' => 'Unknown action'));
}

$db->close();
