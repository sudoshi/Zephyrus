-- Create schemas if they don't exist
CREATE SCHEMA IF NOT EXISTS public;
CREATE SCHEMA IF NOT EXISTS prod;

-- First, drop all triggers from all tables in prod schema
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN (SELECT tablename 
              FROM pg_tables 
              WHERE schemaname = 'prod')
    LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS check_table_protection ON prod.%I', r.tablename);
    END LOOP;
END $$;

-- Now we can safely drop and recreate the function
DROP FUNCTION IF EXISTS check_sessions_access();
DROP FUNCTION IF EXISTS check_table_protection();

-- Create a new trigger function that handles table protection
CREATE OR REPLACE FUNCTION check_table_protection()
RETURNS trigger AS $$
BEGIN
    -- Always allow operations on Laravel system tables
    IF TG_TABLE_NAME IN ('sessions', 'cache', 'jobs', 'failed_jobs', 'personal_access_tokens') THEN
        RETURN NEW;
    END IF;
    
    -- For all other tables, check if it's an ETL operation
    IF current_setting('application_name') LIKE 'ETL%' THEN
        RAISE EXCEPTION 'Table % is protected from ETL modifications', TG_TABLE_NAME;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Set application name for Laravel connections
ALTER DATABASE "OAP" SET application_name = 'Laravel';

-- Create trigger for all tables in prod schema EXCEPT Laravel system tables
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN (SELECT tablename 
              FROM pg_tables 
              WHERE schemaname = 'prod' 
              AND tablename NOT IN ('sessions', 'cache', 'jobs', 'failed_jobs', 'personal_access_tokens'))
    LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS check_table_protection ON prod.%I', r.tablename);
        EXECUTE format(
            'CREATE TRIGGER check_table_protection
             BEFORE INSERT OR UPDATE OR DELETE ON prod.%I
             FOR EACH ROW
             EXECUTE FUNCTION check_table_protection()',
            r.tablename
        );
    END LOOP;
END $$;

-- Ensure Laravel system tables exist in both schemas with proper permissions
DO $$
BEGIN
    -- Create tables in public schema if they don't exist
    CREATE TABLE IF NOT EXISTS public.sessions (
        id VARCHAR(255) NOT NULL PRIMARY KEY,
        user_id BIGINT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        payload TEXT NOT NULL,
        last_activity INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS public.cache (
        key VARCHAR(255) NOT NULL PRIMARY KEY,
        value TEXT NOT NULL,
        expiration INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS public.jobs (
        id BIGSERIAL PRIMARY KEY,
        queue VARCHAR(255) NOT NULL,
        payload TEXT NOT NULL,
        attempts SMALLINT NOT NULL,
        reserved_at INTEGER NULL,
        available_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS public.failed_jobs (
        id BIGSERIAL PRIMARY KEY,
        uuid VARCHAR(255) NOT NULL UNIQUE,
        connection TEXT NOT NULL,
        queue TEXT NOT NULL,
        payload TEXT NOT NULL,
        exception TEXT NOT NULL,
        failed_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS public.personal_access_tokens (
        id BIGSERIAL PRIMARY KEY,
        tokenable_type VARCHAR(255) NOT NULL,
        tokenable_id BIGINT NOT NULL,
        name VARCHAR(255) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        abilities TEXT NULL,
        last_used_at TIMESTAMP(0) NULL,
        expires_at TIMESTAMP(0) NULL,
        created_at TIMESTAMP(0) NULL,
        updated_at TIMESTAMP(0) NULL
    );

    -- Create tables in prod schema if they don't exist
    CREATE TABLE IF NOT EXISTS prod.sessions (LIKE public.sessions INCLUDING ALL);
    CREATE TABLE IF NOT EXISTS prod.cache (LIKE public.cache INCLUDING ALL);
    CREATE TABLE IF NOT EXISTS prod.jobs (LIKE public.jobs INCLUDING ALL);
    CREATE TABLE IF NOT EXISTS prod.failed_jobs (LIKE public.failed_jobs INCLUDING ALL);
    CREATE TABLE IF NOT EXISTS prod.personal_access_tokens (LIKE public.personal_access_tokens INCLUDING ALL);
END $$;

-- Grant all permissions on Laravel system tables
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO postgres;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA prod TO postgres;

-- Verify no triggers exist on Laravel system tables
DO $$
DECLARE
    trigger_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO trigger_count
    FROM information_schema.triggers 
    WHERE event_object_schema IN ('public', 'prod') 
    AND event_object_table IN ('sessions', 'cache', 'jobs', 'failed_jobs', 'personal_access_tokens');
    
    IF trigger_count > 0 THEN
        RAISE NOTICE 'Warning: % triggers still exist on Laravel system tables', trigger_count;
    END IF;
END $$;
