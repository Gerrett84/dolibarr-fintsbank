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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
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

// Un-ignore transaction (restore to new)
if ($action == 'unignore' && GETPOST('trans_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        $trans->setStatus(FintsTransaction::STATUS_NEW);
        setEventMessages($langs->trans("RecordModified"), null, 'mesgs');
    }
}

// Import transaction to bank account
if ($action == 'import' && GETPOST('trans_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        // Get FinTS account to find linked Dolibarr bank account
        $fintsAccount = new FintsAccount($db);
        if ($fintsAccount->fetch($trans->fk_fintsbank_account) > 0 && $fintsAccount->fk_bank > 0) {
            // Create bank line
            $bankAccount = new Account($db);
            if ($bankAccount->fetch($fintsAccount->fk_bank) > 0) {
                // Map booking text to valid payment type code
                $paymentType = 'VIR'; // Default: Virement/Transfer
                $bookingTextLower = strtolower($trans->booking_text ?: '');
                if (strpos($bookingTextLower, 'lastschrift') !== false || strpos($bookingTextLower, 'einzug') !== false) {
                    $paymentType = 'PRE'; // Prelevement/Direct debit
                } elseif (strpos($bookingTextLower, 'dauerauftrag') !== false) {
                    $paymentType = 'VIR';
                } elseif (strpos($bookingTextLower, 'kartenzahlung') !== false || strpos($bookingTextLower, 'card') !== false) {
                    $paymentType = 'CB';  // Carte bancaire
                } elseif (strpos($bookingTextLower, 'bargeld') !== false || strpos($bookingTextLower, 'automat') !== false) {
                    $paymentType = 'LIQ'; // Liquide/Cash
                }

                // Add bank line
                $bankLineId = $bankAccount->addline(
                    $trans->booking_date,           // Date
                    $paymentType,                   // Payment type (short code)
                    $trans->description,            // Label
                    $trans->amount,                 // Amount
                    '',                             // Check number
                    '',                             // Category
                    $user,                          // User
                    $trans->name,                   // Emetteur (sender)
                    '',                             // Bank name
                    '',                             // Account number
                    $trans->value_date              // Value date
                );

                if ($bankLineId > 0) {
                    // Link transaction to bank line
                    $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction";
                    $sql .= " SET fk_bank_line = ".(int)$bankLineId;
                    $sql .= ", status = 'imported'";
                    $sql .= " WHERE rowid = ".(int)$trans->id;
                    $db->query($sql);

                    setEventMessages($langs->trans("TransactionImported"), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans("Error").": ".$bankAccount->error, null, 'errors');
                }
            } else {
                setEventMessages($langs->trans("ErrorBankAccountNotFound"), null, 'errors');
            }
        } else {
            setEventMessages($langs->trans("ErrorNoBankAccountLinked"), null, 'errors');
        }
    }
}

// Auto-match single transaction - redirects to match action with found invoice
if ($action == 'automatch' && GETPOST('trans_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        $invoiceId = $trans->autoMatch();
        if ($invoiceId > 0) {
            // Redirect to match action to create real payment
            header('Location: '.$_SERVER["PHP_SELF"].'?action=match&trans_id='.$trans->id.'&invoice_id='.$invoiceId.'&id='.$id.'&status='.$status.'&token='.newToken());
            exit;
        } else {
            setEventMessages($langs->trans("NoMatchFound"), null, 'warnings');
        }
    }
}

// Manual match with invoice - creates real payment
if ($action == 'match' && GETPOST('trans_id', 'int') && GETPOST('invoice_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        $invoiceId = GETPOST('invoice_id', 'int');
        $error = 0;

        $db->begin();

        // Determine if customer or supplier invoice based on amount
        if ($trans->amount > 0) {
            // Customer invoice payment (incoming money)
            $facture = new Facture($db);
            if ($facture->fetch($invoiceId) > 0) {
                // Load thirdparty
                $facture->fetch_thirdparty();

                // Create payment
                $paiement = new Paiement($db);
                $paiement->datepaye = $trans->booking_date;
                $paiement->amounts = array($invoiceId => $trans->amount);
                $paiement->multicurrency_amounts = array($invoiceId => $trans->amount);
                $paiement->multicurrency_code = array($invoiceId => $trans->currency);
                $paiement->multicurrency_tx = array($invoiceId => 1);
                $paiement->paiementid = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1); // Bank transfer
                $paiement->num_payment = $trans->end_to_end_id ?: $trans->transaction_id;
                $paiement->note_private = $langs->trans("ImportedFromFinTS").' - '.$trans->description;

                $paiement_id = $paiement->create($user, 1, $facture->thirdparty);
                if ($paiement_id > 0) {
                    // Link to existing bank line if transaction was imported
                    if ($trans->fk_bank_line > 0) {
                        // Update payment with bank line reference
                        $sql = "UPDATE ".MAIN_DB_PREFIX."paiement SET fk_bank = ".(int)$trans->fk_bank_line;
                        $sql .= " WHERE rowid = ".(int)$paiement_id;
                        $db->query($sql);

                        // Create bank_url entries to link payment and company to bank line
                        $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type)";
                        $sql .= " VALUES (".(int)$trans->fk_bank_line.", ".(int)$paiement_id;
                        $sql .= ", '/compta/paiement/card.php?id=".$paiement_id."'";
                        $sql .= ", '(Payment)', 'payment')";
                        $db->query($sql);

                        if ($facture->socid > 0) {
                            $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type)";
                            $sql .= " VALUES (".(int)$trans->fk_bank_line.", ".(int)$facture->socid;
                            $sql .= ", '/societe/card.php?socid=".$facture->socid."'";
                            $sql .= ", '".$db->escape($facture->thirdparty->name)."', 'company')";
                            $db->query($sql);
                        }
                    } else {
                        // Transaction not yet imported - create bank line via addPaymentToBank
                        $fintsAccount = new FintsAccount($db);
                        if ($fintsAccount->fetch($trans->fk_fintsbank_account) > 0 && $fintsAccount->fk_bank > 0) {
                            $bank_line_id = $paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $fintsAccount->fk_bank, $trans->name, '');
                            if ($bank_line_id > 0) {
                                // Update transaction with bank line
                                $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction SET fk_bank_line = ".(int)$bank_line_id;
                                $sql .= ", status = 'imported' WHERE rowid = ".(int)$trans->id;
                                $db->query($sql);
                            }
                        }
                    }
                    // Link transaction to invoice and third party
                    $trans->linkToInvoice($invoiceId);
                    if ($facture->socid > 0) {
                        $trans->linkToThirdParty($facture->socid);
                    }
                    setEventMessages($langs->trans("PaymentCreated"), null, 'mesgs');
                } else {
                    $error++;
                    setEventMessages($paiement->error, $paiement->errors, 'errors');
                }
            } else {
                $error++;
                setEventMessages($langs->trans("ErrorInvoiceNotFound"), null, 'errors');
            }
        } else {
            // Supplier invoice payment (outgoing money)
            $facture = new FactureFournisseur($db);
            if ($facture->fetch($invoiceId) > 0) {
                // Load thirdparty
                $facture->fetch_thirdparty();

                // Create supplier payment
                $paiement = new PaiementFourn($db);
                $paiement->datepaye = $trans->booking_date;
                $paiement->amounts = array($invoiceId => abs($trans->amount));
                $paiement->multicurrency_amounts = array($invoiceId => abs($trans->amount));
                $paiement->multicurrency_code = array($invoiceId => $trans->currency);
                $paiement->multicurrency_tx = array($invoiceId => 1);
                $paiement->paiementid = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
                $paiement->num_payment = $trans->end_to_end_id ?: $trans->transaction_id;
                $paiement->note_private = $langs->trans("ImportedFromFinTS").' - '.$trans->description;

                $paiement_id = $paiement->create($user, 1, $facture->thirdparty);
                if ($paiement_id > 0) {
                    // Link to existing bank line if transaction was imported
                    if ($trans->fk_bank_line > 0) {
                        $sql = "UPDATE ".MAIN_DB_PREFIX."paiementfourn SET fk_bank = ".(int)$trans->fk_bank_line;
                        $sql .= " WHERE rowid = ".(int)$paiement_id;
                        $db->query($sql);

                        $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type)";
                        $sql .= " VALUES (".(int)$trans->fk_bank_line.", ".(int)$paiement_id;
                        $sql .= ", '/fourn/paiement/card.php?id=".$paiement_id."'";
                        $sql .= ", '(SupplierPayment)', 'payment_supplier')";
                        $db->query($sql);

                        if ($facture->socid > 0) {
                            $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type)";
                            $sql .= " VALUES (".(int)$trans->fk_bank_line.", ".(int)$facture->socid;
                            $sql .= ", '/societe/card.php?socid=".$facture->socid."'";
                            $sql .= ", '".$db->escape($facture->thirdparty->name)."', 'company')";
                            $db->query($sql);
                        }
                    } else {
                        $fintsAccount = new FintsAccount($db);
                        if ($fintsAccount->fetch($trans->fk_fintsbank_account) > 0 && $fintsAccount->fk_bank > 0) {
                            $bank_line_id = $paiement->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', $fintsAccount->fk_bank, $trans->name, '');
                            if ($bank_line_id > 0) {
                                $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction SET fk_bank_line = ".(int)$bank_line_id;
                                $sql .= ", status = 'imported' WHERE rowid = ".(int)$trans->id;
                                $db->query($sql);
                            }
                        }
                    }
                    // Link transaction to invoice and third party
                    $trans->linkToInvoice($invoiceId);
                    if ($facture->socid > 0) {
                        $trans->linkToThirdParty($facture->socid);
                    }
                    setEventMessages($langs->trans("PaymentCreated"), null, 'mesgs');
                } else {
                    $error++;
                    setEventMessages($paiement->error, $paiement->errors, 'errors');
                }
            } else {
                $error++;
                setEventMessages($langs->trans("ErrorInvoiceNotFound"), null, 'errors');
            }
        }

        if ($error) {
            $db->rollback();
        } else {
            $db->commit();
        }
    }
}

// Unmatch (remove invoice and third party link)
if ($action == 'unmatch' && GETPOST('trans_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        // Keep imported status if already imported, otherwise set to new
        $newStatus = ($trans->status == 'imported') ? 'imported' : 'new';
        $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction";
        $sql .= " SET fk_facture = NULL, fk_societe = NULL, status = '".$newStatus."'";
        $sql .= " WHERE rowid = ".(int)$trans->id;
        $db->query($sql);
        setEventMessages($langs->trans("RecordModified"), null, 'mesgs');
    }
}

// Delete all transactions for account (for re-sync)
if ($action == 'deleteall' && $id > 0) {
    $confirm = GETPOST('confirm', 'alpha');
    if ($confirm == 'yes') {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."fintsbank_transaction WHERE fk_fintsbank_account = ".(int)$id;
        $db->query($sql);
        $deleted = $db->affected_rows();
        $_SESSION['dol_events']['mesgs'][] = sprintf($langs->trans("XTransactionsDeleted"), $deleted);
        // Redirect to avoid issues with empty data
        header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
        exit;
    }
}

// Auto-match all new transactions
if ($action == 'automatchall' && $id > 0) {
    $transaction = new FintsTransaction($db);
    $newTransactions = $transaction->fetchByAccount($id, 'new', 1000, 0);

    $matched = 0;
    foreach ($newTransactions as $trans) {
        $invoiceId = $trans->autoMatch();
        if ($invoiceId > 0) {
            $trans->linkToInvoice($invoiceId);
            $matched++;
        }
    }

    if ($matched > 0) {
        setEventMessages(sprintf($langs->trans("XTransactionsMatched"), $matched), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("NoMatchFound"), null, 'warnings');
    }
}

// Import all new transactions
if ($action == 'importall' && $id > 0) {
    $fintsAccount = new FintsAccount($db);
    if ($fintsAccount->fetch($id) > 0 && $fintsAccount->fk_bank > 0) {
        $bankAccount = new Account($db);
        if ($bankAccount->fetch($fintsAccount->fk_bank) > 0) {
            $transaction = new FintsTransaction($db);
            $newTransactions = $transaction->fetchByAccount($id, 'new', 1000, 0);

            $imported = 0;
            $errors = 0;

            foreach ($newTransactions as $trans) {
                // Map booking text to valid payment type code
                $paymentType = 'VIR'; // Default: Virement/Transfer
                $bookingTextLower = strtolower($trans->booking_text ?: '');
                if (strpos($bookingTextLower, 'lastschrift') !== false || strpos($bookingTextLower, 'einzug') !== false) {
                    $paymentType = 'PRE';
                } elseif (strpos($bookingTextLower, 'kartenzahlung') !== false || strpos($bookingTextLower, 'card') !== false) {
                    $paymentType = 'CB';
                } elseif (strpos($bookingTextLower, 'bargeld') !== false || strpos($bookingTextLower, 'automat') !== false) {
                    $paymentType = 'LIQ';
                }

                $bankLineId = $bankAccount->addline(
                    $trans->booking_date,
                    $paymentType,
                    $trans->description,
                    $trans->amount,
                    '', '', $user,
                    $trans->name,
                    '', '',
                    $trans->value_date
                );

                if ($bankLineId > 0) {
                    $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction";
                    $sql .= " SET fk_bank_line = ".(int)$bankLineId;
                    $sql .= ", status = 'imported'";
                    $sql .= " WHERE rowid = ".(int)$trans->id;
                    $db->query($sql);
                    $imported++;
                } else {
                    $errors++;
                }
            }

            if ($imported > 0) {
                setEventMessages(sprintf($langs->trans("XTransactionsImported"), $imported), null, 'mesgs');
            }
            if ($errors > 0) {
                setEventMessages(sprintf($langs->trans("XTransactionsError"), $errors), null, 'warnings');
            }
            if ($imported == 0 && $errors == 0) {
                setEventMessages($langs->trans("NoNewTransactions"), null, 'warnings');
            }
        }
    }
}

/*
 * View
 */

$page_name = "Transactions";
llxHeader('', $langs->trans($page_name));

// JavaScript for match dropdown
print '<script>
function toggleMatchDropdown(id) {
    // Close all other dropdowns
    document.querySelectorAll(".match-dropdown").forEach(function(el) {
        if (el.id !== "matchdrop_" + id) {
            el.style.display = "none";
        }
    });
    // Toggle this dropdown
    var dropdown = document.getElementById("matchdrop_" + id);
    dropdown.style.display = dropdown.style.display === "none" ? "block" : "none";
}
// Close dropdown when clicking outside
document.addEventListener("click", function(e) {
    if (!e.target.closest(".inline-block")) {
        document.querySelectorAll(".match-dropdown").forEach(function(el) {
            el.style.display = "none";
        });
    }
});
</script>';
print '<style>
.match-dropdown a.match-item:hover { background: #f0f0f0; }
</style>';

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
        // Show linked third party if available
        if ($trans->fk_societe > 0) {
            $societe = new Societe($db);
            if ($societe->fetch($trans->fk_societe) > 0) {
                print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$trans->fk_societe.'">';
                print img_picto('', 'company', 'class="paddingright"');
                print dol_escape_htmltag($societe->name);
                print '</a>';
            } else {
                print dol_escape_htmltag($trans->name);
            }
        } else {
            print dol_escape_htmltag($trans->name);
        }
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
            // Auto-match with invoice
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=automatch&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("AutoMatch").'">';
            print img_picto($langs->trans("AutoMatch"), 'link', 'class="paddingright"');
            print '</a>';
            // Manual match dropdown
            $potentialMatches = $trans->getPotentialMatches(10.0, 10);
            if (count($potentialMatches) > 0) {
                print '<div class="inline-block" style="position: relative;">';
                print '<a href="#" onclick="toggleMatchDropdown('.$trans->id.'); return false;" title="'.$langs->trans("MatchWithInvoice").'">';
                print img_picto($langs->trans("MatchWithInvoice"), 'object_bill', 'class="paddingright"');
                print '</a>';
                print '<div id="matchdrop_'.$trans->id.'" class="match-dropdown" style="display:none; position:absolute; right:0; top:20px; background:#fff; border:1px solid #ccc; padding:5px; z-index:100; min-width:250px; box-shadow:2px 2px 5px rgba(0,0,0,0.2);">';
                foreach ($potentialMatches as $match) {
                    print '<a href="'.$_SERVER["PHP_SELF"].'?action=match&trans_id='.$trans->id.'&invoice_id='.$match['id'].'&id='.$id.'&status='.$status.'&token='.newToken().'" class="match-item" style="display:block; padding:3px 5px; text-decoration:none; color:#333;">';
                    print '<strong>'.$match['ref'].'</strong> - '.price($match['amount'], 0, $langs, 1, -1, 2).'<br>';
                    print '<small>'.$match['thirdparty'].'</small>';
                    print '</a>';
                }
                print '</div>';
                print '</div>';
            }
            // Import to bank
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=import&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("ImportToBank").'">';
            print img_picto($langs->trans("ImportToBank"), 'add', 'class="paddingright"');
            print '</a>';
            // Ignore
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=ignore&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("IgnoreTransaction").'">';
            print img_picto($langs->trans("IgnoreTransaction"), 'disable');
            print '</a>';
        } elseif ($trans->status == 'matched') {
            // Show linked invoice
            if ($trans->fk_facture > 0) {
                print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$trans->fk_facture.'" title="'.$langs->trans("ViewInvoice").'">';
                print img_picto($langs->trans("ViewInvoice"), 'bill', 'class="paddingright"');
                print '</a>';
            }
            // Import to bank (even if matched)
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=import&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("ImportToBank").'">';
            print img_picto($langs->trans("ImportToBank"), 'add', 'class="paddingright"');
            print '</a>';
            // Unmatch
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=unmatch&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("Unmatch").'">';
            print img_picto($langs->trans("Unmatch"), 'unlink');
            print '</a>';
        } elseif ($trans->status == 'ignored') {
            // Un-ignore (restore)
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=unignore&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("RestoreTransaction").'">';
            print img_picto($langs->trans("RestoreTransaction"), 'undo');
            print '</a>';
        } elseif ($trans->status == 'imported') {
            // Show linked invoice if matched
            if ($trans->fk_facture > 0) {
                print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$trans->fk_facture.'" title="'.$langs->trans("ViewInvoice").'">';
                print img_picto($langs->trans("ViewInvoice"), 'bill', 'class="paddingright"');
                print '</a>';
            } else {
                // Auto-match with invoice (even for imported)
                print '<a href="'.$_SERVER["PHP_SELF"].'?action=automatch&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("AutoMatch").'">';
                print img_picto($langs->trans("AutoMatch"), 'link', 'class="paddingright"');
                print '</a>';
                // Manual match dropdown
                $potentialMatches = $trans->getPotentialMatches(10.0, 10);
                if (count($potentialMatches) > 0) {
                    print '<div class="inline-block" style="position: relative;">';
                    print '<a href="#" onclick="toggleMatchDropdown('.$trans->id.'); return false;" title="'.$langs->trans("MatchWithInvoice").'">';
                    print img_picto($langs->trans("MatchWithInvoice"), 'object_bill', 'class="paddingright"');
                    print '</a>';
                    print '<div id="matchdrop_'.$trans->id.'" class="match-dropdown" style="display:none; position:absolute; right:0; top:20px; background:#fff; border:1px solid #ccc; padding:5px; z-index:100; min-width:250px; box-shadow:2px 2px 5px rgba(0,0,0,0.2);">';
                    foreach ($potentialMatches as $match) {
                        print '<a href="'.$_SERVER["PHP_SELF"].'?action=match&trans_id='.$trans->id.'&invoice_id='.$match['id'].'&id='.$id.'&status='.$status.'&token='.newToken().'" class="match-item" style="display:block; padding:3px 5px; text-decoration:none; color:#333;">';
                        print '<strong>'.$match['ref'].'</strong> - '.price($match['amount'], 0, $langs, 1, -1, 2).'<br>';
                        print '<small>'.$match['thirdparty'].'</small>';
                        print '</a>';
                    }
                    print '</div>';
                    print '</div>';
                }
            }
            // Link to bank line
            if ($trans->fk_bank_line > 0) {
                print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.$trans->fk_bank_line.'" title="'.$langs->trans("ViewBankLine").'">';
                print img_picto($langs->trans("ViewBankLine"), 'bank_account');
                print '</a>';
            }
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

// Action buttons
print '<div class="tabsAction">';

// Auto-match all new transactions
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=automatchall&id='.$id.'&token='.newToken().'">';
print '<i class="fas fa-magic"></i> '.$langs->trans("AutoMatchAll");
print '</a>';

// Import all new transactions
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=importall&id='.$id.'&token='.newToken().'">';
print '<i class="fas fa-download"></i> '.$langs->trans("ImportAllNew");
print '</a>';

// Sync
print '<a class="butAction" href="'.dol_buildpath('/fintsbank/sync.php', 1).'?id='.$id.'">';
print '<i class="fas fa-sync"></i> '.$langs->trans("SyncNow");
print '</a>';

// Delete all transactions (for re-sync)
$deleteUrl = $_SERVER["PHP_SELF"].'?action=deleteall&id='.$id.'&confirm=yes&token='.newToken();
$confirmMsg = $langs->trans("ConfirmDeleteAllTransactions");
print '<a class="butActionDelete" href="'.$deleteUrl.'" onclick="return confirm(\''.dol_escape_js($confirmMsg).'\');">';
print '<i class="fas fa-trash"></i> '.$langs->trans("DeleteAllTransactions");
print '</a>';

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
