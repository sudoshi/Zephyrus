/*
Description: Initialize staging schema tables for data transformation
Dependencies: 001-schemas.sql, 002-raw/001-raw-tables.sql
Author: System
Date: 2024-02-03

This script creates tables in the staging schema for:
1. Standardized data structures
2. Validation results
3. Transformation tracking
*/

BEGIN;

-- Verify schema dependencies
SELECT check_schema_dependencies('stg', '003-stg/001-staging-tables.sql');

-- Track data transformations
CREATE TABLE IF NOT EXISTS stg.transformation_jobs (
    job_id SERIAL PRIMARY KEY,
    raw_import_id INTEGER REFERENCES raw.import_history(import_id),
    job_type VARCHAR(50) NOT NULL,  -- 'validation', 'transformation', 'enrichment'
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    status VARCHAR(50) NOT NULL,  -- 'running', 'completed', 'failed'
    error_message TEXT,
    job_metadata JSONB,
    created_by VARCHAR(255)
);

-- Validation results
CREATE TABLE IF NOT EXISTS stg.validation_results (
    result_id SERIAL PRIMARY KEY,
    job_id INTEGER REFERENCES stg.transformation_jobs(job_id),
    entity_type VARCHAR(50) NOT NULL,  -- 'location', 'room', 'provider', 'case'
    raw_id INTEGER NOT NULL,
    validation_type VARCHAR(50) NOT NULL,  -- 'format', 'reference', 'business_rule'
    is_valid BOOLEAN NOT NULL,
    error_code VARCHAR(50),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Standardized staging tables
CREATE TABLE IF NOT EXISTS stg.locations (
    staging_id SERIAL PRIMARY KEY,
    job_id INTEGER REFERENCES stg.transformation_jobs(job_id),
    raw_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50),
    type VARCHAR(50),
    address JSONB,
    metadata JSONB,
    is_valid BOOLEAN DEFAULT false,
    validation_errors JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stg.rooms (
    staging_id SERIAL PRIMARY KEY,
    job_id INTEGER REFERENCES stg.transformation_jobs(job_id),
    raw_id INTEGER NOT NULL,
    location_staging_id INTEGER REFERENCES stg.locations(staging_id),
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50),
    type VARCHAR(50),
    capabilities JSONB,
    metadata JSONB,
    is_valid BOOLEAN DEFAULT false,
    validation_errors JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stg.providers (
    staging_id SERIAL PRIMARY KEY,
    job_id INTEGER REFERENCES stg.transformation_jobs(job_id),
    raw_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    npi VARCHAR(20),
    type VARCHAR(50),
    specialties TEXT[],
    credentials JSONB,
    metadata JSONB,
    is_valid BOOLEAN DEFAULT false,
    validation_errors JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stg.cases (
    staging_id SERIAL PRIMARY KEY,
    job_id INTEGER REFERENCES stg.transformation_jobs(job_id),
    raw_id INTEGER NOT NULL,
    room_staging_id INTEGER REFERENCES stg.rooms(staging_id),
    provider_staging_id INTEGER REFERENCES stg.providers(staging_id),
    case_date DATE NOT NULL,
    case_type VARCHAR(50),
    status VARCHAR(50),
    procedure_data JSONB,
    scheduling_data JSONB,
    metadata JSONB,
    is_valid BOOLEAN DEFAULT false,
    validation_errors JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_transformation_jobs_import ON stg.transformation_jobs(raw_import_id);
CREATE INDEX IF NOT EXISTS idx_transformation_jobs_status ON stg.transformation_jobs(status);
CREATE INDEX IF NOT EXISTS idx_transformation_jobs_dates ON stg.transformation_jobs(started_at, completed_at);

CREATE INDEX IF NOT EXISTS idx_validation_results_job ON stg.validation_results(job_id);
CREATE INDEX IF NOT EXISTS idx_validation_results_entity ON stg.validation_results(entity_type, raw_id);
CREATE INDEX IF NOT EXISTS idx_validation_results_validity ON stg.validation_results(is_valid);

CREATE INDEX IF NOT EXISTS idx_locations_job ON stg.locations(job_id);
CREATE INDEX IF NOT EXISTS idx_locations_validity ON stg.locations(is_valid);

CREATE INDEX IF NOT EXISTS idx_rooms_job ON stg.rooms(job_id);
CREATE INDEX IF NOT EXISTS idx_rooms_validity ON stg.rooms(is_valid);
CREATE INDEX IF NOT EXISTS idx_rooms_location ON stg.rooms(location_staging_id);

CREATE INDEX IF NOT EXISTS idx_providers_job ON stg.providers(job_id);
CREATE INDEX IF NOT EXISTS idx_providers_validity ON stg.providers(is_valid);
CREATE INDEX IF NOT EXISTS idx_providers_npi ON stg.providers(npi);

CREATE INDEX IF NOT EXISTS idx_cases_job ON stg.cases(job_id);
CREATE INDEX IF NOT EXISTS idx_cases_validity ON stg.cases(is_valid);
CREATE INDEX IF NOT EXISTS idx_cases_date ON stg.cases(case_date);
CREATE INDEX IF NOT EXISTS idx_cases_room ON stg.cases(room_staging_id);
CREATE INDEX IF NOT EXISTS idx_cases_provider ON stg.cases(provider_staging_id);

-- Function to update modified_at timestamp
CREATE OR REPLACE FUNCTION stg.update_modified_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.modified_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Add triggers for modified_at updates
DO $$
DECLARE
    table_name text;
BEGIN
    FOR table_name IN SELECT unnest(ARRAY['locations', 'rooms', 'providers', 'cases'])
    LOOP
        EXECUTE format('
            CREATE TRIGGER update_%I_modified_at
                BEFORE UPDATE ON stg.%I
                FOR EACH ROW
                EXECUTE FUNCTION stg.update_modified_at()',
            table_name, table_name);
    END LOOP;
END $$;

-- Log this migration
SELECT log_migration_execution(
    'stg',
    '003-stg/001-staging-tables.sql',
    'init',
    'completed',
    NULL,
    ARRAY['stg.transformation_jobs', 'stg.validation_results', 
          'stg.locations', 'stg.rooms', 'stg.providers', 'stg.cases']
);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
-- Drop triggers first
DO $$
DECLARE
    table_name text;
BEGIN
    FOR table_name IN SELECT unnest(ARRAY['locations', 'rooms', 'providers', 'cases'])
    LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS update_%I_modified_at ON stg.%I',
            table_name, table_name);
    END LOOP;
END $$;

-- Drop functions
DROP FUNCTION IF EXISTS stg.update_modified_at();

-- Drop staging tables (in correct order)
DROP TABLE IF EXISTS stg.cases;
DROP TABLE IF EXISTS stg.providers;
DROP TABLE IF EXISTS stg.rooms;
DROP TABLE IF EXISTS stg.locations;

-- Drop validation tables
DROP TABLE IF EXISTS stg.validation_results;
DROP TABLE IF EXISTS stg.transformation_jobs;

COMMIT;
*/
