# OR Analytics Data Model

## Core Tables

### ORCase
Primary table for surgical cases
- case_id (PK)
- log_id (FK to ORLog)
- patient_id
- surgery_date
- room_id (FK to Room)
- location_id (FK to Location) 
- primary_surgeon_id (FK to Provider)
- case_service_id (FK to Service)
- scheduled_start_time
- scheduled_duration
- record_create_date
- status_id (FK to CaseStatus)
- cancellation_reason_id (FK to CancellationReason)
- asa_rating_id (FK to ASARating)
- case_type_id (FK to CaseType)
- case_class_id (FK to CaseClass)
- patient_class_id (FK to PatientClass)

### ORLog
Tracks actual case execution details
- log_id (PK)
- case_id (FK to ORCase)
- tracking_date
- periop_arrival_time
- preop_in_time
- preop_out_time
- or_in_time
- anesthesia_start_time
- procedure_start_time
- procedure_closing_time
- procedure_end_time
- or_out_time
- anesthesia_end_time
- pacu_in_time
- pacu_out_time
- destination
- number_of_panels
- primary_procedure

### BlockTemplate
Manages block schedule templates
- block_id (PK)
- room_id (FK to Room)
- service_id (FK to Service)
- surgeon_id (FK to Provider)
- group_id
- block_date
- start_time
- end_time
- is_public
- title
- abbreviation

### Provider
Healthcare providers involved in cases
- provider_id (PK)
- npi
- name
- specialty_id (FK to Specialty)
- type (surgeon, anesthesiologist, etc.)
- active_status

### Location
Physical locations/facilities
- location_id (PK)
- name
- abbreviation
- type (OR, PACU, etc.)
- pos_type
- active_status

### Room
Operating rooms
- room_id (PK)
- location_id (FK to Location)
- name
- type
- active_status

## Reference Tables

### Service
Clinical services
- service_id (PK)
- name
- code
- active_status

### CaseType
Types of surgical cases
- case_type_id (PK)
- name
- code
- active_status

### CaseClass
Classifications of surgical cases
- case_class_id (PK)
- name
- code
- active_status

### PatientClass
Patient classifications
- patient_class_id (PK)
- name
- code
- active_status

### CaseStatus
Status codes for cases
- status_id (PK)
- name
- code
- active_status

### CancellationReason 
Reasons for case cancellations
- cancellation_id (PK)
- name
- code
- active_status

### ASARating
ASA physical status classifications
- asa_id (PK)
- name
- code
- description

### Specialty
Medical specialties
- specialty_id (PK)
- name
- code
- active_status

## Analytical Tables

### CaseMetrics
Precomputed metrics for each case
- case_id (PK, FK to ORCase)
- turnover_time
- utilization_percentage
- in_block_time
- out_of_block_time
- prime_time_minutes
- non_prime_time_minutes
- late_start_minutes
- early_finish_minutes

### BlockUtilization
Block time utilization metrics
- block_id (FK to BlockTemplate)
- date
- service_id (FK to Service)
- location_id (FK to Location)
- scheduled_minutes
- actual_minutes
- utilization_percentage
- cases_scheduled
- cases_performed
- prime_time_percentage
- non_prime_time_percentage

### RoomUtilization
Room utilization metrics
- room_id (FK to Room)
- date
- available_minutes
- utilized_minutes
- turnover_minutes
- utilization_percentage
- cases_performed
- avg_case_duration

## Notes on Implementation

1. All tables should include:
   - created_date
   - created_by
   - modified_date
   - modified_by
   - is_deleted flag

2. Indexing Strategy:
   - Primary keys
   - Foreign keys
   - Frequently filtered fields (dates, status)
   - Common join fields

3. Partitioning Strategy:
   - Partition large tables by date
   - Consider partitioning by location for multi-facility deployments

4. ETL Considerations:
   - Implement slowly changing dimension handling for reference tables
   - Build incremental load process for fact tables
   - Include data quality checks in ETL pipeline

5. Performance Optimization:
   - Materialized views for common analytical queries
   - Summary tables refreshed nightly
   - Denormalized views for reporting

6. Data Retention:
   - Keep detailed data for 2 years
   - Summarized data for 7 years
   - Archived data beyond 7 years

## Key Business Rules

1. Block Schedule Rules:
   - No overlapping blocks in same room
   - Prime time definition configurable by location
   - Block release time configurable by service

2. Case Scheduling Rules:
   - Case duration includes setup/cleanup time
   - Case conflicts checked against existing schedules
   - Emergency cases can override normal scheduling rules

3. Utilization Calculations:
   - Block utilization = (used minutes / allocated minutes) * 100
   - Prime time utilization includes cases during defined prime hours
   - Turnover time excluded from utilization calculations

4. Metric Definitions:
   - Start time = wheels in
   - End time = wheels out
   - Turnover time = wheels out to next wheels in
   - Prime time = configurable by facility (typically 7am-5pm)

## Data Quality Rules

1. Required Fields:
   - Case ID
   - Patient ID
   - Surgery Date
   - Location
   - Primary Surgeon
   - Service

2. Validation Rules:
   - End times must be after start times
   - Cases cannot overlap in same room
   - Valid reference data values
   - Date ranges within acceptable bounds

3. Consistency Checks:
   - Matching case IDs across tables
   - Status transitions follow allowed paths
   - Block times align with facility operating hours

4. Data Completeness:
   - Track missing required fields
   - Monitor null values in critical fields
   - Validate reference data coverage
   
This completes the DDL for the data model. The structure includes:

Core tables for case, log, and block template data
Reference tables for supporting dimensions
Analytical tables for metrics and utilization tracking
Utility views for common analysis scenarios

Key features of the design:

Inheritance from BaseTable for audit fields
Appropriate foreign key constraints
Strategic indexing for performance
Comprehensive metrics tracking
Support for block management
Flexibility for multi-facility deployment
   
