/*
Description: Add date fields and constraints for case scheduling
Dependencies: All init scripts and previous migrations must be run first
Author: System
Date: 2024-02-03
*/

BEGIN;

-- Add new date fields to or_cases table
ALTER TABLE prod.or_cases 
    ADD COLUMN IF NOT EXISTS scheduled_date_entered DATE,
    ADD COLUMN IF NOT EXISTS actual_date DATE;

-- Update scheduled_date_entered based on created_at
UPDATE prod.or_cases 
SET scheduled_date_entered = created_at::date 
WHERE scheduled_date_entered IS NULL;

-- Update actual_date based on actual_start_time
UPDATE prod.or_cases 
SET actual_date = scheduled_date 
WHERE actual_date IS NULL AND scheduled_date IS NOT NULL;

-- Add constraints to ensure logical date relationships
ALTER TABLE prod.or_cases 
    ADD CONSTRAINT check_scheduled_date_entered 
    CHECK (scheduled_date_entered <= scheduled_date),
    ADD CONSTRAINT check_actual_date 
    CHECK (actual_date >= scheduled_date_entered);

-- Add indexes for date fields
CREATE INDEX idx_cases_scheduled_date_entered 
    ON prod.or_cases(scheduled_date_entered) 
    WHERE is_deleted = false;

CREATE INDEX idx_cases_actual_date 
    ON prod.or_cases(actual_date) 
    WHERE is_deleted = false;

-- Create function to automatically set scheduled_date_entered
CREATE OR REPLACE FUNCTION prod.set_scheduled_date_entered()
RETURNS TRIGGER AS $$
BEGIN
    NEW.scheduled_date_entered := CURRENT_DATE;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger to set scheduled_date_entered on insert
CREATE TRIGGER trg_set_scheduled_date_entered
    BEFORE INSERT ON prod.or_cases
    FOR EACH ROW
    WHEN (NEW.scheduled_date_entered IS NULL)
    EXECUTE FUNCTION prod.set_scheduled_date_entered();

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP TRIGGER IF EXISTS trg_set_scheduled_date_entered ON prod.or_cases;
DROP FUNCTION IF EXISTS prod.set_scheduled_date_entered();
DROP INDEX IF EXISTS idx_cases_actual_date;
DROP INDEX IF EXISTS idx_cases_scheduled_date_entered;
ALTER TABLE prod.or_cases 
    DROP CONSTRAINT IF EXISTS check_actual_date,
    DROP CONSTRAINT IF EXISTS check_scheduled_date_entered,
    DROP COLUMN IF EXISTS actual_date,
    DROP COLUMN IF EXISTS scheduled_date_entered;
COMMIT;
*/
