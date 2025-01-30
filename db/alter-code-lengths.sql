-- Alter code columns in reference tables
ALTER TABLE prod.specialty ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.service ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.casestatus ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.cancellationreason ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.asarating ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.casetype ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.caseclass ALTER COLUMN code TYPE VARCHAR(255);
ALTER TABLE prod.patientclass ALTER COLUMN code TYPE VARCHAR(255);
