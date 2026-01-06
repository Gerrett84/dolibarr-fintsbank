<?php
/* Copyright (C) 2024 FinTS Bank Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup   fintsbank     Module FinTS Bank
 * \brief      Bank account synchronization via FinTS/HBCI
 * \file       core/modules/modFintsBank.class.php
 * \ingroup    fintsbank
 * \brief      Module descriptor for FinTS Bank
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module FintsBank
 */
class modFintsBank extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Module unique ID
        $this->numero = 500210;

        // Key for module
        $this->rights_class = 'fintsbank';

        // Family
        $this->family = "financial";

        // Module position
        $this->module_position = '90';

        // Module label (lowercase!)
        $this->name = strtolower(preg_replace('/^mod/i', '', get_class($this)));

        // Module description
        $this->description = "Bank account synchronization via FinTS/HBCI";
        $this->descriptionlong = "Retrieve bank statements automatically using FinTS (formerly HBCI). Supports German banks including Commerzbank, Sparkasse, Volksbank and others.";

        // Version
        $this->version = '1.0.0';

        // Editor
        $this->editor_name = 'Gerrett84';
        $this->editor_url = 'https://github.com/Gerrett84';

        // Key for const
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

        // Module icon
        $this->picto = 'fa-university';

        // Dependencies
        $this->depends = array('modBanque'); // Requires bank module
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("fintsbank@fintsbank");
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(18, 0);

        // Constants
        $this->const = array();

        // Boxes/Widgets
        $this->boxes = array();

        // Cronjobs
        $this->cronjobs = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Read bank transactions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Sync bank accounts';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'sync';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Configure bank connections';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'config';

        // Menu entries
        $this->menu = array();
        $r = 0;

        // Top menu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=bank',
            'type' => 'left',
            'titre' => 'FintsBankSync',
            'mainmenu' => 'bank',
            'leftmenu' => 'fintsbank',
            'url' => '/custom/fintsbank/transactions.php',
            'langs' => 'fintsbank@fintsbank',
            'position' => 100,
            'enabled' => '$conf->fintsbank->enabled',
            'perms' => '$user->rights->fintsbank->read',
            'target' => '',
            'user' => 0,
        );

        // Admin menu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=setup',
            'type' => 'left',
            'titre' => 'FinTS Bank',
            'mainmenu' => 'home',
            'leftmenu' => 'fintsbank_admin',
            'url' => '/custom/fintsbank/admin/setup.php',
            'langs' => 'fintsbank@fintsbank',
            'position' => 1100,
            'enabled' => '$conf->fintsbank->enabled',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 2,
        );
    }

    /**
     * Function called when module is enabled
     *
     * @param string $options Options when enabling module
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $result = $this->loadTables();
        if ($result < 0) {
            return -1;
        }

        $sql = array();
        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled
     *
     * @param string $options Options when disabling module
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }

    /**
     * Load database tables
     *
     * @return int <0 if KO, >0 if OK
     */
    private function loadTables()
    {
        return $this->_load_tables('/fintsbank/sql/');
    }
}
