<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/fintsaccount.class.php
 * \ingroup    fintsbank
 * \brief      FinTS Account management class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class FintsAccount - Manages FinTS bank connections
 */
class FintsAccount extends CommonObject
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Element type
     */
    public $element = 'fintsaccount';

    /**
     * @var string Table name
     */
    public $table_element = 'fintsbank_account';

    /**
     * @var int Bank account ID (llx_bank_account)
     */
    public $fk_bank;

    /**
     * @var string Bank code (BLZ)
     */
    public $bank_code;

    /**
     * @var string FinTS server URL
     */
    public $fints_url;

    /**
     * @var string FinTS version
     */
    public $fints_version = '3.0';

    /**
     * @var string Username
     */
    public $username;

    /**
     * @var string Customer ID
     */
    public $customer_id;

    /**
     * @var string IBAN
     */
    public $iban;

    /**
     * @var string BIC
     */
    public $bic;

    /**
     * @var string Account number
     */
    public $account_number;

    /**
     * @var int Last sync timestamp
     */
    public $last_sync;

    /**
     * @var int Sync from date
     */
    public $sync_from_date;

    /**
     * @var int Is active
     */
    public $active = 1;

    /**
     * @var string FinTS product name/registration number
     */
    public $product_name;

    /**
     * @var string Encryption key for credentials
     */
    private $encryptionKey;

    /**
     * Known FinTS URLs for German banks
     */
    public static $bankUrls = array(
        '12030000' => array('name' => 'Deutsche Kreditbank (DKB)', 'url' => 'https://banking-dkb.s-fints-pt-dkb.de/fints30'),
        '20041111' => array('name' => 'Commerzbank', 'url' => 'https://fints.commerzbank.com/PinTanCgi'),
        '37040044' => array('name' => 'Commerzbank', 'url' => 'https://fints.commerzbank.com/PinTanCgi'),
        '50040000' => array('name' => 'Commerzbank', 'url' => 'https://fints.commerzbank.com/PinTanCgi'),
        '67280051' => array('name' => 'Volksbank', 'url' => 'https://fints.gad.de/fints'),
        // Add more banks as needed
    );

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        global $conf;
        $this->encryptionKey = hash('sha256', $conf->db->name.$conf->db->user.'fintsbank', true);
    }

    /**
     * Create a new FinTS account connection
     *
     * @param User $user User object
     * @return int <0 if KO, >0 if OK
     */
    public function create($user)
    {
        global $conf;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " (fk_bank, bank_code, fints_url, fints_version, username, customer_id,";
        $sql .= " iban, bic, account_number, sync_from_date, active, product_name, date_creation, entity)";
        $sql .= " VALUES (";
        $sql .= " ".(int)$this->fk_bank.",";
        $sql .= " '".$this->db->escape($this->bank_code)."',";
        $sql .= " '".$this->db->escape($this->fints_url)."',";
        $sql .= " '".$this->db->escape($this->fints_version)."',";
        $sql .= " '".$this->db->escape($this->username)."',";
        $sql .= " ".($this->customer_id ? "'".$this->db->escape($this->customer_id)."'" : "NULL").",";
        $sql .= " ".($this->iban ? "'".$this->db->escape($this->iban)."'" : "NULL").",";
        $sql .= " ".($this->bic ? "'".$this->db->escape($this->bic)."'" : "NULL").",";
        $sql .= " ".($this->account_number ? "'".$this->db->escape($this->account_number)."'" : "NULL").",";
        $sql .= " ".($this->sync_from_date ? "'".$this->db->idate($this->sync_from_date)."'" : "NULL").",";
        $sql .= " ".(int)$this->active.",";
        $sql .= " ".($this->product_name ? "'".$this->db->escape($this->product_name)."'" : "NULL").",";
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
     * Fetch a FinTS account connection
     *
     * @param int $id Row ID
     * @return int <0 if KO, >0 if OK, 0 if not found
     */
    public function fetch($id)
    {
        // Try with product_name first, fallback to without if column doesn't exist
        $sql = "SELECT rowid, fk_bank, bank_code, fints_url, fints_version, username, customer_id,";
        $sql .= " iban, bic, account_number, last_sync, sync_from_date, active, date_creation";
        $sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE rowid = ".(int)$id;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $this->id = $obj->rowid;
                $this->fk_bank = $obj->fk_bank;
                $this->bank_code = $obj->bank_code;
                $this->fints_url = $obj->fints_url;
                $this->fints_version = $obj->fints_version;
                $this->username = $obj->username;
                $this->customer_id = $obj->customer_id;
                $this->iban = $obj->iban;
                $this->bic = $obj->bic;
                $this->account_number = $obj->account_number;
                $this->last_sync = $this->db->jdate($obj->last_sync);
                $this->sync_from_date = $this->db->jdate($obj->sync_from_date);
                $this->active = $obj->active;
                $this->product_name = isset($obj->product_name) ? $obj->product_name : '';
                $this->date_creation = $this->db->jdate($obj->date_creation);

                $this->db->free($resql);
                return 1;
            }
            $this->db->free($resql);
            return 0;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Fetch by bank account ID
     *
     * @param int $fk_bank Bank account ID
     * @return int <0 if KO, >0 if OK, 0 if not found
     */
    public function fetchByBankAccount($fk_bank)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE fk_bank = ".(int)$fk_bank;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return $this->fetch($obj->rowid);
            }
            return 0;
        }
        return -1;
    }

    /**
     * Update FinTS account connection
     *
     * @param User $user User object
     * @return int <0 if KO, >0 if OK
     */
    public function update($user)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " SET bank_code = '".$this->db->escape($this->bank_code)."',";
        $sql .= " fints_url = '".$this->db->escape($this->fints_url)."',";
        $sql .= " fints_version = '".$this->db->escape($this->fints_version)."',";
        $sql .= " username = '".$this->db->escape($this->username)."',";
        $sql .= " customer_id = ".($this->customer_id ? "'".$this->db->escape($this->customer_id)."'" : "NULL").",";
        $sql .= " iban = ".($this->iban ? "'".$this->db->escape($this->iban)."'" : "NULL").",";
        $sql .= " bic = ".($this->bic ? "'".$this->db->escape($this->bic)."'" : "NULL").",";
        $sql .= " account_number = ".($this->account_number ? "'".$this->db->escape($this->account_number)."'" : "NULL").",";
        $sql .= " sync_from_date = ".($this->sync_from_date ? "'".$this->db->idate($this->sync_from_date)."'" : "NULL").",";
        $sql .= " active = ".(int)$this->active.",";
        $sql .= " product_name = ".($this->product_name ? "'".$this->db->escape($this->product_name)."'" : "NULL").",";
        $sql .= " date_modification = '".$this->db->idate(dol_now())."'";
        $sql .= " WHERE rowid = ".(int)$this->id;

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Update last sync timestamp
     *
     * @return int <0 if KO, >0 if OK
     */
    public function updateLastSync()
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " SET last_sync = '".$this->db->idate(dol_now())."'";
        $sql .= " WHERE rowid = ".(int)$this->id;

        return $this->db->query($sql) ? 1 : -1;
    }

    /**
     * Delete FinTS account connection
     *
     * @param User $user User object
     * @return int <0 if KO, >0 if OK
     */
    public function delete($user)
    {
        // Delete related transactions first
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."fintsbank_transaction";
        $sql .= " WHERE fk_fintsbank_account = ".(int)$this->id;
        $this->db->query($sql);

        // Delete account
        $sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE rowid = ".(int)$this->id;

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Get all FinTS accounts
     *
     * @return array Array of FintsAccount objects
     */
    public function fetchAll()
    {
        global $conf;

        $accounts = array();

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE entity = ".(int)$conf->entity;
        $sql .= " ORDER BY rowid";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $account = new FintsAccount($this->db);
                $account->fetch($obj->rowid);
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * Get FinTS URL for a bank code
     *
     * @param string $bankCode Bank code (BLZ)
     * @return string|null FinTS URL or null
     */
    public static function getFintsUrl($bankCode)
    {
        if (isset(self::$bankUrls[$bankCode])) {
            return self::$bankUrls[$bankCode]['url'];
        }
        return null;
    }

    /**
     * Get bank name for a bank code
     *
     * @param string $bankCode Bank code (BLZ)
     * @return string|null Bank name or null
     */
    public static function getBankName($bankCode)
    {
        if (isset(self::$bankUrls[$bankCode])) {
            return self::$bankUrls[$bankCode]['name'];
        }
        return null;
    }
}
