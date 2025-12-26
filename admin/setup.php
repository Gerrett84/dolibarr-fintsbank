<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       admin/setup.php
 * \ingroup    fintsbank
 * \brief      Admin setup page for FinTS Bank module
 */

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
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
dol_include_once('/fintsbank/class/fintsaccount.class.php');

// Security check
if (!$user->admin) {
    accessforbidden();
}

// Load translations
$langs->loadLangs(array("admin", "banks", "fintsbank@fintsbank"));

// Parameters
$action = GETPOST('action', 'aZ09');
$id = GETPOST('id', 'int');
$confirm = GETPOST('confirm', 'alpha');

/*
 * Actions
 */

// Add new connection
if ($action == 'add') {
    $fintsaccount = new FintsAccount($db);
    $fintsaccount->fk_bank = GETPOST('fk_bank', 'int');
    $fintsaccount->bank_code = GETPOST('bank_code', 'alpha');
    $fintsaccount->fints_url = GETPOST('fints_url', 'alpha');
    $fintsaccount->username = GETPOST('username', 'alpha');
    $fintsaccount->customer_id = GETPOST('customer_id', 'alpha');
    $fintsaccount->iban = GETPOST('iban', 'alpha');
    $fintsaccount->account_number = GETPOST('account_number', 'alpha');
    $fintsaccount->sync_from_date = dol_mktime(0, 0, 0, GETPOST('sync_from_datemonth', 'int'), GETPOST('sync_from_dateday', 'int'), GETPOST('sync_from_dateyear', 'int'));

    if (empty($fintsaccount->fk_bank)) {
        setEventMessages($langs->trans("ErrorNoAccountSelected"), null, 'errors');
    } elseif (empty($fintsaccount->bank_code) || empty($fintsaccount->fints_url) || empty($fintsaccount->username)) {
        setEventMessages($langs->trans("ErrorFieldRequired"), null, 'errors');
    } else {
        $result = $fintsaccount->create($user);
        if ($result > 0) {
            setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]);
            exit;
        } else {
            setEventMessages($fintsaccount->error, null, 'errors');
        }
    }
    $action = 'create';
}

// Update connection
if ($action == 'update' && $id > 0) {
    $fintsaccount = new FintsAccount($db);
    $fintsaccount->fetch($id);

    $fintsaccount->bank_code = GETPOST('bank_code', 'alpha');
    $fintsaccount->fints_url = GETPOST('fints_url', 'alpha');
    $fintsaccount->username = GETPOST('username', 'alpha');
    $fintsaccount->customer_id = GETPOST('customer_id', 'alpha');
    $fintsaccount->iban = GETPOST('iban', 'alpha');
    $fintsaccount->account_number = GETPOST('account_number', 'alpha');
    $fintsaccount->sync_from_date = dol_mktime(0, 0, 0, GETPOST('sync_from_datemonth', 'int'), GETPOST('sync_from_dateday', 'int'), GETPOST('sync_from_dateyear', 'int'));
    $fintsaccount->active = GETPOST('active', 'int');

    $result = $fintsaccount->update($user);
    if ($result > 0) {
        setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
        header("Location: ".$_SERVER["PHP_SELF"]);
        exit;
    } else {
        setEventMessages($fintsaccount->error, null, 'errors');
    }
}

// Delete connection
if ($action == 'confirm_delete' && $confirm == 'yes' && $id > 0) {
    $fintsaccount = new FintsAccount($db);
    $fintsaccount->fetch($id);
    $result = $fintsaccount->delete($user);
    if ($result > 0) {
        setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
    } else {
        setEventMessages($fintsaccount->error, null, 'errors');
    }
}

/*
 * View
 */

$page_name = "FintsBankSetup";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Tabs
$head = array();
$h = 0;
$head[$h][0] = dol_buildpath('/fintsbank/admin/setup.php', 1);
$head[$h][1] = $langs->trans('BankConnections');
$head[$h][2] = 'connections';
$h++;

print dol_get_fiche_head($head, 'connections', '', -1, 'fa-university');

// Check if php-fints is installed
$fintsInstalled = file_exists(dol_buildpath('/fintsbank/vendor/autoload.php', 0));

if (!$fintsInstalled) {
    print '<div class="warning">';
    print '<strong>'.$langs->trans("Warning").':</strong> php-fints library not installed.<br>';
    print 'Run: <code>cd '.dol_buildpath('/fintsbank', 0).' && composer install</code>';
    print '</div><br>';
}

// Delete confirmation
if ($action == 'delete' && $id > 0) {
    $fintsaccount = new FintsAccount($db);
    $fintsaccount->fetch($id);

    print $form->formconfirm(
        $_SERVER["PHP_SELF"].'?id='.$id,
        $langs->trans('Delete'),
        $langs->trans('ConfirmDelete'),
        'confirm_delete',
        '',
        0,
        1
    );
}

// Create/Edit form
if ($action == 'create' || $action == 'edit') {
    $fintsaccount = new FintsAccount($db);
    if ($action == 'edit' && $id > 0) {
        $fintsaccount->fetch($id);
    }

    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="'.($action == 'edit' ? 'update' : 'add').'">';
    if ($id > 0) {
        print '<input type="hidden" name="id" value="'.$id.'">';
    }

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans($action == 'edit' ? 'EditBankConnection' : 'AddBankConnection').'</td>';
    print '<td></td>';
    print '</tr>';

    // Bank account selection
    print '<tr class="oddeven">';
    print '<td class="titlefield fieldrequired">'.$langs->trans("BankAccount").'</td>';
    print '<td>';
    if ($action == 'create') {
        $form = new Form($db);
        print $form->select_comptes($fintsaccount->fk_bank, 'fk_bank', 0, '', 1, '', 0, '', 1);
    } else {
        $bankaccount = new Account($db);
        $bankaccount->fetch($fintsaccount->fk_bank);
        print $bankaccount->getNomUrl(1);
    }
    print '</td>';
    print '</tr>';

    // Bank code
    print '<tr class="oddeven">';
    print '<td class="fieldrequired">'.$langs->trans("BankCode").'</td>';
    print '<td><input type="text" name="bank_code" value="'.dol_escape_htmltag($fintsaccount->bank_code).'" size="20" placeholder="z.B. 50040000"></td>';
    print '</tr>';

    // FinTS URL
    print '<tr class="oddeven">';
    print '<td class="fieldrequired">'.$langs->trans("FintsUrl").'</td>';
    print '<td><input type="text" name="fints_url" value="'.dol_escape_htmltag($fintsaccount->fints_url).'" size="60" placeholder="https://fints.commerzbank.com/PinTanCgi"></td>';
    print '</tr>';

    // Username
    print '<tr class="oddeven">';
    print '<td class="fieldrequired">'.$langs->trans("Username").'</td>';
    print '<td><input type="text" name="username" value="'.dol_escape_htmltag($fintsaccount->username).'" size="30"></td>';
    print '</tr>';

    // Customer ID
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("CustomerId").'</td>';
    print '<td><input type="text" name="customer_id" value="'.dol_escape_htmltag($fintsaccount->customer_id).'" size="30"> <span class="small">(falls abweichend)</span></td>';
    print '</tr>';

    // IBAN
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("IBAN").'</td>';
    print '<td><input type="text" name="iban" value="'.dol_escape_htmltag($fintsaccount->iban).'" size="40"></td>';
    print '</tr>';

    // Account number
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("AccountNumber").'</td>';
    print '<td><input type="text" name="account_number" value="'.dol_escape_htmltag($fintsaccount->account_number).'" size="20"></td>';
    print '</tr>';

    // Sync from date
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("SyncFromDate").'</td>';
    print '<td>';
    $form = new Form($db);
    print $form->selectDate($fintsaccount->sync_from_date ? $fintsaccount->sync_from_date : dol_now() - (30*24*60*60), 'sync_from_date', 0, 0, 0, '', 1, 1);
    print '</td>';
    print '</tr>';

    // Active
    if ($action == 'edit') {
        print '<tr class="oddeven">';
        print '<td>'.$langs->trans("ConnectionActive").'</td>';
        print '<td><input type="checkbox" name="active" value="1"'.($fintsaccount->active ? ' checked' : '').'></td>';
        print '</tr>';
    }

    print '</table>';

    print '<div class="center" style="margin-top: 10px;">';
    print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
    print ' &nbsp; ';
    print '<a class="button button-cancel" href="'.$_SERVER["PHP_SELF"].'">'.$langs->trans("Cancel").'</a>';
    print '</div>';

    print '</form>';

} else {
    // List existing connections
    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans("BankAccount").'</td>';
    print '<td>'.$langs->trans("BankCode").'</td>';
    print '<td>'.$langs->trans("Username").'</td>';
    print '<td>'.$langs->trans("IBAN").'</td>';
    print '<td>'.$langs->trans("LastSync").'</td>';
    print '<td>'.$langs->trans("Status").'</td>';
    print '<td class="right">'.$langs->trans("Actions").'</td>';
    print '</tr>';

    $fintsaccount = new FintsAccount($db);
    $accounts = $fintsaccount->fetchAll();

    if (count($accounts) > 0) {
        foreach ($accounts as $account) {
            $bankaccount = new Account($db);
            $bankaccount->fetch($account->fk_bank);

            print '<tr class="oddeven">';
            print '<td>'.$bankaccount->getNomUrl(1).'</td>';
            print '<td>'.$account->bank_code.'</td>';
            print '<td>'.$account->username.'</td>';
            print '<td>'.$account->iban.'</td>';
            print '<td>'.($account->last_sync ? dol_print_date($account->last_sync, 'dayhour') : '-').'</td>';
            print '<td>';
            if ($account->active) {
                print '<span class="badge badge-status4">'.$langs->trans("Enabled").'</span>';
            } else {
                print '<span class="badge badge-status5">'.$langs->trans("Disabled").'</span>';
            }
            print '</td>';
            print '<td class="right">';
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$account->id.'">'.img_picto($langs->trans("Edit"), 'edit').'</a>';
            print ' &nbsp; ';
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$account->id.'">'.img_picto($langs->trans("Delete"), 'delete').'</a>';
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr class="oddeven">';
        print '<td colspan="7" class="center">'.$langs->trans("NoRecordFound").'</td>';
        print '</tr>';
    }

    print '</table>';
    print '</div>';

    // Add button
    print '<div class="tabsAction">';
    print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=create">'.$langs->trans("AddBankConnection").'</a>';
    print '</div>';

    // Info box
    print '<br>';
    print '<div class="info">';
    print '<strong>'.$langs->trans("SupportedBanks").'</strong><br><br>';
    print '<table class="noborder">';
    print '<tr><th>BLZ</th><th>Bank</th><th>FinTS URL</th></tr>';
    foreach (FintsAccount::$bankUrls as $blz => $info) {
        print '<tr><td>'.$blz.'</td><td>'.$info['name'].'</td><td><small>'.$info['url'].'</small></td></tr>';
    }
    print '</table>';
    print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
