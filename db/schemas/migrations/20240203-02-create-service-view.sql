/*
Description: Create materialized view for service analytics
Dependencies: All init scripts and previous migrations must be run first
Author: System
Date: 2024-02-03
*/

BEGIN;

-- Drop existing view if it exists
DROP MATERIALIZED VIEW IF EXISTS prod.service_analytics_view;

-- Create materialized view for service analytics
CREATE MATERIALIZED VIEW prod.service_analytics_view AS
SELECT 
    s.service_id,
    s.name as service_name,
    s.code as service_code,
    COUNT(DISTINCT c.case_id) as total_cases,
    COUNT(DISTINCT c.primary_surgeon_id) as unique_surgeons,
    COUNT(DISTINCT c.room_id) as unique_rooms,
    AVG(EXTRACT(EPOCH FROM (c.actual_end_time::time - c.actual_start_time::time))/60) as avg_case_duration_minutes,
    SUM(CASE WHEN c.cancellation_id IS NOT NULL THEN 1 ELSE 0 END) as cancelled_cases,
    COUNT(DISTINCT bt.block_id) as block_count,
    SUM(CASE WHEN c.status_id IN (
        SELECT status_id FROM prod.case_statuses 
        WHERE code IN ('COMPLETED', 'CLOSED')
    ) THEN 1 ELSE 0 END) as completed_cases
FROM 
    prod.services s
    LEFT JOIN prod.or_cases c ON s.service_id = c.service_id AND c.is_deleted = false
    LEFT JOIN prod.block_templates bt ON s.service_id = bt.service_id AND bt.is_deleted = false
WHERE 
    s.is_deleted = false
GROUP BY 
    s.service_id, s.name, s.code;

-- Create indexes on the materialized view
CREATE UNIQUE INDEX idx_service_analytics_service_id ON prod.service_analytics_view(service_id);
CREATE INDEX idx_service_analytics_name ON prod.service_analytics_view(service_name);
CREATE INDEX idx_service_analytics_code ON prod.service_analytics_view(service_code);

-- Create function to refresh the materialized view
CREATE OR REPLACE FUNCTION prod.refresh_service_analytics_view()
RETURNS void AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY prod.service_analytics_view;
END;
$$ LANGUAGE plpgsql;

-- Create a comment explaining the refresh process
COMMENT ON FUNCTION prod.refresh_service_analytics_view() IS 
'Refreshes the service_analytics_view materialized view. 
This should be called periodically to keep the analytics data current.
Example usage: SELECT prod.refresh_service_analytics_view();';

COMMIT;

/*
Rollback instructions:
To rollback this migration, run:

BEGIN;
DROP FUNCTION IF EXISTS prod.refresh_service_analytics_view();
DROP MATERIALIZED VIEW IF EXISTS prod.service_analytics_view;
COMMIT;
*/
