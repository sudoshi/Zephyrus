-- Alter name columns in reference tables to handle longer procedure names
ALTER TABLE prod.specialty ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.service ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.casestatus ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.cancellationreason ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.asarating ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.casetype ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.caseclass ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.patientclass ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.location ALTER COLUMN name TYPE VARCHAR(500);
ALTER TABLE prod.provider ALTER COLUMN name TYPE VARCHAR(500);
