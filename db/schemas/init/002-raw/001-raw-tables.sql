/*
Description: Initialize raw schema tables for data imports
Dependencies: 001-schemas.sql
Author: System
Date: 2024-02-03

This script creates tables in the raw schema for:
1. Data source tracking
2. Import history
3. Raw data staging tables
*/

BEGIN;

-- Verify schema dependency
SELECT check_schema_dependencies('raw', '002-raw/001-raw-tables.sql');

-- Track data sources
CREATE TABLE IF NOT EXISTS raw.data_sources (
    source_id SERIAL PRIMARY KEY,
    source_name VARCHAR(255) NOT NULL,
    source_type VARCHAR(50) NOT NULL,  -- 'file', 'api', 'database'
    connection_details JSONB,
    active_status BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    modified_by VARCHAR(255),
    UNIQUE(source_name)
);

-- Track import history
CREATE TABLE IF NOT EXISTS raw.import_history (
    import_id SERIAL PRIMARY KEY,
    source_id INTEGER REFERENCES raw.data_sources(source_id),
    import_type VARCHAR(50) NOT NULL,  -- 'full', 'incremental'
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    status VARCHAR(50) NOT NULL,  -- 'running', 'completed', 'failed'
    record_count INTEGER,
    error_message TEXT,
    import_metadata JSONB,
    created_by VARCHAR(255)
);

-- Raw data staging tables
CREATE TABLE IF NOT EXISTS raw.staging_locations (
    staging_id SERIAL PRIMARY KEY,
    import_id INTEGER REFERENCES raw.import_history(import_id),
    source_id INTEGER REFERENCES raw.data_sources(source_id),
    raw_data JSONB NOT NULL,
    processed BOOLEAN DEFAULT false,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS raw.staging_rooms (
    staging_id SERIAL PRIMARY KEY,
    import_id INTEGER REFERENCES raw.import_history(import_id),
    source_id INTEGER REFERENCES raw.data_sources(source_id),
    raw_data JSONB NOT NULL,
    processed BOOLEAN DEFAULT false,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS raw.staging_providers (
    staging_id SERIAL PRIMARY KEY,
    import_id INTEGER REFERENCES raw.import_history(import_id),
    source_id INTEGER REFERENCES raw.data_sources(source_id),
    raw_data JSONB NOT NULL,
    processed BOOLEAN DEFAULT false,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS raw.staging_cases (
    staging_id SERIAL PRIMARY KEY,
    import_id INTEGER REFERENCES raw.import_history(import_id),
    source_id INTEGER REFERENCES raw.data_sources(source_id),
    raw_data JSONB NOT NULL,
    processed BOOLEAN DEFAULT false,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_import_history_source_id ON raw.import_history(source_id);
CREATE INDEX IF NOT EXISTS idx_import_history_status ON raw.import_history(status);
CREATE INDEX IF NOT EXISTS idx_import_history_dates ON raw.import_history(started_at, completed_at);

CREATE INDEX IF NOT EXISTS idx_staging_locations_import ON raw.staging_locations(import_id);
CREATE INDEX IF NOT EXISTS idx_staging_locations_processed ON raw.staging_locations(processed);

CREATE INDEX IF NOT EXISTS idx_staging_rooms_import ON raw.staging_rooms(import_id);
CREATE INDEX IF NOT EXISTS idx_staging_rooms_processed ON raw.staging_rooms(processed);

CREATE INDEX IF NOT EXISTS idx_staging_providers_import ON raw.staging_providers(import_id);
CREATE INDEX IF NOT EXISTS idx_staging_providers_processed ON raw.staging_providers(processed);

CREATE INDEX IF NOT EXISTS idx_staging_cases_import ON raw.staging_cases(import_id);
CREATE INDEX IF NOT EXISTS idx_staging_cases_processed ON raw.staging_cases(processed);

-- Function to update modified_at timestamp
CREATE OR REPLACE FUNCTION raw.update_modified_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.modified_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Add triggers for modified_at updates
CREATE TRIGGER update_data_sources_modified_at
    BEFORE UPDATE ON raw.data_sources
    FOR EACH ROW
    EXECUTE FUNCTION raw.update_modified_at();

-- Log this migration
SELECT log_migration_execution(
    'raw',
    '002-raw/001-raw-tables.sql',
    'init',
    'completed',
    NULL,
    ARRAY['raw.data_sources', 'raw.import_history', 'raw.staging_locations', 
          'raw.staging_rooms', 'raw.staging_providers', 'raw.staging_cases']
);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
-- Drop triggers first
DROP TRIGGER IF EXISTS update_data_sources_modified_at ON raw.data_sources;

-- Drop functions
DROP FUNCTION IF EXISTS raw.update_modified_at();

-- Drop staging tables
DROP TABLE IF EXISTS raw.staging_cases;
DROP TABLE IF EXISTS raw.staging_providers;
DROP TABLE IF EXISTS raw.staging_rooms;
DROP TABLE IF EXISTS raw.staging_locations;

-- Drop tracking tables
DROP TABLE IF EXISTS raw.import_history;
DROP TABLE IF EXISTS raw.data_sources;

COMMIT;
*/
