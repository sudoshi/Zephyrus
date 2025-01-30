-- Drop the view first
DROP VIEW IF EXISTS prod.vw_serviceutilization;

-- Convert columns to TEXT type
ALTER TABLE prod.service ALTER COLUMN name TYPE TEXT;
ALTER TABLE prod.service ALTER COLUMN code TYPE TEXT;

-- Recreate the view
CREATE OR REPLACE VIEW prod.vw_serviceutilization AS
SELECT 
    s.service_id as case_service_id,
    s.name as service_name,
    l.location_id,
    l.name as location_name,
    c.surgery_date,
    COUNT(c.case_id) as total_cases,
    SUM(c.scheduled_duration) as scheduled_minutes,
    0 as actual_minutes,
    CASE 
        WHEN COUNT(c.case_id) > 0 THEN 
            SUM(c.scheduled_duration) / COUNT(c.case_id)
        ELSE 0 
    END as avg_case_duration,
    0 as prime_time_minutes,
    0 as non_prime_time_minutes,
    COUNT(CASE WHEN cs.code = 'CAN' THEN 1 END) as cancelled_cases,
    0 as block_minutes_used
FROM prod.service s
LEFT JOIN prod.orcase c ON s.service_id = c.case_service_id
LEFT JOIN prod.location l ON c.location_id = l.location_id
LEFT JOIN prod.casestatus cs ON c.status_id = cs.status_id
GROUP BY 
    s.service_id,
    s.name,
    l.location_id,
    l.name,
    c.surgery_date;
