-- Drop all existing triggers from the sessions table
DO $$
DECLARE
    trigger_rec RECORD;
BEGIN
    FOR trigger_rec IN (
        SELECT trigger_name 
        FROM information_schema.triggers 
        WHERE event_object_schema IN ('public', 'prod') 
        AND event_object_table = 'sessions'
    ) LOOP
        EXECUTE 'DROP TRIGGER IF EXISTS ' || trigger_rec.trigger_name || ' ON sessions';
        EXECUTE 'DROP TRIGGER IF EXISTS ' || trigger_rec.trigger_name || ' ON prod.sessions';
        EXECUTE 'DROP TRIGGER IF EXISTS ' || trigger_rec.trigger_name || ' ON public.sessions';
    END LOOP;
END $$;

-- Drop existing trigger functions
DROP FUNCTION IF EXISTS check_sessions_access();
DROP FUNCTION IF EXISTS check_table_protection();

-- Create a new trigger function that handles table protection
CREATE OR REPLACE FUNCTION check_table_protection()
RETURNS trigger AS $$
BEGIN
    -- Always allow operations on the sessions table
    IF TG_TABLE_NAME = 'sessions' THEN
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

-- Create trigger for all tables in prod schema EXCEPT sessions
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN (SELECT tablename 
              FROM pg_tables 
              WHERE schemaname = 'prod' 
              AND tablename != 'sessions')
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

-- Ensure sessions table exists in both schemas and has proper permissions
DO $$
BEGIN
    -- Create sessions table in public schema if it doesn't exist
    IF NOT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = 'sessions'
    ) THEN
        CREATE TABLE IF NOT EXISTS public.sessions (
            id VARCHAR(255) NOT NULL PRIMARY KEY,
            user_id BIGINT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            payload TEXT NOT NULL,
            last_activity INTEGER NOT NULL
        );
    END IF;

    -- Create sessions table in prod schema if it doesn't exist
    IF NOT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'prod' 
        AND table_name = 'sessions'
    ) THEN
        CREATE TABLE IF NOT EXISTS prod.sessions (
            id VARCHAR(255) NOT NULL PRIMARY KEY,
            user_id BIGINT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            payload TEXT NOT NULL,
            last_activity INTEGER NOT NULL
        );
    END IF;
END $$;

-- Grant all permissions on sessions tables
GRANT ALL PRIVILEGES ON public.sessions TO postgres;
GRANT ALL PRIVILEGES ON prod.sessions TO postgres;

-- Verify no triggers exist on sessions tables
DO $$
DECLARE
    trigger_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO trigger_count
    FROM information_schema.triggers 
    WHERE event_object_schema IN ('public', 'prod') 
    AND event_object_table = 'sessions';
    
    IF trigger_count > 0 THEN
        RAISE NOTICE 'Warning: % triggers still exist on sessions tables', trigger_count;
    END IF;
END $$;
