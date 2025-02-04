/*
Description: Create core tables for OR Analytics Platform
Dependencies: 001-schemas.sql, 002-reference-tables.sql
Author: System
Date: 2024-02-03
*/

BEGIN;

-- Location
CREATE TABLE IF NOT EXISTS prod.locations (
    location_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    abbreviation VARCHAR(255) NOT NULL,
    type VARCHAR(255) NOT NULL,
    pos_type VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- Room
CREATE TABLE IF NOT EXISTS prod.rooms (
    room_id BIGSERIAL PRIMARY KEY,
    location_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_rooms_location FOREIGN KEY (location_id) REFERENCES prod.locations(location_id)
);

-- Provider
CREATE TABLE IF NOT EXISTS prod.providers (
    provider_id BIGSERIAL PRIMARY KEY,
    npi VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    specialty_id BIGINT NOT NULL,
    type VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_providers_specialty FOREIGN KEY (specialty_id) REFERENCES prod.specialties(specialty_id)
);

-- BlockTemplate
CREATE TABLE IF NOT EXISTS prod.block_templates (
    block_id BIGSERIAL PRIMARY KEY,
    room_id BIGINT NOT NULL,
    service_id BIGINT NOT NULL,
    surgeon_id BIGINT,
    group_id VARCHAR(255),
    block_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_public BOOLEAN DEFAULT false,
    title VARCHAR(255) NOT NULL,
    abbreviation VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_block_templates_room FOREIGN KEY (room_id) REFERENCES prod.rooms(room_id),
    CONSTRAINT fk_block_templates_service FOREIGN KEY (service_id) REFERENCES prod.services(service_id),
    CONSTRAINT fk_block_templates_surgeon FOREIGN KEY (surgeon_id) REFERENCES prod.providers(provider_id)
);

-- Add room type check constraint
ALTER TABLE prod.rooms ADD CONSTRAINT check_room_type 
    CHECK (type IN ('general', 'OR', 'pre_op', 'post_op', 'cath_lab', 'L&D'));

-- Add provider type check constraint
ALTER TABLE prod.providers ADD CONSTRAINT check_provider_type 
    CHECK (type IN ('surgeon', 'anesthesiologist', 'nurse'));

-- Add indexes for foreign keys and commonly queried fields
CREATE INDEX idx_rooms_location_id ON prod.rooms(location_id);
CREATE INDEX idx_providers_specialty_id ON prod.providers(specialty_id);
CREATE INDEX idx_block_templates_room_id ON prod.block_templates(room_id);
CREATE INDEX idx_block_templates_service_id ON prod.block_templates(service_id);
CREATE INDEX idx_block_templates_surgeon_id ON prod.block_templates(surgeon_id);
CREATE INDEX idx_block_templates_block_date ON prod.block_templates(block_date);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP TABLE IF EXISTS prod.block_templates CASCADE;
DROP TABLE IF EXISTS prod.providers CASCADE;
DROP TABLE IF EXISTS prod.rooms CASCADE;
DROP TABLE IF EXISTS prod.locations CASCADE;
COMMIT;
*/
