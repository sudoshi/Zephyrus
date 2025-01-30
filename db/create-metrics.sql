-- Create metrics for each case
INSERT INTO prod.case_metrics (
    case_id,
    turnover_time,
    utilization_percentage,
    in_block_time,
    out_of_block_time,
    prime_time_minutes,
    non_prime_time_minutes,
    created_by,
    modified_by
)
SELECT 
    c.case_id,
    30 as turnover_time, -- default 30 minutes turnover
    80 as utilization_percentage, -- default 80% utilization
    c.scheduled_duration as in_block_time,
    0 as out_of_block_time,
    CASE 
        WHEN EXTRACT(HOUR FROM c.scheduled_start_time) BETWEEN 7 AND 17 
        THEN c.scheduled_duration
        ELSE 0
    END as prime_time_minutes,
    CASE 
        WHEN EXTRACT(HOUR FROM c.scheduled_start_time) BETWEEN 7 AND 17 
        THEN 0
        ELSE c.scheduled_duration
    END as non_prime_time_minutes,
    'system' as created_by,
    'system' as modified_by
FROM prod.orcase c
WHERE c.surgery_date >= CURRENT_DATE - INTERVAL '7 days'
ON CONFLICT DO NOTHING;
