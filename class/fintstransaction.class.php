<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/fintstransaction.class.php
 * \ingroup    fintsbank
 * \brief      FinTS Transaction management class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class FintsTransaction - Manages imported bank transactions
 */
class FintsTransaction extends CommonObject
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Element type
     */
    public $element = 'fintstransaction';

    /**
     * @var string Table name
     */
    public $table_element = 'fintsbank_transaction';

    // Fields
    public $fk_fintsbank_account;
    public $fk_bank_line;
    public $transaction_id;
    public $booking_date;
    public $value_date;
    public $amount;
    public $currency = 'EUR';
    public $name;
    public $iban;
    public $bic;
    public $description;
    public $booking_text;
    public $primanota;
    public $end_to_end_id;
    public $mandate_id;
    public $creditor_id;
    public $raw_data;
    public $status = 'new';
    public $fk_facture;
    public $fk_societe;
    public $date_import;
    public $date_match;

    // Status constants
    const STATUS_NEW = 'new';
    const STATUS_MATCHED = 'matched';
    const STATUS_IMPORTED = 'imported';
    const STATUS_IGNORED = 'ignored';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Create transaction record
     *
     * @return int <0 if KO, >0 if OK
     */
    public function create()
    {
        global $conf;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " (fk_fintsbank_account, transaction_id, booking_date, value_date, amount, currency,";
        $sql .= " name, iban, bic, description, booking_text, end_to_end_id, raw_data, status, date_import, entity)";
        $sql .= " VALUES (";
        $sql .= " ".(int)$this->fk_fintsbank_account.",";
        $sql .= " '".$this->db->escape($this->transaction_id)."',";
        $sql .= " '".$this->db->idate($this->booking_date)."',";
        $sql .= " ".($this->value_date ? "'".$this->db->idate($this->value_date)."'" : "NULL").",";
        $sql .= " ".(float)$this->amount.",";
        $sql .= " '".$this->db->escape($this->currency)."',";
        $sql .= " ".($this->name ? "'".$this->db->escape($this->name)."'" : "NULL").",";
        $sql .= " ".($this->iban ? "'".$this->db->escape($this->iban)."'" : "NULL").",";
        $sql .= " ".($this->bic ? "'".$this->db->escape($this->bic)."'" : "NULL").",";
        $sql .= " ".($this->description ? "'".$this->db->escape($this->description)."'" : "NULL").",";
        $sql .= " ".($this->booking_text ? "'".$this->db->escape($this->booking_text)."'" : "NULL").",";
        $sql .= " ".($this->end_to_end_id ? "'".$this->db->escape($this->end_to_end_id)."'" : "NULL").",";
        $sql .= " ".($this->raw_data ? "'".$this->db->escape($this->raw_data)."'" : "NULL").",";
        $sql .= " '".$this->db->escape($this->status)."',";
        $sql .= " '".$this->db->idate(dol_now())."',";
        $sql .= " ".(int)$conf->entity;
        $sql .= ")";

        $resql = $this->db->query($sql);
        if ($resql) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
            return $this->id;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Fetch transaction by ID
     *
     * @param int $id Row ID
     * @return int <0 if KO, >0 if OK, 0 if not found
     */
    public function fetch($id)
    {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE rowid = ".(int)$id;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $this->id = $obj->rowid;
                $this->fk_fintsbank_account = $obj->fk_fintsbank_account;
                $this->fk_bank_line = $obj->fk_bank_line;
                $this->transaction_id = $obj->transaction_id;
                $this->booking_date = $this->db->jdate($obj->booking_date);
                $this->value_date = $this->db->jdate($obj->value_date);
                $this->amount = $obj->amount;
                $this->currency = $obj->currency;
                $this->name = $obj->name;
                $this->iban = $obj->iban;
                $this->bic = $obj->bic;
                $this->description = $obj->description;
                $this->booking_text = $obj->booking_text;
                $this->end_to_end_id = $obj->end_to_end_id;
                $this->raw_data = $obj->raw_data;
                $this->status = $obj->status;
                $this->fk_facture = $obj->fk_facture;
                $this->fk_societe = $obj->fk_societe;
                $this->date_import = $this->db->jdate($obj->date_import);
                $this->date_match = $this->db->jdate($obj->date_match);

                $this->db->free($resql);
                return 1;
            }
            return 0;
        }
        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Check if transaction already exists
     *
     * @param string $transactionId Transaction ID
     * @param int $accountId FinTS account ID
     * @return bool True if exists
     */
    public function fetchByTransactionId($transactionId, $accountId)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE transaction_id = '".$this->db->escape($transactionId)."'";
        $sql .= " AND fk_fintsbank_account = ".(int)$accountId;

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return $this->fetch($obj->rowid) > 0;
        }
        return false;
    }

    /**
     * Update transaction status
     *
     * @param string $status New status
     * @return int <0 if KO, >0 if OK
     */
    public function setStatus($status)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " SET status = '".$this->db->escape($status)."'";
        if ($status == self::STATUS_MATCHED || $status == self::STATUS_IMPORTED) {
            $sql .= ", date_match = '".$this->db->idate(dol_now())."'";
        }
        $sql .= " WHERE rowid = ".(int)$this->id;

        if ($this->db->query($sql)) {
            $this->status = $status;
            return 1;
        }
        return -1;
    }

    /**
     * Link to invoice
     *
     * @param int $fk_facture Invoice ID
     * @return int <0 if KO, >0 if OK
     */
    public function linkToInvoice($fk_facture)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " SET fk_facture = ".(int)$fk_facture;
        // Only change status to matched if not already imported
        if ($this->status != self::STATUS_IMPORTED) {
            $sql .= ", status = 'matched'";
        }
        $sql .= ", date_match = '".$this->db->idate(dol_now())."'";
        $sql .= " WHERE rowid = ".(int)$this->id;

        if ($this->db->query($sql)) {
            $this->fk_facture = $fk_facture;
            if ($this->status != self::STATUS_IMPORTED) {
                $this->status = 'matched';
            }
            return 1;
        }
        return -1;
    }

    /**
     * Link to third party
     *
     * @param int $fk_societe Third party ID
     * @return int <0 if KO, >0 if OK
     */
    public function linkToThirdParty($fk_societe)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " SET fk_societe = ".(int)$fk_societe;
        $sql .= " WHERE rowid = ".(int)$this->id;

        if ($this->db->query($sql)) {
            $this->fk_societe = $fk_societe;
            return 1;
        }
        return -1;
    }

    /**
     * Get transactions for an account
     *
     * @param int $accountId FinTS account ID
     * @param string $status Filter by status (optional)
     * @param int $limit Limit results
     * @param int $offset Offset
     * @return array Array of FintsTransaction objects
     */
    public function fetchByAccount($accountId, $status = '', $limit = 100, $offset = 0)
    {
        global $conf;

        $transactions = array();

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE fk_fintsbank_account = ".(int)$accountId;
        $sql .= " AND entity = ".(int)$conf->entity;
        if ($status) {
            $sql .= " AND status = '".$this->db->escape($status)."'";
        }
        $sql .= " ORDER BY booking_date DESC, rowid DESC";
        $sql .= " LIMIT ".(int)$limit." OFFSET ".(int)$offset;

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $trans = new FintsTransaction($this->db);
                $trans->fetch($obj->rowid);
                $transactions[] = $trans;
            }
        }

        return $transactions;
    }

    /**
     * Try to auto-match transaction with an invoice
     * Matches by amount and optionally by reference in description
     *
     * @param float $tolerance Amount tolerance (default 0.01)
     * @return int Invoice ID if matched, 0 if no match
     */
    public function autoMatch($tolerance = 0.01)
    {
        global $conf;

        // Search for unpaid invoices with matching amount
        if ($this->amount > 0) {
            // Customer invoice (incoming payment)
            $sql = "SELECT f.rowid, f.ref, f.total_ttc, f.ref_client";
            $sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
            $sql .= " WHERE f.entity = ".(int)$conf->entity;
            $sql .= " AND f.fk_statut = 1"; // Validated
            $sql .= " AND f.paye = 0"; // Not paid
            $sql .= " AND ABS(f.total_ttc - ".(float)$this->amount.") < ".(float)$tolerance;
        } else {
            // Supplier invoice (outgoing payment)
            $sql = "SELECT f.rowid, f.ref, f.total_ttc, f.ref_supplier as ref_client";
            $sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f";
            $sql .= " WHERE f.entity = ".(int)$conf->entity;
            $sql .= " AND f.fk_statut = 1"; // Validated
            $sql .= " AND f.paye = 0"; // Not paid
            $sql .= " AND ABS(f.total_ttc - ".abs((float)$this->amount).") < ".(float)$tolerance;
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $matches = array();
            while ($obj = $this->db->fetch_object($resql)) {
                $score = 0;

                // Check if invoice ref appears in description
                if ($this->description && $obj->ref) {
                    if (stripos($this->description, $obj->ref) !== false) {
                        $score += 100;
                    }
                }

                // Check if client ref appears in description
                if ($this->description && $obj->ref_client) {
                    if (stripos($this->description, $obj->ref_client) !== false) {
                        $score += 50;
                    }
                }

                // Check if end-to-end ID matches invoice ref
                if ($this->end_to_end_id && $obj->ref) {
                    if (stripos($this->end_to_end_id, $obj->ref) !== false) {
                        $score += 80;
                    }
                }

                $matches[] = array('id' => $obj->rowid, 'ref' => $obj->ref, 'score' => $score);
            }

            // Sort by score descending
            usort($matches, function($a, $b) {
                return $b['score'] - $a['score'];
            });

            // Return best match if score > 0, or first exact amount match
            if (!empty($matches)) {
                if ($matches[0]['score'] > 0) {
                    return $matches[0]['id'];
                }
                // Only one exact amount match = good enough
                if (count($matches) == 1) {
                    return $matches[0]['id'];
                }
            }
        }

        return 0;
    }

    /**
     * Get potential invoice matches for manual selection
     *
     * @param float $tolerance Amount tolerance
     * @param int $limit Max results
     * @return array Array of potential matches
     */
    public function getPotentialMatches($tolerance = 5.0, $limit = 20)
    {
        global $conf;

        $matches = array();

        // For positive amounts, search customer invoices
        if ($this->amount > 0) {
            $sql = "SELECT f.rowid, f.ref, f.ref_client, f.total_ttc, f.datef,";
            $sql .= " s.nom as thirdparty_name";
            $sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
            $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON f.fk_soc = s.rowid";
            $sql .= " WHERE f.entity = ".(int)$conf->entity;
            $sql .= " AND f.fk_statut = 1"; // Validated
            $sql .= " AND f.paye = 0"; // Not paid
            $sql .= " AND ABS(f.total_ttc - ".(float)$this->amount.") < ".(float)$tolerance;
            $sql .= " ORDER BY ABS(f.total_ttc - ".(float)$this->amount.") ASC";
            $sql .= " LIMIT ".(int)$limit;
        } else {
            // For negative amounts, search supplier invoices
            $sql = "SELECT f.rowid, f.ref, f.ref_supplier as ref_client, f.total_ttc, f.datef,";
            $sql .= " s.nom as thirdparty_name";
            $sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f";
            $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON f.fk_soc = s.rowid";
            $sql .= " WHERE f.entity = ".(int)$conf->entity;
            $sql .= " AND f.fk_statut = 1"; // Validated
            $sql .= " AND f.paye = 0"; // Not paid
            $sql .= " AND ABS(f.total_ttc - ".abs((float)$this->amount).") < ".(float)$tolerance;
            $sql .= " ORDER BY ABS(f.total_ttc - ".abs((float)$this->amount).") ASC";
            $sql .= " LIMIT ".(int)$limit;
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $matches[] = array(
                    'id' => $obj->rowid,
                    'ref' => $obj->ref,
                    'ref_client' => $obj->ref_client,
                    'amount' => $obj->total_ttc,
                    'date' => $obj->datef,
                    'thirdparty' => $obj->thirdparty_name,
                    'type' => $this->amount > 0 ? 'customer' : 'supplier'
                );
            }
        }

        return $matches;
    }

    /**
     * Get status label
     *
     * @param string $status Status code
     * @return string HTML badge
     */
    public static function getStatusLabel($status)
    {
        global $langs;
        $langs->load('fintsbank@fintsbank');

        switch ($status) {
            case self::STATUS_NEW:
                return '<span class="badge badge-status0">'.$langs->trans("StatusNew").'</span>';
            case self::STATUS_MATCHED:
                return '<span class="badge badge-status4">'.$langs->trans("StatusMatched").'</span>';
            case self::STATUS_IMPORTED:
                return '<span class="badge badge-status6">'.$langs->trans("StatusImported").'</span>';
            case self::STATUS_IGNORED:
                return '<span class="badge badge-status5">'.$langs->trans("StatusIgnored").'</span>';
            default:
                return '<span class="badge badge-status0">'.$status.'</span>';
        }
    }
}
