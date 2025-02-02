
DO $$ 
BEGIN
    CREATE SCHEMA IF NOT EXISTS appdb;
EXCEPTION 
    WHEN duplicate_schema THEN NULL;
END $$;

DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'milestone_type_enum') THEN
        CREATE TYPE appdb.milestone_type_enum AS ENUM (
            'H&P', 'Consent', 'Labs', 'Safety_Check', 'Transport'
        );
    END IF;
END $$;

DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'milestone_status_enum') THEN
        CREATE TYPE appdb.milestone_status_enum AS ENUM (
            'Pending', 'In_Progress', 'Completed', 'Verified', 'Action_Required'
        );
    END IF;
END $$;

DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'transport_type_enum') THEN
        CREATE TYPE appdb.transport_type_enum AS ENUM (
            'Pre_Procedure', 'Post_Procedure'
        );
    END IF;
END $$;

DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'transport_status_enum') THEN
        CREATE TYPE appdb.transport_status_enum AS ENUM (
            'Pending', 'In_Progress', 'Complete'
        );
    END IF;
END $$;

-- Let's start with the essential patient table since patients are at the center of care journeys
CREATE TABLE IF NOT EXISTS appdb.patients (
    id bigserial PRIMARY KEY,
    name varchar(255) NOT NULL,
    date_of_birth date NULL,
    mrn varchar(20) NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);

-- Next, create the staff table as they'll be referenced in various care activities
CREATE TABLE IF NOT EXISTS appdb.staff (
    id bigserial PRIMARY KEY,
    name varchar(255) NOT NULL,
    role varchar(100) NOT NULL,
    staff_type varchar(50) NULL,
    active boolean DEFAULT true,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);

-- Finally, create the surgical_cases table which will be the anchor for care journeys
CREATE TABLE IF NOT EXISTS appdb.surgical_cases (
    id bigserial PRIMARY KEY,
    patient_id bigint NOT NULL,
    procedure_type varchar(100) NOT NULL,
    scheduled_time timestamp(0) NOT NULL,
    status varchar(50) NOT NULL,
    pre_procedure_location varchar(100) NULL,
    post_procedure_location varchar(100) NULL,
    charge_rn_id bigint NULL,
    anesthesiologist_id bigint NULL,
    safety_status varchar(50) NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_patient FOREIGN KEY (patient_id) REFERENCES appdb.patients(id),
    CONSTRAINT fk_charge_rn FOREIGN KEY (charge_rn_id) REFERENCES appdb.staff(id),
    CONSTRAINT fk_anesthesiologist FOREIGN KEY (anesthesiologist_id) REFERENCES appdb.staff(id)
);

-- Care journey milestones track important checkpoints in the patient's surgical journey
CREATE TABLE IF NOT EXISTS appdb.care_journey_milestones (
    id bigserial PRIMARY KEY,
    surgical_case_id bigint NOT NULL,
    milestone_type appdb.milestone_type_enum NOT NULL,
    status appdb.milestone_status_enum NOT NULL,
    required boolean DEFAULT true,
    completed_at timestamp(0) NULL,
    completed_by bigint NULL,
    notes text NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_milestone_case FOREIGN KEY (surgical_case_id) REFERENCES appdb.surgical_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_milestone_completed_by FOREIGN KEY (completed_by) REFERENCES appdb.staff(id) ON DELETE SET NULL
);

-- Transport tracking manages patient movement between different hospital locations
CREATE TABLE IF NOT EXISTS appdb.care_journey_transport (
    id bigserial PRIMARY KEY,
    surgical_case_id bigint NOT NULL,
    transport_type appdb.transport_type_enum NOT NULL,
    status appdb.transport_status_enum NOT NULL,
    location_from varchar(100) NOT NULL,
    location_to varchar(100) NOT NULL,
    assigned_to bigint NULL,
    planned_time timestamp(0) NOT NULL,
    actual_start timestamp(0) NULL,
    actual_end timestamp(0) NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_case FOREIGN KEY (surgical_case_id) REFERENCES appdb.surgical_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_transport_assigned_to FOREIGN KEY (assigned_to) REFERENCES appdb.staff(id) ON DELETE SET NULL
);

-- Safety notes capture important alerts, barriers, and general safety information
CREATE TABLE IF NOT EXISTS appdb.care_journey_safety_notes (
    id bigserial PRIMARY KEY,
    surgical_case_id bigint NOT NULL,
    note_type varchar(50) NOT NULL,
    content text NOT NULL,
    severity varchar(50) NOT NULL,
    created_by bigint NULL,
    acknowledged_by bigint NULL,
    acknowledged_at timestamp(0) NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_safety_note_case FOREIGN KEY (surgical_case_id) REFERENCES appdb.surgical_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_safety_note_created_by FOREIGN KEY (created_by) REFERENCES appdb.staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_safety_note_acknowledged_by FOREIGN KEY (acknowledged_by) REFERENCES appdb.staff(id) ON DELETE SET NULL,
    CONSTRAINT check_note_type CHECK (note_type IN ('Safety_Alert', 'Barrier', 'General')),
    CONSTRAINT check_severity CHECK (severity IN ('Low', 'Medium', 'High', 'Critical'))
);

-- Timings track the planned and actual durations of each phase in the care journey
CREATE TABLE IF NOT EXISTS appdb.care_journey_timings (
    id bigserial PRIMARY KEY,
    surgical_case_id bigint NOT NULL,
    phase varchar(50) NOT NULL,
    planned_start timestamp(0) NOT NULL,
    planned_duration integer NOT NULL,
    actual_start timestamp(0) NULL,
    actual_duration integer NULL,
    variance integer NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_timing_case FOREIGN KEY (surgical_case_id) REFERENCES appdb.surgical_cases(id) ON DELETE CASCADE,
    CONSTRAINT check_phase CHECK (phase IN ('Pre_Procedure', 'Procedure', 'Post_Procedure', 'Recovery', 'Room_Turnover'))
);

-- Optimize milestone lookups and filtering
CREATE INDEX IF NOT EXISTS idx_milestones_case ON appdb.care_journey_milestones(surgical_case_id);
CREATE INDEX IF NOT EXISTS idx_milestones_status ON appdb.care_journey_milestones(status);
CREATE INDEX IF NOT EXISTS idx_milestones_type_status ON appdb.care_journey_milestones(milestone_type, status);

-- Support efficient transport coordination queries
CREATE INDEX IF NOT EXISTS idx_transport_case ON appdb.care_journey_transport(surgical_case_id);
CREATE INDEX IF NOT EXISTS idx_transport_status ON appdb.care_journey_transport(status);
CREATE INDEX IF NOT EXISTS idx_transport_timing ON appdb.care_journey_transport(planned_time);
CREATE INDEX IF NOT EXISTS idx_transport_assigned ON appdb.care_journey_transport(assigned_to, status);

-- Enable quick safety note retrieval and monitoring
CREATE INDEX IF NOT EXISTS idx_safety_notes_case ON appdb.care_journey_safety_notes(surgical_case_id);
CREATE INDEX IF NOT EXISTS idx_safety_notes_severity ON appdb.care_journey_safety_notes(severity);
CREATE INDEX IF NOT EXISTS idx_safety_notes_unacknowledged ON appdb.care_journey_safety_notes(acknowledged_at) 
WHERE acknowledged_at IS NULL;

-- Facilitate timing analysis and scheduling
CREATE INDEX IF NOT EXISTS idx_timings_case ON appdb.care_journey_timings(surgical_case_id);
CREATE INDEX IF NOT EXISTS idx_timings_phase ON appdb.care_journey_timings(phase, planned_start);
CREATE INDEX IF NOT EXISTS idx_timings_variance ON appdb.care_journey_timings(variance) 
WHERE variance IS NOT NULL;

-- Improve general case lookups
CREATE INDEX IF NOT EXISTS idx_surgical_cases_status ON appdb.surgical_cases(status);
CREATE INDEX IF NOT EXISTS idx_surgical_cases_scheduled ON appdb.surgical_cases(scheduled_time);


-- Add check constraints to surgical_cases to ensure valid status values
ALTER TABLE appdb.surgical_cases
ADD CONSTRAINT IF NOT EXISTS check_case_status 
CHECK (status IN ('Scheduled', 'Pre-Op', 'In Progress', 'Recovery', 'Completed', 'Delayed'));

-- Add validation for safety status values
ALTER TABLE appdb.surgical_cases
ADD CONSTRAINT IF NOT EXISTS check_safety_status 
CHECK (safety_status IN ('Normal', 'Review_Required', 'Alert'));

-- Ensure staff types are properly categorized
ALTER TABLE appdb.staff
ADD CONSTRAINT IF NOT EXISTS check_staff_type 
CHECK (staff_type IN ('Surgeon', 'Anesthesiologist', 'Nurse', 'Transport', 'Support'));

-- Add validation for timing calculations to prevent nonsensical values
ALTER TABLE appdb.care_journey_timings
ADD CONSTRAINT IF NOT EXISTS check_timing_duration 
CHECK (planned_duration > 0 AND (actual_duration IS NULL OR actual_duration > 0));

-- Ensure transport times make logical sense
ALTER TABLE appdb.care_journey_transport
ADD CONSTRAINT IF NOT EXISTS check_transport_times 
CHECK (actual_end IS NULL OR actual_start IS NULL OR actual_end > actual_start);

-- First create the trigger function
CREATE OR REPLACE FUNCTION appdb.update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Then create the triggers more safely
DO $$
DECLARE
    table_name text;
BEGIN
    FOR table_name IN 
        SELECT tablename 
        FROM pg_tables 
        WHERE schemaname = 'appdb' 
        AND tablename IN (
            'surgical_cases',
            'care_journey_milestones',
            'care_journey_transport',
            'care_journey_safety_notes',
            'care_journey_timings'
        )
    LOOP
        -- First drop the trigger if it exists
        EXECUTE 'DROP TRIGGER IF EXISTS update_updated_at ON appdb.' || quote_ident(table_name);
        
        -- Then create the new trigger
        EXECUTE 'CREATE TRIGGER update_updated_at 
                BEFORE UPDATE ON appdb.' || quote_ident(table_name) || '
                FOR EACH ROW 
                EXECUTE FUNCTION appdb.update_updated_at_column()';
    END LOOP;
END $$;

