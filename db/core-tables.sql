-- Ensure you are connected to database "OAP" before running:
-- \c OAP   (in psql, or via a connection parameter in your client)

-- 1. Create the schema
CREATE SCHEMA IF NOT EXISTS prod;

--------------------------------------------------------------------------------
-- 2. Base audit table - provides auditing columns via inheritance
--------------------------------------------------------------------------------
CREATE TABLE prod.basetable (
    created_date   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by     VARCHAR(50) NOT NULL,
    modified_date  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_by    VARCHAR(50) NOT NULL,
    is_deleted     BOOLEAN NOT NULL DEFAULT FALSE
);

--------------------------------------------------------------------------------
-- 3. Reference Tables (Minimal Definitions)
--    These are required so the foreign keys in ORCase, etc. will succeed.
--------------------------------------------------------------------------------

-- 3a. Specialty
CREATE TABLE prod.specialty (
    specialty_id   BIGSERIAL PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    code           VARCHAR(50),
    active_status  BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

-- 3b. Service
CREATE TABLE prod.service (
    service_id     BIGSERIAL PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    code           VARCHAR(50),
    active_status  BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

-- 3c. CaseStatus
CREATE TABLE prod.casestatus (
    status_id      BIGSERIAL PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    code           VARCHAR(50),
    active_status  BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

-- 3d. CancellationReason
CREATE TABLE prod.cancellationreason (
    cancellation_id BIGSERIAL PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    code            VARCHAR(50),
    active_status   BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

-- 3e. ASARating
CREATE TABLE prod.asarating (
    asa_id       BIGSERIAL PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    code         VARCHAR(50),
    description  VARCHAR(500)
)
INHERITS (prod.basetable);

-- 3f. CaseType
CREATE TABLE prod.casetype (
    case_type_id  BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    code          VARCHAR(50),
    active_status BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

-- 3g. CaseClass
CREATE TABLE prod.caseclass (
    case_class_id  BIGSERIAL PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    code           VARCHAR(50),
    active_status  BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

-- 3h. PatientClass
CREATE TABLE prod.patientclass (
    patient_class_id BIGSERIAL PRIMARY KEY,
    name             VARCHAR(200) NOT NULL,
    code             VARCHAR(50),
    active_status    BOOLEAN NOT NULL DEFAULT TRUE
)
INHERITS (prod.basetable);

--------------------------------------------------------------------------------
-- 4. Location
--------------------------------------------------------------------------------
CREATE TABLE prod.location (
    location_id    BIGSERIAL PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    abbreviation   VARCHAR(50) NOT NULL,
    location_type  VARCHAR(50) NOT NULL,
    pos_type       VARCHAR(50),
    active_status  BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT uq_location_name         UNIQUE (name),
    CONSTRAINT uq_location_abbreviation UNIQUE (abbreviation)
)
INHERITS (prod.basetable);

--------------------------------------------------------------------------------
-- 5. Room
--------------------------------------------------------------------------------
CREATE TABLE prod.room (
    room_id      BIGSERIAL PRIMARY KEY,
    location_id  BIGINT NOT NULL,
    name         VARCHAR(50) NOT NULL,
    room_type    VARCHAR(50) NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_room_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id),

    CONSTRAINT uq_room_locationname
        UNIQUE (location_id, name)
)
INHERITS (prod.basetable);

CREATE INDEX ix_room_location ON prod.room(location_id);

--------------------------------------------------------------------------------
-- 6. Provider
--------------------------------------------------------------------------------
CREATE TABLE prod.provider (
    provider_id   BIGSERIAL PRIMARY KEY,
    npi           VARCHAR(10),
    name          VARCHAR(200) NOT NULL,
    specialty_id  BIGINT NOT NULL,
    provider_type VARCHAR(50) NOT NULL,
    active_status BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_provider_specialty
        FOREIGN KEY (specialty_id) REFERENCES prod.specialty(specialty_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_provider_name ON prod.provider(name);
CREATE INDEX ix_provider_npi  ON prod.provider(npi);

--------------------------------------------------------------------------------
-- 7. ORCase
--------------------------------------------------------------------------------
CREATE TABLE prod.orcase (
    case_id                BIGSERIAL PRIMARY KEY,
    log_id                 BIGINT,
    patient_id             VARCHAR(50) NOT NULL,
    surgery_date           DATE NOT NULL,
    room_id                BIGINT NOT NULL,
    location_id            BIGINT NOT NULL,
    primary_surgeon_id     BIGINT NOT NULL,
    case_service_id        BIGINT NOT NULL,
    scheduled_start_time   TIMESTAMP NOT NULL,
    scheduled_duration     INT NOT NULL,  -- in minutes
    record_create_date     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status_id              BIGINT NOT NULL,
    cancellation_reason_id BIGINT,
    asa_rating_id          BIGINT,
    case_type_id           BIGINT NOT NULL,
    case_class_id          BIGINT NOT NULL,
    patient_class_id       BIGINT NOT NULL,
    setup_offset           INT,
    cleanup_offset         INT,
    number_of_panels       INT DEFAULT 1,

    -- Foreign Keys
    CONSTRAINT fk_orcase_room
        FOREIGN KEY (room_id) REFERENCES prod.room(room_id),
    CONSTRAINT fk_orcase_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id),
    CONSTRAINT fk_orcase_provider
        FOREIGN KEY (primary_surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_orcase_service
        FOREIGN KEY (case_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_orcase_status
        FOREIGN KEY (status_id) REFERENCES prod.casestatus(status_id),
    CONSTRAINT fk_orcase_cancellationreason
        FOREIGN KEY (cancellation_reason_id) REFERENCES prod.cancellationreason(cancellation_id),
    CONSTRAINT fk_orcase_asarating
        FOREIGN KEY (asa_rating_id) REFERENCES prod.asarating(asa_id),
    CONSTRAINT fk_orcase_casetype
        FOREIGN KEY (case_type_id) REFERENCES prod.casetype(case_type_id),
    CONSTRAINT fk_orcase_caseclass
        FOREIGN KEY (case_class_id) REFERENCES prod.caseclass(case_class_id),
    CONSTRAINT fk_orcase_patientclass
        FOREIGN KEY (patient_class_id) REFERENCES prod.patientclass(patient_class_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_orcase_surgerydate ON prod.orcase(surgery_date);
CREATE INDEX ix_orcase_location     ON prod.orcase(location_id);
CREATE INDEX ix_orcase_status       ON prod.orcase(status_id);
CREATE INDEX ix_orcase_service      ON prod.orcase(case_service_id);

--------------------------------------------------------------------------------
-- 8. ORLog
--------------------------------------------------------------------------------
CREATE TABLE prod.orlog (
    log_id                      BIGINT PRIMARY KEY,
    case_id                     BIGINT NOT NULL,
    tracking_date               DATE NOT NULL,
    periop_arrival_time         TIMESTAMP,
    preop_in_time               TIMESTAMP,
    preop_out_time              TIMESTAMP,
    or_in_time                  TIMESTAMP,
    anesthesia_start_time       TIMESTAMP,
    procedure_start_time        TIMESTAMP,
    procedure_closing_time      TIMESTAMP,
    procedure_end_time          TIMESTAMP,
    or_out_time                 TIMESTAMP,
    anesthesia_end_time         TIMESTAMP,
    pacu_in_time                TIMESTAMP,
    pacu_out_time               TIMESTAMP,
    pacu2_in_time               TIMESTAMP,
    pacu2_out_time              TIMESTAMP,
    procedural_care_complete_time TIMESTAMP,
    destination                 VARCHAR(50),
    number_of_panels            INT DEFAULT 1,
    primary_procedure           VARCHAR(500),

    -- Foreign Key
    CONSTRAINT fk_orlog_orcase
        FOREIGN KEY (case_id) REFERENCES prod.orcase(case_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_orlog_trackingdate ON prod.orlog(tracking_date);
CREATE INDEX ix_orlog_case         ON prod.orlog(case_id);

--------------------------------------------------------------------------------
-- 9. BlockTemplate
--------------------------------------------------------------------------------
CREATE TABLE prod.blocktemplate (
    block_id      BIGSERIAL PRIMARY KEY,
    room_id       BIGINT NOT NULL,
    service_id    BIGINT NOT NULL,
    surgeon_id    BIGINT,
    group_id      VARCHAR(50),
    block_date    DATE NOT NULL,
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    is_public     BOOLEAN NOT NULL DEFAULT FALSE,
    title         VARCHAR(200),
    abbreviation  VARCHAR(50),
    deployment_id VARCHAR(50),
    comments      VARCHAR(500),

    CONSTRAINT fk_blocktemplate_room
        FOREIGN KEY (room_id) REFERENCES prod.room(room_id),
    CONSTRAINT fk_blocktemplate_service
        FOREIGN KEY (service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktemplate_provider
        FOREIGN KEY (surgeon_id) REFERENCES prod.provider(provider_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_blocktemplate_date    ON prod.blocktemplate(block_date);
CREATE INDEX ix_blocktemplate_room    ON prod.blocktemplate(room_id);
CREATE INDEX ix_blocktemplate_service ON prod.blocktemplate(service_id);

