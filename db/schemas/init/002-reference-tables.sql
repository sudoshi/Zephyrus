/*
Description: Create reference tables for OR Analytics Platform
Dependencies: 001-schemas.sql
Author: System
Date: 2024-02-03
*/

BEGIN;

-- Service
CREATE TABLE IF NOT EXISTS prod.services (
    service_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- CaseType
CREATE TABLE IF NOT EXISTS prod.case_types (
    case_type_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- CaseClass
CREATE TABLE IF NOT EXISTS prod.case_classes (
    case_class_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- PatientClass
CREATE TABLE IF NOT EXISTS prod.patient_classes (
    patient_class_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- CaseStatus
CREATE TABLE IF NOT EXISTS prod.case_statuses (
    status_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- CancellationReason
CREATE TABLE IF NOT EXISTS prod.cancellation_reasons (
    cancellation_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- ASARating
CREATE TABLE IF NOT EXISTS prod.asa_ratings (
    asa_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- Specialty
CREATE TABLE IF NOT EXISTS prod.specialties (
    specialty_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false
);

-- Add unique constraints
ALTER TABLE prod.services ADD CONSTRAINT services_code_unique UNIQUE (code);
ALTER TABLE prod.case_types ADD CONSTRAINT case_types_code_unique UNIQUE (code);
ALTER TABLE prod.case_classes ADD CONSTRAINT case_classes_code_unique UNIQUE (code);
ALTER TABLE prod.patient_classes ADD CONSTRAINT patient_classes_code_unique UNIQUE (code);
ALTER TABLE prod.case_statuses ADD CONSTRAINT case_statuses_code_unique UNIQUE (code);
ALTER TABLE prod.cancellation_reasons ADD CONSTRAINT cancellation_reasons_code_unique UNIQUE (code);
ALTER TABLE prod.asa_ratings ADD CONSTRAINT asa_ratings_code_unique UNIQUE (code);
ALTER TABLE prod.specialties ADD CONSTRAINT specialties_code_unique UNIQUE (code);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP TABLE IF EXISTS prod.specialties CASCADE;
DROP TABLE IF EXISTS prod.asa_ratings CASCADE;
DROP TABLE IF EXISTS prod.cancellation_reasons CASCADE;
DROP TABLE IF EXISTS prod.case_statuses CASCADE;
DROP TABLE IF EXISTS prod.patient_classes CASCADE;
DROP TABLE IF EXISTS prod.case_classes CASCADE;
DROP TABLE IF EXISTS prod.case_types CASCADE;
DROP TABLE IF EXISTS prod.services CASCADE;
COMMIT;
*/
