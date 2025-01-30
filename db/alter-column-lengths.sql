-- Alter basetable columns
ALTER TABLE prod.basetable ALTER COLUMN created_by TYPE VARCHAR(255);
ALTER TABLE prod.basetable ALTER COLUMN modified_by TYPE VARCHAR(255);

-- Alter location columns
ALTER TABLE prod.location ALTER COLUMN abbreviation TYPE VARCHAR(255);
ALTER TABLE prod.location ALTER COLUMN location_type TYPE VARCHAR(255);
ALTER TABLE prod.location ALTER COLUMN pos_type TYPE VARCHAR(255);

-- Alter room columns
ALTER TABLE prod.room ALTER COLUMN name TYPE VARCHAR(255);
ALTER TABLE prod.room ALTER COLUMN room_type TYPE VARCHAR(255);

-- Alter provider columns
ALTER TABLE prod.provider ALTER COLUMN provider_type TYPE VARCHAR(255);

-- Alter orlog columns
ALTER TABLE prod.orlog ALTER COLUMN destination TYPE VARCHAR(255);

-- Alter blocktemplate columns
ALTER TABLE prod.blocktemplate ALTER COLUMN group_id TYPE VARCHAR(255);
ALTER TABLE prod.blocktemplate ALTER COLUMN abbreviation TYPE VARCHAR(255);
ALTER TABLE prod.blocktemplate ALTER COLUMN deployment_id TYPE VARCHAR(255);
