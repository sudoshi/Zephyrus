/*
Description: Create and configure session handling tables and triggers
Dependencies: All init scripts and previous migrations must be run first
Author: System
Date: 2024-02-03
*/

BEGIN;

-- Create sessions table if it doesn't exist
CREATE TABLE IF NOT EXISTS prod.sessions (
    id VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id BIGINT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Create index on last_activity for session cleanup
CREATE INDEX IF NOT EXISTS sessions_last_activity_index 
    ON prod.sessions(last_activity);

-- Create function to update timestamps
CREATE OR REPLACE FUNCTION prod.update_session_timestamps()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger for timestamp updates
DROP TRIGGER IF EXISTS update_session_timestamps ON prod.sessions;
CREATE TRIGGER update_session_timestamps
    BEFORE UPDATE ON prod.sessions
    FOR EACH ROW
    EXECUTE FUNCTION prod.update_session_timestamps();

-- Create function to clean old sessions
CREATE OR REPLACE FUNCTION prod.cleanup_old_sessions(max_lifetime_minutes INTEGER)
RETURNS INTEGER AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM prod.sessions 
    WHERE last_activity < EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - (max_lifetime_minutes || ' minutes')::INTERVAL))::INTEGER
    RETURNING COUNT(*) INTO deleted_count;
    
    RETURN deleted_count;
END;
$$ LANGUAGE plpgsql;

-- Create index for user sessions
CREATE INDEX IF NOT EXISTS sessions_user_id_index 
    ON prod.sessions(user_id) 
    WHERE user_id IS NOT NULL;

-- Add comment explaining session cleanup
COMMENT ON FUNCTION prod.cleanup_old_sessions(INTEGER) IS 
'Removes sessions older than the specified number of minutes.
Example usage: SELECT prod.cleanup_old_sessions(1440); -- cleanup sessions older than 24 hours';

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP FUNCTION IF EXISTS prod.cleanup_old_sessions(INTEGER);
DROP TRIGGER IF EXISTS update_session_timestamps ON prod.sessions;
DROP FUNCTION IF EXISTS prod.update_session_timestamps();
DROP TABLE IF EXISTS prod.sessions;
COMMIT;
*/
