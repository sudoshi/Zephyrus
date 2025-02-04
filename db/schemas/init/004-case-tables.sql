/*
Description: Create case management tables for OR Analytics Platform
Dependencies: 001-schemas.sql, 002-reference-tables.sql, 003-core-tables.sql
Author: System
Date: 2024-02-03
*/

BEGIN;

-- OR Cases
CREATE TABLE IF NOT EXISTS prod.or_cases (
    case_id BIGSERIAL PRIMARY KEY,
    case_number VARCHAR(255) NOT NULL UNIQUE,
    patient_id VARCHAR(255) NOT NULL,
    service_id BIGINT NOT NULL,
    case_type_id BIGINT NOT NULL,
    case_class_id BIGINT NOT NULL,
    patient_class_id BIGINT NOT NULL,
    status_id BIGINT NOT NULL,
    asa_rating_id BIGINT,
    cancellation_id BIGINT,
    primary_surgeon_id BIGINT NOT NULL,
    room_id BIGINT,
    scheduled_date DATE,
    scheduled_start_time TIME,
    scheduled_end_time TIME,
    actual_start_time TIME,
    actual_end_time TIME,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_cases_service FOREIGN KEY (service_id) REFERENCES prod.services(service_id),
    CONSTRAINT fk_cases_case_type FOREIGN KEY (case_type_id) REFERENCES prod.case_types(case_type_id),
    CONSTRAINT fk_cases_case_class FOREIGN KEY (case_class_id) REFERENCES prod.case_classes(case_class_id),
    CONSTRAINT fk_cases_patient_class FOREIGN KEY (patient_class_id) REFERENCES prod.patient_classes(patient_class_id),
    CONSTRAINT fk_cases_status FOREIGN KEY (status_id) REFERENCES prod.case_statuses(status_id),
    CONSTRAINT fk_cases_asa_rating FOREIGN KEY (asa_rating_id) REFERENCES prod.asa_ratings(asa_id),
    CONSTRAINT fk_cases_cancellation FOREIGN KEY (cancellation_id) REFERENCES prod.cancellation_reasons(cancellation_id),
    CONSTRAINT fk_cases_surgeon FOREIGN KEY (primary_surgeon_id) REFERENCES prod.providers(provider_id),
    CONSTRAINT fk_cases_room FOREIGN KEY (room_id) REFERENCES prod.rooms(room_id)
);

-- Case Resources (additional staff assigned to case)
CREATE TABLE IF NOT EXISTS prod.case_resources (
    resource_id BIGSERIAL PRIMARY KEY,
    case_id BIGINT NOT NULL,
    provider_id BIGINT NOT NULL,
    role VARCHAR(255) NOT NULL,
    start_time TIME,
    end_time TIME,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_case_resources_case FOREIGN KEY (case_id) REFERENCES prod.or_cases(case_id),
    CONSTRAINT fk_case_resources_provider FOREIGN KEY (provider_id) REFERENCES prod.providers(provider_id)
);

-- Case Measurements
CREATE TABLE IF NOT EXISTS prod.case_measurements (
    measurement_id BIGSERIAL PRIMARY KEY,
    case_id BIGINT NOT NULL,
    metric_type VARCHAR(255) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    measured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_case_measurements_case FOREIGN KEY (case_id) REFERENCES prod.or_cases(case_id)
);

-- Case Safety Notes
CREATE TABLE IF NOT EXISTS prod.case_safety_notes (
    note_id BIGSERIAL PRIMARY KEY,
    case_id BIGINT NOT NULL,
    note_type VARCHAR(255) NOT NULL,
    note_text TEXT NOT NULL,
    severity VARCHAR(50) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    is_deleted BOOLEAN DEFAULT false,
    CONSTRAINT fk_case_safety_notes_case FOREIGN KEY (case_id) REFERENCES prod.or_cases(case_id)
);

-- Add indexes for commonly queried fields
CREATE INDEX idx_cases_case_number ON prod.or_cases(case_number);
CREATE INDEX idx_cases_patient_id ON prod.or_cases(patient_id);
CREATE INDEX idx_cases_scheduled_date ON prod.or_cases(scheduled_date);
CREATE INDEX idx_cases_service_id ON prod.or_cases(service_id);
CREATE INDEX idx_cases_status_id ON prod.or_cases(status_id);
CREATE INDEX idx_cases_primary_surgeon_id ON prod.or_cases(primary_surgeon_id);
CREATE INDEX idx_cases_room_id ON prod.or_cases(room_id);

CREATE INDEX idx_case_resources_case_id ON prod.case_resources(case_id);
CREATE INDEX idx_case_resources_provider_id ON prod.case_resources(provider_id);

CREATE INDEX idx_case_measurements_case_id ON prod.case_measurements(case_id);
CREATE INDEX idx_case_measurements_metric_type ON prod.case_measurements(metric_type);

CREATE INDEX idx_case_safety_notes_case_id ON prod.case_safety_notes(case_id);
CREATE INDEX idx_case_safety_notes_severity ON prod.case_safety_notes(severity);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP TABLE IF EXISTS prod.case_safety_notes CASCADE;
DROP TABLE IF EXISTS prod.case_measurements CASCADE;
DROP TABLE IF EXISTS prod.case_resources CASCADE;
DROP TABLE IF EXISTS prod.or_cases CASCADE;
COMMIT;
*/
