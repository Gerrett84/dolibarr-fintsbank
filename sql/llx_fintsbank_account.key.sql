-- Update script for fintsbank module
-- Add product_name column if not exists

ALTER TABLE llx_fintsbank_account ADD COLUMN IF NOT EXISTS product_name VARCHAR(100) AFTER active;
