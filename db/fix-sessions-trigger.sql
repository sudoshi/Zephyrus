-- Drop the check_sessions_access trigger if it exists
DROP TRIGGER IF EXISTS check_sessions_access ON prod.sessions;
DROP TRIGGER IF EXISTS check_table_protection ON prod.sessions;

-- Drop the check_sessions_access function if it exists
DROP FUNCTION IF EXISTS check_sessions_access();

-- Create or replace the function to check table protection
CREATE OR REPLACE FUNCTION check_table_protection()
RETURNS trigger AS $$
BEGIN
    -- Skip protection for sessions table
    IF TG_TABLE_NAME = 'sessions' THEN
        RETURN NEW;
    END IF;
    
    -- Keep protection for other tables
    IF current_setting('application_name') LIKE 'ETL%' THEN
        RAISE EXCEPTION 'Table % is protected from ETL modifications', TG_TABLE_NAME;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger for all tables in prod schema except sessions
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

-- Set the application name for the current session to ensure Laravel operations work
SET application_name = 'Laravel';
