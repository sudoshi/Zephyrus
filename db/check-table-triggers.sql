-- List all triggers in the database
SELECT 
    event_object_schema as table_schema,
    event_object_table as table_name,
    trigger_schema,
    trigger_name,
    string_agg(event_manipulation, ',') as event,
    action_timing as activation,
    action_statement as definition
FROM information_schema.triggers
WHERE event_object_schema = 'prod'
GROUP BY 1,2,3,4,5,6,7;

-- Drop any existing triggers on the sessions table
DO $$
DECLARE
    trigger_rec RECORD;
BEGIN
    FOR trigger_rec IN (
        SELECT trigger_name 
        FROM information_schema.triggers 
        WHERE event_object_schema = 'prod' 
        AND event_object_table = 'sessions'
    ) LOOP
        EXECUTE 'DROP TRIGGER IF EXISTS ' || trigger_rec.trigger_name || ' ON prod.sessions';
    END LOOP;
END $$;

-- Create a function to check if the current operation is from Laravel
CREATE OR REPLACE FUNCTION is_laravel_operation()
RETURNS boolean AS $$
BEGIN
    -- Check if the application_name starts with 'Laravel'
    -- or if it's a PHP-FPM process
    RETURN current_setting('application_name') LIKE 'Laravel%'
        OR current_setting('application_name') LIKE 'php-fpm%';
END;
$$ LANGUAGE plpgsql;

-- Create a trigger function that allows Laravel operations
CREATE OR REPLACE FUNCTION check_sessions_access()
RETURNS trigger AS $$
BEGIN
    -- Allow Laravel operations
    IF is_laravel_operation() THEN
        RETURN NEW;
    END IF;
    
    -- Block ETL operations
    IF current_setting('application_name') LIKE 'ETL%' THEN
        RAISE EXCEPTION 'Table sessions is protected from ETL modifications';
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create the trigger on the sessions table
CREATE TRIGGER check_sessions_access
BEFORE INSERT OR UPDATE OR DELETE ON prod.sessions
FOR EACH ROW
EXECUTE FUNCTION check_sessions_access();
