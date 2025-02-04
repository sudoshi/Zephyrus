/*
Description: Create metrics and logging tables for OR Analytics Platform
Dependencies: 001-schemas.sql, 002-reference-tables.sql, 003-core-tables.sql, 004-case-tables.sql
Author: System
Date: 2024-02-03
*/

BEGIN;

-- OR Logs (Audit trail for all operations)
CREATE TABLE IF NOT EXISTS prod.or_logs (
    log_id BIGSERIAL PRIMARY KEY,
    entity_type VARCHAR(255) NOT NULL,
    entity_id BIGINT NOT NULL,
    action VARCHAR(50) NOT NULL,
    old_values JSONB,
    new_values JSONB,
    log_timestamp TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    user_id VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT
);

-- Room Utilization Metrics
CREATE TABLE IF NOT EXISTS prod.room_utilization (
    metric_id BIGSERIAL PRIMARY KEY,
    room_id BIGINT NOT NULL,
    date DATE NOT NULL,
    total_minutes INT NOT NULL DEFAULT 0,
    utilized_minutes INT NOT NULL DEFAULT 0,
    blocked_minutes INT NOT NULL DEFAULT 0,
    turnover_minutes INT NOT NULL DEFAULT 0,
    case_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_room_utilization_room FOREIGN KEY (room_id) REFERENCES prod.rooms(room_id)
);

-- Block Utilization Metrics
CREATE TABLE IF NOT EXISTS prod.block_utilization (
    metric_id BIGSERIAL PRIMARY KEY,
    block_id BIGINT NOT NULL,
    date DATE NOT NULL,
    allocated_minutes INT NOT NULL DEFAULT 0,
    utilized_minutes INT NOT NULL DEFAULT 0,
    released_minutes INT NOT NULL DEFAULT 0,
    case_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_block_utilization_block FOREIGN KEY (block_id) REFERENCES prod.block_templates(block_id)
);

-- Case Metrics
CREATE TABLE IF NOT EXISTS prod.case_metrics (
    metric_id BIGSERIAL PRIMARY KEY,
    case_id BIGINT NOT NULL,
    metric_type VARCHAR(255) NOT NULL,
    metric_value DECIMAL(10,2) NOT NULL,
    metric_date DATE NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_case_metrics_case FOREIGN KEY (case_id) REFERENCES prod.or_cases(case_id)
);

-- Add indexes for commonly queried fields
CREATE INDEX idx_or_logs_entity ON prod.or_logs(entity_type, entity_id);
CREATE INDEX idx_or_logs_timestamp ON prod.or_logs(log_timestamp);
CREATE INDEX idx_or_logs_action ON prod.or_logs(action);

CREATE INDEX idx_room_utilization_room_date ON prod.room_utilization(room_id, date);
CREATE INDEX idx_room_utilization_date ON prod.room_utilization(date);

CREATE INDEX idx_block_utilization_block_date ON prod.block_utilization(block_id, date);
CREATE INDEX idx_block_utilization_date ON prod.block_utilization(date);

CREATE INDEX idx_case_metrics_case ON prod.case_metrics(case_id);
CREATE INDEX idx_case_metrics_type_date ON prod.case_metrics(metric_type, metric_date);

-- Add constraints for data integrity
ALTER TABLE prod.room_utilization 
    ADD CONSTRAINT check_room_utilization_minutes 
    CHECK (total_minutes >= 0 AND utilized_minutes >= 0 AND blocked_minutes >= 0 AND turnover_minutes >= 0);

ALTER TABLE prod.block_utilization 
    ADD CONSTRAINT check_block_utilization_minutes 
    CHECK (allocated_minutes >= 0 AND utilized_minutes >= 0 AND released_minutes >= 0);

ALTER TABLE prod.or_logs 
    ADD CONSTRAINT check_or_logs_action 
    CHECK (action IN ('CREATE', 'UPDATE', 'DELETE', 'RESTORE'));

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP TABLE IF EXISTS prod.case_metrics CASCADE;
DROP TABLE IF EXISTS prod.block_utilization CASCADE;
DROP TABLE IF EXISTS prod.room_utilization CASCADE;
DROP TABLE IF EXISTS prod.or_logs CASCADE;
COMMIT;
*/
