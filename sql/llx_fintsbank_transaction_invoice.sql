-- Junction table: links one transaction to multiple invoices (split payments)

CREATE TABLE IF NOT EXISTS llx_fintsbank_transaction_invoice (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_transaction  INTEGER NOT NULL,
    fk_facture      INTEGER NOT NULL,
    invoice_type    VARCHAR(10) NOT NULL DEFAULT 'customer',
    amount          DOUBLE(24,8) NOT NULL,
    fk_paiement     INTEGER,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    entity          INTEGER DEFAULT 1 NOT NULL
) ENGINE=InnoDB;

ALTER TABLE llx_fintsbank_transaction_invoice ADD INDEX idx_fints_ti_transaction (fk_transaction);
ALTER TABLE llx_fintsbank_transaction_invoice ADD INDEX idx_fints_ti_facture (fk_facture);
