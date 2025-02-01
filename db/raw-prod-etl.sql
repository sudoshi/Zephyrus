------------------------------------------------------------------------------
-- 0) Environment / Setup
------------------------------------------------------------------------------

-- Optionally set the search path
SET search_path TO public;

------------------------------------------------------------------------------
-- 1) Ensure RAW Schema Exists (Do NOT Drop)
------------------------------------------------------------------------------

CREATE SCHEMA IF NOT EXISTS raw AUTHORIZATION postgres;

------------------------------------------------------------------------------
-- 2) Create RAW Tables IF NOT EXISTS (Preserves Existing Data)
--    If these tables already exist and have the same structure, no change.
------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS raw.block_schedule (
    snapshot_date         timestamp NULL,
    "ROOM_ID"             int8 NULL,
    "ORName"              text NULL,
    loc_id                int8 NULL,
    loc_name              text NULL,
    "LINE"                int8 NULL,
    "START_TIME"          text NULL,
    "END_TIME"            text NULL,
    "SLOT_TYPE"           text NULL,
    "BLOCK_KEY"           text NULL,
    "TITLE"               text NULL,
    "SLOT_LENGTH"         int8 NULL,
    "NumofUniqueReleases" float8 NULL,
    "FirstChange"         float8 NULL,
    "SNAPSHOT_DATE"       text NULL,
    "OR_TEMPLATE_AUDIT_ID" float8 NULL,
    "RelDaysBeforeSurgeryDate" float8 NULL,
    "LastChange"          float8 NULL,
    "SNAPSHOT_NUMBER"     float8 NULL
);

CREATE TABLE IF NOT EXISTS raw.block_template (
    schedule_date         timestamp NULL,
    room_id               text NULL,
    roomname             text NULL,
    slot_type_nm         text NULL,
    slot_start_time      timestamp NULL,
    slot_end_time        timestamp NULL,
    public_slot_yn       text NULL,
    service_c            float8 NULL,
    surgeon_id           text NULL,
    group_id             float8 NULL,
    block_key            text NULL,
    title                text NULL,
    abbreviation         text NULL,
    time_off_reason_c    float8 NULL,
    "comments"           text NULL,
    deployment_id        int8 NULL,
    responsible_prov_id  float8 NULL,
    prov_name            text NULL,
    loc_name             text NULL,
    location_abbr        text NULL,
    pos_type             text NULL
);

CREATE TABLE IF NOT EXISTS raw.or_cases (
    case_id     float8 NULL,
    case_date   date NULL,
    "procedure" text NULL,
    surgeon     text NULL,
    duration    int8 NULL,
    room        text NULL
);

CREATE TABLE IF NOT EXISTS raw.or_schedules (
    schedule_id   float8 NULL,
    schedule_date date NULL,
    room          text NULL,
    start_time    text NULL,
    end_time      text NULL,
    case_id       int8 NULL
);

/*
  We assume raw.provider_list also exists and has your critical data.
  Do NOT drop it, do NOT create it. We simply leave it as-is.
  If it doesn’t exist, you can add a CREATE TABLE IF NOT EXISTS raw.provider_list here,
  matching your known structure.
*/

------------------------------------------------------------------------------
-- 3) Create or Replace RAW Procedures (No Drop => preserves data)
------------------------------------------------------------------------------

-- Example placeholders. Replace "..." with the actual procedure bodies you have:
CREATE OR REPLACE PROCEDURE raw.analyzeprimetimeutilization(IN p_startdate date, IN p_enddate date)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.analyzeturnovertimes(IN p_startdate date, IN p_enddate date)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.logdataerror(
    IN p_sourcefile character varying,
    IN p_errormessage text,
    IN p_errordetails text,
    IN p_rowdata text
)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.resolvedataqualityexception(
    IN p_exceptionid bigint,
    IN p_resolvedby character varying,
    IN p_resolutionnotes text
)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.rundataqualitychecks(
    IN p_startdate date,
    IN p_enddate date
)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.trackfileprocessing(
    IN p_filename character varying,
    IN p_filetype character varying,
    IN p_filesize bigint,
    IN p_status character varying,
    IN p_notes text DEFAULT NULL
)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.updateblockutilization(IN p_startdate date, IN p_enddate date)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.validateblockschedule(IN p_filename character varying)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

CREATE OR REPLACE PROCEDURE raw.validateorcase(IN p_filename character varying)
LANGUAGE plpgsql
AS $procedure$
BEGIN
    -- ...
END;
$procedure$;

------------------------------------------------------------------------------
-- 4) Create IF NOT EXISTS raw.room_map bridging table & Insert Values
--    Preserves any existing data in raw.room_map (we just skip duplicates).
------------------------------------------------------------------------------

BEGIN;
CREATE TABLE IF NOT EXISTS raw.room_map (
    raw_room  text PRIMARY KEY,
    prod_room text NOT NULL
);

INSERT INTO raw.room_map (raw_room, prod_room)
VALUES
    ('MARH IR RM 1', 'MARH IR'),
    /* ... all your pairs ... */
    ('VH WILH OR OFFSITE', 'UNKNOWN')
ON CONFLICT (raw_room) DO NOTHING;
COMMIT;

------------------------------------------------------------------------------
-- 5) Drop & Recreate Most of the PROD Schema
--    But PRESERVE prod.users (and any user data within it).
------------------------------------------------------------------------------

-- 5.1) Drop all relevant prod tables (except "prod.users") and their sequences

DROP TABLE IF EXISTS prod.blocktransaction   CASCADE;
DROP TABLE IF EXISTS prod.blockutilization  CASCADE;
DROP TABLE IF EXISTS prod.blocktemplate     CASCADE;
DROP TABLE IF EXISTS prod.surgeonmetrics    CASCADE;
DROP TABLE IF EXISTS prod.orlog             CASCADE;
DROP TABLE IF EXISTS prod.casemetrics       CASCADE;
DROP TABLE IF EXISTS prod.orcase            CASCADE;
DROP TABLE IF EXISTS prod.serviceutilization CASCADE;
DROP TABLE IF EXISTS prod.service           CASCADE;
DROP TABLE IF EXISTS prod.roomutilization   CASCADE;
DROP TABLE IF EXISTS prod.room              CASCADE;
DROP TABLE IF EXISTS prod."location"        CASCADE;
DROP TABLE IF EXISTS prod.provider          CASCADE;
DROP TABLE IF EXISTS prod.specialty         CASCADE;
DROP TABLE IF EXISTS prod.patientclass      CASCADE;
DROP TABLE IF EXISTS prod.casetype          CASCADE;
DROP TABLE IF EXISTS prod.casestatus        CASCADE;
DROP TABLE IF EXISTS prod.caseclass         CASCADE;
DROP TABLE IF EXISTS prod.cancellationreason CASCADE;
DROP TABLE IF EXISTS prod.asarating         CASCADE;
DROP TABLE IF EXISTS prod.dailymetrics      CASCADE;
DROP TABLE IF EXISTS prod.basetable         CASCADE;
DROP TABLE IF EXISTS prod."cache"           CASCADE;
DROP TABLE IF EXISTS prod.migrations        CASCADE;
DROP TABLE IF EXISTS prod.password_reset_tokens CASCADE;
DROP TABLE IF EXISTS prod.sessions          CASCADE;
/* DO NOT drop prod.users! */

-- Drop sequences that fed those tables
DROP SEQUENCE IF EXISTS prod.asarating_asa_id_seq;
DROP SEQUENCE IF EXISTS prod.blocktemplate_block_id_seq;
DROP SEQUENCE IF EXISTS prod.blocktransaction_block_transaction_id_seq;
DROP SEQUENCE IF EXISTS prod.blockutilization_block_utilization_id_seq;
DROP SEQUENCE IF EXISTS prod.cancellationreason_cancellation_id_seq;
DROP SEQUENCE IF EXISTS prod.caseclass_case_class_id_seq;
DROP SEQUENCE IF EXISTS prod.casestatus_status_id_seq;
DROP SEQUENCE IF EXISTS prod.casetype_case_type_id_seq;
DROP SEQUENCE IF EXISTS prod.dailymetrics_daily_metric_id_seq;
DROP SEQUENCE IF EXISTS prod.location_location_id_seq;
DROP SEQUENCE IF EXISTS prod.migrations_id_seq;
DROP SEQUENCE IF EXISTS prod.orcase_case_id_seq;
DROP SEQUENCE IF EXISTS prod.patientclass_patient_class_id_seq;
DROP SEQUENCE IF EXISTS prod.provider_provider_id_seq;
DROP SEQUENCE IF EXISTS prod.room_room_id_seq;
DROP SEQUENCE IF EXISTS prod.roomutilization_room_utilization_id_seq;
DROP SEQUENCE IF EXISTS prod.service_service_id_seq;
DROP SEQUENCE IF EXISTS prod.serviceutilization_service_utilization_id_seq;
DROP SEQUENCE IF EXISTS prod.specialty_specialty_id_seq;
DROP SEQUENCE IF EXISTS prod.surgeonmetrics_surgeon_metric_id_seq;
/* Possibly keep or drop prod.users_id_seq if it’s used by your existing users table. */

-- 5.2) Create the prod schema if needed
CREATE SCHEMA IF NOT EXISTS prod AUTHORIZATION postgres;

------------------------------------------------------------------------------
-- 6) Create Sequences in PROD
------------------------------------------------------------------------------

CREATE SEQUENCE prod.asarating_asa_id_seq   START 1;
CREATE SEQUENCE prod.blocktemplate_block_id_seq START 1;
CREATE SEQUENCE prod.blocktransaction_block_transaction_id_seq START 1;
CREATE SEQUENCE prod.blockutilization_block_utilization_id_seq START 1;
CREATE SEQUENCE prod.cancellationreason_cancellation_id_seq START 1;
CREATE SEQUENCE prod.caseclass_case_class_id_seq START 1;
CREATE SEQUENCE prod.casestatus_status_id_seq START 1;
CREATE SEQUENCE prod.casetype_case_type_id_seq START 1;
CREATE SEQUENCE prod.dailymetrics_daily_metric_id_seq START 1;
CREATE SEQUENCE prod.location_location_id_seq START 1;
CREATE SEQUENCE prod.migrations_id_seq START 1;
CREATE SEQUENCE prod.orcase_case_id_seq START 1;
CREATE SEQUENCE prod.patientclass_patient_class_id_seq START 1;
CREATE SEQUENCE prod.provider_provider_id_seq START 1;
CREATE SEQUENCE prod.room_room_id_seq START 1;
CREATE SEQUENCE prod.roomutilization_room_utilization_id_seq START 1;
CREATE SEQUENCE prod.service_service_id_seq START 1;
CREATE SEQUENCE prod.serviceutilization_service_utilization_id_seq START 1;
CREATE SEQUENCE prod.specialty_specialty_id_seq START 1;
CREATE SEQUENCE prod.surgeonmetrics_surgeon_metric_id_seq START 1;
/* Keep or omit prod.users_id_seq based on your existing setup. */

------------------------------------------------------------------------------
-- 7) Recreate PROD Tables (Except prod.users)
------------------------------------------------------------------------------

/* basetable */
CREATE TABLE prod.basetable (
    created_date        timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_by          varchar(255) NOT NULL,
    modified_date       timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    modified_by         varchar(255) NOT NULL,
    is_deleted          bool DEFAULT false NOT NULL,
    etl_run_id          varchar(50) NULL,
    etl_source          varchar(255) NULL,
    etl_load_datetime   timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    etl_update_datetime timestamp NULL
);

/* The other utility tables (cache, migrations, etc.) */
CREATE TABLE prod."cache" (
    "key"        varchar(255) NOT NULL,
    value        text NOT NULL,
    expiration   int4 NOT NULL,
    CONSTRAINT cache_pkey PRIMARY KEY ("key")
);

CREATE TABLE prod.migrations (
    id        serial4 NOT NULL,
    migration varchar(255) NOT NULL,
    batch     int4 NOT NULL,
    CONSTRAINT migrations_pkey PRIMARY KEY (id)
);

CREATE TABLE prod.password_reset_tokens (
    email       varchar(255) NOT NULL,
    "token"     varchar(255) NOT NULL,
    created_at  timestamp(0) NULL,
    CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email)
);

CREATE TABLE prod.sessions (
    id           varchar(255) NOT NULL,
    user_id      int8 NULL,
    ip_address   varchar(45) NULL,
    user_agent   text NULL,
    payload      text NOT NULL,
    last_activity int4 NOT NULL,
    CONSTRAINT sessions_pkey PRIMARY KEY (id)
);

/* We do NOT touch prod.users! */

/* asarating, cancellationreason, caseclass, casestatus, casetype, dailymetrics, etc. */
CREATE TABLE prod.asarating (
    asa_id      bigserial NOT NULL,
    "name"      varchar(500) NOT NULL,
    code        varchar(255) NULL,
    description varchar(500) NULL,
    CONSTRAINT asarating_pkey PRIMARY KEY (asa_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.cancellationreason (
    cancellation_id bigserial NOT NULL,
    "name"          varchar(500) NOT NULL,
    code            varchar(255) NULL,
    active_status   bool DEFAULT true NOT NULL,
    CONSTRAINT cancellationreason_pkey PRIMARY KEY (cancellation_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.caseclass (
    case_class_id bigserial NOT NULL,
    "name"        varchar(500) NOT NULL,
    code          varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT caseclass_pkey PRIMARY KEY (case_class_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.casestatus (
    status_id     bigserial NOT NULL,
    "name"        varchar(500) NOT NULL,
    code          varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT casestatus_pkey PRIMARY KEY (status_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.casetype (
    case_type_id bigserial NOT NULL,
    "name"       varchar(500) NOT NULL,
    code         varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT casetype_pkey PRIMARY KEY (case_type_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.dailymetrics (
    daily_metric_id          bigserial NOT NULL,
    location_id              int8 NOT NULL,
    metric_date              date NOT NULL,
    total_cases              int4 NOT NULL,
    completed_cases          int4 NOT NULL,
    cancelled_cases          int4 NOT NULL,
    total_minutes_scheduled  int4 NOT NULL,
    total_minutes_actual     int4 NOT NULL,
    prime_time_utilization   numeric(5,2) NOT NULL,
    overall_utilization      numeric(5,2) NOT NULL,
    first_case_ontime_percentage numeric(5,2) NOT NULL,
    avg_turnover_time        numeric(10,2) NOT NULL,
    emergency_cases          int4 NULL,
    add_on_cases             int4 NULL,
    block_utilization        numeric(5,2) NULL,
    CONSTRAINT dailymetrics_pkey PRIMARY KEY (daily_metric_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_dailymetrics_date ON prod.dailymetrics USING btree (metric_date);
CREATE INDEX ix_dailymetrics_location ON prod.dailymetrics USING btree (location_id);

CREATE TABLE prod."location" (
    location_id   bigserial NOT NULL,
    "name"        varchar(200) NOT NULL,
    abbreviation  varchar(50) NOT NULL,
    location_type varchar(50) NOT NULL,
    pos_type      varchar(50) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT location_pkey PRIMARY KEY (location_id),
    CONSTRAINT uq_location_name UNIQUE ("name")
)
INHERITS (prod.basetable);
CREATE INDEX idx_location_abbreviation ON prod."location"(abbreviation);
CREATE INDEX idx_location_name ON prod."location"("name");

CREATE TABLE prod.patientclass (
    patient_class_id bigserial NOT NULL,
    "name"           varchar(500) NOT NULL,
    code             varchar(255) NULL,
    active_status    bool DEFAULT true NOT NULL,
    CONSTRAINT patientclass_pkey PRIMARY KEY (patient_class_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.room (
    room_id        bigserial NOT NULL,
    location_id    int8 NOT NULL,
    "name"         varchar(255) NOT NULL,
    room_type      varchar(255) NOT NULL,
    active_status  bool DEFAULT true NOT NULL,
    CONSTRAINT room_pkey PRIMARY KEY (room_id),
    CONSTRAINT uq_room_location_name UNIQUE (location_id, "name"),
    CONSTRAINT fk_room_location FOREIGN KEY (location_id) REFERENCES prod."location"(location_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_room_location ON prod.room(location_id);

CREATE TABLE prod.roomutilization (
    room_utilization_id  bigserial NOT NULL,
    room_id              int8 NOT NULL,
    utilization_date     date NOT NULL,
    available_minutes    int4 NOT NULL,
    utilized_minutes     int4 NOT NULL,
    turnover_minutes     int4 NOT NULL,
    block_minutes        int4 NOT NULL,
    open_minutes         int4 NOT NULL,
    utilization_percentage numeric(5,2) NOT NULL,
    cases_performed      int4 NOT NULL,
    avg_case_duration    numeric(10,2) NOT NULL,
    avg_turnover_time    numeric(10,2) NOT NULL,
    prime_time_utilization_percentage numeric(5,2) NOT NULL,
    first_case_ontime_percentage numeric(5,2) NULL,
    CONSTRAINT roomutilization_pkey PRIMARY KEY (room_utilization_id),
    CONSTRAINT fk_roomutilization_room FOREIGN KEY (room_id) REFERENCES prod.room(room_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_roomutilization_date ON prod.roomutilization(utilization_date);
CREATE INDEX ix_roomutilization_room ON prod.roomutilization(room_id);

CREATE TABLE prod.service (
    service_id    bigserial NOT NULL,
    "name"        text NOT NULL,
    code          text NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT service_pkey PRIMARY KEY (service_id),
    CONSTRAINT uq_service_code UNIQUE (code)
)
INHERITS (prod.basetable);

CREATE TABLE prod.serviceutilization (
    service_utilization_id bigserial NOT NULL,
    service_id             int8 NOT NULL,
    location_id            int8 NOT NULL,
    utilization_date       date NOT NULL,
    total_block_minutes    int4 NOT NULL,
    used_block_minutes     int4 NOT NULL,
    block_utilization_percentage numeric(5,2) NOT NULL,
    cases_in_block         int4 NOT NULL,
    cases_out_of_block     int4 NOT NULL,
    prime_time_percentage  numeric(5,2) NOT NULL,
    avg_case_duration      numeric(10,2) NOT NULL,
    total_cases            int4 NOT NULL,
    cancelled_cases        int4 NULL,
    CONSTRAINT serviceutilization_pkey PRIMARY KEY (service_utilization_id),
    CONSTRAINT fk_serviceutil_service FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_serviceutilization_date ON prod.serviceutilization(utilization_date);
CREATE INDEX ix_serviceutilization_location ON prod.serviceutilization(location_id);
CREATE INDEX ix_serviceutilization_service ON prod.serviceutilization(service_id);

CREATE TABLE prod.specialty (
    specialty_id  bigserial NOT NULL,
    "name"        varchar(500) NOT NULL,
    code          varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT specialty_pkey PRIMARY KEY (specialty_id),
    CONSTRAINT uq_specialty_code UNIQUE (code)
)
INHERITS (prod.basetable);

CREATE TABLE prod.provider (
    provider_id   bigserial NOT NULL,
    npi           varchar(100) NULL,
    "name"        varchar(200) NOT NULL,
    specialty_id  int8 NOT NULL,
    provider_type varchar(255) NOT NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT provider_pkey PRIMARY KEY (provider_id),
    CONSTRAINT uq_provider_npi UNIQUE (npi),
    CONSTRAINT fk_provider_specialty FOREIGN KEY (specialty_id) REFERENCES prod.specialty(specialty_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_provider_name ON prod.provider("name");
CREATE INDEX ix_provider_npi ON prod.provider(npi);

CREATE TABLE prod.surgeonmetrics (
    surgeon_metric_id bigserial NOT NULL,
    provider_id       int8 NOT NULL,
    location_id       int8 NOT NULL,
    metric_date       date NOT NULL,
    total_cases       int4 NOT NULL,
    total_minutes     int4 NOT NULL,
    avg_case_duration numeric(10,2) NOT NULL,
    block_utilization_percentage numeric(5,2) NULL,
    first_case_ontime_percentage numeric(5,2) NULL,
    turnover_time_avg numeric(10,2) NULL,
    cancellation_rate numeric(5,2) NULL,
    cases_in_block    int4 NULL,
    cases_out_of_block int4 NULL,
    CONSTRAINT surgeonmetrics_pkey PRIMARY KEY (surgeon_metric_id),
    CONSTRAINT fk_surgeonmetrics_provider FOREIGN KEY (provider_id) REFERENCES prod.provider(provider_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_surgeonmetrics_date ON prod.surgeonmetrics(metric_date);
CREATE INDEX ix_surgeonmetrics_location ON prod.surgeonmetrics(location_id);
CREATE INDEX ix_surgeonmetrics_provider ON prod.surgeonmetrics(provider_id);

CREATE TABLE prod.blocktemplate (
    block_id     bigserial NOT NULL,
    room_id      int8 NOT NULL,
    service_id   int8 NOT NULL,
    surgeon_id   int8 NULL,
    group_id     varchar(255) NULL,
    block_date   date NOT NULL,
    start_time   time NOT NULL,
    end_time     time NOT NULL,
    is_public    bool DEFAULT false NOT NULL,
    title        varchar(200) NULL,
    abbreviation varchar(255) NULL,
    deployment_id varchar(255) NULL,
    "comments"   varchar(500) NULL,
    CONSTRAINT blocktemplate_pkey PRIMARY KEY (block_id),
    CONSTRAINT fk_blocktemplate_provider FOREIGN KEY (surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_blocktemplate_room FOREIGN KEY (room_id) REFERENCES prod.room(room_id),
    CONSTRAINT fk_blocktemplate_service FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_blocktemplate_date ON prod.blocktemplate(block_date);
CREATE INDEX ix_blocktemplate_room ON prod.blocktemplate(room_id);
CREATE INDEX ix_blocktemplate_service ON prod.blocktemplate(service_id);

CREATE TABLE prod.blocktransaction (
    block_transaction_id bigserial NOT NULL,
    block_id        int8 NOT NULL,
    transaction_date date NOT NULL,
    transaction_type varchar(50) NOT NULL,
    minutes_affected int4 NOT NULL,
    from_service_id  int8 NOT NULL,
    to_service_id    int8 NULL,
    from_surgeon_id  int8 NULL,
    to_surgeon_id    int8 NULL,
    release_hours_notice int4 NULL,
    "comments"       varchar(500) NULL,
    CONSTRAINT blocktransaction_pkey PRIMARY KEY (block_transaction_id),
    CONSTRAINT fk_blocktransaction_block FOREIGN KEY (block_id) REFERENCES prod.blocktemplate(block_id),
    CONSTRAINT fk_blocktransaction_from_service FOREIGN KEY (from_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktransaction_from_surgeon FOREIGN KEY (from_surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_blocktransaction_to_service FOREIGN KEY (to_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktransaction_to_surgeon FOREIGN KEY (to_surgeon_id) REFERENCES prod.provider(provider_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_blocktransaction_block ON prod.blocktransaction(block_id);
CREATE INDEX ix_blocktransaction_date ON prod.blocktransaction(transaction_date);

CREATE TABLE prod.blockutilization (
    block_utilization_id bigserial NOT NULL,
    block_id     int8 NOT NULL,
    utilization_date date NOT NULL,
    service_id   int8 NOT NULL,
    location_id  int8 NOT NULL,
    scheduled_minutes int4 NOT NULL,
    actual_minutes int4 NOT NULL,
    utilization_percentage numeric(5,2) NOT NULL,
    cases_scheduled int4 NOT NULL,
    cases_performed int4 NOT NULL,
    prime_time_percentage numeric(5,2) NOT NULL,
    non_prime_time_percentage numeric(5,2) NOT NULL,
    released_minutes int4 NULL,
    exchanged_minutes int4 NULL,
    CONSTRAINT blockutilization_pkey PRIMARY KEY (block_utilization_id),
    CONSTRAINT fk_blockutil_block FOREIGN KEY (block_id) REFERENCES prod.blocktemplate(block_id),
    CONSTRAINT fk_blockutil_service FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_blockutilization_date ON prod.blockutilization(utilization_date);
CREATE INDEX ix_blockutilization_location ON prod.blockutilization(location_id);
CREATE INDEX ix_blockutilization_service ON prod.blockutilization(service_id);

CREATE TABLE prod.orcase (
    case_id            bigserial NOT NULL,
    log_id             int8 NULL,
    patient_id         varchar(50) NOT NULL,
    surgery_date       date NOT NULL,
    room_id            int8 NOT NULL,
    location_id        int8 NOT NULL,
    primary_surgeon_id int8 NOT NULL,
    case_service_id    int8 NOT NULL,
    scheduled_start_time timestamp NOT NULL,
    scheduled_duration int4 NOT NULL,
    record_create_date timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    status_id          int8 NOT NULL,
    cancellation_reason_id int8 NULL,
    asa_rating_id      int8 NULL,
    case_type_id       int8 NOT NULL,
    case_class_id      int8 NOT NULL,
    patient_class_id   int8 NOT NULL,
    setup_offset       int4 NULL,
    cleanup_offset     int4 NULL,
    number_of_panels   int4 DEFAULT 1 NULL,
    procedure_name     varchar(255) NULL,
    CONSTRAINT orcase_pkey PRIMARY KEY (case_id),
    CONSTRAINT fk_orcase_asarating FOREIGN KEY (asa_rating_id) REFERENCES prod.asarating(asa_id),
    CONSTRAINT fk_orcase_cancellationreason FOREIGN KEY (cancellation_reason_id) REFERENCES prod.cancellationreason(cancellation_id),
    CONSTRAINT fk_orcase_caseclass FOREIGN KEY (case_class_id) REFERENCES prod.caseclass(case_class_id),
    CONSTRAINT fk_orcase_casetype FOREIGN KEY (case_type_id) REFERENCES prod.casetype(case_type_id),
    CONSTRAINT fk_orcase_patientclass FOREIGN KEY (patient_class_id) REFERENCES prod.patientclass(patient_class_id),
    CONSTRAINT fk_orcase_provider FOREIGN KEY (primary_surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_orcase_room FOREIGN KEY (room_id) REFERENCES prod.room(room_id),
    CONSTRAINT fk_orcase_service FOREIGN KEY (case_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_orcase_status FOREIGN KEY (status_id) REFERENCES prod.casestatus(status_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_orcase_location ON prod.orcase(location_id);
CREATE INDEX ix_orcase_service ON prod.orcase(case_service_id);
CREATE INDEX ix_orcase_status ON prod.orcase(status_id);
CREATE INDEX ix_orcase_surgerydate ON prod.orcase(surgery_date);

CREATE TABLE prod.orlog (
    log_id                int8 NOT NULL,
    case_id               int8 NOT NULL,
    tracking_date         date NOT NULL,
    periop_arrival_time   timestamp NULL,
    preop_in_time         timestamp NULL,
    preop_out_time        timestamp NULL,
    or_in_time            timestamp NULL,
    anesthesia_start_time timestamp NULL,
    procedure_start_time  timestamp NULL,
    procedure_closing_time timestamp NULL,
    procedure_end_time    timestamp NULL,
    or_out_time           timestamp NULL,
    anesthesia_end_time   timestamp NULL,
    pacu_in_time          timestamp NULL,
    pacu_out_time         timestamp NULL,
    pacu2_in_time         timestamp NULL,
    pacu2_out_time        timestamp NULL,
    procedural_care_complete_time timestamp NULL,
    destination           varchar(255) NULL,
    number_of_panels      int4 DEFAULT 1 NULL,
    primary_procedure     varchar(500) NULL,
    CONSTRAINT orlog_pkey PRIMARY KEY (log_id),
    CONSTRAINT fk_orlog_orcase FOREIGN KEY (case_id) REFERENCES prod.orcase(case_id)
)
INHERITS (prod.basetable);
CREATE INDEX ix_orlog_case ON prod.orlog(case_id);
CREATE INDEX ix_orlog_trackingdate ON prod.orlog(tracking_date);

CREATE TABLE prod.casemetrics (
    case_id                 int8 NOT NULL,
    turnover_time           int4 NULL,
    utilization_percentage  numeric(5,2) NULL,
    in_block_time           int4 NULL,
    out_of_block_time       int4 NULL,
    prime_time_minutes      int4 NULL,
    non_prime_time_minutes  int4 NULL,
    late_start_minutes      int4 NULL,
    early_finish_minutes    int4 NULL,
    total_case_minutes      int4 NULL,
    total_anesthesia_minutes int4 NULL,
    total_procedure_minutes int4 NULL,
    preop_minutes           int4 NULL,
    pacu_minutes            int4 NULL,
    CONSTRAINT casemetrics_pkey PRIMARY KEY (case_id),
    CONSTRAINT fk_casemetrics_case FOREIGN KEY (case_id) REFERENCES prod.orcase(case_id)
)
INHERITS (prod.basetable);

------------------------------------------------------------------------------
-- 8) Create PROD Views
------------------------------------------------------------------------------

CREATE OR REPLACE VIEW prod.vw_casetimings AS
SELECT 
    c.case_id,
    c.surgery_date,
    c.location_id,
    c.room_id,
    c.primary_surgeon_id,
    c.case_service_id,
    l.or_in_time,
    l.or_out_time,
    l.anesthesia_start_time,
    l.anesthesia_end_time,
    l.procedure_start_time,
    l.procedure_end_time,
    (EXTRACT(epoch FROM l.or_out_time - l.or_in_time) / 60::numeric)::integer AS total_or_minutes,
    (EXTRACT(epoch FROM l.anesthesia_end_time - l.anesthesia_start_time) / 60::numeric)::integer AS total_anesthesia_minutes,
    (EXTRACT(epoch FROM l.procedure_end_time - l.procedure_start_time) / 60::numeric)::integer AS total_procedure_minutes,
    c.scheduled_duration AS scheduled_minutes,
    cm.turnover_time,
    cm.in_block_time,
    cm.out_of_block_time
FROM prod.orcase c
JOIN prod.orlog l ON c.case_id = l.case_id
LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = false;

CREATE OR REPLACE VIEW prod.vw_surgeonperformance AS
SELECT 
    c.primary_surgeon_id,
    p.name AS surgeon_name,
    c.location_id,
    c.surgery_date,
    count(c.case_id) AS total_cases,
    avg(EXTRACT(epoch FROM l.or_out_time - l.or_in_time) / 60::numeric)::integer AS avg_case_duration,
    avg(cm.turnover_time) AS avg_turnover_time,
    sum(
        CASE WHEN cm.late_start_minutes > 0 THEN 1 ELSE 0 END
    ) AS late_starts,
    sum(cm.in_block_time) AS total_block_minutes_used,
    sum(cm.prime_time_minutes) AS prime_time_minutes,
    sum(
        CASE WHEN c.cancellation_reason_id IS NOT NULL THEN 1 ELSE 0 END
    ) AS cancelled_cases
FROM prod.orcase c
JOIN prod.provider p ON c.primary_surgeon_id = p.provider_id
JOIN prod.orlog l ON c.case_id = l.case_id
LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = false
GROUP BY c.primary_surgeon_id, p.name, c.location_id, c.surgery_date;

------------------------------------------------------------------------------
-- 9) Insert "Unknown"/Default Rows (Dimension Seeding)
------------------------------------------------------------------------------

INSERT INTO prod.service (code, "name", active_status, etl_source, created_by, modified_by)
VALUES 
('UNKNOWN_SERVICE', 'Unknown Service', TRUE, 'manual_setup', 'admin', 'admin')
ON CONFLICT (code) DO NOTHING;

INSERT INTO prod.specialty (specialty_id, "name", code, active_status, created_by, modified_by)
OVERRIDING SYSTEM VALUE
VALUES
(
    137,
    'Unknown Specialty',
    'UNKNOWN',
    TRUE,
    'ETL_process',
    'ETL_process'
)
ON CONFLICT (specialty_id) DO NOTHING;

INSERT INTO prod.provider (
    npi,
    "name",
    specialty_id,
    provider_type,
    active_status,
    etl_source,
    created_by,
    modified_by
)
VALUES
(
    'UNKNOWN_SURGEON',
    'Unknown Surgeon',
    137,
    'Unknown',
    TRUE,
    'manual_setup',
    'admin',
    'admin'
)
ON CONFLICT (npi) DO NOTHING;

------------------------------------------------------------------------------
-- 10) Perform ETL / Upserts from RAW → PROD
--     (All raw data remains intact, we just read it and upsert to prod)
------------------------------------------------------------------------------

BEGIN;

/* 10.1) Upsert Location */
INSERT INTO prod."location" AS loc
(
    "name",
    abbreviation,
    location_type,
    pos_type,
    active_status,
    etl_source,
    created_by,
    modified_by
)
SELECT DISTINCT
    bt.loc_name,
    bt.location_abbr,
    'BLOCK',
    bt.pos_type,
    TRUE,
    'raw.block_template',
    'ETL_process',
    'ETL_process'
FROM raw.block_template bt
WHERE bt.loc_name IS NOT NULL
ON CONFLICT ("name")
DO UPDATE
  SET abbreviation        = EXCLUDED.abbreviation,
      pos_type            = EXCLUDED.pos_type,
      modified_date       = CURRENT_TIMESTAMP,
      modified_by         = 'ETL_process',
      etl_update_datetime = CURRENT_TIMESTAMP;

/* 10.2) Upsert Room */
INSERT INTO prod.room AS r
(
    location_id,
    "name",
    room_type,
    active_status,
    etl_source,
    created_by,
    modified_by
)
SELECT DISTINCT
    loc.location_id,
    COALESCE(bt.roomname, '(No Room Name)'),
    'OR',
    TRUE,
    'raw.block_template',
    'ETL_process',
    'ETL_process'
FROM raw.block_template bt
JOIN prod."location" loc
    ON loc."name" = bt.loc_name
WHERE bt.roomname IS NOT NULL
ON CONFLICT (location_id, "name")
DO UPDATE
  SET room_type          = EXCLUDED.room_type,
      modified_date      = CURRENT_TIMESTAMP,
      modified_by        = 'ETL_process',
      etl_update_datetime= CURRENT_TIMESTAMP;

/* 10.3) Upsert Specialty from raw.provider_list */
WITH raw_specs AS (
    SELECT DISTINCT COALESCE(pl.specialty, 'UNKNOWN') AS raw_specialty
    FROM raw.provider_list pl
)
INSERT INTO prod.specialty AS s
(
    code,
    "name",
    active_status,
    created_by,
    modified_by
)
SELECT
    raw_specs.raw_specialty,
    raw_specs.raw_specialty,
    TRUE,
    'ETL_process',
    'ETL_process'
FROM raw_specs
ON CONFLICT (code)
DO NOTHING;

/* 10.4) Upsert Provider from raw.provider_list */
INSERT INTO prod.provider AS p
(
    npi,
    "name",
    specialty_id,
    provider_type,
    active_status,
    etl_source,
    created_by,
    modified_by
)
SELECT DISTINCT
    pl.provider_name,
    pl.provider_name,
    s.specialty_id,
    COALESCE(pl.title, 'UNKNOWN'),
    TRUE,
    'raw.provider_list',
    'ETL_process',
    'ETL_process'
FROM raw.provider_list pl
JOIN prod.specialty s
   ON s.code = pl.specialty
WHERE pl.provider_name IS NOT NULL
ON CONFLICT (npi)
DO UPDATE
  SET "name"             = EXCLUDED."name",
      provider_type      = EXCLUDED.provider_type,
      specialty_id       = EXCLUDED.specialty_id,
      modified_date      = CURRENT_TIMESTAMP,
      modified_by        = 'ETL_process',
      etl_update_datetime= CURRENT_TIMESTAMP;

/* 10.5) Upsert Service from raw.block_template */
INSERT INTO prod.service AS svc
(
    code,
    "name",
    active_status,
    etl_source,
    created_by,
    modified_by
)
SELECT DISTINCT
    CAST(bt.service_c AS text) AS code,
    CAST(bt.service_c AS text) AS name,
    TRUE,
    'raw.block_template',
    'ETL_process',
    'ETL_process'
FROM raw.block_template bt
WHERE bt.service_c IS NOT NULL
ON CONFLICT (code)
DO UPDATE
  SET "name"             = EXCLUDED."name",
      modified_date      = CURRENT_TIMESTAMP,
      modified_by        = 'ETL_process',
      etl_update_datetime= CURRENT_TIMESTAMP;

/* 10.6) Upsert BlockTemplate */
INSERT INTO prod.blocktemplate AS btemp
(
    room_id,
    service_id,
    surgeon_id,
    block_date,
    start_time,
    end_time,
    title,
    abbreviation,
    "comments",
    deployment_id,
    etl_source,
    created_by,
    modified_by
)
SELECT DISTINCT
    r.room_id,
    s.service_id,
    p.provider_id,
    rawbt.schedule_date::date,
    rawbt.slot_start_time::time,
    rawbt.slot_end_time::time,
    rawbt.title,
    rawbt.abbreviation,
    rawbt."comments",
    rawbt.deployment_id::text,
    'raw.block_template',
    'ETL_process',
    'ETL_process'
FROM raw.block_template AS rawbt
LEFT JOIN prod.room r
    ON r."name" = COALESCE(rawbt.roomname, '(No Room Name)')
LEFT JOIN prod.service s
    ON s.code = CAST(rawbt.service_c AS text)
LEFT JOIN prod.provider p
    ON p.npi = rawbt.surgeon_id
;

COMMIT;

------------------------------------------------------------------------------
-- 11) (Optional) Insert OR Cases from raw.or_cases, bridging via raw.room_map
------------------------------------------------------------------------------

BEGIN;

/* 11.1) Make patient_id nullable if needed */
ALTER TABLE prod.orcase
  ALTER COLUMN patient_id DROP NOT NULL;

/* 11.2) Ensure "Unknown Service" & "Unknown Surgeon" exist */
INSERT INTO prod.service (code, "name", active_status, etl_source, created_by, modified_by)
VALUES ('UNKNOWN_SERVICE','Unknown Service',TRUE,'manual_setup','admin','admin')
ON CONFLICT (code) DO NOTHING;

INSERT INTO prod.provider (
    npi,
    "name",
    specialty_id,
    provider_type,
    active_status,
    etl_source,
    created_by,
    modified_by
)
VALUES ('UNKNOWN_SURGEON','Unknown Surgeon',137,'Unknown',TRUE,'manual_setup','admin','admin')
ON CONFLICT (npi) DO NOTHING;

/* 11.3) Insert from raw.or_cases → prod.orcase using raw.room_map */
INSERT INTO prod.orcase
(
    surgery_date,
    room_id,
    location_id,
    case_service_id,
    primary_surgeon_id,
    procedure_name,
    scheduled_duration,
    etl_source,
    created_by,
    modified_by,
    scheduled_start_time
)
SELECT DISTINCT
    roc.case_date,
    prdroom.room_id,
    prdroom.location_id,
    us.service_id AS case_service_id,
    COALESCE(prov.provider_id, unk.provider_id) AS primary_surgeon_id,
    roc."procedure",
    roc.duration,
    'raw.or_cases',
    'ETL_process',
    'ETL_process',
    (CURRENT_DATE + TIME '07:00')  -- default start time or adapt to your logic
FROM raw.or_cases roc
JOIN raw.room_map map
    ON UPPER(map.raw_room) = UPPER(roc.room)
JOIN prod.room prdroom
    ON UPPER(prdroom."name") = UPPER(map.prod_room)
LEFT JOIN prod.provider prov
    ON UPPER(prov."name") = UPPER(roc.surgeon)
JOIN prod.provider unk
    ON unk.npi = 'UNKNOWN_SURGEON'
JOIN prod.service us
    ON us.code = 'UNKNOWN_SERVICE'
WHERE roc.case_date IS NOT NULL
  AND roc.room IS NOT NULL;

COMMIT;

------------------------------------------------------------------------------
-- DONE! This script preserves all raw data, preserves prod.users,
-- and rebuilds all other prod tables + does ETL from raw to prod.
------------------------------------------------------------------------------

