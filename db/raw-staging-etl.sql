------------------------------------------------------------------------------
-- 1. Create schema if needed
------------------------------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS stg;

------------------------------------------------------------------------------
-- 2. Create staging tables
------------------------------------------------------------------------------

-- BlockSchedule
CREATE TABLE stg.blockschedule (
    schedule_date     DATE,
    room_id           VARCHAR(50),
    room_name         VARCHAR(100),
    slot_type         VARCHAR(50),
    slot_start_time   TIME,
    slot_end_time     TIME,
    is_public_slot    BOOLEAN,  -- BIT => BOOLEAN
    service_code      VARCHAR(50),
    surgeon_id        VARCHAR(50),
    group_id          VARCHAR(50),
    block_key         VARCHAR(50),
    block_title       VARCHAR(200),
    block_abbreviation VARCHAR(50),
    time_off_reason_code VARCHAR(50),
    comments          VARCHAR(500),
    deployment_id     VARCHAR(50),
    responsible_provider_id VARCHAR(50),
    provider_name     VARCHAR(200),
    location_name     VARCHAR(200),
    location_abbr     VARCHAR(50),
    pos_type          VARCHAR(50),
    file_name         VARCHAR(200),
    load_datetime     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_stg_blockschedule PRIMARY KEY (schedule_date, room_id, slot_start_time)
);

-- ORCase
CREATE TABLE stg.orcase (
    case_id                   VARCHAR(50),
    log_id                    VARCHAR(50),
    patient_id                VARCHAR(50),
    enc_csn_id                VARCHAR(50),
    orl_csn                   VARCHAR(50),
    orl_log_name              VARCHAR(100),
    or_case_id                VARCHAR(50),
    hosp_admsn_time           TIMESTAMP,
    hosp_disch_time           TIMESTAMP,
    hsp_account_id            VARCHAR(50),
    pat_class_code            VARCHAR(50),
    pat_class_name            VARCHAR(100),
    case_type_code            VARCHAR(50),
    case_type_name            VARCHAR(100),
    case_class_name           VARCHAR(100),
    orl_pat_type_name         VARCHAR(100),
    surgery_date              DATE,
    room_id                   VARCHAR(50),
    room_name                 VARCHAR(200),
    location_id               VARCHAR(50),
    location_name             VARCHAR(200),
    primary_surgeon           VARCHAR(200),
    primary_surgeon_npi       VARCHAR(20),
    primary_surgeon_prov_id   VARCHAR(50),
    primary_surgeon_specialty VARCHAR(100),
    case_service              VARCHAR(100),
    sched_status              VARCHAR(50),
    cancel_name               VARCHAR(100),
    cancel_code               VARCHAR(50),
    cancel_desc               VARCHAR(500),
    periop_arrival            TIMESTAMP,
    preop_in                  TIMESTAMP,
    preop_out                 TIMESTAMP,
    or_in                     TIMESTAMP,
    anes_start                TIMESTAMP,
    proc_start                TIMESTAMP,
    proc_closing              TIMESTAMP,
    proc_end                  TIMESTAMP,
    or_out                    TIMESTAMP,
    anes_end                  TIMESTAMP,
    pacu_in                   TIMESTAMP,
    pacu_out                  TIMESTAMP,
    pacu2_in                  TIMESTAMP,
    pacu2_out                 TIMESTAMP,
    proc_care_complete        TIMESTAMP,
    sched_start_time          TIMESTAMP,
    sched_duration            INT,
    record_create_date        TIMESTAMP,
    setup_offset              INT,
    cleanup_offset            INT,
    panel1_start_at           TIMESTAMP,
    destination               VARCHAR(100),
    enc_discharge_dept        VARCHAR(200),
    num_of_panels             INT,
    primary_procedure         TEXT,  -- VARCHAR(MAX) => TEXT
    asa_rating_code           VARCHAR(50),
    asa_code_name             VARCHAR(100),
    file_name                 VARCHAR(200),
    load_datetime             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_stg_orcase PRIMARY KEY (case_id)
);

-- ORSchedule
CREATE TABLE stg.orschedule (
    log_id                 VARCHAR(50),
    or_case_id             VARCHAR(50),
    patient_id             VARCHAR(50),
    primary_surgeon_id     VARCHAR(50),
    primary_surgeon_name   VARCHAR(200),
    primary_surgeon_npi    VARCHAR(20),
    primary_surgeon_specialty VARCHAR(100),
    surgery_date           DATE,
    case_created_date      TIMESTAMP,
    requested_date         DATE,
    requested_time         TIME,
    room_name              VARCHAR(200),
    location_name          VARCHAR(200),
    projected_start_time   TIMESTAMP,
    projected_end_time     TIMESTAMP,
    case_service           VARCHAR(100),
    pat_class_name         VARCHAR(100),
    sched_status           VARCHAR(50),
    case_cancel_reason_code VARCHAR(50),
    case_cancel_reason     VARCHAR(100),
    cancel_user_id         VARCHAR(50),
    cancel_comments        VARCHAR(500),
    cancel_date            TIMESTAMP,
    anes_type              VARCHAR(50),
    sched_start_time       TIMESTAMP,
    sched_duration         INT,
    setup_offset           INT,
    cleanup_offset         INT,
    num_of_panels          INT,
    panel1_start_at        TIMESTAMP,
    primary_proc_name      TEXT, -- VARCHAR(MAX) => TEXT
    record_update_dt       TIMESTAMP,
    file_name              VARCHAR(200),
    load_datetime          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_stg_orschedule PRIMARY KEY (or_case_id)
);

-- DataLoadError
CREATE TABLE stg.dataloaderror (
    error_id        BIGSERIAL PRIMARY KEY, -- IDENTITY => BIGSERIAL
    source_file     VARCHAR(200),
    error_datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    error_message   TEXT,    -- VARCHAR(MAX) => TEXT
    error_details   TEXT,    -- ditto
    row_data        TEXT,    -- ditto
    status          VARCHAR(50) DEFAULT 'NEW',
    resolution_notes TEXT,
    resolved_datetime TIMESTAMP,
    resolved_by     VARCHAR(50)
);

-- FileTracking
CREATE TABLE stg.filetracking (
    file_id        BIGSERIAL PRIMARY KEY,
    file_name      VARCHAR(200),
    file_type      VARCHAR(50),
    file_size      BIGINT,
    record_count   INT,
    load_start_time TIMESTAMP,
    load_end_time TIMESTAMP,
    status        VARCHAR(50),
    error_count   INT DEFAULT 0,
    notes         TEXT, -- was VARCHAR(MAX)
    CONSTRAINT uq_filetracking_filename UNIQUE (file_name)
);

------------------------------------------------------------------------------
-- 3. Create indexes
------------------------------------------------------------------------------

CREATE INDEX ix_blockschedule_serviceroom 
    ON stg.blockschedule(service_code, room_id, schedule_date);

CREATE INDEX ix_blockschedule_location
    ON stg.blockschedule(location_name, schedule_date);

CREATE INDEX ix_orcase_surgery
    ON stg.orcase(surgery_date, location_id, room_id);

CREATE INDEX ix_orcase_surgeon
    ON stg.orcase(primary_surgeon_prov_id, surgery_date);

CREATE INDEX ix_orschedule_surgery
    ON stg.orschedule(surgery_date, location_name, room_name);

------------------------------------------------------------------------------
-- 4. Add history tracking columns
------------------------------------------------------------------------------
ALTER TABLE stg.blockschedule 
    ADD COLUMN first_load_datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN last_update_datetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE stg.orcase 
    ADD COLUMN first_load_datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN last_update_datetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE stg.orschedule 
    ADD COLUMN first_load_datetime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN last_update_datetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

------------------------------------------------------------------------------
-- 5. Stored Procedures (PostgreSQL style)
------------------------------------------------------------------------------
-- Note: Using CREATE PROCEDURE (Postgres 11+). 
--       For older versions, use CREATE FUNCTION with a RETURNS void.

------------------------------------------------------------------------------
-- stg.TrackFileProcessing: Replaces T-SQL MERGE with PostgreSQL "upsert"
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.trackfileprocessing(
    IN p_filename VARCHAR(200),
    IN p_filetype VARCHAR(50),
    IN p_filesize BIGINT,
    IN p_status   VARCHAR(50),
    IN p_notes    TEXT DEFAULT NULL
)
LANGUAGE plpgsql
AS $$
BEGIN
    -- Upsert into stg.filetracking
    INSERT INTO stg.filetracking (
        file_name,
        file_type,
        file_size,
        status,
        load_start_time,
        notes
    )
    VALUES (
        p_filename,
        p_filetype,
        p_filesize,
        p_status,
        CASE WHEN p_status = 'STARTED' THEN CURRENT_TIMESTAMP ELSE NULL END,
        p_notes
    )
    ON CONFLICT (file_name)
    DO UPDATE
    SET load_start_time = CASE 
            WHEN EXCLUDED.status = 'STARTED' THEN EXCLUDED.load_start_time
            ELSE stg.filetracking.load_start_time
        END,
        load_end_time = CASE 
            WHEN EXCLUDED.status = 'COMPLETED' THEN CURRENT_TIMESTAMP
            ELSE stg.filetracking.load_end_time
        END,
        status = EXCLUDED.status,
        notes = COALESCE(EXCLUDED.notes, stg.filetracking.notes);
END;
$$;

------------------------------------------------------------------------------
-- stg.LogDataError
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.logdataerror(
    IN p_sourcefile   VARCHAR(200),
    IN p_errormessage TEXT,
    IN p_errordetails TEXT,
    IN p_rowdata      TEXT
)
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO stg.dataloaderror (
        source_file, 
        error_message, 
        error_details, 
        row_data
    )
    VALUES (
        p_sourcefile, 
        p_errormessage, 
        p_errordetails, 
        p_rowdata
    );

    -- Update error_count in filetracking
    UPDATE stg.filetracking
       SET error_count = error_count + 1
     WHERE file_name = p_sourcefile;
END;
$$;

------------------------------------------------------------------------------
-- stg.ValidateBlockSchedule
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.validateblockschedule(
    IN p_filename VARCHAR(200)
)
LANGUAGE plpgsql
AS $$
BEGIN
    -- 1. Invalid dates
    INSERT INTO stg.dataloaderror (
        source_file, 
        error_message, 
        error_details, 
        row_data, 
        status
    )
    SELECT 
        p_filename,
        'Invalid Date',
        'Schedule date is in the past or more than 2 years in future',
        room_id || '|' || TO_CHAR(schedule_date, 'YYYY-MM-DD'),
        'NEW'
    FROM stg.blockschedule
    WHERE schedule_date < CURRENT_DATE
       OR schedule_date > (CURRENT_DATE + INTERVAL '2 years');

    -- 2. Overlapping blocks
    WITH OverlappingBlocks AS (
        SELECT 
            b1.room_id,
            b1.schedule_date,
            b1.slot_start_time,
            b1.slot_end_time,
            b2.slot_start_time AS overlapping_start,
            b2.slot_end_time   AS overlapping_end
        FROM stg.blockschedule b1
        JOIN stg.blockschedule b2 
          ON b1.room_id = b2.room_id
         AND b1.schedule_date = b2.schedule_date
         AND b1.slot_start_time < b2.slot_end_time
         AND b1.slot_end_time > b2.slot_start_time
         AND b1.slot_start_time != b2.slot_start_time
    )
    INSERT INTO stg.dataloaderror (
        source_file, error_message, error_details, row_data, status
    )
    SELECT 
        p_filename,
        'Overlapping Blocks',
        'Multiple blocks assigned to same room/time',
        room_id || '|' || TO_CHAR(schedule_date, 'YYYY-MM-DD') || '|' ||
        TO_CHAR(slot_start_time, 'HH24:MI:SS') || '-' ||
        TO_CHAR(slot_end_time, 'HH24:MI:SS'),
        'NEW'
    FROM OverlappingBlocks;
END;
$$;

------------------------------------------------------------------------------
-- stg.ValidateORCase
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.validateorcase(
    IN p_filename VARCHAR(200)
)
LANGUAGE plpgsql
AS $$
BEGIN
    -- 1. Invalid Case Times
    INSERT INTO stg.dataloaderror (
        source_file, error_message, error_details, row_data, status
    )
    SELECT 
        p_filename,
        'Invalid Case Times',
        'Case times are not in logical sequence',
        case_id || '|' || TO_CHAR(surgery_date, 'YYYY-MM-DD'),
        'NEW'
    FROM stg.orcase
    WHERE (or_in > or_out)
       OR (proc_start > proc_end)
       OR (anes_start > anes_end)
       OR (preop_in > preop_out)
       OR (or_in < preop_out)
       OR (proc_start < or_in)
       OR (proc_end > or_out);

    -- 2. Missing Required Fields
    INSERT INTO stg.dataloaderror (
        source_file, error_message, error_details, row_data, status
    )
    SELECT 
        p_filename,
        'Missing Required Fields',
        'One or more required fields are null',
        case_id,
        'NEW'
    FROM stg.orcase
    WHERE case_id IS NULL
       OR surgery_date IS NULL
       OR room_id IS NULL
       OR location_id IS NULL;
END;
$$;

------------------------------------------------------------------------------
-- stg.UpdateBlockUtilization
-- NOTE: Rewrites T-SQL DATEDIFF logic using EXTRACT(EPOCH FROM ...).
--       Evaluate carefully that logic matches your original intentions.
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.updateblockutilization(
    IN p_startdate DATE,
    IN p_enddate   DATE
)
LANGUAGE plpgsql
AS $$
BEGIN
    WITH blockusage AS (
        SELECT
            b.schedule_date,
            b.room_id,
            b.service_code,
            b.slot_start_time,
            b.slot_end_time,
            (EXTRACT(EPOCH FROM (b.slot_end_time - b.slot_start_time)) / 60)::int AS block_minutes,
            COUNT(c.case_id) AS cases_scheduled,
            SUM(
                CASE
                    WHEN c.or_in >= b.slot_start_time
                         AND c.or_out <= b.slot_end_time
                    THEN (EXTRACT(EPOCH FROM (c.or_out - c.or_in)) / 60)::int

                    WHEN c.or_in < b.slot_start_time
                         AND c.or_out <= b.slot_end_time
                    THEN (EXTRACT(EPOCH FROM (c.or_out - b.slot_start_time)) / 60)::int

                    WHEN c.or_in >= b.slot_start_time
                         AND c.or_out > b.slot_end_time
                    THEN (EXTRACT(EPOCH FROM (b.slot_end_time - c.or_in)) / 60)::int

                    WHEN c.or_in < b.slot_start_time
                         AND c.or_out > b.slot_end_time
                    THEN (EXTRACT(EPOCH FROM (b.slot_end_time - b.slot_start_time)) / 60)::int
                    ELSE 0
                END
            ) AS utilized_minutes,
            SUM(
                CASE
                    WHEN c.or_in < b.slot_start_time
                    THEN (EXTRACT(EPOCH FROM (b.slot_start_time - c.or_in)) / 60)::int
                    ELSE 0
                END
            ) AS early_minutes,
            SUM(
                CASE
                    WHEN c.or_out > b.slot_end_time
                    THEN (EXTRACT(EPOCH FROM (c.or_out - b.slot_end_time)) / 60)::int
                    ELSE 0
                END
            ) AS late_minutes
        FROM stg.blockschedule b
        LEFT JOIN stg.orcase c
               ON b.schedule_date = c.surgery_date
              AND b.room_id = c.room_id
              AND c.or_in IS NOT NULL
              AND c.or_out IS NOT NULL
        WHERE b.schedule_date BETWEEN p_startdate AND p_enddate
        GROUP BY
            b.schedule_date,
            b.room_id,
            b.service_code,
            b.slot_start_time,
            b.slot_end_time
    )
    -- Insert results into a prod table (update path as needed).
    INSERT INTO prod.blockutilization (
        -- Adjust these columns to match your actual columns in prod.blockutilization
        utilization_date,
        room_id,
        service_id,
        scheduled_minutes,
        actual_minutes,
        utilization_percentage,
        cases_scheduled,
        released_minutes,
        exchanged_minutes,
        -- For illustration: storing early/late in extra columns (optional)
        metric_date
    )
    SELECT
        bu.schedule_date,
        bu.room_id,
        s.service_id,
        bu.block_minutes,
        bu.utilized_minutes,
        CASE 
            WHEN bu.block_minutes > 0
            THEN (bu.utilized_minutes::decimal(10,2) / bu.block_minutes) * 100
            ELSE 0
        END,
        bu.cases_scheduled,
        bu.early_minutes,
        bu.late_minutes,
        CURRENT_TIMESTAMP
    FROM blockusage bu
    JOIN prod.service s
      ON bu.service_code = s.code;
END;
$$;

------------------------------------------------------------------------------
-- stg.AnalyzeTurnoverTimes
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.analyzeturnovertimes(
    IN p_startdate DATE,
    IN p_enddate   DATE
)
LANGUAGE plpgsql
AS $$
BEGIN
    WITH casesequence AS (
        SELECT
            c1.case_id,
            c1.surgery_date,
            c1.room_id,
            c1.primary_surgeon_prov_id,
            c1.or_out AS case_end,
            LEAD(c1.case_id) OVER (
                PARTITION BY c1.room_id, c1.surgery_date
                ORDER BY c1.or_in
            ) AS next_case_id,
            LEAD(c1.or_in) OVER (
                PARTITION BY c1.room_id, c1.surgery_date
                ORDER BY c1.or_in
            ) AS next_case_start,
            LEAD(c1.primary_surgeon_prov_id) OVER (
                PARTITION BY c1.room_id, c1.surgery_date
                ORDER BY c1.or_in
            ) AS next_surgeon_id
        FROM stg.orcase c1
        WHERE c1.surgery_date BETWEEN p_startdate AND p_enddate
          AND c1.or_in IS NOT NULL
          AND c1.or_out IS NOT NULL
    )
    INSERT INTO prod.turnovermetrics (
        case_id,
        next_case_id,
        surgery_date,
        room_id,
        turnover_minutes,
        is_same_surgeon,
        metric_date
    )
    SELECT
        cs.case_id,
        cs.next_case_id,
        cs.surgery_date,
        cs.room_id,
        (EXTRACT(EPOCH FROM (cs.next_case_start - cs.case_end)) / 60)::int AS turnover_minutes,
        CASE WHEN cs.primary_surgeon_prov_id = cs.next_surgeon_id THEN 1 ELSE 0 END,
        CURRENT_TIMESTAMP
    FROM casesequence cs
    WHERE cs.next_case_id IS NOT NULL
      AND ((EXTRACT(EPOCH FROM (cs.next_case_start - cs.case_end)) / 60)::int BETWEEN 0 AND 180);
END;
$$;

------------------------------------------------------------------------------
-- stg.AnalyzePrimeTimeUtilization
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.analyzeprimetimeutilization(
    IN p_startdate DATE,
    IN p_enddate   DATE
)
LANGUAGE plpgsql
AS $$
BEGIN
    WITH primetimeusage AS (
        SELECT
            c.surgery_date,
            c.location_id,
            c.room_id,
            SUM(
                GREATEST(
                    LEAST(
                        EXTRACT(EPOCH FROM (LEAST(c.or_out, c.or_in::date + INTERVAL '17:00:00') 
                                            - GREATEST(c.or_in, c.or_in::date + INTERVAL '07:00:00'))),
                        0
                    ),
                    0
                ) / 60
            )::int AS prime_time_minutes,
            SUM(
                (EXTRACT(EPOCH FROM (c.or_out - c.or_in)) / 60)
            )::int AS total_minutes,
            COUNT(*) AS total_cases
        FROM stg.orcase c
        WHERE c.surgery_date BETWEEN p_startdate AND p_enddate
          AND c.or_in IS NOT NULL
          AND c.or_out IS NOT NULL
        GROUP BY
            c.surgery_date,
            c.location_id,
            c.room_id
    )
    INSERT INTO prod.primetimeutilization (
        surgery_date,
        location_id,
        room_id,
        prime_time_minutes,
        total_minutes,
        prime_time_percentage,
        total_cases,
        metric_date
    )
    SELECT
        ptu.surgery_date,
        ptu.location_id,
        ptu.room_id,
        ptu.prime_time_minutes,
        ptu.total_minutes,
        CASE WHEN ptu.total_minutes > 0
             THEN (ptu.prime_time_minutes::decimal(10,2) / ptu.total_minutes) * 100
             ELSE 0
        END,
        ptu.total_cases,
        CURRENT_TIMESTAMP
    FROM primetimeusage ptu;
END;
$$;

------------------------------------------------------------------------------
-- DataQualityException
------------------------------------------------------------------------------
CREATE TABLE stg.dataqualityexception (
    exception_id       BIGSERIAL PRIMARY KEY,
    check_name         VARCHAR(100),
    check_description  VARCHAR(500),
    source_table       VARCHAR(100),
    primary_key_value  VARCHAR(100),
    field_name         VARCHAR(100),
    field_value        TEXT,   -- was VARCHAR(MAX)
    exception_type     VARCHAR(50),
    exception_message  VARCHAR(500),
    check_date         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_date      TIMESTAMP,
    resolved_by        VARCHAR(50),
    resolution_notes   TEXT
);

------------------------------------------------------------------------------
-- stg.RunDataQualityChecks
-- Large logic block rewriting T-SQL date/time functions to Postgres.
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.rundataqualitychecks(
    IN p_startdate DATE,
    IN p_enddate   DATE
)
LANGUAGE plpgsql
AS $$
DECLARE
    v_checkname VARCHAR(100);
    v_checkdesc VARCHAR(500);
BEGIN
    ----------------------------------------------------------------------------
    -- Check 1: Invalid surgery dates
    ----------------------------------------------------------------------------
    v_checkname := 'InvalidSurgeryDates';
    v_checkdesc := 'Surgery dates that are in the past or too far in future';

    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'ORCase',
        case_id,
        'surgery_date',
        TO_CHAR(surgery_date, 'YYYY-MM-DD HH24:MI:SS'),
        'InvalidValue',
        'Surgery date ' || TO_CHAR(surgery_date, 'YYYY-MM-DD HH24:MI:SS') || ' is invalid'
    FROM stg.orcase
    WHERE surgery_date < CURRENT_DATE
       OR surgery_date > (CURRENT_DATE + INTERVAL '2 years');

    ----------------------------------------------------------------------------
    -- Check 2: Missing required fields
    ----------------------------------------------------------------------------
    v_checkname := 'MissingRequiredFields';
    v_checkdesc := 'Required fields that are null or empty';

    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'ORCase',
        case_id,
        CASE
          WHEN patient_id IS NULL THEN 'patient_id'
          WHEN room_id IS NULL THEN 'room_id'
          WHEN location_id IS NULL THEN 'location_id'
          WHEN primary_surgeon_prov_id IS NULL THEN 'primary_surgeon_prov_id'
        END,
        NULL,
        'MissingValue',
        'Required field is null'
    FROM stg.orcase
    WHERE patient_id IS NULL
       OR room_id IS NULL
       OR location_id IS NULL
       OR primary_surgeon_prov_id IS NULL;

    ----------------------------------------------------------------------------
    -- Check 3: Invalid time sequences
    ----------------------------------------------------------------------------
    v_checkname := 'InvalidTimeSequence';
    v_checkdesc := 'Case times that are not in logical sequence';

    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'ORCase',
        case_id,
        'case_times',
        TO_CHAR(or_in, 'YYYY-MM-DD HH24:MI:SS') || ' to ' || TO_CHAR(or_out, 'YYYY-MM-DD HH24:MI:SS'),
        'InvalidSequence',
        'Case times are not in logical sequence'
    FROM stg.orcase
    WHERE (or_in > or_out)
       OR (proc_start > proc_end)
       OR (anes_start > anes_end)
       OR (preop_in > preop_out);

    ----------------------------------------------------------------------------
    -- Check 4: Overlapping blocks
    ----------------------------------------------------------------------------
    v_checkname := 'OverlappingBlocks';
    v_checkdesc := 'Block times that overlap for the same room';

    WITH OverlappingBlocks AS (
        SELECT 
            b1.room_id,
            b1.schedule_date,
            b1.slot_start_time,
            b1.slot_end_time,
            b2.slot_start_time AS overlapping_start,
            b2.slot_end_time   AS overlapping_end
        FROM stg.blockschedule b1
        JOIN stg.blockschedule b2
          ON b1.room_id = b2.room_id
         AND b1.schedule_date = b2.schedule_date
         AND b1.slot_start_time < b2.slot_end_time
         AND b1.slot_end_time > b2.slot_start_time
         AND b1.slot_start_time != b2.slot_start_time
    )
    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT DISTINCT
        v_checkname,
        v_checkdesc,
        'BlockSchedule',
        room_id || '|' || TO_CHAR(schedule_date, 'YYYY-MM-DD'),
        'block_times',
        TO_CHAR(slot_start_time, 'HH24:MI:SS') || ' - ' || TO_CHAR(slot_end_time, 'HH24:MI:SS'),
        'Overlap',
        'Block times overlap with another block'
    FROM OverlappingBlocks;

    ----------------------------------------------------------------------------
    -- Check 5: Unusual case durations
    ----------------------------------------------------------------------------
    v_checkname := 'UnusualCaseDuration';
    v_checkdesc := 'Cases with unusually short or long durations';

    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'ORCase',
        case_id,
        'case_duration',
        CAST( ((EXTRACT(EPOCH FROM (or_out - or_in))) / 60 )::int AS VARCHAR),
        'OutOfRange',
        CASE 
            WHEN ((EXTRACT(EPOCH FROM (or_out - or_in))) / 60 )::int < 15 
                THEN 'Case duration < 15 minutes'
            WHEN ((EXTRACT(EPOCH FROM (or_out - or_in))) / 60 )::int > 720
                THEN 'Case duration > 12 hours'
        END
    FROM stg.orcase
    WHERE ( (EXTRACT(EPOCH FROM (or_out - or_in)) / 60 )::int < 15 )
       OR ( (EXTRACT(EPOCH FROM (or_out - or_in)) / 60 )::int > 720 );

    ----------------------------------------------------------------------------
    -- Check 6: Room utilization outliers
    ----------------------------------------------------------------------------
    v_checkname := 'RoomUtilizationOutlier';
    v_checkdesc := 'Rooms with unusually high or low utilization';

    WITH roomstats AS (
        SELECT
            room_id,
            surgery_date,
            SUM( (EXTRACT(EPOCH FROM (or_out - or_in)) / 60 )::int ) AS total_minutes,
            COUNT(*) AS case_count
        FROM stg.orcase
        WHERE surgery_date BETWEEN p_startdate AND p_enddate
        GROUP BY room_id, surgery_date
    )
    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'ORCase',
        room_id || '|' || TO_CHAR(surgery_date, 'YYYY-MM-DD'),
        'daily_utilization',
        total_minutes::varchar,
        'OutOfRange',
        CASE 
            WHEN total_minutes > 720 THEN 'Unusually high utilization (> 12 hrs)'
            WHEN total_minutes < 120 AND case_count > 0 THEN 'Unusually low utilization (< 2 hrs)'
        END
    FROM roomstats
    WHERE total_minutes > 720
       OR (total_minutes < 120 AND case_count > 0);

    ----------------------------------------------------------------------------
    -- Check 7: Block utilization anomalies
    ----------------------------------------------------------------------------
    v_checkname := 'BlockUtilizationAnomaly';
    v_checkdesc := 'Blocks with unusual utilization patterns';

    WITH blockstats AS (
        SELECT
            b.room_id,
            b.schedule_date,
            b.service_code,
            (EXTRACT(EPOCH FROM (b.slot_end_time - b.slot_start_time)) / 60)::int AS block_minutes,
            COUNT(c.case_id) AS case_count,
            SUM(
                (EXTRACT(EPOCH FROM (c.or_out - c.or_in)) / 60)::int
            ) AS used_minutes
        FROM stg.blockschedule b
        LEFT JOIN stg.orcase c
               ON b.schedule_date = c.surgery_date
              AND b.room_id = c.room_id
              AND c.or_in >= b.slot_start_time
              AND c.or_in < b.slot_end_time
        WHERE b.schedule_date BETWEEN p_startdate AND p_enddate
        GROUP BY
            b.room_id,
            b.schedule_date,
            b.service_code,
            b.slot_start_time,
            b.slot_end_time
    )
    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'BlockSchedule',
        room_id || '|' || TO_CHAR(schedule_date, 'YYYY-MM-DD'),
        'utilization_rate',
        CAST(
          ( (used_minutes::decimal(10,2) / NULLIF(block_minutes,0) ) * 100 ) AS VARCHAR
        ) || '%',
        'OutOfRange',
        CASE
            WHEN used_minutes > block_minutes * 1.2 THEN 'Utilization >120% of block time'
            WHEN used_minutes < block_minutes * 0.2 
                 AND case_count > 0 
            THEN 'Utilization <20% of block time'
        END
    FROM blockstats
    WHERE (block_minutes > 0 AND used_minutes > block_minutes * 1.2)
       OR (block_minutes > 0 AND used_minutes < block_minutes * 0.2 AND case_count > 0);

    ----------------------------------------------------------------------------
    -- Check 8: Turnover time anomalies
    ----------------------------------------------------------------------------
    v_checkname := 'TurnoverTimeAnomaly';
    v_checkdesc := 'Unusual turnover times between cases';

    WITH casepairs AS (
        SELECT
            c1.case_id,
            c1.room_id,
            c1.surgery_date,
            c1.or_out AS first_case_end,
            c2.or_in  AS next_case_start,
            (EXTRACT(EPOCH FROM (c2.or_in - c1.or_out)) / 60)::int AS turnover_time
        FROM stg.orcase c1
        JOIN stg.orcase c2
          ON c1.room_id = c2.room_id
         AND c1.surgery_date = c2.surgery_date
         AND c2.or_in > c1.or_out
         AND NOT EXISTS (
             SELECT 1
             FROM stg.orcase c3
             WHERE c3.room_id = c1.room_id
               AND c3.surgery_date = c1.surgery_date
               AND c3.or_in > c1.or_out
               AND c3.or_in < c2.or_in
         )
    )
    INSERT INTO stg.dataqualityexception (
        check_name,
        check_description,
        source_table,
        primary_key_value,
        field_name,
        field_value,
        exception_type,
        exception_message
    )
    SELECT
        v_checkname,
        v_checkdesc,
        'ORCase',
        case_id,
        'turnover_time',
        turnover_time::varchar,
        'OutOfRange',
        CASE
            WHEN turnover_time > 90 THEN 'Unusually long turnover (> 90 min)'
            WHEN turnover_time < 15 THEN 'Unusually short turnover (< 15 min)'
        END
    FROM casepairs
    WHERE turnover_time > 90
       OR turnover_time < 15;

    -- Final summary (optional, just a SELECT)
    SELECT
        check_name,
        COUNT(*) AS exception_count,
        MIN(check_date) AS first_occurrence,
        MAX(check_date) AS last_occurrence
    FROM stg.dataqualityexception
    WHERE check_date >= CURRENT_DATE - 1  -- last 1 day
    GROUP BY check_name
    ORDER BY exception_count DESC;
END;
$$;

------------------------------------------------------------------------------
-- stg.ResolveDataQualityException
------------------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE stg.resolvedataqualityexception(
    IN p_exceptionid BIGINT,
    IN p_resolvedby  VARCHAR(50),
    IN p_resolutionnotes TEXT
)
LANGUAGE plpgsql
AS $$
BEGIN
    UPDATE stg.dataqualityexception
       SET resolved_date = CURRENT_TIMESTAMP,
           resolved_by   = p_resolvedby,
           resolution_notes = p_resolutionnotes
     WHERE exception_id = p_exceptionid;
END;
$$;

------------------------------------------------------------------------------
-- 6. Create view for unresolved exceptions
------------------------------------------------------------------------------
CREATE OR REPLACE VIEW stg.unresolvedexceptions AS
SELECT
    exception_id,
    check_name,
    check_description,
    source_table,
    primary_key_value,
    field_name,
    field_value,
    exception_type,
    exception_message,
    check_date
FROM stg.dataqualityexception
WHERE resolved_date IS NULL;

