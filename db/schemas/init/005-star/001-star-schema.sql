/*
Description: Initialize star schema for analytics
Dependencies: 001-schemas.sql, 004-prod/001-prod-tables.sql
Author: System
Date: 2024-02-03

This script creates the star schema with:
1. Dimension tables
2. Fact tables
3. Materialized views for common analytics
*/

BEGIN;

-- Verify schema dependencies
SELECT check_schema_dependencies('star', '005-star/001-star-schema.sql');

-- Time dimension
CREATE TABLE IF NOT EXISTS star.dim_date (
    date_key INTEGER PRIMARY KEY,  -- YYYYMMDD format
    date_actual DATE NOT NULL,
    year INTEGER NOT NULL,
    quarter INTEGER NOT NULL,
    month INTEGER NOT NULL,
    week INTEGER NOT NULL,
    day_of_week INTEGER NOT NULL,
    day_of_month INTEGER NOT NULL,
    day_of_year INTEGER NOT NULL,
    is_weekday BOOLEAN NOT NULL,
    is_holiday BOOLEAN NOT NULL,
    holiday_name VARCHAR(50),
    fiscal_year INTEGER NOT NULL,
    fiscal_quarter INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date_actual)
);

-- Location dimension
CREATE TABLE IF NOT EXISTS star.dim_location (
    location_key SERIAL PRIMARY KEY,
    location_id INTEGER NOT NULL,  -- Reference to prod.locations
    location_name VARCHAR(100) NOT NULL,
    location_type VARCHAR(50),
    region VARCHAR(50),
    address JSONB,
    is_active BOOLEAN DEFAULT true,
    valid_from TIMESTAMP NOT NULL,
    valid_to TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(location_id, valid_from)
);

-- Room dimension
CREATE TABLE IF NOT EXISTS star.dim_room (
    room_key SERIAL PRIMARY KEY,
    room_id INTEGER NOT NULL,  -- Reference to prod.rooms
    location_key INTEGER REFERENCES star.dim_location(location_key),
    room_name VARCHAR(100) NOT NULL,
    room_type VARCHAR(50),
    capabilities JSONB,
    is_active BOOLEAN DEFAULT true,
    valid_from TIMESTAMP NOT NULL,
    valid_to TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(room_id, valid_from)
);

-- Provider dimension
CREATE TABLE IF NOT EXISTS star.dim_provider (
    provider_key SERIAL PRIMARY KEY,
    provider_id INTEGER NOT NULL,  -- Reference to prod.providers
    provider_name VARCHAR(100) NOT NULL,
    provider_type VARCHAR(50),
    specialties TEXT[],
    credentials JSONB,
    is_active BOOLEAN DEFAULT true,
    valid_from TIMESTAMP NOT NULL,
    valid_to TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(provider_id, valid_from)
);

-- Service dimension
CREATE TABLE IF NOT EXISTS star.dim_service (
    service_key SERIAL PRIMARY KEY,
    service_id INTEGER NOT NULL,  -- Reference to prod.services
    service_name VARCHAR(100) NOT NULL,
    service_code VARCHAR(50),
    service_type VARCHAR(50),
    specialty VARCHAR(50),
    is_active BOOLEAN DEFAULT true,
    valid_from TIMESTAMP NOT NULL,
    valid_to TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(service_id, valid_from)
);

-- Case fact table
CREATE TABLE IF NOT EXISTS star.fact_cases (
    case_key SERIAL PRIMARY KEY,
    case_id INTEGER NOT NULL,  -- Reference to prod.cases
    date_key INTEGER REFERENCES star.dim_date(date_key),
    location_key INTEGER REFERENCES star.dim_location(location_key),
    room_key INTEGER REFERENCES star.dim_room(room_key),
    provider_key INTEGER REFERENCES star.dim_provider(provider_key),
    service_key INTEGER REFERENCES star.dim_service(service_key),
    scheduled_duration_minutes INTEGER,
    actual_duration_minutes INTEGER,
    turnover_minutes INTEGER,
    case_status VARCHAR(50),
    cancellation_reason VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(case_id)
);

-- Room utilization fact table
CREATE TABLE IF NOT EXISTS star.fact_room_utilization (
    utilization_key SERIAL PRIMARY KEY,
    date_key INTEGER REFERENCES star.dim_date(date_key),
    room_key INTEGER REFERENCES star.dim_room(room_key),
    total_minutes INTEGER NOT NULL DEFAULT 0,
    utilized_minutes INTEGER NOT NULL DEFAULT 0,
    blocked_minutes INTEGER NOT NULL DEFAULT 0,
    turnover_minutes INTEGER NOT NULL DEFAULT 0,
    case_count INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date_key, room_key)
);

-- Provider utilization fact table
CREATE TABLE IF NOT EXISTS star.fact_provider_utilization (
    utilization_key SERIAL PRIMARY KEY,
    date_key INTEGER REFERENCES star.dim_date(date_key),
    provider_key INTEGER REFERENCES star.dim_provider(provider_key),
    service_key INTEGER REFERENCES star.dim_service(service_key),
    total_minutes INTEGER NOT NULL DEFAULT 0,
    utilized_minutes INTEGER NOT NULL DEFAULT 0,
    case_count INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date_key, provider_key, service_key)
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_dim_date_actual ON star.dim_date(date_actual);
CREATE INDEX IF NOT EXISTS idx_dim_date_year_month ON star.dim_date(year, month);

CREATE INDEX IF NOT EXISTS idx_dim_location_id ON star.dim_location(location_id);
CREATE INDEX IF NOT EXISTS idx_dim_location_valid ON star.dim_location(valid_from, valid_to);

CREATE INDEX IF NOT EXISTS idx_dim_room_id ON star.dim_room(room_id);
CREATE INDEX IF NOT EXISTS idx_dim_room_valid ON star.dim_room(valid_from, valid_to);

CREATE INDEX IF NOT EXISTS idx_dim_provider_id ON star.dim_provider(provider_id);
CREATE INDEX IF NOT EXISTS idx_dim_provider_valid ON star.dim_provider(valid_from, valid_to);

CREATE INDEX IF NOT EXISTS idx_dim_service_id ON star.dim_service(service_id);
CREATE INDEX IF NOT EXISTS idx_dim_service_valid ON star.dim_service(valid_from, valid_to);

CREATE INDEX IF NOT EXISTS idx_fact_cases_date ON star.fact_cases(date_key);
CREATE INDEX IF NOT EXISTS idx_fact_cases_location ON star.fact_cases(location_key);
CREATE INDEX IF NOT EXISTS idx_fact_cases_room ON star.fact_cases(room_key);
CREATE INDEX IF NOT EXISTS idx_fact_cases_provider ON star.fact_cases(provider_key);
CREATE INDEX IF NOT EXISTS idx_fact_cases_service ON star.fact_cases(service_key);

CREATE INDEX IF NOT EXISTS idx_fact_room_util_date ON star.fact_room_utilization(date_key);
CREATE INDEX IF NOT EXISTS idx_fact_room_util_room ON star.fact_room_utilization(room_key);

CREATE INDEX IF NOT EXISTS idx_fact_provider_util_date ON star.fact_provider_utilization(date_key);
CREATE INDEX IF NOT EXISTS idx_fact_provider_util_provider ON star.fact_provider_utilization(provider_key);
CREATE INDEX IF NOT EXISTS idx_fact_provider_util_service ON star.fact_provider_utilization(service_key);

-- Create materialized views for common analytics
CREATE MATERIALIZED VIEW IF NOT EXISTS star.mv_daily_utilization AS
SELECT 
    d.date_actual,
    d.year,
    d.month,
    d.quarter,
    l.location_name,
    r.room_name,
    ru.total_minutes,
    ru.utilized_minutes,
    ru.blocked_minutes,
    ru.turnover_minutes,
    ru.case_count,
    CASE 
        WHEN ru.total_minutes > 0 
        THEN ROUND((ru.utilized_minutes::float / ru.total_minutes) * 100, 2)
        ELSE 0 
    END as utilization_percentage
FROM star.fact_room_utilization ru
JOIN star.dim_date d ON ru.date_key = d.date_key
JOIN star.dim_room r ON ru.room_key = r.room_key
JOIN star.dim_location l ON r.location_key = l.location_key
WHERE r.is_active = true
WITH NO DATA;

CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_daily_util 
ON star.mv_daily_utilization(date_actual, room_name);

-- Log this migration
SELECT log_migration_execution(
    'star',
    '005-star/001-star-schema.sql',
    'init',
    'completed',
    NULL,
    ARRAY['star.dim_date', 'star.dim_location', 'star.dim_room', 
          'star.dim_provider', 'star.dim_service', 'star.fact_cases',
          'star.fact_room_utilization', 'star.fact_provider_utilization',
          'star.mv_daily_utilization']
);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
-- Drop materialized views
DROP MATERIALIZED VIEW IF EXISTS star.mv_daily_utilization;

-- Drop fact tables
DROP TABLE IF EXISTS star.fact_provider_utilization;
DROP TABLE IF EXISTS star.fact_room_utilization;
DROP TABLE IF EXISTS star.fact_cases;

-- Drop dimension tables
DROP TABLE IF EXISTS star.dim_service;
DROP TABLE IF EXISTS star.dim_provider;
DROP TABLE IF EXISTS star.dim_room;
DROP TABLE IF EXISTS star.dim_location;
DROP TABLE IF EXISTS star.dim_date;

COMMIT;
*/
