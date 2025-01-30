-- Update some cases to today's date
UPDATE prod.orcase 
SET surgery_date = CURRENT_DATE,
    scheduled_start_time = CURRENT_DATE + scheduled_start_time::time
WHERE case_id IN (
    SELECT case_id 
    FROM prod.orcase 
    ORDER BY case_id 
    LIMIT 10
);

-- Update corresponding logs
UPDATE prod.orlog
SET tracking_date = CURRENT_DATE,
    or_in_time = c.scheduled_start_time,
    or_out_time = c.scheduled_start_time + (c.scheduled_duration || ' minutes')::interval
FROM prod.orcase c
WHERE orlog.case_id = c.case_id
AND c.surgery_date = CURRENT_DATE;

-- Update corresponding metrics
UPDATE prod.case_metrics
SET created_date = CURRENT_DATE,
    modified_date = CURRENT_DATE
WHERE case_id IN (
    SELECT case_id 
    FROM prod.orcase 
    WHERE surgery_date = CURRENT_DATE
);
