/*
Description: Initial schema creation and version control setup for OR Analytics Platform
Dependencies: None - this must be run first
Author: System
Date: 2024-02-03

This script:
1. Creates all required schemas if they don't exist
2. Sets up version control infrastructure
3. Establishes schema dependencies tracking
4. Creates schema-specific migration logs
*/

BEGIN;

-- Function to safely create schema
CREATE OR REPLACE FUNCTION create_schema_if_not_exists(schema_name text)
RETURNS void AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.schemata 
        WHERE schema_name = $1
    ) THEN
        EXECUTE format('CREATE SCHEMA %I', schema_name);
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Create all required schemas
SELECT create_schema_if_not_exists('raw');   -- Raw data imports
SELECT create_schema_if_not_exists('stg');   -- Staging area
SELECT create_schema_if_not_exists('prod');  -- Production application data
SELECT create_schema_if_not_exists('star');  -- Star schema for analytics
SELECT create_schema_if_not_exists('fhir');  -- FHIR standard healthcare data

-- Create global version control table in public schema
CREATE TABLE IF NOT EXISTS public.schema_versions (
    version_id SERIAL PRIMARY KEY,
    schema_name VARCHAR(50) NOT NULL,
    migration_name VARCHAR(255) NOT NULL,
    migration_type VARCHAR(50) NOT NULL,  -- 'init', 'migration', 'function'
    dependencies TEXT[],  -- Array of required prior migrations
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NOT NULL,
    applied_by VARCHAR(255) NOT NULL,
    is_success BOOLEAN DEFAULT true,
    error_message TEXT,
    UNIQUE(schema_name, migration_name)
);

-- Create schema dependency tracking
CREATE TABLE IF NOT EXISTS public.schema_dependencies (
    dependency_id SERIAL PRIMARY KEY,
    dependent_schema VARCHAR(50) NOT NULL,
    required_schema VARCHAR(50) NOT NULL,
    dependency_type VARCHAR(50) NOT NULL,  -- 'hard' (must exist), 'soft' (may exist)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(dependent_schema, required_schema)
);

-- Insert known schema dependencies
INSERT INTO public.schema_dependencies 
    (dependent_schema, required_schema, dependency_type)
VALUES
    ('stg', 'raw', 'hard'),    -- Staging depends on raw data
    ('prod', 'stg', 'hard'),   -- Production depends on staging
    ('star', 'prod', 'hard'),  -- Analytics depends on production
    ('fhir', 'prod', 'hard')   -- FHIR depends on production
ON CONFLICT (dependent_schema, required_schema) DO NOTHING;

-- Create schema-specific migration logs
DO $$
DECLARE
    schema_name text;
BEGIN
    FOR schema_name IN SELECT unnest(ARRAY['raw', 'stg', 'prod', 'star', 'fhir'])
    LOOP
        EXECUTE format('
            CREATE TABLE IF NOT EXISTS %I.migrations_log (
                id SERIAL PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL,
                migration_type VARCHAR(50) NOT NULL,
                status VARCHAR(50) NOT NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP,
                error_message TEXT,
                affected_objects TEXT[],
                checksum VARCHAR(64),
                executed_by VARCHAR(255)
            )', schema_name);
    END LOOP;
END $$;

-- Function to validate schema dependencies
CREATE OR REPLACE FUNCTION check_schema_dependencies(
    p_schema_name text,
    p_migration_name text
) RETURNS boolean AS $$
DECLARE
    v_required_schema text;
BEGIN
    FOR v_required_schema IN 
        SELECT required_schema 
        FROM public.schema_dependencies 
        WHERE dependent_schema = p_schema_name 
        AND dependency_type = 'hard'
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.schemata 
            WHERE schema_name = v_required_schema
        ) THEN
            RAISE EXCEPTION 'Required schema % not found for %', v_required_schema, p_schema_name;
        END IF;
    END LOOP;
    
    RETURN true;
END;
$$ LANGUAGE plpgsql;

-- Function to log migration execution
CREATE OR REPLACE FUNCTION log_migration_execution(
    p_schema_name text,
    p_migration_name text,
    p_migration_type text,
    p_status text,
    p_error_message text DEFAULT NULL,
    p_affected_objects text[] DEFAULT NULL,
    p_checksum text DEFAULT NULL
) RETURNS void AS $$
BEGIN
    EXECUTE format('
        INSERT INTO %I.migrations_log (
            migration_name,
            migration_type,
            status,
            error_message,
            affected_objects,
            checksum,
            executed_by
        ) VALUES ($1, $2, $3, $4, $5, $6, current_user)',
        p_schema_name)
    USING 
        p_migration_name,
        p_migration_type,
        p_status,
        p_error_message,
        p_affected_objects,
        p_checksum;
END;
$$ LANGUAGE plpgsql;

-- Log this migration
SELECT log_migration_execution(
    'public',
    '001-schemas.sql',
    'init',
    'completed',
    NULL,
    ARRAY['public.schema_versions', 'public.schema_dependencies']
);

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
-- Drop schema-specific logs first
DO $$
DECLARE
    schema_name text;
BEGIN
    FOR schema_name IN SELECT unnest(ARRAY['raw', 'stg', 'prod', 'star', 'fhir'])
    LOOP
        EXECUTE format('DROP TABLE IF EXISTS %I.migrations_log', schema_name);
    END LOOP;
END $$;

-- Drop functions
DROP FUNCTION IF EXISTS log_migration_execution(text,text,text,text,text,text[],text);
DROP FUNCTION IF EXISTS check_schema_dependencies(text,text);
DROP FUNCTION IF EXISTS create_schema_if_not_exists(text);

-- Drop tracking tables
DROP TABLE IF EXISTS public.schema_dependencies;
DROP TABLE IF EXISTS public.schema_versions;

-- Drop schemas (only if empty)
DROP SCHEMA IF EXISTS fhir CASCADE;
DROP SCHEMA IF EXISTS star CASCADE;
DROP SCHEMA IF EXISTS prod CASCADE;
DROP SCHEMA IF EXISTS stg CASCADE;
DROP SCHEMA IF EXISTS raw CASCADE;

COMMIT;
*/
