-- LATRA Compliance: Phase 7 – Battery percentage capture
-- ============================================================
-- These Wialon units report internal battery as a percentage (e.g. 72),
-- not millivolts. Add a column to store the charge percentage alongside
-- the millivolt reading (used by units that do report mV).
--
-- Idempotent: safe to run repeatedly.

USE latra_wialon;

ALTER TABLE device_status
    ADD COLUMN internal_battery_pct DECIMAL(5,2) NULL AFTER internal_battery_mv;
