/*
Description: Examine populated tables in prod schema
Author: System
Date: 2024-02-03

This script provides a series of queries to safely examine the contents
of the prod schema tables without modifying any data.
*/

-- Get overview of all tables and their row counts
SELECT 
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname || '.' || tablename)) as total_size,
    pg_size_pretty(pg_relation_size(schemaname || '.' || tablename)) as table_size,
    pg_size_pretty(pg_total_relation_size(schemaname || '.' || tablename) - pg_relation_size(schemaname || '.' || tablename)) as index_size,
    (SELECT COUNT(*) FROM prod.locations) as row_count
FROM pg_tables
WHERE schemaname = 'prod'
ORDER BY tablename;

-- Examine locations
SELECT 
    l.name,
    l.type,
    l.abbreviation,
    COUNT(DISTINCT r.room_id) as room_count,
    COUNT(DISTINCT c.case_id) as case_count
FROM prod.locations l
LEFT JOIN prod.rooms r ON r.location_id = l.location_id
LEFT JOIN prod.or_cases c ON c.location_id = l.location_id
GROUP BY l.location_id, l.name, l.type, l.abbreviation
ORDER BY l.name;

-- Examine rooms
SELECT 
    l.name as location_name,
    r.name as room_name,
    r.type as room_type,
    COUNT(DISTINCT c.case_id) as case_count,
    COUNT(DISTINCT bt.block_id) as block_count
FROM prod.rooms r
JOIN prod.locations l ON l.location_id = r.location_id
LEFT JOIN prod.or_cases c ON c.room_id = r.room_id
LEFT JOIN prod.block_templates bt ON bt.room_id = r.room_id
GROUP BY l.name, r.room_id, r.name, r.type
ORDER BY l.name, r.name;

-- Examine providers
SELECT 
    p.name,
    p.type,
    p.npi,
    COUNT(DISTINCT c.case_id) as case_count,
    COUNT(DISTINCT bt.block_id) as block_count,
    array_agg(DISTINCT s.name) as specialties
FROM prod.providers p
LEFT JOIN prod.or_cases c ON c.primary_surgeon_id = p.provider_id
LEFT JOIN prod.block_templates bt ON bt.provider_id = p.provider_id
LEFT JOIN prod.specialties s ON s.specialty_id = ANY(p.specialty_ids)
GROUP BY p.provider_id, p.name, p.type, p.npi
ORDER BY p.name;

-- Examine services
SELECT 
    s.name,
    s.code,
    COUNT(DISTINCT c.case_id) as case_count,
    COUNT(DISTINCT bt.block_id) as block_count,
    COUNT(DISTINCT p.provider_id) as provider_count
FROM prod.services s
LEFT JOIN prod.or_cases c ON c.service_id = s.service_id
LEFT JOIN prod.block_templates bt ON bt.service_id = s.service_id
LEFT JOIN prod.providers p ON p.provider_id = c.primary_surgeon_id
GROUP BY s.service_id, s.name, s.code
ORDER BY s.name;

-- Examine case distribution
SELECT 
    cs.name as status,
    ct.name as type,
    cc.name as class,
    COUNT(*) as case_count
FROM prod.or_cases c
JOIN prod.case_statuses cs ON cs.status_id = c.status_id
JOIN prod.case_types ct ON ct.type_id = c.type_id
JOIN prod.case_classes cc ON cc.class_id = c.class_id
GROUP BY cs.name, ct.name, cc.name
ORDER BY cs.name, ct.name, cc.name;

-- Examine block templates
SELECT 
    l.name as location_name,
    r.name as room_name,
    s.name as service_name,
    p.name as provider_name,
    bt.day_of_week,
    bt.start_time,
    bt.duration_minutes,
    bt.is_recurring
FROM prod.block_templates bt
JOIN prod.locations l ON l.location_id = bt.location_id
JOIN prod.rooms r ON r.room_id = bt.room_id
JOIN prod.services s ON s.service_id = bt.service_id
LEFT JOIN prod.providers p ON p.provider_id = bt.provider_id
ORDER BY l.name, r.name, bt.day_of_week, bt.start_time;

-- Examine case measurements
SELECT 
    cs.name as status,
    cm.measurement_type,
    COUNT(*) as count,
    MIN(cm.measurement_value) as min_value,
    AVG(cm.measurement_value) as avg_value,
    MAX(cm.measurement_value) as max_value
FROM prod.case_measurements cm
JOIN prod.or_cases c ON c.case_id = cm.case_id
JOIN prod.case_statuses cs ON cs.status_id = c.status_id
GROUP BY cs.name, cm.measurement_type
ORDER BY cs.name, cm.measurement_type;

-- Examine case resources
SELECT 
    cr.resource_type,
    COUNT(*) as count,
    array_agg(DISTINCT cr.status) as statuses
FROM prod.case_resources cr
GROUP BY cr.resource_type
ORDER BY cr.resource_type;

-- Examine case safety notes
SELECT 
    csn.note_type,
    csn.severity,
    COUNT(*) as count
FROM prod.case_safety_notes csn
GROUP BY csn.note_type, csn.severity
ORDER BY csn.severity DESC, csn.note_type;

-- Examine case timings
SELECT 
    ct.phase,
    COUNT(*) as count,
    AVG(ct.planned_duration) as avg_planned_minutes,
    AVG(ct.actual_duration) as avg_actual_minutes,
    AVG(ct.variance) as avg_variance_minutes
FROM prod.case_timings ct
GROUP BY ct.phase
ORDER BY ct.phase;

-- Examine case transport
SELECT 
    ctr.transport_type,
    ctr.status,
    COUNT(*) as count,
    AVG(EXTRACT(EPOCH FROM (ctr.actual_end - ctr.actual_start))/60) as avg_duration_minutes
FROM prod.case_transport ctr
WHERE ctr.actual_start IS NOT NULL AND ctr.actual_end IS NOT NULL
GROUP BY ctr.transport_type, ctr.status
ORDER BY ctr.transport_type, ctr.status;

-- Examine care journey milestones
SELECT 
    cjm.milestone_type,
    cjm.status,
    COUNT(*) as count,
    COUNT(cjm.completed_at) as completed_count,
    AVG(EXTRACT(EPOCH FROM (cjm.completed_at - cjm.created_at))/3600) as avg_completion_hours
FROM prod.care_journey_milestones cjm
GROUP BY cjm.milestone_type, cjm.status
ORDER BY cjm.milestone_type, cjm.status;
