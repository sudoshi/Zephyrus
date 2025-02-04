/*
Description: Adjust column lengths for various tables to accommodate larger values
Dependencies: All init scripts must be run first
Author: System
Date: 2024-02-03
*/

BEGIN;

-- Increase column lengths for codes and names
ALTER TABLE prod.services ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.services ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.case_types ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.case_types ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.case_classes ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.case_classes ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.patient_classes ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.patient_classes ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.case_statuses ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.case_statuses ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.cancellation_reasons ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.cancellation_reasons ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.asa_ratings ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.asa_ratings ALTER COLUMN name TYPE VARCHAR(100);

ALTER TABLE prod.specialties ALTER COLUMN code TYPE VARCHAR(50);
ALTER TABLE prod.specialties ALTER COLUMN name TYPE VARCHAR(100);

-- Increase lengths for location and room identifiers
ALTER TABLE prod.locations ALTER COLUMN name TYPE VARCHAR(100);
ALTER TABLE prod.locations ALTER COLUMN abbreviation TYPE VARCHAR(20);
ALTER TABLE prod.locations ALTER COLUMN type TYPE VARCHAR(50);
ALTER TABLE prod.locations ALTER COLUMN pos_type TYPE VARCHAR(50);

ALTER TABLE prod.rooms ALTER COLUMN name TYPE VARCHAR(100);
ALTER TABLE prod.rooms ALTER COLUMN type TYPE VARCHAR(50);

-- Increase lengths for provider fields
ALTER TABLE prod.providers ALTER COLUMN name TYPE VARCHAR(100);
ALTER TABLE prod.providers ALTER COLUMN npi TYPE VARCHAR(20);
ALTER TABLE prod.providers ALTER COLUMN type TYPE VARCHAR(50);

-- Increase lengths for block template fields
ALTER TABLE prod.block_templates ALTER COLUMN title TYPE VARCHAR(100);
ALTER TABLE prod.block_templates ALTER COLUMN abbreviation TYPE VARCHAR(20);
ALTER TABLE prod.block_templates ALTER COLUMN group_id TYPE VARCHAR(50);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
-- Reset column lengths for codes and names
ALTER TABLE prod.services ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.services ALTER COLUMN name TYPE VARCHAR(255);

-- Continue for all other tables...
-- (Full rollback SQL omitted for brevity, but would follow same pattern)
COMMIT;
*/
