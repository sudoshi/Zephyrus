/*
Description: Initialize FHIR schema for healthcare data interoperability
Dependencies: 001-schemas.sql, 004-prod/001-prod-tables.sql
Author: System
Date: 2024-02-03

This script creates tables for:
1. FHIR resources
2. Resource mappings
3. FHIR operations history
*/

BEGIN;

-- Verify schema dependencies
SELECT check_schema_dependencies('fhir', '006-fhir/001-fhir-tables.sql');

-- Track FHIR resources
CREATE TABLE IF NOT EXISTS fhir.resources (
    resource_id SERIAL PRIMARY KEY,
    resource_type VARCHAR(50) NOT NULL,  -- 'Location', 'Practitioner', 'Schedule', etc.
    fhir_id VARCHAR(64) NOT NULL,  -- FHIR resource ID
    version_id VARCHAR(64) NOT NULL,  -- FHIR version ID
    status VARCHAR(50) NOT NULL,  -- 'active', 'retired', 'entered-in-error'
    last_updated TIMESTAMP NOT NULL,
    resource_data JSONB NOT NULL,  -- Full FHIR resource
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(255),
    UNIQUE(resource_type, fhir_id, version_id)
);

-- Track resource history
CREATE TABLE IF NOT EXISTS fhir.resource_history (
    history_id SERIAL PRIMARY KEY,
    resource_id INTEGER REFERENCES fhir.resources(resource_id),
    operation VARCHAR(50) NOT NULL,  -- 'create', 'update', 'delete'
    previous_version VARCHAR(64),
    new_version VARCHAR(64),
    changes JSONB,
    operation_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    performed_by VARCHAR(255)
);

-- Map internal locations to FHIR locations
CREATE TABLE IF NOT EXISTS fhir.location_mappings (
    mapping_id SERIAL PRIMARY KEY,
    internal_id INTEGER NOT NULL,  -- Reference to prod.locations
    fhir_resource_id INTEGER REFERENCES fhir.resources(resource_id),
    mapping_status VARCHAR(50) NOT NULL,  -- 'active', 'inactive'
    mapping_metadata JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(internal_id, fhir_resource_id)
);

-- Map internal providers to FHIR practitioners
CREATE TABLE IF NOT EXISTS fhir.practitioner_mappings (
    mapping_id SERIAL PRIMARY KEY,
    internal_id INTEGER NOT NULL,  -- Reference to prod.providers
    fhir_resource_id INTEGER REFERENCES fhir.resources(resource_id),
    mapping_status VARCHAR(50) NOT NULL,  -- 'active', 'inactive'
    mapping_metadata JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(internal_id, fhir_resource_id)
);

-- Map internal cases to FHIR schedules/appointments
CREATE TABLE IF NOT EXISTS fhir.schedule_mappings (
    mapping_id SERIAL PRIMARY KEY,
    internal_id INTEGER NOT NULL,  -- Reference to prod.cases
    fhir_resource_id INTEGER REFERENCES fhir.resources(resource_id),
    mapping_status VARCHAR(50) NOT NULL,  -- 'active', 'inactive'
    mapping_metadata JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(internal_id, fhir_resource_id)
);

-- Track FHIR operations
CREATE TABLE IF NOT EXISTS fhir.operations_log (
    operation_id SERIAL PRIMARY KEY,
    operation_type VARCHAR(50) NOT NULL,  -- 'read', 'search', 'create', 'update', 'delete'
    resource_type VARCHAR(50) NOT NULL,
    request_id VARCHAR(64),
    request_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    response_code INTEGER,
    error_details TEXT,
    operation_metadata JSONB,
    performed_by VARCHAR(255)
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_resources_type_id ON fhir.resources(resource_type, fhir_id);
CREATE INDEX IF NOT EXISTS idx_resources_updated ON fhir.resources(last_updated);
CREATE INDEX IF NOT EXISTS idx_resources_status ON fhir.resources(status);

CREATE INDEX IF NOT EXISTS idx_resource_history_resource ON fhir.resource_history(resource_id);
CREATE INDEX IF NOT EXISTS idx_resource_history_operation ON fhir.resource_history(operation);
CREATE INDEX IF NOT EXISTS idx_resource_history_timestamp ON fhir.resource_history(operation_timestamp);

CREATE INDEX IF NOT EXISTS idx_location_mappings_internal ON fhir.location_mappings(internal_id);
CREATE INDEX IF NOT EXISTS idx_location_mappings_fhir ON fhir.location_mappings(fhir_resource_id);
CREATE INDEX IF NOT EXISTS idx_location_mappings_status ON fhir.location_mappings(mapping_status);

CREATE INDEX IF NOT EXISTS idx_practitioner_mappings_internal ON fhir.practitioner_mappings(internal_id);
CREATE INDEX IF NOT EXISTS idx_practitioner_mappings_fhir ON fhir.practitioner_mappings(fhir_resource_id);
CREATE INDEX IF NOT EXISTS idx_practitioner_mappings_status ON fhir.practitioner_mappings(mapping_status);

CREATE INDEX IF NOT EXISTS idx_schedule_mappings_internal ON fhir.schedule_mappings(internal_id);
CREATE INDEX IF NOT EXISTS idx_schedule_mappings_fhir ON fhir.schedule_mappings(fhir_resource_id);
CREATE INDEX IF NOT EXISTS idx_schedule_mappings_status ON fhir.schedule_mappings(mapping_status);

CREATE INDEX IF NOT EXISTS idx_operations_log_type ON fhir.operations_log(operation_type, resource_type);
CREATE INDEX IF NOT EXISTS idx_operations_log_timestamp ON fhir.operations_log(request_timestamp);
CREATE INDEX IF NOT EXISTS idx_operations_log_response ON fhir.operations_log(response_code);

-- Function to update mapping timestamps
CREATE OR REPLACE FUNCTION fhir.update_mapping_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Add triggers for timestamp updates
DO $$
DECLARE
    table_name text;
BEGIN
    FOR table_name IN SELECT unnest(ARRAY['location_mappings', 'practitioner_mappings', 'schedule_mappings'])
    LOOP
        EXECUTE format('
            CREATE TRIGGER update_%I_timestamp
                BEFORE UPDATE ON fhir.%I
                FOR EACH ROW
                EXECUTE FUNCTION fhir.update_mapping_timestamp()',
            table_name, table_name);
    END LOOP;
END $$;

-- Log this migration
SELECT log_migration_execution(
    'fhir',
    '006-fhir/001-fhir-tables.sql',
    'init',
    'completed',
    NULL,
    ARRAY['fhir.resources', 'fhir.resource_history', 
          'fhir.location_mappings', 'fhir.practitioner_mappings', 
          'fhir.schedule_mappings', 'fhir.operations_log']
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
    FOR table_name IN SELECT unnest(ARRAY['location_mappings', 'practitioner_mappings', 'schedule_mappings'])
    LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS update_%I_timestamp ON fhir.%I',
            table_name, table_name);
    END LOOP;
END $$;

-- Drop functions
DROP FUNCTION IF EXISTS fhir.update_mapping_timestamp();

-- Drop tables in correct order
DROP TABLE IF EXISTS fhir.operations_log;
DROP TABLE IF EXISTS fhir.schedule_mappings;
DROP TABLE IF EXISTS fhir.practitioner_mappings;
DROP TABLE IF EXISTS fhir.location_mappings;
DROP TABLE IF EXISTS fhir.resource_history;
DROP TABLE IF EXISTS fhir.resources;

COMMIT;
*/
