<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       transactions.php
 * \ingroup    fintsbank
 * \brief      View imported bank transactions
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
dol_include_once('/fintsbank/class/fintsaccount.class.php');
dol_include_once('/fintsbank/class/fintstransaction.class.php');

// Security check
if (!$user->rights->fintsbank->read) {
    accessforbidden();
}

// Load translations
$langs->loadLangs(array("banks", "fintsbank@fintsbank"));

// Parameters
$action = GETPOST('action', 'aZ09');
$id = GETPOST('id', 'int');
$status = GETPOST('status', 'alpha');
$page = GETPOST('page', 'int') ?: 0;
$limit = GETPOST('limit', 'int') ?: 50;
$offset = $page * $limit;

/*
 * Actions
 */

// Ignore transaction
if ($action == 'ignore' && GETPOST('trans_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        $trans->setStatus(FintsTransaction::STATUS_IGNORED);
        setEventMessages($langs->trans("RecordModified"), null, 'mesgs');
    }
}

/*
 * View
 */

$page_name = "Transactions";
llxHeader('', $langs->trans($page_name));

print load_fiche_titre($langs->trans("Transactions"), '', 'fa-university');

// Get available accounts
$fintsaccount = new FintsAccount($db);
$accounts = $fintsaccount->fetchAll();

if (count($accounts) == 0) {
    print '<div class="info">';
    print $langs->trans("NoRecordFound").'<br><br>';
    print '<a href="'.dol_buildpath('/fintsbank/admin/setup.php', 1).'" class="butAction">'.$langs->trans("AddBankConnection").'</a>';
    print '</div>';
    llxFooter();
    exit;
}

// Select account if not specified
if (!$id && count($accounts) > 0) {
    $id = $accounts[0]->id;
}

// Tabs
$head = array();
$h = 0;
$head[$h][0] = dol_buildpath('/fintsbank/transactions.php', 1).'?id='.$id;
$head[$h][1] = $langs->trans('Transactions');
$head[$h][2] = 'transactions';
$h++;
$head[$h][0] = dol_buildpath('/fintsbank/sync.php', 1).'?id='.$id;
$head[$h][1] = $langs->trans('SyncNow');
$head[$h][2] = 'sync';
$h++;

print dol_get_fiche_head($head, 'transactions', '', -1, 'fa-university');

// Account selector and filters
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("BankAccount").'</td>';
print '<td>'.$langs->trans("Status").'</td>';
print '<td></td>';
print '</tr>';
print '<tr class="oddeven">';

// Account
print '<td>';
print '<select name="id" class="flat minwidth200">';
foreach ($accounts as $acc) {
    $bankaccount = new Account($db);
    $bankaccount->fetch($acc->fk_bank);
    $selected = ($acc->id == $id) ? ' selected' : '';
    print '<option value="'.$acc->id.'"'.$selected.'>'.$bankaccount->label.' ('.$acc->iban.')</option>';
}
print '</select>';
print '</td>';

// Status filter
print '<td>';
print '<select name="status" class="flat">';
print '<option value="">-- '.$langs->trans("All").' --</option>';
print '<option value="new"'.($status == 'new' ? ' selected' : '').'>'.$langs->trans("StatusNew").'</option>';
print '<option value="matched"'.($status == 'matched' ? ' selected' : '').'>'.$langs->trans("StatusMatched").'</option>';
print '<option value="imported"'.($status == 'imported' ? ' selected' : '').'>'.$langs->trans("StatusImported").'</option>';
print '<option value="ignored"'.($status == 'ignored' ? ' selected' : '').'>'.$langs->trans("StatusIgnored").'</option>';
print '</select>';
print '</td>';

print '<td>';
print '<input type="submit" class="button" value="'.$langs->trans("Search").'">';
print '</td>';
print '</tr>';
print '</table>';
print '</div>';
print '</form>';

print '<br>';

// Get transactions
$transaction = new FintsTransaction($db);
$transactions = $transaction->fetchByAccount($id, $status, $limit, $offset);

// Count total
$sql = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."fintsbank_transaction WHERE fk_fintsbank_account = ".(int)$id;
if ($status) {
    $sql .= " AND status = '".$db->escape($status)."'";
}
$resql = $db->query($sql);
$obj = $db->fetch_object($resql);
$total = $obj->total;

// Transactions table
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("BookingDate").'</td>';
print '<td>'.$langs->trans("CounterParty").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '<td class="right">'.$langs->trans("Amount").'</td>';
print '<td>'.$langs->trans("Status").'</td>';
print '<td class="right">'.$langs->trans("Actions").'</td>';
print '</tr>';

if (count($transactions) > 0) {
    foreach ($transactions as $trans) {
        print '<tr class="oddeven">';

        // Date
        print '<td>'.dol_print_date($trans->booking_date, 'day').'</td>';

        // Counter party
        print '<td>';
        print dol_escape_htmltag($trans->name);
        if ($trans->iban) {
            print '<br><small class="opacitymedium">'.dol_escape_htmltag($trans->iban).'</small>';
        }
        print '</td>';

        // Description
        print '<td>';
        print '<span title="'.dol_escape_htmltag($trans->description).'">';
        print dol_trunc(dol_escape_htmltag($trans->description), 60);
        print '</span>';
        if ($trans->booking_text) {
            print '<br><small class="opacitymedium">'.$trans->booking_text.'</small>';
        }
        print '</td>';

        // Amount
        print '<td class="right nowrap">';
        $amountClass = $trans->amount >= 0 ? 'amountpaymentcomplete' : 'amountremaintopay';
        print '<span class="'.$amountClass.'">'.price($trans->amount, 0, $langs, 1, -1, 2, $trans->currency).'</span>';
        print '</td>';

        // Status
        print '<td>'.FintsTransaction::getStatusLabel($trans->status).'</td>';

        // Actions
        print '<td class="right nowrap">';
        if ($trans->status == 'new') {
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=ignore&trans_id='.$trans->id.'&id='.$id.'&token='.newToken().'" title="'.$langs->trans("IgnoreTransaction").'">';
            print img_picto($langs->trans("IgnoreTransaction"), 'disable');
            print '</a>';
        }
        print '</td>';

        print '</tr>';
    }
} else {
    print '<tr class="oddeven">';
    print '<td colspan="6" class="center">'.$langs->trans("NoRecordFound").'</td>';
    print '</tr>';
}

print '</table>';
print '</div>';

// Pagination
if ($total > $limit) {
    print '<div class="center" style="margin-top: 10px;">';
    $numPages = ceil($total / $limit);
    for ($i = 0; $i < $numPages && $i < 20; $i++) {
        $class = ($i == $page) ? 'butActionRefused' : 'butAction';
        $url = $_SERVER["PHP_SELF"].'?id='.$id.'&page='.$i.'&limit='.$limit;
        if ($status) $url .= '&status='.$status;
        print '<a class="'.$class.'" href="'.$url.'">'.($i + 1).'</a> ';
    }
    print '</div>';
}

// Sync button
print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/fintsbank/sync.php', 1).'?id='.$id.'">';
print '<i class="fas fa-sync"></i> '.$langs->trans("SyncNow");
print '</a>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
