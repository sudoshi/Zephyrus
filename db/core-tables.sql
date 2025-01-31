--------------------------------------------------------------------------
-- 1. Drop and Recreate the prod Schema
--------------------------------------------------------------------------
DROP SCHEMA IF EXISTS prod CASCADE;
CREATE SCHEMA prod AUTHORIZATION postgres;

--------------------------------------------------------------------------
-- 2. Recreate Sequences (as in your original script)
--------------------------------------------------------------------------
CREATE SEQUENCE prod.asarating_asa_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.blocktemplate_block_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.blocktransaction_block_transaction_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.blockutilization_block_utilization_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.cancellationreason_cancellation_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.caseclass_case_class_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.casestatus_status_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.casetype_case_type_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.dailymetrics_daily_metric_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.location_location_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.migrations_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 2147483647
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.orcase_case_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.patientclass_patient_class_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.provider_provider_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.room_room_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.roomutilization_room_utilization_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.service_service_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.serviceutilization_service_utilization_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.specialty_specialty_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.surgeonmetrics_surgeon_metric_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

CREATE SEQUENCE prod.users_id_seq
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 9223372036854775807
    START 1
    CACHE 1
    NO CYCLE;

--------------------------------------------------------------------------
-- 3. Create the Revised prod.basetable with ETL Traceability Columns
--------------------------------------------------------------------------
CREATE TABLE prod.basetable (
    created_date         timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_by           varchar(255) NOT NULL,
    modified_date        timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    modified_by          varchar(255) NOT NULL,
    is_deleted           bool DEFAULT false NOT NULL,

    -- New ETL-related columns:
    etl_run_id           varchar(50)   NULL,
    etl_source           varchar(255)  NULL,
    etl_load_datetime    timestamp     DEFAULT CURRENT_TIMESTAMP NOT NULL,
    etl_update_datetime  timestamp     NULL
);

--------------------------------------------------------------------------
-- 4. Recreate All Other prod Tables (Inheriting from basetable)
--    Adjusted to remove "unnecessary" unique constraints
--------------------------------------------------------------------------
-- 4.1. prod.cache
CREATE TABLE prod."cache" (
    "key" varchar(255) NOT NULL,
    value text NOT NULL,
    expiration int4 NOT NULL,
    CONSTRAINT cache_pkey PRIMARY KEY ("key")
);

-- 4.2. prod.migrations
CREATE TABLE prod.migrations (
    id serial NOT NULL,
    migration varchar(255) NOT NULL,
    batch int4 NOT NULL,
    CONSTRAINT migrations_pkey PRIMARY KEY (id)
);

-- 4.3. prod.password_reset_tokens
CREATE TABLE prod.password_reset_tokens (
    email varchar(255) NOT NULL,
    "token" varchar(255) NOT NULL,
    created_at timestamp(0) NULL,
    CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email)
);

-- 4.4. prod.sessions
CREATE TABLE prod.sessions (
    id varchar(255) NOT NULL,
    user_id int8 NULL,
    ip_address varchar(45) NULL,
    user_agent text NULL,
    payload text NOT NULL,
    last_activity int4 NOT NULL,
    CONSTRAINT sessions_pkey PRIMARY KEY (id)
);

-- 4.5. prod.users
--     Removed the UNIQUE constraint on email (if no longer needed).
CREATE TABLE prod.users (
    id bigserial NOT NULL,
    "name" varchar(255) NOT NULL,
    email varchar(255) NOT NULL,
    email_verified_at timestamp NULL,
    "password" varchar(255) NOT NULL,
    remember_token varchar(100) NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    --CONSTRAINT users_email_key UNIQUE (email),   -- (Commented out if not needed)
    CONSTRAINT users_pkey PRIMARY KEY (id)
);

--------------------------------------------------------------------------
-- 4.6. Dimensional Tables Inheriting prod.basetable
--------------------------------------------------------------------------
CREATE TABLE prod.asarating (
    asa_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    description varchar(500) NULL,
    CONSTRAINT asarating_pkey PRIMARY KEY (asa_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.cancellationreason (
    cancellation_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT cancellationreason_pkey PRIMARY KEY (cancellation_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.caseclass (
    case_class_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT caseclass_pkey PRIMARY KEY (case_class_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.casestatus (
    status_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT casestatus_pkey PRIMARY KEY (status_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.casetype (
    case_type_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT casetype_pkey PRIMARY KEY (case_type_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.patientclass (
    patient_class_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT patientclass_pkey PRIMARY KEY (patient_class_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.specialty (
    specialty_id bigserial NOT NULL,
    "name" varchar(500) NOT NULL,
    code varchar(255) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT specialty_pkey PRIMARY KEY (specialty_id)
)
INHERITS (prod.basetable);

--------------------------------------------------------------------------
-- 4.7. prod.location (Removed unique constraints on name/abbreviation)
--------------------------------------------------------------------------
CREATE TABLE prod."location" (
    location_id bigserial NOT NULL,
    "name" varchar(200) NOT NULL,
    abbreviation varchar(50) NOT NULL,
    location_type varchar(50) NOT NULL,
    pos_type varchar(50) NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT location_pkey PRIMARY KEY (location_id)
)
INHERITS (prod.basetable);

-- If you still need fast lookups by name or abbreviation,
-- create non-unique indexes here:
CREATE INDEX idx_location_name
    ON prod."location" ("name");
CREATE INDEX idx_location_abbreviation
    ON prod."location" (abbreviation);

--------------------------------------------------------------------------
-- 4.8. prod.room (Removed unique constraint on (location_id, name))
--------------------------------------------------------------------------
CREATE TABLE prod.room (
    room_id bigserial NOT NULL,
    location_id int8 NOT NULL,
    "name" varchar(255) NOT NULL,
    room_type varchar(255) NOT NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT room_pkey PRIMARY KEY (room_id),
    CONSTRAINT fk_room_location FOREIGN KEY (location_id) REFERENCES prod."location"(location_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_room_location ON prod.room USING btree (location_id);
-- (Optional) Non-unique index for (location_id, name):
CREATE INDEX idx_room_location_name
    ON prod.room(location_id, "name");

--------------------------------------------------------------------------
-- 4.9. Other Fact/Metric Tables Inheriting prod.basetable
--------------------------------------------------------------------------
CREATE TABLE prod.dailymetrics (
    daily_metric_id bigserial NOT NULL,
    location_id int8 NOT NULL,
    metric_date date NOT NULL,
    total_cases int4 NOT NULL,
    completed_cases int4 NOT NULL,
    cancelled_cases int4 NOT NULL,
    total_minutes_scheduled int4 NOT NULL,
    total_minutes_actual int4 NOT NULL,
    prime_time_utilization numeric(5, 2) NOT NULL,
    overall_utilization numeric(5, 2) NOT NULL,
    first_case_ontime_percentage numeric(5, 2) NOT NULL,
    avg_turnover_time numeric(10, 2) NOT NULL,
    emergency_cases int4 NULL,
    add_on_cases int4 NULL,
    block_utilization numeric(5, 2) NULL,
    CONSTRAINT dailymetrics_pkey PRIMARY KEY (daily_metric_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_dailymetrics_date ON prod.dailymetrics USING btree (metric_date);
CREATE INDEX ix_dailymetrics_location ON prod.dailymetrics USING btree (location_id);

CREATE TABLE prod.roomutilization (
    room_utilization_id bigserial NOT NULL,
    room_id int8 NOT NULL,
    utilization_date date NOT NULL,
    available_minutes int4 NOT NULL,
    utilized_minutes int4 NOT NULL,
    turnover_minutes int4 NOT NULL,
    block_minutes int4 NOT NULL,
    open_minutes int4 NOT NULL,
    utilization_percentage numeric(5, 2) NOT NULL,
    cases_performed int4 NOT NULL,
    avg_case_duration numeric(10, 2) NOT NULL,
    avg_turnover_time numeric(10, 2) NOT NULL,
    prime_time_utilization_percentage numeric(5, 2) NOT NULL,
    first_case_ontime_percentage numeric(5, 2) NULL,
    CONSTRAINT roomutilization_pkey PRIMARY KEY (room_utilization_id),
    CONSTRAINT fk_roomutilization_room FOREIGN KEY (room_id) REFERENCES prod.room(room_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_roomutilization_date ON prod.roomutilization USING btree (utilization_date);
CREATE INDEX ix_roomutilization_room ON prod.roomutilization USING btree (room_id);

CREATE TABLE prod.service (
    service_id bigserial NOT NULL,
    "name" text NOT NULL,
    code text NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT service_pkey PRIMARY KEY (service_id)
)
INHERITS (prod.basetable);

CREATE TABLE prod.serviceutilization (
    service_utilization_id bigserial NOT NULL,
    service_id int8 NOT NULL,
    location_id int8 NOT NULL,
    utilization_date date NOT NULL,
    total_block_minutes int4 NOT NULL,
    used_block_minutes int4 NOT NULL,
    block_utilization_percentage numeric(5, 2) NOT NULL,
    cases_in_block int4 NOT NULL,
    cases_out_of_block int4 NOT NULL,
    prime_time_percentage numeric(5, 2) NOT NULL,
    avg_case_duration numeric(10, 2) NOT NULL,
    total_cases int4 NOT NULL,
    cancelled_cases int4 NULL,
    CONSTRAINT serviceutilization_pkey PRIMARY KEY (service_utilization_id),
    CONSTRAINT fk_serviceutil_service FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_serviceutilization_date ON prod.serviceutilization USING btree (utilization_date);
CREATE INDEX ix_serviceutilization_location ON prod.serviceutilization USING btree (location_id);
CREATE INDEX ix_serviceutilization_service ON prod.serviceutilization USING btree (service_id);

CREATE TABLE prod.provider (
    provider_id bigserial NOT NULL,
    npi varchar(10) NULL,
    "name" varchar(200) NOT NULL,
    specialty_id int8 NOT NULL,
    provider_type varchar(255) NOT NULL,
    active_status bool DEFAULT true NOT NULL,
    CONSTRAINT provider_pkey PRIMARY KEY (provider_id),
    CONSTRAINT fk_provider_specialty FOREIGN KEY (specialty_id) REFERENCES prod.specialty(specialty_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_provider_name ON prod.provider USING btree ("name");
CREATE INDEX ix_provider_npi ON prod.provider USING btree (npi);

CREATE TABLE prod.surgeonmetrics (
    surgeon_metric_id bigserial NOT NULL,
    provider_id int8 NOT NULL,
    location_id int8 NOT NULL,
    metric_date date NOT NULL,
    total_cases int4 NOT NULL,
    total_minutes int4 NOT NULL,
    avg_case_duration numeric(10, 2) NOT NULL,
    block_utilization_percentage numeric(5, 2) NULL,
    first_case_ontime_percentage numeric(5, 2) NULL,
    turnover_time_avg numeric(10, 2) NULL,
    cancellation_rate numeric(5, 2) NULL,
    cases_in_block int4 NULL,
    cases_out_of_block int4 NULL,
    CONSTRAINT surgeonmetrics_pkey PRIMARY KEY (surgeon_metric_id),
    CONSTRAINT fk_surgeonmetrics_provider FOREIGN KEY (provider_id) REFERENCES prod.provider(provider_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_surgeonmetrics_date ON prod.surgeonmetrics USING btree (metric_date);
CREATE INDEX ix_surgeonmetrics_location ON prod.surgeonmetrics USING btree (location_id);
CREATE INDEX ix_surgeonmetrics_provider ON prod.surgeonmetrics USING btree (provider_id);

--------------------------------------------------------------------------
-- 4.10. Block Scheduling Tables
--------------------------------------------------------------------------
CREATE TABLE prod.blocktemplate (
    block_id bigserial NOT NULL,
    room_id int8 NOT NULL,
    service_id int8 NOT NULL,
    surgeon_id int8 NULL,
    group_id varchar(255) NULL,
    block_date date NOT NULL,
    start_time time NOT NULL,
    end_time time NOT NULL,
    is_public bool DEFAULT false NOT NULL,
    title varchar(200) NULL,
    abbreviation varchar(255) NULL,
    deployment_id varchar(255) NULL,
    "comments" varchar(500) NULL,
    CONSTRAINT blocktemplate_pkey PRIMARY KEY (block_id),
    CONSTRAINT fk_blocktemplate_provider FOREIGN KEY (surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_blocktemplate_room FOREIGN KEY (room_id) REFERENCES prod.room(room_id),
    CONSTRAINT fk_blocktemplate_service FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_blocktemplate_date ON prod.blocktemplate USING btree (block_date);
CREATE INDEX ix_blocktemplate_room ON prod.blocktemplate USING btree (room_id);
CREATE INDEX ix_blocktemplate_service ON prod.blocktemplate USING btree (service_id);

CREATE TABLE prod.blocktransaction (
    block_transaction_id bigserial NOT NULL,
    block_id int8 NOT NULL,
    transaction_date date NOT NULL,
    transaction_type varchar(50) NOT NULL,
    minutes_affected int4 NOT NULL,
    from_service_id int8 NOT NULL,
    to_service_id int8 NULL,
    from_surgeon_id int8 NULL,
    to_surgeon_id int8 NULL,
    release_hours_notice int4 NULL,
    "comments" varchar(500) NULL,
    CONSTRAINT blocktransaction_pkey PRIMARY KEY (block_transaction_id),
    CONSTRAINT fk_blocktransaction_block FOREIGN KEY (block_id) REFERENCES prod.blocktemplate(block_id),
    CONSTRAINT fk_blocktransaction_from_service FOREIGN KEY (from_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktransaction_from_surgeon FOREIGN KEY (from_surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_blocktransaction_to_service FOREIGN KEY (to_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktransaction_to_surgeon FOREIGN KEY (to_surgeon_id) REFERENCES prod.provider(provider_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_blocktransaction_block ON prod.blocktransaction USING btree (block_id);
CREATE INDEX ix_blocktransaction_date ON prod.blocktransaction USING btree (transaction_date);

CREATE TABLE prod.blockutilization (
    block_utilization_id bigserial NOT NULL,
    block_id int8 NOT NULL,
    utilization_date date NOT NULL,
    service_id int8 NOT NULL,
    location_id int8 NOT NULL,
    scheduled_minutes int4 NOT NULL,
    actual_minutes int4 NOT NULL,
    utilization_percentage numeric(5, 2) NOT NULL,
    cases_scheduled int4 NOT NULL,
    cases_performed int4 NOT NULL,
    prime_time_percentage numeric(5, 2) NOT NULL,
    non_prime_time_percentage numeric(5, 2) NOT NULL,
    released_minutes int4 NULL,
    exchanged_minutes int4 NULL,
    CONSTRAINT blockutilization_pkey PRIMARY KEY (block_utilization_id),
    CONSTRAINT fk_blockutil_block FOREIGN KEY (block_id) REFERENCES prod.blocktemplate(block_id),
    CONSTRAINT fk_blockutil_service FOREIGN KEY (service_id) REFERENCES prod.service(service_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_blockutilization_date ON prod.blockutilization USING btree (utilization_date);
CREATE INDEX ix_blockutilization_location ON prod.blockutilization USING btree (location_id);
CREATE INDEX ix_blockutilization_service ON prod.blockutilization USING btree (service_id);

--------------------------------------------------------------------------
-- 4.11. OR Case Tables
--------------------------------------------------------------------------
CREATE TABLE prod.orcase (
    case_id bigserial NOT NULL,
    log_id int8 NULL,
    patient_id varchar(50) NOT NULL,
    surgery_date date NOT NULL,
    room_id int8 NOT NULL,
    location_id int8 NOT NULL,
    primary_surgeon_id int8 NOT NULL,
    case_service_id int8 NOT NULL,
    scheduled_start_time timestamp NOT NULL,
    scheduled_duration int4 NOT NULL,
    record_create_date timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    status_id int8 NOT NULL,
    cancellation_reason_id int8 NULL,
    asa_rating_id int8 NULL,
    case_type_id int8 NOT NULL,
    case_class_id int8 NOT NULL,
    patient_class_id int8 NOT NULL,
    setup_offset int4 NULL,
    cleanup_offset int4 NULL,
    number_of_panels int4 DEFAULT 1 NULL,
    procedure_name varchar(255) NULL,
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

CREATE INDEX ix_orcase_location ON prod.orcase USING btree (location_id);
CREATE INDEX ix_orcase_service ON prod.orcase USING btree (case_service_id);
CREATE INDEX ix_orcase_status ON prod.orcase USING btree (status_id);
CREATE INDEX ix_orcase_surgerydate ON prod.orcase USING btree (surgery_date);

CREATE TABLE prod.orlog (
    log_id int8 NOT NULL,
    case_id int8 NOT NULL,
    tracking_date date NOT NULL,
    periop_arrival_time timestamp NULL,
    preop_in_time timestamp NULL,
    preop_out_time timestamp NULL,
    or_in_time timestamp NULL,
    anesthesia_start_time timestamp NULL,
    procedure_start_time timestamp NULL,
    procedure_closing_time timestamp NULL,
    procedure_end_time timestamp NULL,
    or_out_time timestamp NULL,
    anesthesia_end_time timestamp NULL,
    pacu_in_time timestamp NULL,
    pacu_out_time timestamp NULL,
    pacu2_in_time timestamp NULL,
    pacu2_out_time timestamp NULL,
    procedural_care_complete_time timestamp NULL,
    destination varchar(255) NULL,
    number_of_panels int4 DEFAULT 1 NULL,
    primary_procedure varchar(500) NULL,
    CONSTRAINT orlog_pkey PRIMARY KEY (log_id),
    CONSTRAINT fk_orlog_orcase FOREIGN KEY (case_id) REFERENCES prod.orcase(case_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_orlog_case ON prod.orlog USING btree (case_id);
CREATE INDEX ix_orlog_trackingdate ON prod.orlog USING btree (tracking_date);

CREATE TABLE prod.casemetrics (
    case_id int8 NOT NULL,
    turnover_time int4 NULL,
    utilization_percentage numeric(5, 2) NULL,
    in_block_time int4 NULL,
    out_of_block_time int4 NULL,
    prime_time_minutes int4 NULL,
    non_prime_time_minutes int4 NULL,
    late_start_minutes int4 NULL,
    early_finish_minutes int4 NULL,
    total_case_minutes int4 NULL,
    total_anesthesia_minutes int4 NULL,
    total_procedure_minutes int4 NULL,
    preop_minutes int4 NULL,
    pacu_minutes int4 NULL,
    CONSTRAINT casemetrics_pkey PRIMARY KEY (case_id),
    CONSTRAINT fk_casemetrics_case FOREIGN KEY (case_id) REFERENCES prod.orcase(case_id)
)
INHERITS (prod.basetable);

--------------------------------------------------------------------------
-- 4.12. Views
--------------------------------------------------------------------------
CREATE OR REPLACE VIEW prod.vw_casetimings
AS
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
    (EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60::numeric)::integer AS total_or_minutes,
    (EXTRACT(EPOCH FROM (l.anesthesia_end_time - l.anesthesia_start_time)) / 60::numeric)::integer AS total_anesthesia_minutes,
    (EXTRACT(EPOCH FROM (l.procedure_end_time - l.procedure_start_time)) / 60::numeric)::integer AS total_procedure_minutes,
    c.scheduled_duration AS scheduled_minutes,
    cm.turnover_time,
    cm.in_block_time,
    cm.out_of_block_time
FROM prod.orcase c
JOIN prod.orlog l ON c.case_id = l.case_id
LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = false;

CREATE OR REPLACE VIEW prod.vw_surgeonperformance
AS
SELECT
    c.primary_surgeon_id,
    p.name AS surgeon_name,
    c.location_id,
    c.surgery_date,
    COUNT(c.case_id) AS total_cases,
    AVG(EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60::numeric)::integer AS avg_case_duration,
    AVG(cm.turnover_time) AS avg_turnover_time,
    SUM(
      CASE
        WHEN cm.late_start_minutes > 0 THEN 1
        ELSE 0
      END
    ) AS late_starts,
    SUM(cm.in_block_time) AS total_block_minutes_used,
    SUM(cm.prime_time_minutes) AS prime_time_minutes,
    SUM(
      CASE
        WHEN c.cancellation_reason_id IS NOT NULL THEN 1
        ELSE 0
      END
    ) AS cancelled_cases
FROM prod.orcase c
JOIN prod.provider p ON c.primary_surgeon_id = p.provider_id
JOIN prod.orlog l ON c.case_id = l.case_id
LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = false
GROUP BY c.primary_surgeon_id, p.name, c.location_id, c.surgery_date;

--------------------------------------------------------------------------
-- Done
--------------------------------------------------------------------------
-- At this point, you have a fresh prod schema with:
--   1) No unnecessary UNIQUE constraints on location, room, etc.
--   2) Additional ETL columns in prod.basetable for traceability.
--   3) All original references, indexes, and foreign keys intact
--      except those unique constraints you wanted removed.
--------------------------------------------------------------------------
