-- ============================================================
--  Xendit payments migration  (safe & RE-RUNNABLE)
--  Adds Xendit reference columns + status values to `transactions`
--  while preserving existing data and the legacy Stripe column.
--
--  Safe & backward compatible:
--    * Only ADDs columns / indexes and EXTENDs the status enum.
--    * No DROP, no TRUNCATE, no rename.
--    * Existing rows keep their data; new columns default to NULL.
--    * Uses IF NOT EXISTS so it can be run again without errors.
--
--  How to run in phpMyAdmin:
--    1. Back up first (Export -> Go).
--    2. Select the `gym_system` database in the left sidebar.
--    3. Click the "SQL" tab, paste this whole file, click "Go".
--
--  Or from the command line:
--    mysql -u root gym_system < migrations/2026_10_xendit_payments.sql
--
--  The PHP runner migrations/migrate_xendit.php does the same thing
--  and is also idempotent (it checks information_schema first).
-- ============================================================

-- ------------------------------------------------------------
-- New reference / provider columns
-- ------------------------------------------------------------
ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `payment_provider` varchar(30) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `xendit_payment_request_id` varchar(100) DEFAULT NULL AFTER `payment_provider`,
  ADD COLUMN IF NOT EXISTS `xendit_payment_id` varchar(100) DEFAULT NULL AFTER `xendit_payment_request_id`,
  ADD COLUMN IF NOT EXISTS `xendit_capture_id` varchar(100) DEFAULT NULL AFTER `xendit_payment_id`,
  ADD COLUMN IF NOT EXISTS `xendit_channel_code` varchar(40) DEFAULT NULL AFTER `xendit_capture_id`,
  ADD COLUMN IF NOT EXISTS `xendit_status` varchar(30) DEFAULT NULL AFTER `xendit_channel_code`,
  ADD COLUMN IF NOT EXISTS `checkout_url` varchar(500) DEFAULT NULL AFTER `xendit_status`,
  ADD COLUMN IF NOT EXISTS `expires_at` datetime DEFAULT NULL AFTER `checkout_url`;

-- ------------------------------------------------------------
-- Extend the status enum with Failed / Expired.
-- MODIFY COLUMN is naturally idempotent (same definition each run).
-- ------------------------------------------------------------
ALTER TABLE `transactions`
  MODIFY COLUMN `status` enum('Paid','Pending','Cancelled','Failed','Expired') DEFAULT 'Pending';

-- ------------------------------------------------------------
-- Indexes for webhook / verification lookups
-- (the legacy idx_stripe_intent index is intentionally kept)
-- ------------------------------------------------------------
ALTER TABLE `transactions`
  ADD INDEX IF NOT EXISTS `idx_xendit_pr` (`xendit_payment_request_id`),
  ADD INDEX IF NOT EXISTS `idx_xendit_payment` (`xendit_payment_id`);
