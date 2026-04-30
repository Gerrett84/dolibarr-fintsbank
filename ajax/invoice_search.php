<?php
/**
 * AJAX: search invoices by reference or thirdparty name for split assignment
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = include "../../../main.inc.php";
}
if (!$res) {
    http_response_code(403);
    exit;
}

dol_include_once('/fintsbank/class/fintstransaction.class.php');

header('Content-Type: application/json');

if (!$user->rights->fintsbank->read) {
    print json_encode(array());
    exit;
}

$query   = GETPOST('query', 'alphanohtml');
$transId = GETPOST('trans_id', 'int');

if (strlen($query) < 1 || $transId <= 0) {
    print json_encode(array());
    exit;
}

$trans = new FintsTransaction($db);
if ($trans->fetch($transId) <= 0) {
    print json_encode(array());
    exit;
}

$results = $trans->searchInvoices($query, 20);
print json_encode($results);
