/*
Description: Schema versioning and comparison functions
Author: System
Date: 2024-02-03

This script creates functions to:
1. Generate schema checksums
2. Track schema changes
3. Compare schema versions
*/

BEGIN;

-- Create schema version tracking table
CREATE TABLE IF NOT EXISTS public.schema_metadata (
    metadata_id SERIAL PRIMARY KEY,
    schema_name VARCHAR(50) NOT NULL,
    object_type VARCHAR(50) NOT NULL,  -- 'table', 'function', 'view', 'constraint', 'index'
    object_name VARCHAR(255) NOT NULL,
    object_definition TEXT NOT NULL,
    checksum VARCHAR(64) NOT NULL,
    git_commit_hash VARCHAR(40),
    git_branch VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(schema_name, object_type, object_name)
);

-- Function to generate checksum for an object definition
CREATE OR REPLACE FUNCTION public.generate_object_checksum(definition TEXT)
RETURNS VARCHAR(64) AS $$
BEGIN
    RETURN encode(sha256(definition::bytea), 'hex');
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Function to capture table definition
CREATE OR REPLACE FUNCTION public.get_table_definition(p_schema TEXT, p_table TEXT)
RETURNS TEXT AS $$
DECLARE
    v_definition TEXT;
BEGIN
    -- Get column definitions
    WITH columns AS (
        SELECT 
            column_name,
            data_type,
            CASE 
                WHEN character_maximum_length IS NOT NULL 
                THEN '(' || character_maximum_length || ')'
                ELSE ''
            END as length,
            is_nullable,
            column_default
        FROM information_schema.columns
        WHERE table_schema = p_schema AND table_name = p_table
        ORDER BY ordinal_position
    ),
    constraints AS (
        SELECT 
            constraint_name,
            string_agg(column_name, ', ') as columns,
            constraint_type
        FROM information_schema.constraint_column_usage cc
        JOIN information_schema.table_constraints tc 
            ON cc.constraint_name = tc.constraint_name
        WHERE tc.table_schema = p_schema AND tc.table_name = p_table
        GROUP BY constraint_name, constraint_type
        ORDER BY constraint_name
    )
    SELECT string_agg(
        format(
            '%s %s%s %s %s',
            column_name,
            data_type,
            length,
            CASE WHEN is_nullable = 'NO' THEN 'NOT NULL' ELSE 'NULL' END,
            COALESCE('DEFAULT ' || column_default, '')
        ),
        E'\n'
    ) || E'\n' ||
    COALESCE(string_agg(
        format(
            '%s (%s) %s',
            constraint_name,
            columns,
            CASE 
                WHEN constraint_type = 'PRIMARY KEY' THEN 'PRIMARY KEY'
                WHEN constraint_type = 'FOREIGN KEY' THEN 'FOREIGN KEY'
                WHEN constraint_type = 'UNIQUE' THEN 'UNIQUE'
                ELSE constraint_type
            END
        ),
        E'\n'
    ), '')
    INTO v_definition
    FROM columns
    LEFT JOIN constraints ON true;

    RETURN v_definition;
END;
$$ LANGUAGE plpgsql;

-- Function to capture function definition
CREATE OR REPLACE FUNCTION public.get_function_definition(p_schema TEXT, p_function TEXT)
RETURNS TEXT AS $$
BEGIN
    RETURN pg_get_functiondef(
        (SELECT oid FROM pg_proc p JOIN pg_namespace n ON p.pronamespace = n.oid
         WHERE n.nspname = p_schema AND p.proname = p_function)
    );
END;
$$ LANGUAGE plpgsql;

-- Function to capture view definition
CREATE OR REPLACE FUNCTION public.get_view_definition(p_schema TEXT, p_view TEXT)
RETURNS TEXT AS $$
BEGIN
    RETURN pg_get_viewdef(format('%I.%I', p_schema, p_view), true);
END;
$$ LANGUAGE plpgsql;

-- Function to capture all schema objects
CREATE OR REPLACE FUNCTION public.capture_schema_state(p_schema TEXT)
RETURNS TABLE (
    object_type VARCHAR(50),
    object_name VARCHAR(255),
    checksum VARCHAR(64)
) AS $$
DECLARE
    v_definition TEXT;
BEGIN
    -- Capture tables
    FOR object_name IN 
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = p_schema AND table_type = 'BASE TABLE'
    LOOP
        v_definition := public.get_table_definition(p_schema, object_name);
        object_type := 'table';
        checksum := public.generate_object_checksum(v_definition);
        RETURN NEXT;
        
        -- Insert/Update metadata
        INSERT INTO public.schema_metadata (
            schema_name, object_type, object_name, 
            object_definition, checksum
        ) VALUES (
            p_schema, 'table', object_name, 
            v_definition, checksum
        )
        ON CONFLICT (schema_name, object_type, object_name) 
        DO UPDATE SET 
            object_definition = EXCLUDED.object_definition,
            checksum = EXCLUDED.checksum,
            modified_at = CURRENT_TIMESTAMP
        WHERE schema_metadata.checksum != EXCLUDED.checksum;
    END LOOP;

    -- Capture functions
    FOR object_name IN 
        SELECT p.proname 
        FROM pg_proc p 
        JOIN pg_namespace n ON p.pronamespace = n.oid
        WHERE n.nspname = p_schema
    LOOP
        v_definition := public.get_function_definition(p_schema, object_name);
        object_type := 'function';
        checksum := public.generate_object_checksum(v_definition);
        RETURN NEXT;
        
        INSERT INTO public.schema_metadata (
            schema_name, object_type, object_name, 
            object_definition, checksum
        ) VALUES (
            p_schema, 'function', object_name, 
            v_definition, checksum
        )
        ON CONFLICT (schema_name, object_type, object_name) 
        DO UPDATE SET 
            object_definition = EXCLUDED.object_definition,
            checksum = EXCLUDED.checksum,
            modified_at = CURRENT_TIMESTAMP
        WHERE schema_metadata.checksum != EXCLUDED.checksum;
    END LOOP;

    -- Capture views
    FOR object_name IN 
        SELECT table_name 
        FROM information_schema.views 
        WHERE table_schema = p_schema
    LOOP
        v_definition := public.get_view_definition(p_schema, object_name);
        object_type := 'view';
        checksum := public.generate_object_checksum(v_definition);
        RETURN NEXT;
        
        INSERT INTO public.schema_metadata (
            schema_name, object_type, object_name, 
            object_definition, checksum
        ) VALUES (
            p_schema, 'view', object_name, 
            v_definition, checksum
        )
        ON CONFLICT (schema_name, object_type, object_name) 
        DO UPDATE SET 
            object_definition = EXCLUDED.object_definition,
            checksum = EXCLUDED.checksum,
            modified_at = CURRENT_TIMESTAMP
        WHERE schema_metadata.checksum != EXCLUDED.checksum;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Function to detect schema changes
CREATE OR REPLACE FUNCTION public.detect_schema_changes(p_schema TEXT)
RETURNS TABLE (
    change_type VARCHAR(50),
    object_type VARCHAR(50),
    object_name VARCHAR(255),
    details TEXT
) AS $$
DECLARE
    v_old_state RECORD;
    v_new_state RECORD;
BEGIN
    -- Create temporary table for new state
    CREATE TEMP TABLE temp_new_state AS
    SELECT * FROM public.capture_schema_state(p_schema);

    -- Compare with previous state
    FOR v_old_state IN 
        SELECT * FROM public.schema_metadata 
        WHERE schema_name = p_schema
    LOOP
        -- Check for modified objects
        SELECT * INTO v_new_state 
        FROM temp_new_state 
        WHERE object_type = v_old_state.object_type 
        AND object_name = v_old_state.object_name;

        IF v_new_state IS NULL THEN
            change_type := 'DROPPED';
            object_type := v_old_state.object_type;
            object_name := v_old_state.object_name;
            details := 'Object no longer exists';
            RETURN NEXT;
        ELSIF v_new_state.checksum != v_old_state.checksum THEN
            change_type := 'MODIFIED';
            object_type := v_old_state.object_type;
            object_name := v_old_state.object_name;
            details := format(
                'Checksum changed from %s to %s',
                v_old_state.checksum,
                v_new_state.checksum
            );
            RETURN NEXT;
        END IF;
    END LOOP;

    -- Check for new objects
    FOR v_new_state IN 
        SELECT * FROM temp_new_state
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM public.schema_metadata 
            WHERE schema_name = p_schema
            AND object_type = v_new_state.object_type
            AND object_name = v_new_state.object_name
        ) THEN
            change_type := 'ADDED';
            object_type := v_new_state.object_type;
            object_name := v_new_state.object_name;
            details := format('New object with checksum %s', v_new_state.checksum);
            RETURN NEXT;
        END IF;
    END LOOP;

    DROP TABLE temp_new_state;
END;
$$ LANGUAGE plpgsql;

-- Function to update git metadata
CREATE OR REPLACE FUNCTION public.update_git_metadata(
    p_schema TEXT,
    p_commit_hash VARCHAR(40),
    p_branch VARCHAR(255)
)
RETURNS VOID AS $$
BEGIN
    UPDATE public.schema_metadata
    SET 
        git_commit_hash = p_commit_hash,
        git_branch = p_branch,
        modified_at = CURRENT_TIMESTAMP
    WHERE schema_name = p_schema;
END;
$$ LANGUAGE plpgsql;

-- Log this migration
INSERT INTO public.schema_versions (
    schema_name,
    migration_name,
    migration_type,
    dependencies,
    checksum,
    applied_by
) VALUES (
    'public',
    'schema-version.sql',
    'function',
    ARRAY['001-schemas.sql'],
    public.generate_object_checksum(pg_read_file('schema-version.sql')),
    current_user
);

COMMIT;

/*
Example usage:

-- Capture initial state
SELECT * FROM public.capture_schema_state('prod');

-- Make some changes to the schema
ALTER TABLE ...

-- Detect changes
SELECT * FROM public.detect_schema_changes('prod');

-- Update git metadata after commit
SELECT public.update_git_metadata('prod', '123abc...', 'main');
*/
