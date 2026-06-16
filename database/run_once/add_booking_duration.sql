-- Run once in phpMyAdmin SQL tab
-- Adds the per-booking duration override column.
-- Safe to re-run: IF NOT EXISTS means it silently skips if already present.

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS duration_mins INT DEFAULT NULL AFTER booked_time;
