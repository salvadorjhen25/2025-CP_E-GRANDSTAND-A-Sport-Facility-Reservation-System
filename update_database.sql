-- Update script to add daily_rate column to existing facilities table
USE facility_reservation;

-- Add daily_rate column if it doesn't exist
ALTER TABLE facilities ADD COLUMN IF NOT EXISTS daily_rate DECIMAL(10,2) DEFAULT 0.00 AFTER hourly_rate;

-- Update existing facilities with reasonable daily rates (8x hourly rate)
UPDATE facilities SET daily_rate = hourly_rate * 8 WHERE daily_rate = 0.00;

-- Verify the update
SELECT id, name, hourly_rate, daily_rate FROM facilities;
