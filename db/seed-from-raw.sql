-- First create a specialty since it's required for providers
INSERT INTO prod.specialty (name, code, active_status, created_by, modified_by)
VALUES ('General Surgery', 'GS', true, 'system', 'system')
ON CONFLICT DO NOTHING;

-- Services (based on unique procedures)
INSERT INTO prod.service (name, code, active_status, created_by, modified_by)
SELECT DISTINCT 
    procedure as name,
    procedure as code,
    true as active_status,
    'system' as created_by,
    'system' as modified_by
FROM raw.or_cases 
WHERE procedure IS NOT NULL
ON CONFLICT DO NOTHING;

-- Create a default location since it's required for rooms
INSERT INTO prod.location (name, abbreviation, location_type, pos_type, active_status, created_by, modified_by)
VALUES ('Main Hospital', 'MAIN', 'Hospital', 'Inpatient', true, 'system', 'system')
ON CONFLICT DO NOTHING;

-- Rooms (from raw.or_cases)
WITH location_id AS (SELECT location_id FROM prod.location WHERE name = 'Main Hospital' LIMIT 1)
INSERT INTO prod.room (location_id, name, room_type, active_status, created_by, modified_by)
SELECT DISTINCT 
    (SELECT location_id FROM location_id),
    room as name,
    'OR' as room_type,
    true as active_status,
    'system' as created_by,
    'system' as modified_by
FROM raw.or_cases
WHERE room IS NOT NULL
ON CONFLICT DO NOTHING;

-- Providers (surgeons from raw.or_cases)
WITH specialty_id AS (SELECT specialty_id FROM prod.specialty WHERE name = 'General Surgery' LIMIT 1)
INSERT INTO prod.provider (name, specialty_id, provider_type, active_status, created_by, modified_by)
SELECT DISTINCT 
    surgeon as name,
    (SELECT specialty_id FROM specialty_id),
    'Surgeon' as provider_type,
    true as active_status,
    'system' as created_by,
    'system' as modified_by
FROM raw.or_cases
WHERE surgeon IS NOT NULL
ON CONFLICT DO NOTHING;

-- Create required reference data for OR cases
INSERT INTO prod.casestatus (name, code, active_status, created_by, modified_by)
VALUES ('Scheduled', 'SCH', true, 'system', 'system')
ON CONFLICT DO NOTHING;

INSERT INTO prod.casetype (name, code, active_status, created_by, modified_by)
VALUES ('Elective', 'ELE', true, 'system', 'system')
ON CONFLICT DO NOTHING;

INSERT INTO prod.caseclass (name, code, active_status, created_by, modified_by)
VALUES ('Routine', 'ROU', true, 'system', 'system')
ON CONFLICT DO NOTHING;

INSERT INTO prod.patientclass (name, code, active_status, created_by, modified_by)
VALUES ('Inpatient', 'IP', true, 'system', 'system')
ON CONFLICT DO NOTHING;

INSERT INTO prod.asarating (name, code, created_by, modified_by)
VALUES ('ASA I', '1', 'system', 'system')
ON CONFLICT DO NOTHING;

-- OR Cases
WITH refs AS (
    SELECT 
        (SELECT location_id FROM prod.location WHERE name = 'Main Hospital' LIMIT 1) as location_id,
        (SELECT status_id FROM prod.casestatus WHERE code = 'SCH' LIMIT 1) as status_id,
        (SELECT case_type_id FROM prod.casetype WHERE code = 'ELE' LIMIT 1) as case_type_id,
        (SELECT case_class_id FROM prod.caseclass WHERE code = 'ROU' LIMIT 1) as case_class_id,
        (SELECT patient_class_id FROM prod.patientclass WHERE code = 'IP' LIMIT 1) as patient_class_id,
        (SELECT asa_id FROM prod.asarating WHERE code = '1' LIMIT 1) as asa_rating_id
)
INSERT INTO prod.orcase (
    case_id, 
    patient_id,
    surgery_date, 
    room_id, 
    location_id,
    primary_surgeon_id,
    case_service_id,
    scheduled_start_time,
    scheduled_duration,
    status_id,
    case_type_id,
    case_class_id,
    patient_class_id,
    asa_rating_id,
    created_by, 
    modified_by
)
SELECT 
    c.case_id::bigint,
    'P' || c.case_id as patient_id,
    c.case_date,
    r.room_id,
    refs.location_id,
    p.provider_id,
    s.service_id,
    CASE 
        WHEN sch.start_time IS NOT NULL THEN 
            (c.case_date + sch.start_time::time)::timestamp 
        ELSE 
            c.case_date + '08:00:00'::time 
    END as scheduled_start_time,
    COALESCE(c.duration, 60) as scheduled_duration,
    refs.status_id,
    refs.case_type_id,
    refs.case_class_id,
    refs.patient_class_id,
    refs.asa_rating_id,
    'system',
    'system'
FROM raw.or_cases c
JOIN prod.room r ON r.name = c.room
JOIN prod.provider p ON p.name = c.surgeon
JOIN prod.service s ON s.name = c.procedure
LEFT JOIN raw.or_schedules sch ON sch.case_id = c.case_id::bigint
CROSS JOIN refs
ON CONFLICT DO NOTHING;
