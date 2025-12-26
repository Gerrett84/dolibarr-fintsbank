-- Imported bank transactions
-- Stores raw transaction data from FinTS before matching

CREATE TABLE IF NOT EXISTS llx_fintsbank_transaction (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_fintsbank_account INTEGER NOT NULL,         -- Link to fintsbank_account
    fk_bank_line        INTEGER,                   -- Link to llx_bank (after import)
    transaction_id      VARCHAR(100),              -- Bank's unique transaction ID
    booking_date        DATE NOT NULL,             -- Buchungsdatum
    value_date          DATE,                      -- Valutadatum
    amount              DOUBLE(24,8) NOT NULL,     -- Amount (positive=credit, negative=debit)
    currency            VARCHAR(3) DEFAULT 'EUR',  -- Currency code
    name                VARCHAR(255),              -- Auftraggeber/Empfänger
    iban                VARCHAR(34),               -- Counter party IBAN
    bic                 VARCHAR(11),               -- Counter party BIC
    description         TEXT,                      -- Verwendungszweck
    booking_text        VARCHAR(100),              -- Buchungstext (e.g. SEPA-Überweisung)
    primanota           VARCHAR(20),               -- Primanota reference
    end_to_end_id       VARCHAR(50),               -- SEPA End-to-End ID
    mandate_id          VARCHAR(50),               -- SEPA Mandate ID
    creditor_id         VARCHAR(50),               -- SEPA Creditor ID
    raw_data            TEXT,                      -- Full raw transaction data (JSON)
    status              VARCHAR(20) DEFAULT 'new', -- new, matched, imported, ignored
    fk_facture          INTEGER,                   -- Matched invoice (if any)
    fk_societe          INTEGER,                   -- Matched third party (if any)
    date_import         DATETIME NOT NULL,         -- When imported from bank
    date_match          DATETIME,                  -- When matched/processed
    entity              INTEGER DEFAULT 1 NOT NULL
) ENGINE=InnoDB;

ALTER TABLE llx_fintsbank_transaction ADD INDEX idx_fintsbank_trans_account (fk_fintsbank_account);
ALTER TABLE llx_fintsbank_transaction ADD INDEX idx_fintsbank_trans_booking (booking_date);
ALTER TABLE llx_fintsbank_transaction ADD INDEX idx_fintsbank_trans_status (status);
ALTER TABLE llx_fintsbank_transaction ADD INDEX idx_fintsbank_trans_entity (entity);
ALTER TABLE llx_fintsbank_transaction ADD UNIQUE INDEX uk_fintsbank_trans_id (fk_fintsbank_account, transaction_id);
