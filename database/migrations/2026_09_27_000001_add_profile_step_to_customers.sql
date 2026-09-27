-- Batch 2: resumable profile wizard (spec 4.1 step 4). Highest completed step, 0-4.

ALTER TABLE customers
    ADD COLUMN profile_step TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Highest completed profile wizard step (0-4)' AFTER kyc_remarks;
