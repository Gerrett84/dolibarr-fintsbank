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

                // Generate statement number for auto-reconciliation (FINTS-YYYY-MM-DD)
                $statementNum = '';
                $autoReconcile = getDolGlobalInt('FINTSBANK_AUTO_RECONCILE');
                if ($autoReconcile) {
                    $statementNum = 'FINTS-'.dol_print_date($trans->booking_date, '%Y-%m-%d');
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
                    $trans->value_date,             // Value date
                    $statementNum                   // Statement number (num_releve)
                );

                if ($bankLineId > 0) {
                    // Auto-reconcile if enabled
                    if ($autoReconcile && !empty($statementNum)) {
                        $sql = "UPDATE ".MAIN_DB_PREFIX."bank SET rappro = 1 WHERE rowid = ".(int)$bankLineId;
                        $db->query($sql);
                    }

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
        // Clear any split assignments too
        $trans->clearInvoiceAssignments();
        // Keep imported status if already imported, otherwise set to new
        $newStatus = ($trans->status == 'imported' || $trans->status == 'matched') ? 'imported' : 'new';
        $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction";
        $sql .= " SET fk_facture = NULL, fk_societe = NULL, status = '".$newStatus."'";
        $sql .= " WHERE rowid = ".(int)$trans->id;
        $db->query($sql);
        setEventMessages($langs->trans("RecordModified"), null, 'mesgs');
    }
}

// Split match: assign transaction to multiple invoices with individual amounts
if ($action == 'splitmatch' && GETPOST('trans_id', 'int')) {
    $trans = new FintsTransaction($db);
    if ($trans->fetch(GETPOST('trans_id', 'int')) > 0) {
        $splitDataRaw = GETPOST('split_data', 'none');
        $splitData = json_decode($splitDataRaw, true);

        if (!is_array($splitData) || count($splitData) == 0) {
            setEventMessages($langs->trans("ErrorInvalidSplitData"), null, 'errors');
        } else {
            $error = 0;
            $db->begin();

            // Clear existing split assignments
            $trans->clearInvoiceAssignments();

            $firstSocid = 0;
            $firstFkFacture = 0;
            $seenSocids = array();

            foreach ($splitData as $split) {
                $invoiceId   = (int)$split['invoice_id'];
                $splitAmount = (float)$split['amount'];
                $entryType   = (isset($split['invoice_type']) && $split['invoice_type'] === 'supplier') ? 'supplier' : 'customer';

                if ($invoiceId <= 0 || $splitAmount <= 0) {
                    continue;
                }

                if ($entryType === 'supplier') {
                    $facture = new FactureFournisseur($db);
                } else {
                    $facture = new Facture($db);
                }

                if ($facture->fetch($invoiceId) <= 0) {
                    $error++;
                    setEventMessages($langs->trans("ErrorInvoiceNotFound"), null, 'errors');
                    break;
                }
                $facture->fetch_thirdparty();

                if ($entryType === 'supplier') {
                    $paiement = new PaiementFourn($db);
                } else {
                    $paiement = new Paiement($db);
                }
                $paiement->datepaye              = $trans->booking_date;
                $paiement->amounts               = array($invoiceId => $splitAmount);
                $paiement->multicurrency_amounts = array($invoiceId => $splitAmount);
                $paiement->multicurrency_code    = array($invoiceId => $trans->currency);
                $paiement->multicurrency_tx      = array($invoiceId => 1);
                $paiement->paiementid            = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
                $paiement->num_payment           = $trans->end_to_end_id ?: $trans->transaction_id;
                $paiement->note_private          = $langs->trans("ImportedFromFinTS").' - '.$trans->description;

                $paiement_id = $paiement->create($user, 1, $facture->thirdparty);
                if ($paiement_id > 0) {
                    if ($trans->fk_bank_line > 0) {
                        if ($entryType === 'supplier') {
                            $sql = "UPDATE ".MAIN_DB_PREFIX."paiementfourn SET fk_bank = ".(int)$trans->fk_bank_line." WHERE rowid = ".(int)$paiement_id;
                            $db->query($sql);
                            $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type) VALUES (".(int)$trans->fk_bank_line.", ".(int)$paiement_id.", '/fourn/paiement/card.php?id=".$paiement_id."', '(SupplierPayment)', 'payment_supplier')";
                            $db->query($sql);
                        } else {
                            $sql = "UPDATE ".MAIN_DB_PREFIX."paiement SET fk_bank = ".(int)$trans->fk_bank_line." WHERE rowid = ".(int)$paiement_id;
                            $db->query($sql);
                            $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type) VALUES (".(int)$trans->fk_bank_line.", ".(int)$paiement_id.", '/compta/paiement/card.php?id=".$paiement_id."', '(Payment)', 'payment')";
                            $db->query($sql);
                        }
                        if ($facture->socid > 0 && !in_array($facture->socid, $seenSocids)) {
                            $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type)";
                            $sql .= " VALUES (".(int)$trans->fk_bank_line.", ".(int)$facture->socid;
                            $sql .= ", '/societe/card.php?socid=".$facture->socid."'";
                            $sql .= ", '".$db->escape($facture->thirdparty->name)."', 'company')";
                            $db->query($sql);
                            $seenSocids[] = $facture->socid;
                        }
                    } else {
                        $fintsAccount = new FintsAccount($db);
                        if ($fintsAccount->fetch($trans->fk_fintsbank_account) > 0 && $fintsAccount->fk_bank > 0) {
                            $payLabel = $entryType === 'supplier' ? 'payment_supplier' : 'payment';
                            $payNote  = $entryType === 'supplier' ? '(SupplierInvoicePayment)' : '(CustomerInvoicePayment)';
                            $bank_line_id = $paiement->addPaymentToBank($user, $payLabel, $payNote, $fintsAccount->fk_bank, $trans->name, '');
                            if ($bank_line_id > 0) {
                                $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction SET fk_bank_line = ".(int)$bank_line_id.", status = 'imported' WHERE rowid = ".(int)$trans->id;
                                $db->query($sql);
                                $trans->fk_bank_line = $bank_line_id;
                            }
                        }
                    }

                    $sql = "INSERT INTO ".MAIN_DB_PREFIX."fintsbank_transaction_invoice (fk_transaction, fk_facture, invoice_type, amount, fk_paiement, entity)";
                    $sql .= " VALUES (".(int)$trans->id.", ".(int)$invoiceId.", '".$entryType."', ".(float)$splitAmount.", ".(int)$paiement_id.", ".(int)$conf->entity.")";
                    $db->query($sql);

                    if (!$firstSocid && $facture->socid > 0) {
                        $firstSocid = $facture->socid;
                    }
                    if (!$firstFkFacture) {
                        $firstFkFacture = $invoiceId;
                    }
                } else {
                    $error++;
                    setEventMessages($paiement->error, $paiement->errors, 'errors');
                    break;
                }
            }

            if (!$error) {
                $sql = "UPDATE ".MAIN_DB_PREFIX."fintsbank_transaction";
                $sql .= " SET status = 'matched', fk_facture = ".(int)$firstFkFacture;
                if ($firstSocid) {
                    $sql .= ", fk_societe = ".(int)$firstSocid;
                }
                $sql .= ", date_match = '".$db->idate(dol_now())."' WHERE rowid = ".(int)$trans->id;
                $db->query($sql);
                $db->commit();
                setEventMessages($langs->trans("SplitPaymentsCreated"), null, 'mesgs');
            } else {
                $db->rollback();
            }
        }
    }
}

// Delete all transactions for account (for re-sync)
if ($action == 'deleteall' && $id > 0 && GETPOST('confirm', 'alpha') == 'yes') {
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."fintsbank_transaction WHERE fk_fintsbank_account = ".(int)$id;
    $db->query($sql);
    // Simple redirect without message
    header('Location: '.dol_buildpath('/fintsbank/transactions.php', 1).'?id='.$id);
    exit;
}

// Auto-match all imported transactions (without invoice link)
if ($action == 'automatchall' && $id > 0) {
    $transaction = new FintsTransaction($db);
    $importedTransactions = $transaction->fetchByAccount($id, 'imported', 1000, 0);

    $matched = 0;
    foreach ($importedTransactions as $trans) {
        // Skip if already has invoice
        if ($trans->fk_facture > 0) {
            continue;
        }
        $invoiceId = $trans->autoMatch();
        if ($invoiceId > 0) {
            // Redirect to match action to create real payment
            // For batch, we do it inline
            $trans->linkToInvoice($invoiceId);
            if ($trans->fk_bank_line > 0) {
                // Create payment linked to existing bank line
                // This is simplified - full payment creation would need more code
            }
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

            // Check if auto-reconcile is enabled
            $autoReconcile = getDolGlobalInt('FINTSBANK_AUTO_RECONCILE');

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

                // Generate statement number for auto-reconciliation
                $statementNum = '';
                if ($autoReconcile) {
                    $statementNum = 'FINTS-'.dol_print_date($trans->booking_date, '%Y-%m-%d');
                }

                $bankLineId = $bankAccount->addline(
                    $trans->booking_date,
                    $paymentType,
                    $trans->description,
                    $trans->amount,
                    '', '', $user,
                    $trans->name,
                    '', '',
                    $trans->value_date,
                    $statementNum  // Statement number for reconciliation
                );

                if ($bankLineId > 0) {
                    // Auto-reconcile if enabled
                    if ($autoReconcile && !empty($statementNum)) {
                        $sql = "UPDATE ".MAIN_DB_PREFIX."bank SET rappro = 1 WHERE rowid = ".(int)$bankLineId;
                        $db->query($sql);
                    }

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

// JavaScript for match dropdown and split panel
$ajaxUrl = dol_buildpath('/fintsbank/ajax/invoice_search.php', 1);
$tokenVal = newToken();
print '<script>
var splitState = {};
var splitSearchResults = {};

function toggleMatchDropdown(id) {
    document.querySelectorAll(".match-dropdown").forEach(function(el) {
        if (el.id !== "matchdrop_" + id) el.style.display = "none";
    });
    var dropdown = document.getElementById("matchdrop_" + id);
    dropdown.style.display = dropdown.style.display === "none" ? "block" : "none";
}
document.addEventListener("click", function(e) {
    if (!e.target.closest(".inline-block") && !e.target.closest(".split-panel")) {
        document.querySelectorAll(".match-dropdown").forEach(function(el) { el.style.display = "none"; });
    }
});

function openSplitPanel(transId) {
    var row = document.getElementById("splitrow_" + transId);
    if (!row) return;
    var visible = row.style.display !== "none";
    // Close all others first
    document.querySelectorAll(".splitrow").forEach(function(r) { r.style.display = "none"; });
    if (!visible) {
        row.style.display = "";
        if (!splitState[transId]) {
            var panel = document.getElementById("splitpanel_" + transId);
            splitState[transId] = [];
            renderSplitEntries(transId);
        }
    }
}

function searchSplitInvoices(transId) {
    var input = document.getElementById("splitsearch_" + transId);
    var query = input ? input.value.trim() : "";
    if (query.length < 1) return;
    var resultsDiv = document.getElementById("splitresults_" + transId);
    resultsDiv.innerHTML = "...";
    resultsDiv.style.display = "block";
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "'.addslashes($ajaxUrl).'?query=" + encodeURIComponent(query) + "&trans_id=" + transId + "&token='.addslashes($tokenVal).'", true);
    xhr.onload = function() {
        if (xhr.status == 200) {
            try {
                var results = JSON.parse(xhr.responseText);
                renderSplitResults(transId, results);
            } catch(e) {
                resultsDiv.innerHTML = "<em>Fehler</em>";
            }
        }
    };
    xhr.send();
}

function addSplitEntryFromSearch(transId, idx) {
    var r = splitSearchResults[transId] && splitSearchResults[transId][idx];
    if (!r) return;
    var remaining = (r.amount_remaining !== undefined) ? parseFloat(r.amount_remaining) : parseFloat(r.amount);
    addSplitEntry(transId, r.id, r.ref, r.thirdparty || "", parseFloat(r.amount), r.type, remaining);
}

function renderSplitResults(transId, results) {
    splitSearchResults[transId] = results;
    var resultsDiv = document.getElementById("splitresults_" + transId);
    if (!results || results.length === 0) {
        resultsDiv.innerHTML = "<em style=\"color:#888;\">Keine Rechnungen gefunden</em>";
        return;
    }
    var html = "";
    results.forEach(function(r, idx) {
        var total = parseFloat(r.amount).toFixed(2).replace(".", ",");
        var rem   = (r.amount_remaining !== undefined) ? parseFloat(r.amount_remaining) : parseFloat(r.amount);
        var remFmt = rem.toFixed(2).replace(".", ",");
        var partialNote = (rem < parseFloat(r.amount) - 0.01) ? " <small style=\"color:#e67e22;\">(noch offen: " + remFmt + " &euro;)</small>" : "";
        var typeLabel = r.type === "supplier"
            ? "<span style=\"background:#f0ad4e;color:#fff;font-size:10px;padding:1px 4px;border-radius:3px;margin-right:4px;\">Lieferant</span>"
            : "<span style=\"background:#5bc0de;color:#fff;font-size:10px;padding:1px 4px;border-radius:3px;margin-right:4px;\">Kunde</span>";
        html += "<div class=\"split-result-item\" onclick=\"addSplitEntryFromSearch(" + transId + "," + idx + ")\" style=\"padding:4px 6px; cursor:pointer; border-bottom:1px solid #eee;\">";
        html += typeLabel + "<strong>" + escHtml(r.ref) + "</strong> &mdash; " + escHtml(r.thirdparty || "") + " &mdash; " + total + " &euro;" + partialNote;
        html += "</div>";
    });
    resultsDiv.innerHTML = html;
}

function escHtml(s) {
    var d = document.createElement("div");
    d.appendChild(document.createTextNode(s || ""));
    return d.innerHTML;
}

function addSplitEntry(transId, invoiceId, ref, thirdparty, invoiceAmount, invoiceType, invoiceRemaining) {
    if (!splitState[transId]) splitState[transId] = [];
    // Prevent duplicate invoice
    for (var i = 0; i < splitState[transId].length; i++) {
        if (splitState[transId][i].invoice_id == invoiceId) return;
    }
    var panel = document.getElementById("splitpanel_" + transId);
    var transAmount = Math.abs(parseFloat(panel.dataset.transAmount));
    var assigned = splitState[transId].reduce(function(s, e) { return s + parseFloat(e.amount); }, 0);
    var transRemaining = Math.round((transAmount - assigned) * 100) / 100;
    // Default amount based on invoice total (not remaining) to avoid 0-amount edge cases
    var defaultAmt = Math.min(invoiceAmount, transRemaining > 0 ? transRemaining : invoiceAmount);
    if (defaultAmt <= 0) defaultAmt = transRemaining > 0 ? transRemaining : invoiceAmount;
    var invRem = (invoiceRemaining !== undefined && invoiceRemaining > 0) ? invoiceRemaining : invoiceAmount;
    splitState[transId].push({ invoice_id: invoiceId, ref: ref, thirdparty: thirdparty, invoice_amount: invoiceAmount, invoice_remaining: invRem, amount: defaultAmt, invoice_type: invoiceType || "customer" });
    // Clear search
    var input = document.getElementById("splitsearch_" + transId);
    if (input) input.value = "";
    document.getElementById("splitresults_" + transId).style.display = "none";
    renderSplitEntries(transId);
}

function removeSplitEntry(transId, idx) {
    if (splitState[transId]) splitState[transId].splice(idx, 1);
    renderSplitEntries(transId);
}

function renderSplitEntries(transId) {
    var panel = document.getElementById("splitpanel_" + transId);
    var transAmount = Math.abs(parseFloat(panel.dataset.transAmount));
    var listDiv = document.getElementById("splitlist_" + transId);
    var entries = splitState[transId] || [];

    if (entries.length === 0) {
        listDiv.innerHTML = "<em style=\"color:#888;\">Noch keine Rechnungen hinzugefuegt.</em>";
    } else {
        var html = "<table style=\"width:100%; border-collapse:collapse; margin-top:6px;\">";
        html += "<tr style=\"background:#f0f0f0;\"><th style=\"text-align:left;padding:3px 6px;\">Rechnung</th><th style=\"text-align:left;padding:3px 6px;\">Kunde</th><th style=\"text-align:right;padding:3px 6px;\">Betrag (&euro;)</th><th></th></tr>";
        entries.forEach(function(e, idx) {
            var typeLabel = e.invoice_type === "supplier" ? "<span style=\"background:#f0ad4e;color:#fff;font-size:10px;padding:1px 4px;border-radius:3px;\">Lieferant</span>" : "<span style=\"background:#5bc0de;color:#fff;font-size:10px;padding:1px 4px;border-radius:3px;\">Kunde</span>";
            var amt = parseFloat(e.amount || 0);
            var invRem = parseFloat(e.invoice_remaining !== undefined ? e.invoice_remaining : e.invoice_amount);
            var overLimit = amt > invRem + 0.01;
            var rowStyle = overLimit ? "border-bottom:1px solid #ddd; background:#fff3cd;" : "border-bottom:1px solid #ddd;";
            var warning = overLimit ? " <span title=\"Betrag ueberschreitet offenen Rechnungsbetrag (" + invRem.toFixed(2).replace(".",",") + " €)\" style=\"color:#e67e22; cursor:help;\">&#9888;</span>" : "";
            html += "<tr style=\"" + rowStyle + "\">";
            html += "<td style=\"padding:3px 6px;\">" + typeLabel + " " + escHtml(e.ref) + warning + "</td>";
            html += "<td style=\"padding:3px 6px;\">" + escHtml(e.thirdparty) + "</td>";
            html += "<td style=\"padding:3px 6px; text-align:right;\"><input type=\"number\" step=\"0.01\" min=\"0.01\" value=\"" + amt.toFixed(2) + "\" style=\"width:90px; text-align:right;" + (overLimit ? " border-color:#e67e22;" : "") + "\" onchange=\"updateSplitAmount(" + transId + "," + idx + ",this.value)\"></td>";
            html += "<td style=\"padding:3px 6px;\"><a href=\"#\" onclick=\"removeSplitEntry(" + transId + "," + idx + ");return false;\" style=\"color:red;\">&#x2715;</a></td>";
            html += "</tr>";
        });
        html += "</table>";
        listDiv.innerHTML = html;
    }

    updateSplitTotals(transId);
}

function updateSplitAmount(transId, idx, val) {
    if (splitState[transId] && splitState[transId][idx]) {
        splitState[transId][idx].amount = parseFloat(val) || 0;
        updateSplitTotals(transId);
    }
}

function updateSplitTotals(transId) {
    var panel = document.getElementById("splitpanel_" + transId);
    var transAmount = Math.abs(parseFloat(panel.dataset.transAmount));
    var entries = splitState[transId] || [];
    var assigned = entries.reduce(function(s, e) { return s + parseFloat(e.amount || 0); }, 0);
    assigned = Math.round(assigned * 100) / 100;
    var remaining = Math.round((transAmount - assigned) * 100) / 100;

    var assignedEl = document.getElementById("splittotal_" + transId);
    var remainEl = document.getElementById("splitremain_" + transId);
    if (assignedEl) assignedEl.textContent = assigned.toFixed(2).replace(".", ",");
    if (remainEl) {
        remainEl.textContent = remaining.toFixed(2).replace(".", ",");
        remainEl.style.color = Math.abs(remaining) < 0.01 ? "green" : "red";
    }

    var btn = document.getElementById("splitbtn_" + transId);
    if (btn) btn.disabled = !(entries.length > 0 && assigned > 0 && assigned <= transAmount + 0.01);
}

function submitSplitMatch(transId) {
    var entries = splitState[transId] || [];
    if (entries.length === 0) { alert("Bitte mindestens eine Rechnung hinzufuegen."); return; }
    var panel = document.getElementById("splitpanel_" + transId);
    var transAmount = Math.abs(parseFloat(panel.dataset.transAmount));
    var assigned = entries.reduce(function(s, e) { return s + parseFloat(e.amount || 0); }, 0);
    if (assigned <= 0) { alert("Bitte einen Betrag eingeben."); return; }
    if (assigned > transAmount + 0.01) { alert("Zugeordneter Betrag ueberschreitet den Transaktionsbetrag."); return; }
    var splitData = entries.map(function(e) { return { invoice_id: e.invoice_id, amount: parseFloat(e.amount), invoice_type: e.invoice_type || "customer" }; });
    document.getElementById("splitdata_" + transId).value = JSON.stringify(splitData);
    document.getElementById("splitmatchform_" + transId).submit();
}
</script>';
print '<style>
.match-dropdown a.match-item:hover { background: #f0f0f0; }
.split-panel { background: #fafafa; border: 1px solid #ddd; border-radius: 4px; padding: 12px; }
.split-result-item:hover { background: #e8f0ff; }
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
            // Import to bank first, then matching becomes available
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=import&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("ImportToBank").'">';
            print img_picto($langs->trans("ImportToBank"), 'add', 'class="paddingright"');
            print '</a>';
            // Ignore
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=ignore&trans_id='.$trans->id.'&id='.$id.'&status='.$status.'&token='.newToken().'" title="'.$langs->trans("IgnoreTransaction").'">';
            print img_picto($langs->trans("IgnoreTransaction"), 'disable');
            print '</a>';
        } elseif ($trans->status == 'matched') {
            // Check for split assignments
            $assignments = $trans->getInvoiceAssignments();
            if (count($assignments) > 1) {
                // Multiple invoices (split)
                foreach ($assignments as $asgn) {
                    print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$asgn['fk_facture'].'" title="'.$langs->trans("ViewInvoice").': '.dol_escape_htmltag($asgn['ref']).'">';
                    print img_picto($langs->trans("ViewInvoice"), 'bill', 'class="paddingright"');
                    print '</a>';
                }
            } elseif ($trans->fk_facture > 0) {
                print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$trans->fk_facture.'" title="'.$langs->trans("ViewInvoice").'">';
                print img_picto($langs->trans("ViewInvoice"), 'bill', 'class="paddingright"');
                print '</a>';
            }
            // View bank line
            if ($trans->fk_bank_line > 0) {
                print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.$trans->fk_bank_line.'" title="'.$langs->trans("ViewBankLine").'">';
                print img_picto($langs->trans("ViewBankLine"), 'bank_account', 'class="paddingright"');
                print '</a>';
            }
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
                // Split across multiple invoices
                print '<a href="#" onclick="openSplitPanel('.$trans->id.'); return false;" title="'.$langs->trans("SplitTransaction").'">';
                print img_picto($langs->trans("SplitTransaction"), 'split', 'class="paddingright"');
                print '</a>';
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

        // Hidden split panel row (only for imported without invoice)
        if ($trans->status == 'imported' && !$trans->fk_facture) {
            $transAmountAbs = abs((float)$trans->amount);
            print '<tr id="splitrow_'.$trans->id.'" class="splitrow" style="display:none;">';
            print '<td colspan="6" style="padding:0;">';
            print '<div class="split-panel" id="splitpanel_'.$trans->id.'" data-trans-id="'.$trans->id.'" data-trans-amount="'.$transAmountAbs.'">';
            print '<strong>'.$langs->trans("SplitTransaction").'</strong>';
            print ' &mdash; '.price($trans->amount, 0, $langs, 1, -1, 2, $trans->currency);
            print '<div style="margin-top:10px; display:flex; gap:6px; align-items:center;">';
            print '<input type="text" id="splitsearch_'.$trans->id.'" placeholder="'.$langs->trans("SplitSearchPlaceholder").'" style="width:260px;" onkeydown="if(event.key===\'Enter\'){searchSplitInvoices('.$trans->id.');}">';
            print '<button class="butAction" onclick="searchSplitInvoices('.$trans->id.');">'.$langs->trans("SplitSearch").'</button>';
            print '</div>';
            print '<div id="splitresults_'.$trans->id.'" style="display:none; border:1px solid #ccc; background:#fff; max-height:160px; overflow-y:auto; margin-top:4px; border-radius:3px;"></div>';
            print '<div id="splitlist_'.$trans->id.'" style="margin-top:10px;"></div>';
            print '<div style="margin-top:8px;">';
            print $langs->trans("SplitAssigned").': <strong><span id="splittotal_'.$trans->id.'">0,00</span></strong> &euro;';
            print ' &nbsp;|&nbsp; ';
            print $langs->trans("SplitRemaining").': <strong><span id="splitremain_'.$trans->id.'" style="color:red;">'.number_format($transAmountAbs, 2, ',', '').'</span></strong> &euro;';
            print ' <small style="color:#888;">(Gesamt: '.number_format($transAmountAbs, 2, ',', '').' &euro;)</small>';
            print '</div>';
            print '<div style="margin-top:10px; display:flex; gap:8px;">';
            print '<button id="splitbtn_'.$trans->id.'" class="butAction" onclick="submitSplitMatch('.$trans->id.');" disabled>'.$langs->trans("SplitAssign").'</button>';
            print '<button class="butActionDelete" onclick="openSplitPanel('.$trans->id.');">'.$langs->trans("SplitCancel").'</button>';
            print '</div>';
            // Hidden form for submission
            print '<form id="splitmatchform_'.$trans->id.'" method="POST" action="'.$_SERVER["PHP_SELF"].'">';
            print '<input type="hidden" name="action" value="splitmatch">';
            print '<input type="hidden" name="trans_id" value="'.$trans->id.'">';
            print '<input type="hidden" name="id" value="'.$id.'">';
            print '<input type="hidden" name="status" value="'.dol_escape_htmltag($status).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" id="splitdata_'.$trans->id.'" name="split_data" value="">';
            print '</form>';
            print '</div>';
            print '</td>';
            print '</tr>';
        }
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
