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
