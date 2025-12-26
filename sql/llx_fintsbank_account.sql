-- Bank connection settings
-- Stores FinTS connection configuration for each bank account

CREATE TABLE IF NOT EXISTS llx_fintsbank_account (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_bank         INTEGER NOT NULL,              -- Link to llx_bank_account
    bank_code       VARCHAR(20) NOT NULL,          -- BLZ (Bankleitzahl)
    fints_url       VARCHAR(255) NOT NULL,         -- FinTS server URL
    fints_version   VARCHAR(10) DEFAULT '3.0',     -- FinTS version
    username        VARCHAR(100) NOT NULL,         -- Bank login username
    customer_id     VARCHAR(50),                   -- Customer ID (if different)
    iban            VARCHAR(34),                   -- IBAN
    bic             VARCHAR(11),                   -- BIC
    account_number  VARCHAR(20),                   -- Account number
    last_sync       DATETIME,                      -- Last successful sync
    sync_from_date  DATE,                          -- Sync transactions from this date
    active          TINYINT DEFAULT 1,             -- Is connection active?
    date_creation   DATETIME NOT NULL,
    date_modification DATETIME,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=InnoDB;

ALTER TABLE llx_fintsbank_account ADD INDEX idx_fintsbank_account_fk_bank (fk_bank);
ALTER TABLE llx_fintsbank_account ADD INDEX idx_fintsbank_account_entity (entity);
