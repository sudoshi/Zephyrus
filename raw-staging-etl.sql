-- Block Schedule staging table
CREATE TABLE stg.BlockSchedule (
    schedule_date DATE,
    room_id VARCHAR(50),
    room_name VARCHAR(100),
    slot_type VARCHAR(50),
    slot_start_time TIME,
    slot_end_time TIME,
    is_public_slot BIT,
    service_code VARCHAR(50),
    surgeon_id VARCHAR(50),
    group_id VARCHAR(50),
    block_key VARCHAR(50),
    block_title VARCHAR(200),
    block_abbreviation VARCHAR(50),
    time_off_reason_code VARCHAR(50),
    comments VARCHAR(500),
    deployment_id VARCHAR(50),
    responsible_provider_id VARCHAR(50),
    provider_name VARCHAR(200),
    location_name VARCHAR(200),
    location_abbr VARCHAR(50),
    pos_type VARCHAR(50),
    file_name VARCHAR(200),
    load_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT PK_stg_BlockSchedule PRIMARY KEY (schedule_date, room_id, slot_start_time)
);

-- OR Case staging table
CREATE TABLE stg.ORCase (
    case_id VARCHAR(50),
    log_id VARCHAR(50),
    patient_id VARCHAR(50),
    enc_csn_id VARCHAR(50),
    orl_csn VARCHAR(50),
    orl_log_name VARCHAR(100),
    or_case_id VARCHAR(50), 
    hosp_admsn_time DATETIME2,
    hosp_disch_time DATETIME2,
    hsp_account_id VARCHAR(50),
    pat_class_code VARCHAR(50),
    pat_class_name VARCHAR(100),
    case_type_code VARCHAR(50),
    case_type_name VARCHAR(100),
    case_class_name VARCHAR(100),
    orl_pat_type_name VARCHAR(100),
    surgery_date DATE,
    room_id VARCHAR(50),
    room_name VARCHAR(200),
    location_id VARCHAR(50),
    location_name VARCHAR(200),
    primary_surgeon VARCHAR(200),
    primary_surgeon_npi VARCHAR(20),
    primary_surgeon_prov_id VARCHAR(50),
    primary_surgeon_specialty VARCHAR(100),
    case_service VARCHAR(100),
    sched_status VARCHAR(50),
    cancel_name VARCHAR(100),
    cancel_code VARCHAR(50),
    cancel_desc VARCHAR(500),
    periop_arrival DATETIME2,
    preop_in DATETIME2,
    preop_out DATETIME2,
    or_in DATETIME2,
    anes_start DATETIME2,
    proc_start DATETIME2,
    proc_closing DATETIME2,
    proc_end DATETIME2,
    or_out DATETIME2,
    anes_end DATETIME2,
    pacu_in DATETIME2,
    pacu_out DATETIME2, 
    pacu2_in DATETIME2,
    pacu2_out DATETIME2,
    proc_care_complete DATETIME2,
    sched_start_time DATETIME2,
    sched_duration INT,
    record_create_date DATETIME2,
    setup_offset INT,
    cleanup_offset INT,
    panel1_start_at DATETIME2,
    destination VARCHAR(100),
    enc_discharge_dept VARCHAR(200),
    num_of_panels INT,
    primary_procedure VARCHAR(MAX),
    asa_rating_code VARCHAR(50),
    asa_code_name VARCHAR(100),
    file_name VARCHAR(200),
    load_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT PK_stg_ORCase PRIMARY KEY (case_id)
);

-- OR Schedule staging table 
CREATE TABLE stg.ORSchedule (
    log_id VARCHAR(50),
    or_case_id VARCHAR(50),
    patient_id VARCHAR(50),
    primary_surgeon_id VARCHAR(50),
    primary_surgeon_name VARCHAR(200),
    primary_surgeon_npi VARCHAR(20),
    primary_surgeon_specialty VARCHAR(100),
    surgery_date DATE,
    case_created_date DATETIME2,
    requested_date DATE,
    requested_time TIME,
    room_name VARCHAR(200), 
    location_name VARCHAR(200),
    projected_start_time DATETIME2,
    projected_end_time DATETIME2,
    case_service VARCHAR(100),
    pat_class_name VARCHAR(100),
    sched_status VARCHAR(50),
    case_cancel_reason_code VARCHAR(50),
    case_cancel_reason VARCHAR(100),
    cancel_user_id VARCHAR(50),
    cancel_comments VARCHAR(500),
    cancel_date DATETIME2,
    anes_type VARCHAR(50),
    sched_start_time DATETIME2,
    sched_duration INT,
    setup_offset INT,
    cleanup_offset INT,
    num_of_panels INT,
    panel1_start_at DATETIME2,
    primary_proc_name VARCHAR(MAX),
    record_update_dt DATETIME2,
    file_name VARCHAR(200),
    load_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT PK_stg_ORSchedule PRIMARY KEY (or_case_id)
);

-- Error logging table
CREATE TABLE stg.DataLoadError (
    error_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    source_file VARCHAR(200),
    error_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    error_message VARCHAR(MAX),
    error_details VARCHAR(MAX),
    row_data VARCHAR(MAX),
    status VARCHAR(50) DEFAULT 'NEW',
    resolution_notes VARCHAR(MAX),
    resolved_datetime DATETIME2,
    resolved_by VARCHAR(50)
);

-- File tracking table
CREATE TABLE stg.FileTracking (
    file_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    file_name VARCHAR(200),
    file_type VARCHAR(50),
    file_size BIGINT,
    record_count INT,
    load_start_time DATETIME2,
    load_end_time DATETIME2,
    status VARCHAR(50),
    error_count INT DEFAULT 0,
    notes VARCHAR(MAX),
    CONSTRAINT UQ_FileTracking_FileName UNIQUE (file_name)
);

-- Create indexes
CREATE NONCLUSTERED INDEX IX_BlockSchedule_ServiceRoom 
ON stg.BlockSchedule(service_code, room_id, schedule_date);

CREATE NONCLUSTERED INDEX IX_BlockSchedule_Location 
ON stg.BlockSchedule(location_name, schedule_date);

CREATE NONCLUSTERED INDEX IX_ORCase_Surgery 
ON stg.ORCase(surgery_date, location_id, room_id);

CREATE NONCLUSTERED INDEX IX_ORCase_Surgeon
ON stg.ORCase(primary_surgeon_prov_id, surgery_date);

CREATE NONCLUSTERED INDEX IX_ORSchedule_Surgery
ON stg.ORSchedule(surgery_date, location_name, room_name);

-- Add history tracking
ALTER TABLE stg.BlockSchedule ADD 
    first_load_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    last_update_datetime DATETIME2 NOT NULL DEFAULT GETDATE();

ALTER TABLE stg.ORCase ADD
    first_load_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    last_update_datetime DATETIME2 NOT NULL DEFAULT GETDATE();

ALTER TABLE stg.ORSchedule ADD
    first_load_datetime DATETIME2 NOT NULL DEFAULT GETDATE(),
    last_update_datetime DATETIME2 NOT NULL DEFAULT GETDATE();
    
-- Procedure to track file processing
CREATE OR ALTER PROCEDURE stg.TrackFileProcessing
    @FileName VARCHAR(200),
    @FileType VARCHAR(50),
    @FileSize BIGINT,
    @Status VARCHAR(50),
    @Notes VARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    MERGE stg.FileTracking AS target
    USING (SELECT @FileName as file_name) AS source
    ON target.file_name = source.file_name
    WHEN MATCHED THEN
        UPDATE SET 
            load_start_time = CASE 
                WHEN @Status = 'STARTED' THEN GETDATE() 
                ELSE target.load_start_time 
            END,
            load_end_time = CASE 
                WHEN @Status = 'COMPLETED' THEN GETDATE() 
                ELSE target.load_end_time 
            END,
            status = @Status,
            notes = ISNULL(@Notes, target.notes)
    WHEN NOT MATCHED THEN
        INSERT (file_name, file_type, file_size, status, load_start_time, notes)
        VALUES (@FileName, @FileType, @FileSize, @Status, 
            CASE WHEN @Status = 'STARTED' THEN GETDATE() ELSE NULL END, 
            @Notes);
END;
GO

-- Procedure to log data errors
CREATE OR ALTER PROCEDURE stg.LogDataError
    @SourceFile VARCHAR(200),
    @ErrorMessage VARCHAR(MAX),
    @ErrorDetails VARCHAR(MAX),
    @RowData VARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    
    INSERT INTO stg.DataLoadError 
        (source_file, error_message, error_details, row_data)
    VALUES 
        (@SourceFile, @ErrorMessage, @ErrorDetails, @RowData);

    -- Update error count in file tracking
    UPDATE stg.FileTracking
    SET error_count = error_count + 1
    WHERE file_name = @SourceFile;
END;
GO

-- Procedure to validate block schedule data
CREATE OR ALTER PROCEDURE stg.ValidateBlockSchedule
    @FileName VARCHAR(200)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check for invalid dates
    INSERT INTO stg.DataLoadError 
        (source_file, error_message, error_details, row_data, status)
    SELECT 
        @FileName,
        'Invalid Date',
        'Schedule date is in the past or too far in future',
        room_id + '|' + CONVERT(VARCHAR, schedule_date, 120),
        'NEW'
    FROM stg.BlockSchedule 
    WHERE schedule_date < GETDATE()
    OR schedule_date > DATEADD(YEAR, 2, GETDATE());

    -- Check for overlapping blocks
    WITH OverlappingBlocks AS (
        SELECT 
            b1.room_id,
            b1.schedule_date,
            b1.slot_start_time,
            b1.slot_end_time,
            b2.slot_start_time as overlapping_start,
            b2.slot_end_time as overlapping_end
        FROM stg.BlockSchedule b1
        INNER JOIN stg.BlockSchedule b2 
            ON b1.room_id = b2.room_id
            AND b1.schedule_date = b2.schedule_date
            AND b1.slot_start_time < b2.slot_end_time
            AND b1.slot_end_time > b2.slot_start_time
            AND b1.slot_start_time != b2.slot_start_time
    )
    INSERT INTO stg.DataLoadError 
        (source_file, error_message, error_details, row_data, status)
    SELECT 
        @FileName,
        'Overlapping Blocks',
        'Multiple blocks assigned to same room/time',
        room_id + '|' + CONVERT(VARCHAR, schedule_date, 120) + '|' + 
        CONVERT(VARCHAR, slot_start_time, 108) + '-' + CONVERT(VARCHAR, slot_end_time, 108),
        'NEW'
    FROM OverlappingBlocks;
END;
GO

-- Procedure to validate OR case data
CREATE OR ALTER PROCEDURE stg.ValidateORCase
    @FileName VARCHAR(200)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check for valid case times
    INSERT INTO stg.DataLoadError 
        (source_file, error_message, error_details, row_data, status)
    SELECT 
        @FileName,
        'Invalid Case Times',
        'Case times are not in logical sequence',
        case_id + '|' + CONVERT(VARCHAR, surgery_date, 120),
        'NEW'
    FROM stg.ORCase
    WHERE (or_in > or_out)
    OR (proc_start > proc_end)
    OR (anes_start > anes_end)
    OR (preop_in > preop_out)
    OR (or_in < preop_out)
    OR (proc_start < or_in)
    OR (proc_end > or_out);

    -- Check for missing required fields
    INSERT INTO stg.DataLoadError 
        (source_file, error_message, error_details, row_data, status)
    SELECT 
        @FileName,
        'Missing Required Fields',
        'One or more required fields are null',
        case_id,
        'NEW'
    FROM stg.ORCase
    WHERE case_id IS NULL
    OR surgery_date IS NULL
    OR room_id IS NULL
    OR location_id IS NULL;
END;
GO

-- Procedure to update block utilization metrics
CREATE OR ALTER PROCEDURE stg.UpdateBlockUtilization
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Calculate block utilization for date range
    WITH BlockUsage AS (
        SELECT 
            b.schedule_date,
            b.room_id,
            b.service_code,
            b.slot_start_time,
            b.slot_end_time,
            DATEDIFF(MINUTE, b.slot_start_time, b.slot_end_time) as block_minutes,
            COUNT(c.case_id) as cases_scheduled,
            SUM(CASE 
                WHEN c.or_in >= b.slot_start_time 
                    AND c.or_out <= b.slot_end_time 
                THEN DATEDIFF(MINUTE, c.or_in, c.or_out)
                WHEN c.or_in < b.slot_start_time 
                    AND c.or_out <= b.slot_end_time
                THEN DATEDIFF(MINUTE, b.slot_start_time, c.or_out)
                WHEN c.or_in >= b.slot_start_time 
                    AND c.or_out > b.slot_end_time
                THEN DATEDIFF(MINUTE, c.or_in, b.slot_end_time)
                WHEN c.or_in < b.slot_start_time 
                    AND c.or_out > b.slot_end_time
                THEN DATEDIFF(MINUTE, b.slot_start_time, b.slot_end_time)
                ELSE 0
            END) as utilized_minutes,
            SUM(CASE
                WHEN c.or_in < b.slot_start_time 
                THEN DATEDIFF(MINUTE, c.or_in, b.slot_start_time)
                ELSE 0
            END) as early_minutes,
            SUM(CASE
                WHEN c.or_out > b.slot_end_time
                THEN DATEDIFF(MINUTE, b.slot_end_time, c.or_out)
                ELSE 0
            END) as late_minutes
        FROM stg.BlockSchedule b
        LEFT JOIN stg.ORCase c ON 
            b.schedule_date = c.surgery_date
            AND b.room_id = c.room_id
            AND c.or_in IS NOT NULL 
            AND c.or_out IS NOT NULL
        WHERE b.schedule_date BETWEEN @StartDate AND @EndDate
        GROUP BY 
            b.schedule_date,
            b.room_id,
            b.service_code,
            b.slot_start_time,
            b.slot_end_time
    )
    -- Insert results into metrics table
    INSERT INTO dbo.BlockUtilization (
        block_date,
        room_id,
        service_id,
        block_minutes,
        utilized_minutes,
        utilization_percentage,
        cases_scheduled,
        early_minutes,
        late_minutes,
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
            THEN CAST(bu.utilized_minutes AS DECIMAL(10,2))/bu.block_minutes * 100
            ELSE 0 
        END as utilization_percentage,
        bu.cases_scheduled,
        bu.early_minutes,
        bu.late_minutes,
        GETDATE() as metric_date
    FROM BlockUsage bu
    INNER JOIN dbo.Service s ON bu.service_code = s.code;
END;
GO

-- Procedure to analyze turnover times
CREATE OR ALTER PROCEDURE stg.AnalyzeTurnoverTimes
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    WITH CaseSequence AS (
        -- Get sequential cases in same room
        SELECT 
            c1.case_id,
            c1.surgery_date,
            c1.room_id,
            c1.primary_surgeon_prov_id,
            c1.or_out as case_end,
            LEAD(c1.case_id) OVER (
                PARTITION BY c1.room_id, c1.surgery_date 
                ORDER BY c1.or_in
            ) as next_case_id,
            LEAD(c1.or_in) OVER (
                PARTITION BY c1.room_id, c1.surgery_date 
                ORDER BY c1.or_in
            ) as next_case_start,
            LEAD(c1.primary_surgeon_prov_id) OVER (
                PARTITION BY c1.room_id, c1.surgery_date 
                ORDER BY c1.or_in
            ) as next_surgeon_id
        FROM stg.ORCase c1
        WHERE c1.surgery_date BETWEEN @StartDate AND @EndDate
            AND c1.or_in IS NOT NULL
            AND c1.or_out IS NOT NULL
    )
    INSERT INTO dbo.TurnoverMetrics (
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
        DATEDIFF(MINUTE, cs.case_end, cs.next_case_start) as turnover_minutes,
        CASE 
            WHEN cs.primary_surgeon_prov_id = cs.next_surgeon_id 
            THEN 1 
            ELSE 0 
        END as is_same_surgeon,
        GETDATE() as metric_date
    FROM CaseSequence cs
    WHERE cs.next_case_id IS NOT NULL
        AND DATEDIFF(MINUTE, cs.case_end, cs.next_case_start) BETWEEN 0 AND 180; -- Exclude unreasonable turnovers
END;
GO

-- Procedure to analyze prime time utilization
CREATE OR ALTER PROCEDURE stg.AnalyzePrimeTimeUtilization
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;

    WITH PrimeTimeUsage AS (
        SELECT
            c.surgery_date,
            c.location_id,
            c.room_id,
            -- Calculate minutes during prime time (7am-5pm by default)
            SUM(
                DATEDIFF(MINUTE,
                    CASE 
                        WHEN CAST(c.or_in AS TIME) < '07:00' THEN 
                            DATEADD(DAY, DATEDIFF(DAY, 0, c.or_in), '07:00')
                        ELSE c.or_in
                    END,
                    CASE 
                        WHEN CAST(c.or_out AS TIME) > '17:00' THEN
                            DATEADD(DAY, DATEDIFF(DAY, 0, c.or_out), '17:00')
                        ELSE c.or_out
                    END
                )
            ) as prime_time_minutes,
            -- Calculate total minutes
            SUM(DATEDIFF(MINUTE, c.or_in, c.or_out)) as total_minutes,
            COUNT(*) as total_cases
        FROM stg.ORCase c
        WHERE c.surgery_date BETWEEN @StartDate AND @EndDate
            AND c.or_in IS NOT NULL
            AND c.or_out IS NOT NULL
        GROUP BY
            c.surgery_date,
            c.location_id,
            c.room_id
    )
    INSERT INTO dbo.PrimeTimeUtilization (
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
        CAST(ptu.prime_time_minutes AS DECIMAL(10,2))/
            CASE 
                WHEN ptu.total_minutes > 0 THEN ptu.total_minutes 
                ELSE 1 
            END * 100 as prime_time_percentage,
        ptu.total_cases,
        GETDATE() as metric_date
    FROM PrimeTimeUsage ptu;
END;
GO

-- Data quality exception logging table
CREATE TABLE stg.DataQualityException (
    exception_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    check_name VARCHAR(100),
    check_description VARCHAR(500),
    source_table VARCHAR(100),
    primary_key_value VARCHAR(100),
    field_name VARCHAR(100),
    field_value VARCHAR(MAX),
    exception_type VARCHAR(50),
    exception_message VARCHAR(500),
    check_date DATETIME2 DEFAULT GETDATE(),
    resolved_date DATETIME2,
    resolved_by VARCHAR(50),
    resolution_notes VARCHAR(MAX)
);

-- Procedure to run all data quality checks
CREATE OR ALTER PROCEDURE stg.RunDataQualityChecks
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @CheckName VARCHAR(100)
    DECLARE @CheckDesc VARCHAR(500)

    -- Check 1: Invalid surgery dates
    SET @CheckName = 'InvalidSurgeryDates'
    SET @CheckDesc = 'Surgery dates that are in the past or too far in future'
    
    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'ORCase',
        case_id,
        'surgery_date',
        CONVERT(VARCHAR, surgery_date, 120),
        'InvalidValue',
        'Surgery date ' + CONVERT(VARCHAR, surgery_date, 120) + ' is invalid'
    FROM stg.ORCase
    WHERE surgery_date < GETDATE()
    OR surgery_date > DATEADD(YEAR, 2, GETDATE());

    -- Check 2: Missing required fields
    SET @CheckName = 'MissingRequiredFields'
    SET @CheckDesc = 'Required fields that are null or empty'

    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
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
    FROM stg.ORCase
    WHERE patient_id IS NULL
    OR room_id IS NULL
    OR location_id IS NULL
    OR primary_surgeon_prov_id IS NULL;

    -- Check 3: Invalid time sequences
    SET @CheckName = 'InvalidTimeSequence'
    SET @CheckDesc = 'Case times that are not in logical sequence'

    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'ORCase',
        case_id,
        'case_times',
        CONVERT(VARCHAR, or_in, 120) + ' to ' + CONVERT(VARCHAR, or_out, 120),
        'InvalidSequence',
        'Case times are not in logical sequence'
    FROM stg.ORCase
    WHERE (or_in > or_out)
    OR (proc_start > proc_end)
    OR (anes_start > anes_end)
    OR (preop_in > preop_out);

    -- Check 4: Overlapping blocks
    SET @CheckName = 'OverlappingBlocks'
    SET @CheckDesc = 'Block times that overlap for the same room'

    WITH OverlappingBlocks AS (
        SELECT 
            b1.room_id,
            b1.schedule_date,
            b1.slot_start_time,
            b1.slot_end_time,
            b2.slot_start_time as overlapping_start,
            b2.slot_end_time as overlapping_end
        FROM stg.BlockSchedule b1
        INNER JOIN stg.BlockSchedule b2 
            ON b1.room_id = b2.room_id
            AND b1.schedule_date = b2.schedule_date
            AND b1.slot_start_time < b2.slot_end_time
            AND b1.slot_end_time > b2.slot_start_time
            AND b1.slot_start_time != b2.slot_start_time
    )
    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'BlockSchedule',
        room_id + '|' + CONVERT(VARCHAR, schedule_date, 120),
        'block_times',
        CONVERT(VARCHAR, slot_start_time, 108) + ' - ' + CONVERT(VARCHAR, slot_end_time, 108),
        'Overlap',
        'Block times overlap with another block'
    FROM OverlappingBlocks;

    -- Check 5: Unusual case durations
    SET @CheckName = 'UnusualCaseDuration'
    SET @CheckDesc = 'Cases with unusually short or long durations'

    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'ORCase',
        case_id,
        'case_duration',
        CAST(DATEDIFF(MINUTE, or_in, or_out) AS VARCHAR),
        'OutOfRange',
        'Case duration is outside normal range'
    FROM stg.ORCase
    WHERE DATEDIFF(MINUTE, or_in, or_out) < 15  -- Unusually short
    OR DATEDIFF(MINUTE, or_in, or_out) > 720;   -- Unusually long (12 hours)

    -- Check 6: Room utilization outliers
    SET @CheckName = 'RoomUtilizationOutlier'
    SET @CheckDesc = 'Rooms with unusually high or low utilization'

    WITH RoomStats AS (
        SELECT
            room_id,
            surgery_date,
            SUM(DATEDIFF(MINUTE, or_in, or_out)) as total_minutes,
            COUNT(*) as case_count
        FROM stg.ORCase
        WHERE surgery_date BETWEEN @StartDate AND @EndDate
        GROUP BY room_id, surgery_date
    )
    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'ORCase',
        room_id + '|' + CONVERT(VARCHAR, surgery_date, 120),
        'daily_utilization',
        CAST(total_minutes AS VARCHAR),
        'OutOfRange',
        CASE 
            WHEN total_minutes > 720 THEN 'Unusually high utilization'
            WHEN total_minutes < 120 AND case_count > 0 THEN 'Unusually low utilization'
        END
    FROM RoomStats
    WHERE total_minutes > 720  -- More than 12 hours
    OR (total_minutes < 120 AND case_count > 0);  -- Less than 2 hours but has cases

    -- Check 7: Block utilization anomalies
    SET @CheckName = 'BlockUtilizationAnomaly'
    SET @CheckDesc = 'Blocks with unusual utilization patterns'

    WITH BlockStats AS (
        SELECT
            b.room_id,
            b.schedule_date,
            b.service_code,
            DATEDIFF(MINUTE, b.slot_start_time, b.slot_end_time) as block_minutes,
            COUNT(c.case_id) as case_count,
            SUM(DATEDIFF(MINUTE, c.or_in, c.or_out)) as used_minutes
        FROM stg.BlockSchedule b
        LEFT JOIN stg.ORCase c ON 
            b.schedule_date = c.surgery_date
            AND b.room_id = c.room_id
            AND c.or_in BETWEEN b.slot_start_time AND b.slot_end_time
        WHERE b.schedule_date BETWEEN @StartDate AND @EndDate
        GROUP BY 
            b.room_id,
            b.schedule_date,
            b.service_code,
            b.slot_start_time,
            b.slot_end_time
    )
    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'BlockSchedule',
        room_id + '|' + CONVERT(VARCHAR, schedule_date, 120),
        'utilization_rate',
        CAST(CAST(used_minutes AS DECIMAL(10,2))/block_minutes * 100 AS VARCHAR) + '%',
        'OutOfRange',
        CASE 
            WHEN used_minutes > block_minutes * 1.2 THEN 'Utilization significantly over block time'
            WHEN used_minutes < block_minutes * 0.2 THEN 'Very low block utilization'
        END
    FROM BlockStats
    WHERE used_minutes > block_minutes * 1.2  -- Over 120% utilization
    OR (used_minutes < block_minutes * 0.2 AND case_count > 0);  -- Under 20% utilization with cases

    -- Check 8: Turnover time anomalies
    SET @CheckName = 'TurnoverTimeAnomaly'
    SET @CheckDesc = 'Unusual turnover times between cases'

    WITH CasePairs AS (
        SELECT
            c1.case_id,
            c1.room_id,
            c1.surgery_date,
            c1.or_out as first_case_end,
            c2.or_in as next_case_start,
            DATEDIFF(MINUTE, c1.or_out, c2.or_in) as turnover_time
        FROM stg.ORCase c1
        INNER JOIN stg.ORCase c2 ON
            c1.room_id = c2.room_id
            AND c1.surgery_date = c2.surgery_date
            AND c2.or_in > c1.or_out
            AND NOT EXISTS (
                SELECT 1 FROM stg.ORCase c3
                WHERE c3.room_id = c1.room_id
                AND c3.surgery_date = c1.surgery_date
                AND c3.or_in > c1.or_out
                AND c3.or_in < c2.or_in
            )
    )
    INSERT INTO stg.DataQualityException (
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
        @CheckName,
        @CheckDesc,
        'ORCase',
        case_id,
        'turnover_time',
        CAST(turnover_time AS VARCHAR),
        'OutOfRange',
        CASE 
            WHEN turnover_time > 90 THEN 'Unusually long turnover'
            WHEN turnover_time < 15 THEN 'Unusually short turnover'
        END
    FROM CasePairs
    WHERE turnover_time > 90  -- More than 90 minutes
    OR turnover_time < 15;    -- Less than 15 minutes

    -- Generate summary report
    SELECT
        check_name,
        COUNT(*) as exception_count,
        MIN(check_date) as first_occurrence,
        MAX(check_date) as last_occurrence
    FROM stg.DataQualityException
    WHERE check_date >= DATEADD(DAY, -1, GETDATE())
    GROUP BY check_name
    ORDER BY exception_count DESC;
END;
GO

-- Procedure to resolve data quality exceptions
CREATE OR ALTER PROCEDURE stg.ResolveDataQualityException
    @ExceptionId BIGINT,
    @ResolvedBy VARCHAR(50),
    @ResolutionNotes VARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE stg.DataQualityException
    SET 
        resolved_date = GETDATE(),
        resolved_by = @ResolvedBy,
        resolution_notes = @ResolutionNotes
    WHERE exception_id = @ExceptionId;
END;
GO

-- Create view for unresolved exceptions
CREATE OR ALTER VIEW stg.UnresolvedExceptions
AS
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
FROM stg.DataQualityException
WHERE resolved_date IS NULL;



