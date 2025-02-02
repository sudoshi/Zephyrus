-- First, let's handle the hospital_units modifications
DO $$ 
BEGIN 
    -- Add unit_type if it doesn't exist
    IF NOT EXISTS (SELECT 1 
                   FROM information_schema.columns 
                   WHERE table_schema = 'appdb' 
                   AND table_name = 'hospital_units' 
                   AND column_name = 'unit_type') THEN
        ALTER TABLE appdb.hospital_units
        ADD COLUMN unit_type varchar(50) DEFAULT 'general' NOT NULL;
    END IF;

    -- Add capacity if it doesn't exist
    IF NOT EXISTS (SELECT 1 
                   FROM information_schema.columns 
                   WHERE table_schema = 'appdb' 
                   AND table_name = 'hospital_units' 
                   AND column_name = 'capacity') THEN
        ALTER TABLE appdb.hospital_units
        ADD COLUMN capacity integer DEFAULT 0 NOT NULL;
    END IF;

    -- Add the check constraint if it doesn't exist
    IF NOT EXISTS (SELECT 1 
                   FROM information_schema.table_constraints 
                   WHERE constraint_schema = 'appdb' 
                   AND constraint_name = 'hospital_units_unit_type_check') THEN
        ALTER TABLE appdb.hospital_units
        ADD CONSTRAINT hospital_units_unit_type_check
        CHECK (unit_type IN ('general', 'OR', 'pre_op', 'post_op', 'cath_lab', 'L&D'));
    END IF;
END $$;

-- Handle the staff table modifications
DO $$
BEGIN
    -- Add specialty columns if they don't exist
    IF NOT EXISTS (SELECT 1 
                   FROM information_schema.columns 
                   WHERE table_schema = 'appdb' 
                   AND table_name = 'staff' 
                   AND column_name = 'specialty') THEN
        ALTER TABLE appdb.staff
        ADD COLUMN specialty varchar(50) NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 
                   FROM information_schema.columns 
                   WHERE table_schema = 'appdb' 
                   AND table_name = 'staff' 
                   AND column_name = 'sub_specialty') THEN
        ALTER TABLE appdb.staff
        ADD COLUMN sub_specialty varchar(50) NULL;
    END IF;

    -- Add staff specialty check constraint if it doesn't exist
    IF NOT EXISTS (SELECT 1 
                   FROM information_schema.table_constraints 
                   WHERE constraint_schema = 'appdb' 
                   AND constraint_name = 'staff_specialty_check') THEN
        ALTER TABLE appdb.staff
        ADD CONSTRAINT staff_specialty_check
        CHECK (specialty IN ('General Surgery', 'Orthopedics', 'OBGYN', 'Cardiac', 'Cath Lab'));
    END IF;
END $$;

-- Now create the new tables and their dependencies in the correct order
DO $$
BEGIN
    -- First, create the procedures table if it doesn't exist
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables 
                  WHERE table_schema = 'appdb' AND table_name = 'procedures') THEN
        CREATE TABLE appdb.procedures (
            id bigserial PRIMARY KEY,
            name varchar(255) NOT NULL,
            specialty varchar(50) NOT NULL,
            typical_duration integer NOT NULL,
            complexity_score decimal(3,1) NOT NULL,
            created_at timestamp(0) NULL,
            updated_at timestamp(0) NULL,
            CONSTRAINT procedures_specialty_check
                CHECK (specialty IN ('General Surgery', 'Orthopedics', 'OBGYN', 'Cardiac', 'Cath Lab'))
        );
    END IF;

    -- Then create the surgical_cases table if it doesn't exist
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables 
                  WHERE table_schema = 'appdb' AND table_name = 'surgical_cases') THEN
        CREATE TABLE appdb.surgical_cases (
            id bigserial PRIMARY KEY,
            mrn varchar(20) NOT NULL,
            patient_id bigint NOT NULL,
            procedure_id bigint NOT NULL,
            primary_surgeon_id bigint NOT NULL,
            hospital_unit_id bigint NOT NULL,
            status varchar(50) NOT NULL,
            phase varchar(50) NOT NULL,
            scheduled_start_time timestamp(0) NOT NULL,
            expected_duration integer NOT NULL,
            actual_start_time timestamp(0) NULL,
            actual_duration integer NULL,
            journey_progress integer DEFAULT 0,
            resource_status varchar(50) DEFAULT 'On Time',
            created_at timestamp(0) NULL,
            updated_at timestamp(0) NULL,
            CONSTRAINT surgical_cases_status_check
                CHECK (status IN ('Scheduled', 'Pre-Op', 'In Progress', 'Recovery', 'Completed', 'Delayed')),
            CONSTRAINT surgical_cases_phase_check
                CHECK (phase IN ('Pre-Op', 'Procedure', 'Recovery')),
            CONSTRAINT surgical_cases_resource_status_check
                CHECK (resource_status IN ('On Time', 'Delayed', 'Warning')),
            CONSTRAINT surgical_cases_patient_id_fkey
                FOREIGN KEY (patient_id) REFERENCES appdb.patients(id),
            CONSTRAINT surgical_cases_procedure_id_fkey
                FOREIGN KEY (procedure_id) REFERENCES appdb.procedures(id),
            CONSTRAINT surgical_cases_surgeon_id_fkey
                FOREIGN KEY (primary_surgeon_id) REFERENCES appdb.staff(id),
            CONSTRAINT surgical_cases_unit_id_fkey
                FOREIGN KEY (hospital_unit_id) REFERENCES appdb.hospital_units(id)
        );

        -- Create indexes immediately after creating the table
        CREATE INDEX idx_active_cases ON appdb.surgical_cases(status)
            WHERE status IN ('Pre-Op', 'In Progress', 'Recovery');
        CREATE INDEX idx_case_schedule ON appdb.surgical_cases(scheduled_start_time);
    END IF;

    -- Finally create the case_tracking table if it doesn't exist
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables 
                  WHERE table_schema = 'appdb' AND table_name = 'case_tracking') THEN
        CREATE TABLE appdb.case_tracking (
            id bigserial PRIMARY KEY,
            surgical_case_id bigint NOT NULL,
            tracked_at timestamp(0) DEFAULT CURRENT_TIMESTAMP NOT NULL,
            current_phase varchar(50) NOT NULL,
            progress_percentage integer NOT NULL,
            status_update text NULL,
            updated_by bigint NULL,
            CONSTRAINT case_tracking_phase_check
                CHECK (current_phase IN ('Pre-Op', 'Procedure', 'Recovery')),
            CONSTRAINT case_tracking_case_id_fkey
                FOREIGN KEY (surgical_case_id) REFERENCES appdb.surgical_cases(id),
            CONSTRAINT case_tracking_user_id_fkey
                FOREIGN KEY (updated_by) REFERENCES appdb.users(id)
        );
    END IF;
END $$;