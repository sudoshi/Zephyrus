-- Create logs for each case
INSERT INTO prod.orlog (
    log_id,
    case_id,
    tracking_date,
    or_in_time,
    or_out_time,
    primary_procedure,
    created_by,
    modified_by
)
SELECT 
    case_id as log_id,
    case_id,
    surgery_date as tracking_date,
    scheduled_start_time as or_in_time,
    scheduled_start_time + (scheduled_duration || ' minutes')::interval as or_out_time,
    'Procedure ' || case_id as primary_procedure,
    'system' as created_by,
    'system' as modified_by
FROM prod.orcase
WHERE surgery_date = CURRENT_DATE
ON CONFLICT DO NOTHING;
