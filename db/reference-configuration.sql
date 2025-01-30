-- ============================================================================
-- Reference Tables for schema: prod
-- ============================================================================
-- NOTE: Assumes prod.basetable already exists for INHERITANCE.
-- NOTE: Also assumes that prod.location and prod.service exist for FK references.
-- ============================================================================

-- Service
CREATE TABLE prod.service (
    service_id    BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    code          VARCHAR(50)  NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_service_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- CaseType
CREATE TABLE prod.casetype (
    case_type_id  BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    code          VARCHAR(50)  NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_casetype_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- CaseClass
CREATE TABLE prod.caseclass (
    case_class_id BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    code          VARCHAR(50)  NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_caseclass_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- PatientClass
CREATE TABLE prod.patientclass (
    patient_class_id BIGSERIAL PRIMARY KEY,
    name             VARCHAR(200) NOT NULL,
    code             VARCHAR(50)  NOT NULL,
    active_status    BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_patientclass_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- CaseStatus
CREATE TABLE prod.casestatus (
    status_id     BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    code          VARCHAR(50)  NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_casestatus_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- CancellationReason
CREATE TABLE prod.cancellationreason (
    cancellation_id BIGSERIAL PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    code            VARCHAR(50)  NOT NULL,
    active_status   BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_cancellationreason_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- ASARating
CREATE TABLE prod.asarating (
    asa_id       BIGSERIAL PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    code         VARCHAR(50)  NOT NULL,
    description  VARCHAR(500),
    active_status BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_asarating_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- Specialty
CREATE TABLE prod.specialty (
    specialty_id  BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    code          VARCHAR(50)  NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_specialty_code UNIQUE (code)
)
INHERITS (prod.basetable);

-- ============================================================================
-- Configuration Tables
-- ============================================================================

-- LocationConfig
CREATE TABLE prod.locationconfig (
    location_id          INT PRIMARY KEY,
    prime_time_start     TIME NOT NULL DEFAULT '07:00',
    prime_time_end       TIME NOT NULL DEFAULT '17:00',
    block_release_hours  INT NOT NULL DEFAULT 48,
    scheduling_horizon_days INT NOT NULL DEFAULT 180,
    CONSTRAINT fk_locationconfig_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id)
)
INHERITS (prod.basetable);

-- ServiceConfig
CREATE TABLE prod.serviceconfig (
    service_id               INT PRIMARY KEY,
    min_scheduling_notice_hours INT,
    default_setup_minutes    INT,
    default_cleanup_minutes  INT,
    allow_concurrent_booking BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_serviceconfig_service
        FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);

