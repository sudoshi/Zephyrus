-------------------------------------------------------------------------------
-- 1. ANALYTICAL TABLES
-------------------------------------------------------------------------------

-- CaseMetrics
CREATE TABLE prod.casemetrics (
    case_id                   BIGINT PRIMARY KEY,
    turnover_time             INT,          -- in minutes
    utilization_percentage    DECIMAL(5,2),
    in_block_time             INT,          -- in minutes
    out_of_block_time         INT,          -- in minutes
    prime_time_minutes        INT,
    non_prime_time_minutes    INT,
    late_start_minutes        INT,
    early_finish_minutes      INT,
    total_case_minutes        INT,
    total_anesthesia_minutes  INT,
    total_procedure_minutes   INT,
    preop_minutes             INT,
    pacu_minutes              INT,
    CONSTRAINT fk_casemetrics_case
        FOREIGN KEY (case_id) REFERENCES prod.orcase(case_id)
)
INHERITS (prod.basetable);

-- BlockUtilization
CREATE TABLE prod.blockutilization (
    block_utilization_id   BIGSERIAL PRIMARY KEY,
    block_id               BIGINT NOT NULL,
    utilization_date       DATE   NOT NULL,
    service_id             BIGINT NOT NULL,
    location_id            BIGINT NOT NULL,
    scheduled_minutes      INT    NOT NULL,
    actual_minutes         INT    NOT NULL,
    utilization_percentage DECIMAL(5,2) NOT NULL,
    cases_scheduled        INT    NOT NULL,
    cases_performed        INT    NOT NULL,
    prime_time_percentage  DECIMAL(5,2) NOT NULL,
    non_prime_time_percentage DECIMAL(5,2) NOT NULL,
    released_minutes       INT,
    exchanged_minutes      INT,
    CONSTRAINT fk_blockutil_block
        FOREIGN KEY (block_id) REFERENCES prod.blocktemplate(block_id),
    CONSTRAINT fk_blockutil_service
        FOREIGN KEY (service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blockutil_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_blockutilization_date     ON prod.blockutilization(utilization_date);
CREATE INDEX ix_blockutilization_service  ON prod.blockutilization(service_id);
CREATE INDEX ix_blockutilization_location ON prod.blockutilization(location_id);

-- RoomUtilization
CREATE TABLE prod.roomutilization (
    room_utilization_id    BIGSERIAL PRIMARY KEY,
    room_id                BIGINT NOT NULL,
    utilization_date       DATE   NOT NULL,
    available_minutes      INT    NOT NULL,
    utilized_minutes       INT    NOT NULL,
    turnover_minutes       INT    NOT NULL,
    block_minutes          INT    NOT NULL,
    open_minutes           INT    NOT NULL,
    utilization_percentage DECIMAL(5,2) NOT NULL,
    cases_performed        INT    NOT NULL,
    avg_case_duration      DECIMAL(10,2) NOT NULL,
    avg_turnover_time      DECIMAL(10,2) NOT NULL,
    prime_time_utilization_percentage DECIMAL(5,2) NOT NULL,
    first_case_ontime_percentage DECIMAL(5,2),
    CONSTRAINT fk_roomutilization_room
        FOREIGN KEY (room_id) REFERENCES prod.room(room_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_roomutilization_date ON prod.roomutilization(utilization_date);
CREATE INDEX ix_roomutilization_room ON prod.roomutilization(room_id);

-- ServiceUtilization
CREATE TABLE prod.serviceutilization (
    service_utilization_id  BIGSERIAL PRIMARY KEY,
    service_id              BIGINT NOT NULL,
    location_id             BIGINT NOT NULL,
    utilization_date        DATE   NOT NULL,
    total_block_minutes     INT    NOT NULL,
    used_block_minutes      INT    NOT NULL,
    block_utilization_percentage DECIMAL(5,2) NOT NULL,
    cases_in_block          INT    NOT NULL,
    cases_out_of_block      INT    NOT NULL,
    prime_time_percentage   DECIMAL(5,2) NOT NULL,
    avg_case_duration       DECIMAL(10,2) NOT NULL,
    total_cases             INT    NOT NULL,
    cancelled_cases         INT,
    CONSTRAINT fk_serviceutil_service
        FOREIGN KEY (service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_serviceutil_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_serviceutilization_date     ON prod.serviceutilization(utilization_date);
CREATE INDEX ix_serviceutilization_service  ON prod.serviceutilization(service_id);
CREATE INDEX ix_serviceutilization_location ON prod.serviceutilization(location_id);

-- SurgeonMetrics
CREATE TABLE prod.surgeonmetrics (
    surgeon_metric_id            BIGSERIAL PRIMARY KEY,
    provider_id                  BIGINT NOT NULL,
    location_id                  BIGINT NOT NULL,
    metric_date                  DATE   NOT NULL,
    total_cases                  INT    NOT NULL,
    total_minutes                INT    NOT NULL,
    avg_case_duration            DECIMAL(10,2) NOT NULL,
    block_utilization_percentage DECIMAL(5,2),
    first_case_ontime_percentage DECIMAL(5,2),
    turnover_time_avg            DECIMAL(10,2),
    cancellation_rate            DECIMAL(5,2),
    cases_in_block               INT,
    cases_out_of_block           INT,
    CONSTRAINT fk_surgeonmetrics_provider
        FOREIGN KEY (provider_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_surgeonmetrics_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_surgeonmetrics_date     ON prod.surgeonmetrics(metric_date);
CREATE INDEX ix_surgeonmetrics_provider ON prod.surgeonmetrics(provider_id);
CREATE INDEX ix_surgeonmetrics_location ON prod.surgeonmetrics(location_id);

-- DailyMetrics
CREATE TABLE prod.dailymetrics (
    daily_metric_id           BIGSERIAL PRIMARY KEY,
    location_id               BIGINT NOT NULL,
    metric_date               DATE   NOT NULL,
    total_cases               INT    NOT NULL,
    completed_cases           INT    NOT NULL,
    cancelled_cases           INT    NOT NULL,
    total_minutes_scheduled   INT    NOT NULL,
    total_minutes_actual      INT    NOT NULL,
    prime_time_utilization    DECIMAL(5,2) NOT NULL,
    overall_utilization       DECIMAL(5,2) NOT NULL,
    first_case_ontime_percentage DECIMAL(5,2) NOT NULL,
    avg_turnover_time         DECIMAL(10,2) NOT NULL,
    emergency_cases           INT,
    add_on_cases              INT,
    block_utilization         DECIMAL(5,2),
    CONSTRAINT fk_dailymetrics_location
        FOREIGN KEY (location_id) REFERENCES prod.location(location_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_dailymetrics_date     ON prod.dailymetrics(metric_date);
CREATE INDEX ix_dailymetrics_location ON prod.dailymetrics(location_id);

-- BlockTransaction
CREATE TABLE prod.blocktransaction (
    block_transaction_id  BIGSERIAL PRIMARY KEY,
    block_id              BIGINT NOT NULL,
    transaction_date      DATE   NOT NULL,
    transaction_type      VARCHAR(50) NOT NULL, -- e.g., 'RELEASE', 'EXCHANGE'
    minutes_affected      INT    NOT NULL,
    from_service_id       BIGINT NOT NULL,
    to_service_id         BIGINT,        -- NULL for releases
    from_surgeon_id       BIGINT,
    to_surgeon_id         BIGINT,
    release_hours_notice  INT,
    comments              VARCHAR(500),
    CONSTRAINT fk_blocktransaction_block
        FOREIGN KEY (block_id) REFERENCES prod.blocktemplate(block_id),
    CONSTRAINT fk_blocktransaction_from_service
        FOREIGN KEY (from_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktransaction_to_service
        FOREIGN KEY (to_service_id) REFERENCES prod.service(service_id),
    CONSTRAINT fk_blocktransaction_from_surgeon
        FOREIGN KEY (from_surgeon_id) REFERENCES prod.provider(provider_id),
    CONSTRAINT fk_blocktransaction_to_surgeon
        FOREIGN KEY (to_surgeon_id) REFERENCES prod.provider(provider_id)
)
INHERITS (prod.basetable);

CREATE INDEX ix_blocktransaction_date ON prod.blocktransaction(transaction_date);
CREATE INDEX ix_blocktransaction_block ON prod.blocktransaction(block_id);

-------------------------------------------------------------------------------
-- 2. VIEWS
-------------------------------------------------------------------------------
-- NOTE: We replace T-SQL DATEDIFF with PostgreSQL expressions.
--       (end_time - start_time) gives an interval; 
--       EXTRACT(EPOCH FROM interval) / 60 returns minutes as a float.
--       "::int" casts to integer.

-- vw_CaseTimings
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
    (EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60)::int AS total_or_minutes,
    (EXTRACT(EPOCH FROM (l.anesthesia_end_time - l.anesthesia_start_time)) / 60)::int AS total_anesthesia_minutes,
    (EXTRACT(EPOCH FROM (l.procedure_end_time - l.procedure_start_time)) / 60)::int AS total_procedure_minutes,
    c.scheduled_duration AS scheduled_minutes,
    cm.turnover_time,
    cm.in_block_time,
    cm.out_of_block_time
FROM prod.orcase c
    INNER JOIN prod.orlog l ON c.case_id = l.case_id
    LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = FALSE;

-- vw_BlockUtilization
CREATE OR REPLACE VIEW prod.vw_blockutilization AS
SELECT
    bt.block_id,
    bt.block_date,
    bt.room_id,
    bt.service_id,
    bt.surgeon_id,
    r.location_id,
    (EXTRACT(EPOCH FROM (bt.end_time - bt.start_time)) / 60)::int AS total_block_minutes,
    COUNT(c.case_id) AS scheduled_cases,
    SUM(c.scheduled_duration) AS scheduled_minutes,
    SUM(cm.in_block_time) AS utilized_minutes,
    SUM(cm.out_of_block_time) AS out_of_block_minutes,
    SUM(cm.prime_time_minutes) AS prime_time_minutes,
    SUM(cm.non_prime_time_minutes) AS non_prime_time_minutes
FROM prod.blocktemplate bt
    INNER JOIN prod.room r ON bt.room_id = r.room_id
    INNER JOIN prod.location l ON r.location_id = l.location_id
    LEFT JOIN prod.orcase c 
        ON c.surgery_date = bt.block_date
       AND c.room_id = bt.room_id
       AND c.is_deleted = FALSE
    LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE bt.is_deleted = FALSE
GROUP BY
    bt.block_id,
    bt.block_date,
    bt.room_id,
    bt.service_id,
    bt.surgeon_id,
    r.location_id;

-- vw_SurgeonPerformance
CREATE OR REPLACE VIEW prod.vw_surgeonperformance AS
SELECT
    c.primary_surgeon_id,
    p.name AS surgeon_name,
    c.location_id,
    c.surgery_date,
    COUNT(c.case_id) AS total_cases,
    AVG((EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60))::int AS avg_case_duration,
    AVG(cm.turnover_time) AS avg_turnover_time,
    SUM(CASE WHEN cm.late_start_minutes > 0 THEN 1 ELSE 0 END) AS late_starts,
    SUM(cm.in_block_time) AS total_block_minutes_used,
    SUM(cm.prime_time_minutes) AS prime_time_minutes,
    SUM(CASE WHEN c.cancellation_reason_id IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_cases
FROM prod.orcase c
    INNER JOIN prod.provider p ON c.primary_surgeon_id = p.provider_id
    INNER JOIN prod.orlog l ON c.case_id = l.case_id
    LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = FALSE
GROUP BY
    c.primary_surgeon_id,
    p.name,
    c.location_id,
    c.surgery_date;

-- vw_ServiceUtilization
CREATE OR REPLACE VIEW prod.vw_serviceutilization AS
SELECT
    c.case_service_id,
    s.name AS service_name,
    c.location_id,
    l.name AS location_name,
    c.surgery_date,
    COUNT(c.case_id) AS total_cases,
    SUM(c.scheduled_duration) AS scheduled_minutes,
    SUM((EXTRACT(EPOCH FROM (log.or_out_time - log.or_in_time)) / 60))::int AS actual_minutes,
    AVG((EXTRACT(EPOCH FROM (log.or_out_time - log.or_in_time)) / 60))::int AS avg_case_duration,
    SUM(cm.prime_time_minutes) AS prime_time_minutes,
    SUM(cm.non_prime_time_minutes) AS non_prime_time_minutes,
    SUM(CASE WHEN c.cancellation_reason_id IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_cases,
    SUM(cm.in_block_time) AS block_minutes_used
FROM prod.orcase c
    INNER JOIN prod.service s ON c.case_service_id = s.service_id
    INNER JOIN prod.location l ON c.location_id = l.location_id
    INNER JOIN prod.orlog log ON c.case_id = log.case_id
    LEFT JOIN prod.casemetrics cm ON c.case_id = cm.case_id
WHERE c.is_deleted = FALSE
GROUP BY
    c.case_service_id,
    s.name,
    c.location_id,
    l.name,
    c.surgery_date;

