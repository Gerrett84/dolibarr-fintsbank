<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       sync.php
 * \ingroup    fintsbank
 * \brief      Bank sync page with TAN dialog
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
dol_include_once('/fintsbank/class/fintsservice.class.php');

// Security check
if (!$user->rights->fintsbank->sync) {
    accessforbidden();
}

// Load translations
$langs->loadLangs(array("banks", "fintsbank@fintsbank"));

// Parameters
$action = GETPOST('action', 'aZ09');
$id = GETPOST('id', 'int');

/*
 * View
 */

$page_name = "FintsBankSync";
llxHeader('', $langs->trans($page_name), '', '', 0, 0, array(), array('/fintsbank/css/fintsbank.css'));

print load_fiche_titre($langs->trans("FintsBankSync"), '', 'fa-university');

// Check if library is available
if (!FintsService::isLibraryAvailable()) {
    print '<div class="error">';
    print '<strong>'.$langs->trans("Error").':</strong> php-fints library not installed.<br>';
    print 'Run: <code>cd '.dol_buildpath('/fintsbank', 0).' && composer install</code>';
    print '</div>';
    llxFooter();
    exit;
}

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

// Account selector
print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("BankAccount").'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>';
print '<select name="id" class="flat minwidth200" onchange="this.form.submit()">';
foreach ($accounts as $acc) {
    $bankaccount = new Account($db);
    $bankaccount->fetch($acc->fk_bank);
    $selected = ($acc->id == $id) ? ' selected' : '';
    print '<option value="'.$acc->id.'"'.$selected.'>'.$bankaccount->label.' ('.$acc->iban.')</option>';
}
print '</select>';
print '</td>';
print '<td>';
if ($id > 0) {
    $currentAccount = new FintsAccount($db);
    $currentAccount->fetch($id);
    if ($currentAccount->last_sync) {
        print '<small>'.$langs->trans("LastSync").': '.dol_print_date($currentAccount->last_sync, 'dayhour').'</small>';
    }
}
print '</td>';
print '</tr>';
print '</table>';
print '</form>';

print '</div>'; // fichethirdleft
print '</div>'; // fichecenter

print '<div class="clearboth"></div><br>';

// Sync dialog
if ($id > 0) {
    $currentAccount = new FintsAccount($db);
    $currentAccount->fetch($id);

    print '<div id="sync-container" class="center" style="max-width: 500px; margin: 0 auto;">';

    // Step 1: PIN Entry
    print '<div id="step-pin" class="sync-step">';
    print '<div class="card" style="padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    print '<h3><i class="fas fa-lock"></i> '.$langs->trans("EnterPIN").'</h3>';
    print '<p>'.$langs->trans("EnterYourBankingPIN").'</p>';

    print '<div style="margin: 20px 0;">';
    print '<label for="pin"><strong>PIN:</strong></label><br>';
    print '<input type="password" id="pin" name="pin" class="flat" style="width: 200px; padding: 10px; font-size: 16px;" autocomplete="off">';
    print '</div>';

    print '<div style="margin: 20px 0;">';
    print '<label><strong>'.$langs->trans("SyncFromDate").':</strong></label><br>';
    $defaultFrom = $currentAccount->last_sync ? $currentAccount->last_sync : (dol_now() - 30*24*60*60);
    print '<input type="date" id="sync_from" value="'.date('Y-m-d', $defaultFrom).'" class="flat" style="padding: 8px;">';
    print '</div>';

    print '<button type="button" id="btn-start-sync" class="button">';
    print '<i class="fas fa-sync"></i> '.$langs->trans("SyncNow");
    print '</button>';
    print '</div>'; // card
    print '</div>'; // step-pin

    // Step 2: TAN Entry (hidden initially)
    print '<div id="step-tan" class="sync-step" style="display: none;">';
    print '<div class="card" style="padding: 20px; background: #fff3cd; border-radius: 8px;">';
    print '<h3><i class="fas fa-mobile-alt"></i> '.$langs->trans("TANRequired").'</h3>';

    // PhotoTAN image container
    print '<div id="tan-challenge" style="margin: 20px 0; text-align: center;">';
    print '<div id="phototan-image" style="display: none; margin-bottom: 15px;">';
    print '<img id="phototan-img" src="" alt="photoTAN" style="max-width: 300px; border: 2px solid #333;">';
    print '</div>';
    print '<p id="tan-instructions">'.$langs->trans("ScanPhotoTAN").'</p>';
    print '</div>';

    print '<div style="margin: 20px 0;">';
    print '<label for="tan"><strong>TAN:</strong></label><br>';
    print '<input type="text" id="tan" name="tan" class="flat" style="width: 200px; padding: 10px; font-size: 20px; text-align: center; letter-spacing: 3px;" maxlength="10" autocomplete="off">';
    print '</div>';

    print '<button type="button" id="btn-submit-tan" class="button">';
    print '<i class="fas fa-check"></i> '.$langs->trans("Confirm");
    print '</button>';
    print ' ';
    print '<button type="button" id="btn-cancel" class="button button-cancel">';
    print $langs->trans("Cancel");
    print '</button>';
    print '</div>'; // card
    print '</div>'; // step-tan

    // Step 3: Loading (hidden initially)
    print '<div id="step-loading" class="sync-step" style="display: none;">';
    print '<div class="card" style="padding: 40px; text-align: center;">';
    print '<i class="fas fa-spinner fa-spin fa-3x"></i>';
    print '<p id="loading-message" style="margin-top: 20px;">'.$langs->trans("WaitingForTAN").'</p>';
    print '</div>';
    print '</div>'; // step-loading

    // Step 4: Result (hidden initially)
    print '<div id="step-result" class="sync-step" style="display: none;">';
    print '<div id="result-success" class="card" style="padding: 20px; background: #d4edda; border-radius: 8px; display: none;">';
    print '<h3><i class="fas fa-check-circle" style="color: green;"></i> '.$langs->trans("SyncSuccessful").'</h3>';
    print '<p id="result-message"></p>';
    print '<a href="'.dol_buildpath('/fintsbank/transactions.php', 1).'?id='.$id.'" class="button">'.$langs->trans("ViewTransactions").'</a>';
    print '</div>';

    print '<div id="result-error" class="card" style="padding: 20px; background: #f8d7da; border-radius: 8px; display: none;">';
    print '<h3><i class="fas fa-exclamation-circle" style="color: red;"></i> '.$langs->trans("Error").'</h3>';
    print '<p id="error-message"></p>';
    print '<button type="button" id="btn-retry" class="button">'.$langs->trans("Retry").'</button>';
    print '</div>';
    print '</div>'; // step-result

    print '</div>'; // sync-container

    // JavaScript for sync process
    print '<script>
    var accountId = '.$id.';
    var ajaxUrl = "'.dol_buildpath('/fintsbank/ajax/sync.php', 1).'";

    function showStep(stepId) {
        document.querySelectorAll(".sync-step").forEach(function(el) {
            el.style.display = "none";
        });
        document.getElementById(stepId).style.display = "block";
    }

    function showLoading(message) {
        document.getElementById("loading-message").textContent = message || "'.$langs->trans("WaitingForTAN").'";
        showStep("step-loading");
    }

    function showError(message) {
        document.getElementById("error-message").textContent = message;
        document.getElementById("result-error").style.display = "block";
        document.getElementById("result-success").style.display = "none";
        showStep("step-result");
    }

    function showSuccess(message) {
        document.getElementById("result-message").textContent = message;
        document.getElementById("result-success").style.display = "block";
        document.getElementById("result-error").style.display = "none";
        showStep("step-result");
    }

    // Start sync button
    document.getElementById("btn-start-sync").addEventListener("click", function() {
        var pin = document.getElementById("pin").value;
        var syncFrom = document.getElementById("sync_from").value;

        if (!pin) {
            alert("Bitte PIN eingeben");
            return;
        }

        showLoading("'.$langs->trans("Connecting").'...");

        fetch(ajaxUrl, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "action=start&id=" + accountId + "&pin=" + encodeURIComponent(pin) + "&sync_from=" + syncFrom + "&token='.newToken().'"
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.needsTan) {
                    // Show TAN step
                    if (data.challengeImage) {
                        document.getElementById("phototan-img").src = "data:image/png;base64," + data.challengeImage;
                        document.getElementById("phototan-image").style.display = "block";
                    }
                    if (data.tanMediumName) {
                        document.getElementById("tan-instructions").textContent = data.tanMediumName + ": " + "'.$langs->trans("EnterTANFromApp").'";
                    }
                    showStep("step-tan");
                    document.getElementById("tan").focus();
                } else {
                    // Sync complete
                    showSuccess(data.message || "'.$langs->trans("SyncSuccessful").'");
                }
            } else {
                showError(data.error || "'.$langs->trans("ErrorSyncFailed").'");
            }
        })
        .catch(error => {
            showError(error.message);
        });
    });

    // Submit TAN button
    document.getElementById("btn-submit-tan").addEventListener("click", function() {
        var tan = document.getElementById("tan").value;

        if (!tan) {
            alert("Bitte TAN eingeben");
            return;
        }

        showLoading("'.$langs->trans("Processing").'...");

        fetch(ajaxUrl, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "action=tan&tan=" + encodeURIComponent(tan) + "&token='.newToken().'"
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.needsTan) {
                    // Another TAN needed (rare)
                    showStep("step-tan");
                } else {
                    showSuccess(data.message || data.imported + " '.$langs->trans("TransactionsImported").'");
                }
            } else {
                showError(data.error || "'.$langs->trans("ErrorInvalidTAN").'");
            }
        })
        .catch(error => {
            showError(error.message);
        });
    });

    // Cancel button
    document.getElementById("btn-cancel").addEventListener("click", function() {
        showStep("step-pin");
        document.getElementById("pin").value = "";
        document.getElementById("tan").value = "";
    });

    // Retry button
    document.getElementById("btn-retry").addEventListener("click", function() {
        showStep("step-pin");
        document.getElementById("pin").value = "";
        document.getElementById("tan").value = "";
    });

    // Enter key handlers
    document.getElementById("pin").addEventListener("keypress", function(e) {
        if (e.key === "Enter") {
            document.getElementById("btn-start-sync").click();
        }
    });

    document.getElementById("tan").addEventListener("keypress", function(e) {
        if (e.key === "Enter") {
            document.getElementById("btn-submit-tan").click();
        }
    });
    </script>';
}

llxFooter();
$db->close();
