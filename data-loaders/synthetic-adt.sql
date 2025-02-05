BEGIN;

------------------------------------------------------------------------------
-- Drop & Create the fhir schema
------------------------------------------------------------------------------

-- DROP SCHEMA fhir;  -- Uncomment if you want to remove the entire schema first
-- CREATE SCHEMA IF NOT EXISTS fhir AUTHORIZATION postgres;

------------------------------------------------------------------------------
-- PGCRYPTO FUNCTIONS (these were in your script, leaving them as-is)
------------------------------------------------------------------------------

-- DROP FUNCTION fhir.armor(bytea);
CREATE OR REPLACE FUNCTION fhir.armor(bytea)
 RETURNS text
 LANGUAGE c
 IMMUTABLE PARALLEL SAFE STRICT
AS '$libdir/pgcrypto', $function$pg_armor$function$
;

-- ... (All your fhir.pgcrypto function definitions follow) ...
-- For brevity, we won’t repeat every single function body here,
-- but include them if you need them exactly as in your script:

-- DROP FUNCTION fhir.armor(bytea, _text, _text);
CREATE OR REPLACE FUNCTION fhir.armor(bytea, text[], text[])
 RETURNS text
 LANGUAGE c
 IMMUTABLE PARALLEL SAFE STRICT
AS '$libdir/pgcrypto', $function$pg_armor$function$
;

-- DROP FUNCTION fhir.crypt(text, text);
CREATE OR REPLACE FUNCTION fhir.crypt(text, text)
 RETURNS text
 LANGUAGE c
 IMMUTABLE PARALLEL SAFE STRICT
AS '$libdir/pgcrypto', $function$pg_crypt$function$
;

-- ... (continue including all the CREATE OR REPLACE FUNCTION statements) ...

------------------------------------------------------------------------------
-- FHIR Tables Provided in Your Script
------------------------------------------------------------------------------

-- fhir.fhir_extension_definition
-- DROP TABLE fhir.fhir_extension_definition;
CREATE TABLE fhir.fhir_extension_definition (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	url varchar(200) NOT NULL,
	"name" varchar(100) NOT NULL,
	context _varchar NOT NULL,
	"type" varchar(50) NOT NULL,
	is_modifier bool DEFAULT false NULL,
	CONSTRAINT fhir_extension_definition_pkey PRIMARY KEY (id),
	CONSTRAINT fhir_extension_definition_url_key UNIQUE (url)
);

-- fhir.fhir_resource
-- DROP TABLE fhir.fhir_resource;
CREATE TABLE fhir.fhir_resource (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	resource_type varchar(50) NOT NULL,
	resource_json jsonb NOT NULL,
	last_updated timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
	CONSTRAINT fhir_resource_pkey PRIMARY KEY (id)
);
CREATE INDEX idx_fhir_resource_last_updated ON fhir.fhir_resource USING btree (last_updated);
CREATE INDEX idx_fhir_resource_resource_json ON fhir.fhir_resource USING gin (resource_json);
CREATE INDEX idx_fhir_resource_type ON fhir.fhir_resource USING btree (resource_type);

-- fhir.fhir_terminology_mapping
-- DROP TABLE fhir.fhir_terminology_mapping;
CREATE TABLE fhir.fhir_terminology_mapping (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	local_code varchar(100) NOT NULL,
	local_system varchar(100) NOT NULL,
	fhir_code varchar(100) NOT NULL,
	fhir_system varchar(200) NOT NULL,
	mapping_type varchar(50) NOT NULL,
	CONSTRAINT fhir_terminology_mapping_pkey PRIMARY KEY (id)
);
CREATE INDEX idx_terminology_fhir ON fhir.fhir_terminology_mapping USING btree (fhir_code, fhir_system);
CREATE INDEX idx_terminology_local ON fhir.fhir_terminology_mapping USING btree (local_code, local_system);

-- fhir.care_area
-- DROP TABLE fhir.care_area;
CREATE TABLE fhir.care_area (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	fhir_location_id uuid NULL,
	area_type varchar(50) NOT NULL,
	parent_area_id uuid NULL,
	CONSTRAINT care_area_pkey PRIMARY KEY (id),
	CONSTRAINT valid_area_type CHECK (
	  (area_type)::text = ANY (
	    (ARRAY['ED','INPATIENT_UNIT','OR','PACU','PREOP'])::text[]
	  )
	),
	CONSTRAINT care_area_fhir_location_id_fkey FOREIGN KEY (fhir_location_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT care_area_parent_area_id_fkey FOREIGN KEY (parent_area_id) REFERENCES fhir.care_area(id)
);

-- fhir.clinical_workflow
-- DROP TABLE fhir.clinical_workflow;
CREATE TABLE fhir.clinical_workflow (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	patient_id uuid NOT NULL,
	encounter_id uuid NOT NULL,
	workflow_type varchar(50) NOT NULL,
	current_phase varchar(50) NOT NULL,
	start_time timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
	expected_end_time timestamptz NULL,
	actual_end_time timestamptz NULL,
	CONSTRAINT clinical_workflow_pkey PRIMARY KEY (id),
	CONSTRAINT valid_workflow_type CHECK (
	  (workflow_type)::text = ANY (
	    (ARRAY['ED','INPATIENT','PERIOP'])::text[]
	  )
	),
	CONSTRAINT clinical_workflow_encounter_id_fkey FOREIGN KEY (encounter_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT clinical_workflow_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.facility
-- DROP TABLE fhir.facility;
CREATE TABLE fhir.facility (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	fhir_organization_id uuid NULL,
	facility_name varchar(100) NOT NULL,
	facility_type varchar(50) NOT NULL,
	parent_facility_id uuid NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	CONSTRAINT facility_pkey PRIMARY KEY (id),
	CONSTRAINT facility_fhir_organization_id_fkey FOREIGN KEY (fhir_organization_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT facility_parent_facility_id_fkey FOREIGN KEY (parent_facility_id) REFERENCES fhir.facility(id)
);

-- fhir.fhir_extension_value
-- DROP TABLE fhir.fhir_extension_value;
CREATE TABLE fhir.fhir_extension_value (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	definition_id uuid NOT NULL,
	resource_id uuid NOT NULL,
	value_string text NULL,
	value_integer int4 NULL,
	value_decimal numeric NULL,
	value_boolean bool NULL,
	value_datetime timestamptz NULL,
	CONSTRAINT fhir_extension_value_pkey PRIMARY KEY (id),
	CONSTRAINT fhir_extension_value_definition_id_fkey FOREIGN KEY (definition_id) REFERENCES fhir.fhir_extension_definition(id),
	CONSTRAINT fhir_extension_value_resource_id_fkey FOREIGN KEY (resource_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.fhir_resource_mapping
-- DROP TABLE fhir.fhir_resource_mapping;
CREATE TABLE fhir.fhir_resource_mapping (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	local_table varchar(100) NOT NULL,
	local_id uuid NOT NULL,
	fhir_resource_id uuid NOT NULL,
	mapping_type varchar(50) NOT NULL,
	last_synced timestamptz DEFAULT CURRENT_TIMESTAMP NULL,
	CONSTRAINT fhir_resource_mapping_pkey PRIMARY KEY (id),
	CONSTRAINT fhir_resource_mapping_fhir_resource_id_fkey FOREIGN KEY (fhir_resource_id) REFERENCES fhir.fhir_resource(id)
);
CREATE INDEX idx_fhir_mapping_local ON fhir.fhir_resource_mapping USING btree (local_table, local_id);
CREATE INDEX idx_fhir_mapping_resource ON fhir.fhir_resource_mapping USING btree (fhir_resource_id);

-- fhir.nursing_assignment
-- DROP TABLE fhir.nursing_assignment;
CREATE TABLE fhir.nursing_assignment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	nurse_id uuid NOT NULL,
	patient_id uuid NOT NULL,
	care_area_id uuid NOT NULL,
	shift_start timestamptz NOT NULL,
	shift_end timestamptz NOT NULL,
	assignment_type varchar(50) NOT NULL,
	CONSTRAINT nursing_assignment_pkey PRIMARY KEY (id),
	CONSTRAINT valid_assignment_type CHECK (
	  (assignment_type)::text = ANY (
	    (ARRAY['PRIMARY','SECONDARY','CHARGE','RESOURCE'])::text[]
	  )
	),
	CONSTRAINT nursing_assignment_care_area_id_fkey FOREIGN KEY (care_area_id) REFERENCES fhir.care_area(id),
	CONSTRAINT nursing_assignment_nurse_id_fkey FOREIGN KEY (nurse_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT nursing_assignment_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.patient_admission
-- DROP TABLE fhir.patient_admission;
CREATE TABLE fhir.patient_admission (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	patient_id uuid NOT NULL,
	encounter_id uuid NOT NULL,
	admission_time timestamptz NOT NULL,
	discharge_time timestamptz NULL,
	admission_type varchar(50) NOT NULL,
	admission_source varchar(50) NOT NULL,
	expected_los_days numeric(5,2) NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT patient_admission_pkey PRIMARY KEY (id),
	CONSTRAINT valid_admission_status CHECK (
	  (status)::text = ANY (
	    (ARRAY['PENDING','ADMITTED','DISCHARGED','CANCELLED'])::text[]
	  )
	),
	CONSTRAINT patient_admission_encounter_id_fkey FOREIGN KEY (encounter_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT patient_admission_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES fhir.fhir_resource(id)
);
CREATE INDEX idx_admission_patient ON fhir.patient_admission USING btree (patient_id);
CREATE INDEX idx_admission_status ON fhir.patient_admission USING btree (status);

-- fhir.patient_location
-- DROP TABLE fhir.patient_location;
CREATE TABLE fhir.patient_location (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	patient_id uuid NOT NULL,
	encounter_id uuid NOT NULL,  -- We'll fix the foreign key below
	care_area_id uuid NOT NULL,
	bed_id uuid NULL,
	status varchar(50) NOT NULL,
	start_time timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
	end_time timestamptz NULL,
	CONSTRAINT patient_location_pkey PRIMARY KEY (id),
	CONSTRAINT valid_status CHECK (
	  (status)::text = ANY (
	    (ARRAY['PENDING','ACTIVE','COMPLETED','CANCELLED'])::text[]
	  )
	),
	-- We'll drop & re-add this constraint below to reference patient_admission
	CONSTRAINT patient_location_bed_id_fkey FOREIGN KEY (bed_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT patient_location_care_area_id_fkey FOREIGN KEY (care_area_id) REFERENCES fhir.care_area(id),
	CONSTRAINT patient_location_encounter_id_fkey FOREIGN KEY (encounter_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT patient_location_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.pregnancy_record
-- DROP TABLE fhir.pregnancy_record;
CREATE TABLE fhir.pregnancy_record (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	patient_id uuid NOT NULL,
	gravida int4 NOT NULL,
	para int4 NOT NULL,
	edd date NOT NULL,
	risk_factors jsonb NULL,
	current_gestational_age int4 NULL,
	fhir_condition_id uuid NULL,
	CONSTRAINT pregnancy_record_pkey PRIMARY KEY (id),
	CONSTRAINT pregnancy_record_fhir_condition_id_fkey FOREIGN KEY (fhir_condition_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT pregnancy_record_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.quality_measure
-- DROP TABLE fhir.quality_measure;
CREATE TABLE fhir.quality_measure (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	measure_type varchar(50) NOT NULL,
	measure_time timestamptz NOT NULL,
	measure_value jsonb NOT NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT quality_measure_pkey PRIMARY KEY (id),
	CONSTRAINT quality_measure_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id)
);
CREATE INDEX idx_quality_measure_type ON fhir.quality_measure USING btree (measure_type);

-- fhir.rehab_evaluation
-- DROP TABLE fhir.rehab_evaluation;
CREATE TABLE fhir.rehab_evaluation (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	evaluation_type varchar(50) NOT NULL,
	evaluation_time timestamptz NOT NULL,
	functional_scores jsonb NOT NULL,
	treatment_goals jsonb NOT NULL,
	fhir_observation_id uuid NULL,
	CONSTRAINT rehab_evaluation_pkey PRIMARY KEY (id),
	CONSTRAINT rehab_evaluation_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT rehab_evaluation_fhir_observation_id_fkey FOREIGN KEY (fhir_observation_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.workflow_transition
-- DROP TABLE fhir.workflow_transition;
CREATE TABLE fhir.workflow_transition (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	workflow_id uuid NOT NULL,
	from_phase varchar(50) NOT NULL,
	to_phase varchar(50) NOT NULL,
	transition_time timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
	triggered_by uuid NULL,
	reason_code varchar(100) NULL,
	CONSTRAINT workflow_transition_pkey PRIMARY KEY (id),
	CONSTRAINT workflow_transition_triggered_by_fkey FOREIGN KEY (triggered_by) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT workflow_transition_workflow_id_fkey FOREIGN KEY (workflow_id) REFERENCES fhir.clinical_workflow(id)
);

-- fhir.care_plan
-- DROP TABLE fhir.care_plan;
CREATE TABLE fhir.care_plan (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	care_plan_type varchar(50) NOT NULL,
	start_time timestamptz NOT NULL,
	end_time timestamptz NULL,
	status varchar(20) NOT NULL,
	plan_details jsonb NOT NULL,
	CONSTRAINT care_plan_pkey PRIMARY KEY (id),
	CONSTRAINT care_plan_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id)
);

-- fhir.clinical_event
-- DROP TABLE fhir.clinical_event;
CREATE TABLE fhir.clinical_event (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	event_type varchar(50) NOT NULL,
	event_time timestamptz NOT NULL,
	severity varchar(20) NOT NULL,
	event_details jsonb NOT NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT clinical_event_pkey PRIMARY KEY (id),
	CONSTRAINT clinical_event_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id)
);

-- fhir.department
-- DROP TABLE fhir.department;
CREATE TABLE fhir.department (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	facility_id uuid NOT NULL,
	fhir_organization_id uuid NULL,
	department_name varchar(100) NOT NULL,
	department_type varchar(50) NOT NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	CONSTRAINT department_pkey PRIMARY KEY (id),
	CONSTRAINT department_facility_id_fkey FOREIGN KEY (facility_id) REFERENCES fhir.facility(id),
	CONSTRAINT department_fhir_organization_id_fkey FOREIGN KEY (fhir_organization_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.ed_visit
-- DROP TABLE fhir.ed_visit;
CREATE TABLE fhir.ed_visit (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	arrival_time timestamptz NOT NULL,
	triage_time timestamptz NULL,
	provider_time timestamptz NULL,
	disposition_time timestamptz NULL,
	departure_time timestamptz NULL,
	acuity_level int4 NOT NULL,
	chief_complaint text NOT NULL,
	disposition_type varchar(50) NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT ed_visit_pkey PRIMARY KEY (id),
	CONSTRAINT ed_visit_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id)
);
CREATE INDEX idx_ed_visit_arrival ON fhir.ed_visit USING btree (arrival_time);

-- fhir.icu_monitoring
-- DROP TABLE fhir.icu_monitoring;
CREATE TABLE fhir.icu_monitoring (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	monitoring_time timestamptz NOT NULL,
	vital_signs jsonb NOT NULL,
	ventilator_settings jsonb NULL,
	medication_drips jsonb NULL,
	neurological_status jsonb NULL,
	hemodynamic_parameters jsonb NULL,
	fhir_observation_id uuid NULL,
	CONSTRAINT icu_monitoring_pkey PRIMARY KEY (id),
	CONSTRAINT icu_monitoring_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT icu_monitoring_fhir_observation_id_fkey FOREIGN KEY (fhir_observation_id) REFERENCES fhir.fhir_resource(id)
);
CREATE INDEX idx_icu_monitoring_time ON fhir.icu_monitoring USING btree (monitoring_time);

-- fhir.labor_tracking
-- DROP TABLE fhir.labor_tracking;
CREATE TABLE fhir.labor_tracking (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	pregnancy_record_id uuid NOT NULL,
	assessment_time timestamptz NOT NULL,
	cervical_dilation int4 NULL,
	contraction_pattern jsonb NULL,
	fetal_heart_rate jsonb NULL,
	fhir_observation_id uuid NULL,
	CONSTRAINT labor_tracking_pkey PRIMARY KEY (id),
	CONSTRAINT labor_tracking_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT labor_tracking_fhir_observation_id_fkey FOREIGN KEY (fhir_observation_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT labor_tracking_pregnancy_record_id_fkey FOREIGN KEY (pregnancy_record_id) REFERENCES fhir.pregnancy_record(id)
);
CREATE INDEX idx_labor_tracking_time ON fhir.labor_tracking USING btree (assessment_time);

-- fhir.staff
-- DROP TABLE fhir.staff;
CREATE TABLE fhir.staff (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	fhir_practitioner_id uuid NULL,
	primary_department_id uuid NULL,
	staff_type varchar(50) NOT NULL,
	specialties _varchar NOT NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	start_date date NOT NULL,
	end_date date NULL,
	CONSTRAINT staff_pkey PRIMARY KEY (id),
	CONSTRAINT staff_fhir_practitioner_id_fkey FOREIGN KEY (fhir_practitioner_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT staff_primary_department_id_fkey FOREIGN KEY (primary_department_id) REFERENCES fhir.department(id)
);

-- fhir.staff_credential
-- DROP TABLE fhir.staff_credential;
CREATE TABLE fhir.staff_credential (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	staff_id uuid NOT NULL,
	credential_type varchar(50) NOT NULL,
	credential_number varchar(100) NULL,
	issuing_body varchar(100) NULL,
	issue_date date NOT NULL,
	expiry_date date NOT NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	CONSTRAINT staff_credential_pkey PRIMARY KEY (id),
	CONSTRAINT staff_credential_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES fhir.staff(id)
);

-- fhir.therapy_session
-- DROP TABLE fhir.therapy_session;
CREATE TABLE fhir.therapy_session (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	evaluation_id uuid NOT NULL,
	therapist_id uuid NOT NULL,
	session_time timestamptz NOT NULL,
	therapy_type varchar(50) NOT NULL,
	activities_performed jsonb NOT NULL,
	progress_notes jsonb NULL,
	fhir_procedure_id uuid NULL,
	CONSTRAINT therapy_session_pkey PRIMARY KEY (id),
	CONSTRAINT therapy_session_evaluation_id_fkey FOREIGN KEY (evaluation_id) REFERENCES fhir.rehab_evaluation(id),
	CONSTRAINT therapy_session_fhir_procedure_id_fkey FOREIGN KEY (fhir_procedure_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT therapy_session_therapist_id_fkey FOREIGN KEY (therapist_id) REFERENCES fhir.staff(id)
);
CREATE INDEX idx_therapy_session_time ON fhir.therapy_session USING btree (session_time);

-- fhir.trauma_activation
-- DROP TABLE fhir.trauma_activation;
CREATE TABLE fhir.trauma_activation (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	ed_visit_id uuid NOT NULL,
	activation_time timestamptz NOT NULL,
	activation_level varchar(50) NOT NULL,
	mechanism_of_injury jsonb NULL,
	team_response jsonb NULL,
	fhir_flag_id uuid NULL,
	CONSTRAINT trauma_activation_pkey PRIMARY KEY (id),
	CONSTRAINT trauma_activation_ed_visit_id_fkey FOREIGN KEY (ed_visit_id) REFERENCES fhir.ed_visit(id),
	CONSTRAINT trauma_activation_fhir_flag_id_fkey FOREIGN KEY (fhir_flag_id) REFERENCES fhir.fhir_resource(id)
);
CREATE INDEX idx_trauma_activation_time ON fhir.trauma_activation USING btree (activation_time);

-- fhir.unit
-- DROP TABLE fhir.unit;
CREATE TABLE fhir.unit (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	department_id uuid NOT NULL,
	fhir_location_id uuid NULL,
	unit_name varchar(100) NOT NULL,
	unit_type varchar(50) NOT NULL,
	capacity_beds int4 NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	CONSTRAINT unit_pkey PRIMARY KEY (id),
	CONSTRAINT unit_department_id_fkey FOREIGN KEY (department_id) REFERENCES fhir.department(id),
	CONSTRAINT unit_fhir_location_id_fkey FOREIGN KEY (fhir_location_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.care_team_assignment
-- DROP TABLE fhir.care_team_assignment;
CREATE TABLE fhir.care_team_assignment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	staff_id uuid NOT NULL,
	role_type varchar(50) NOT NULL,
	assignment_start timestamptz NOT NULL,
	assignment_end timestamptz NULL,
	fhir_careteam_id uuid NULL,
	CONSTRAINT care_team_assignment_pkey PRIMARY KEY (id),
	CONSTRAINT care_team_assignment_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT care_team_assignment_fhir_careteam_id_fkey FOREIGN KEY (fhir_careteam_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT care_team_assignment_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES fhir.staff(id)
);
CREATE INDEX idx_care_team_assignment_time ON fhir.care_team_assignment USING btree (assignment_start, assignment_end);

-- fhir.clinical_assessment
-- DROP TABLE fhir.clinical_assessment;
CREATE TABLE fhir.clinical_assessment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	staff_id uuid NOT NULL,
	assessment_type varchar(50) NOT NULL,
	assessment_time timestamptz NOT NULL,
	acuity_score int4 NULL,
	assessment_data jsonb NOT NULL,
	CONSTRAINT clinical_assessment_pkey PRIMARY KEY (id),
	CONSTRAINT clinical_assessment_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT clinical_assessment_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES fhir.staff(id)
);

-- fhir.dialysis_prescription
-- DROP TABLE fhir.dialysis_prescription;
CREATE TABLE fhir.dialysis_prescription (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	patient_id uuid NOT NULL,
	prescribed_by uuid NOT NULL,
	prescription_date date NOT NULL,
	dialysate_composition jsonb NOT NULL,
	blood_flow_rate int4 NULL,
	treatment_duration int4 NULL,
	fhir_careplan_id uuid NULL,
	CONSTRAINT dialysis_prescription_pkey PRIMARY KEY (id),
	CONSTRAINT dialysis_prescription_fhir_careplan_id_fkey FOREIGN KEY (fhir_careplan_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT dialysis_prescription_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT dialysis_prescription_prescribed_by_fkey FOREIGN KEY (prescribed_by) REFERENCES fhir.staff(id)
);

-- fhir.dialysis_treatment
-- DROP TABLE fhir.dialysis_treatment;
CREATE TABLE fhir.dialysis_treatment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	prescription_id uuid NOT NULL,
	start_time timestamptz NOT NULL,
	end_time timestamptz NULL,
	actual_parameters jsonb NOT NULL,
	complications jsonb NULL,
	fhir_procedure_id uuid NULL,
	CONSTRAINT dialysis_treatment_pkey PRIMARY KEY (id),
	CONSTRAINT dialysis_treatment_fhir_procedure_id_fkey FOREIGN KEY (fhir_procedure_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT dialysis_treatment_prescription_id_fkey FOREIGN KEY (prescription_id) REFERENCES fhir.dialysis_prescription(id)
);
CREATE INDEX idx_dialysis_treatment_time ON fhir.dialysis_treatment USING btree (start_time);

-- fhir.ed_imaging_tracking
-- DROP TABLE fhir.ed_imaging_tracking;
CREATE TABLE fhir.ed_imaging_tracking (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	ed_visit_id uuid NOT NULL,
	imaging_type varchar(50) NOT NULL,
	ordered_time timestamptz NOT NULL,
	completed_time timestamptz NULL,
	report_time timestamptz NULL,
	status varchar(20) NOT NULL,
	fhir_servicerequest_id uuid NULL,
	CONSTRAINT ed_imaging_tracking_pkey PRIMARY KEY (id),
	CONSTRAINT ed_imaging_tracking_ed_visit_id_fkey FOREIGN KEY (ed_visit_id) REFERENCES fhir.ed_visit(id),
	CONSTRAINT ed_imaging_tracking_fhir_servicerequest_id_fkey FOREIGN KEY (fhir_servicerequest_id) REFERENCES fhir.fhir_resource(id)
);

-- fhir.equipment
-- DROP TABLE fhir.equipment;
CREATE TABLE fhir.equipment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	unit_id uuid NOT NULL,
	equipment_type varchar(50) NOT NULL,
	serial_number varchar(100) NULL,
	status varchar(20) NOT NULL,
	last_maintenance_date timestamptz NULL,
	next_maintenance_date timestamptz NULL,
	CONSTRAINT equipment_pkey PRIMARY KEY (id),
	CONSTRAINT equipment_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES fhir.unit(id)
);
CREATE INDEX idx_equipment_status ON fhir.equipment USING btree (status);

-- fhir.equipment_assignment
-- DROP TABLE fhir.equipment_assignment;
CREATE TABLE fhir.equipment_assignment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	equipment_id uuid NOT NULL,
	admission_id uuid NOT NULL,
	start_time timestamptz NOT NULL,
	end_time timestamptz NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT equipment_assignment_pkey PRIMARY KEY (id),
	CONSTRAINT equipment_assignment_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT equipment_assignment_equipment_id_fkey FOREIGN KEY (equipment_id) REFERENCES fhir.equipment(id)
);

-- fhir.interdisciplinary_notes
-- DROP TABLE fhir.interdisciplinary_notes;
CREATE TABLE fhir.interdisciplinary_notes (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	staff_id uuid NOT NULL,
	note_time timestamptz NOT NULL,
	note_type varchar(50) NOT NULL,
	"content" text NOT NULL,
	fhir_composition_id uuid NULL,
	CONSTRAINT interdisciplinary_notes_pkey PRIMARY KEY (id),
	CONSTRAINT interdisciplinary_notes_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT interdisciplinary_notes_fhir_composition_id_fkey FOREIGN KEY (fhir_composition_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT interdisciplinary_notes_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES fhir.staff(id)
);

-- fhir.order_entry
-- DROP TABLE fhir.order_entry;
CREATE TABLE fhir.order_entry (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	ordering_staff_id uuid NOT NULL,
	order_type varchar(50) NOT NULL,
	order_time timestamptz NOT NULL,
	start_time timestamptz NOT NULL,
	end_time timestamptz NULL,
	status varchar(20) NOT NULL,
	priority varchar(20) NOT NULL,
	order_details jsonb NOT NULL,
	CONSTRAINT order_entry_pkey PRIMARY KEY (id),
	CONSTRAINT order_entry_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT order_entry_ordering_staff_id_fkey FOREIGN KEY (ordering_staff_id) REFERENCES fhir.staff(id)
);

-- fhir.room
-- DROP TABLE fhir.room;
CREATE TABLE fhir.room (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	unit_id uuid NOT NULL,
	fhir_location_id uuid NULL,
	room_number varchar(20) NOT NULL,
	room_type varchar(50) NOT NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	is_isolation bool DEFAULT false NULL,
	is_negative_pressure bool DEFAULT false NULL,
	telemetry_capable bool DEFAULT false NULL,
	CONSTRAINT room_pkey PRIMARY KEY (id),
	CONSTRAINT room_fhir_location_id_fkey FOREIGN KEY (fhir_location_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT room_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES fhir.unit(id)
);

-- fhir.staff_schedule
-- DROP TABLE fhir.staff_schedule;
CREATE TABLE fhir.staff_schedule (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	staff_id uuid NOT NULL,
	unit_id uuid NOT NULL,
	shift_start timestamptz NOT NULL,
	shift_end timestamptz NOT NULL,
	shift_type varchar(50) NOT NULL,
	role_type varchar(50) NOT NULL,
	status varchar(20) DEFAULT 'SCHEDULED' NULL,
	CONSTRAINT staff_schedule_pkey PRIMARY KEY (id),
	CONSTRAINT valid_schedule_status CHECK (
	  (status)::text = ANY (
	    (ARRAY['SCHEDULED','COMPLETED','CANCELLED'])::text[]
	  )
	),
	CONSTRAINT staff_schedule_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES fhir.staff(id),
	CONSTRAINT staff_schedule_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES fhir.unit(id)
);
CREATE INDEX idx_staff_schedule_time ON fhir.staff_schedule USING btree (shift_start, shift_end);

-- fhir.surgical_case
-- DROP TABLE fhir.surgical_case;
CREATE TABLE fhir.surgical_case (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	primary_surgeon_id uuid NOT NULL,
	room_id uuid NOT NULL,
	scheduled_start_time timestamptz NOT NULL,
	scheduled_duration_minutes int4 NOT NULL,
	actual_start_time timestamptz NULL,
	actual_end_time timestamptz NULL,
	case_type varchar(50) NOT NULL,
	priority varchar(20) NOT NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT surgical_case_pkey PRIMARY KEY (id),
	CONSTRAINT valid_case_status CHECK (
	  (status)::text = ANY (
	    (ARRAY['SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED'])::text[]
	  )
	),
	CONSTRAINT surgical_case_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT surgical_case_primary_surgeon_id_fkey FOREIGN KEY (primary_surgeon_id) REFERENCES fhir.staff(id),
	CONSTRAINT surgical_case_room_id_fkey FOREIGN KEY (room_id) REFERENCES fhir.room(id)
);
CREATE INDEX idx_surgical_case_date ON fhir.surgical_case USING btree (scheduled_start_time);

-- fhir.ventilator_management
-- DROP TABLE fhir.ventilator_management;
CREATE TABLE fhir.ventilator_management (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	start_time timestamptz NOT NULL,
	end_time timestamptz NULL,
	ventilator_id uuid NULL,
	settings jsonb NOT NULL,
	compliance_metrics jsonb NULL,
	fhir_device_id uuid NULL,
	fhir_procedure_id uuid NULL,
	CONSTRAINT ventilator_management_pkey PRIMARY KEY (id),
	CONSTRAINT ventilator_management_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT ventilator_management_fhir_device_id_fkey FOREIGN KEY (fhir_device_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT ventilator_management_fhir_procedure_id_fkey FOREIGN KEY (fhir_procedure_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT ventilator_management_ventilator_id_fkey FOREIGN KEY (ventilator_id) REFERENCES fhir.equipment(id)
);

-- fhir.anesthesia_record
-- DROP TABLE fhir.anesthesia_record;
CREATE TABLE fhir.anesthesia_record (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	surgical_case_id uuid NOT NULL,
	anesthesiologist_id uuid NOT NULL,
	anesthesia_type varchar(50) NOT NULL,
	induction_time timestamptz NULL,
	emergence_time timestamptz NULL,
	vital_signs_trend jsonb NULL,
	medications_given jsonb NULL,
	fhir_procedure_id uuid NULL,
	CONSTRAINT anesthesia_record_pkey PRIMARY KEY (id),
	CONSTRAINT anesthesia_record_anesthesiologist_id_fkey FOREIGN KEY (anesthesiologist_id) REFERENCES fhir.staff(id),
	CONSTRAINT anesthesia_record_fhir_procedure_id_fkey FOREIGN KEY (fhir_procedure_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT anesthesia_record_surgical_case_id_fkey FOREIGN KEY (surgical_case_id) REFERENCES fhir.surgical_case(id)
);

-- fhir.bed
-- DROP TABLE fhir.bed;
CREATE TABLE fhir.bed (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	room_id uuid NOT NULL,
	fhir_location_id uuid NULL,
	bed_number varchar(20) NOT NULL,
	bed_type varchar(50) NOT NULL,
	status varchar(20) DEFAULT 'ACTIVE' NULL,
	is_monitored bool DEFAULT false NULL,
	equipment_requirements jsonb NULL,
	CONSTRAINT bed_pkey PRIMARY KEY (id),
	CONSTRAINT bed_fhir_location_id_fkey FOREIGN KEY (fhir_location_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT bed_room_id_fkey FOREIGN KEY (room_id) REFERENCES fhir.room(id)
);

-- fhir.case_milestone
-- DROP TABLE fhir.case_milestone;
CREATE TABLE fhir.case_milestone (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	surgical_case_id uuid NOT NULL,
	milestone_type varchar(50) NOT NULL,
	expected_time timestamptz NOT NULL,
	actual_time timestamptz NULL,
	status varchar(20) NOT NULL,
	delay_reason varchar(100) NULL,
	CONSTRAINT case_milestone_pkey PRIMARY KEY (id),
	CONSTRAINT case_milestone_surgical_case_id_fkey FOREIGN KEY (surgical_case_id) REFERENCES fhir.surgical_case(id)
);

-- fhir.case_staff_assignment
-- DROP TABLE fhir.case_staff_assignment;
CREATE TABLE fhir.case_staff_assignment (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	surgical_case_id uuid NOT NULL,
	staff_id uuid NOT NULL,
	role_type varchar(50) NOT NULL,
	assignment_start timestamptz NOT NULL,
	assignment_end timestamptz NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT case_staff_assignment_pkey PRIMARY KEY (id),
	CONSTRAINT case_staff_assignment_staff_id_fkey FOREIGN KEY (staff_id) REFERENCES fhir.staff(id),
	CONSTRAINT case_staff_assignment_surgical_case_id_fkey FOREIGN KEY (surgical_case_id) REFERENCES fhir.surgical_case(id)
);

-- fhir.ed_tracking
-- DROP TABLE fhir.ed_tracking;
CREATE TABLE fhir.ed_tracking (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	ed_visit_id uuid NOT NULL,
	tracking_location_id uuid NOT NULL,
	start_time timestamptz NOT NULL,
	end_time timestamptz NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT ed_tracking_pkey PRIMARY KEY (id),
	CONSTRAINT ed_tracking_ed_visit_id_fkey FOREIGN KEY (ed_visit_id) REFERENCES fhir.ed_visit(id),
	CONSTRAINT ed_tracking_tracking_location_id_fkey FOREIGN KEY (tracking_location_id) REFERENCES fhir.room(id)
);

-- fhir.intraop_documentation
-- DROP TABLE fhir.intraop_documentation;
CREATE TABLE fhir.intraop_documentation (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	surgical_case_id uuid NOT NULL,
	documentation_time timestamptz NOT NULL,
	document_type varchar(50) NOT NULL,
	"content" jsonb NOT NULL,
	fhir_documentreference_id uuid NULL,
	CONSTRAINT intraop_documentation_pkey PRIMARY KEY (id),
	CONSTRAINT intraop_documentation_fhir_documentreference_id_fkey FOREIGN KEY (fhir_documentreference_id) REFERENCES fhir.fhir_resource(id),
	CONSTRAINT intraop_documentation_surgical_case_id_fkey FOREIGN KEY (surgical_case_id) REFERENCES fhir.surgical_case(id)
);

-- fhir.patient_movement
-- DROP TABLE fhir.patient_movement;
CREATE TABLE fhir.patient_movement (
	id uuid DEFAULT gen_random_uuid() NOT NULL,
	admission_id uuid NOT NULL,
	from_unit_id uuid NULL,
	to_unit_id uuid NULL,
	from_bed_id uuid NULL,
	to_bed_id uuid NULL,
	movement_time timestamptz NOT NULL,
	movement_type varchar(50) NOT NULL,
	reason_code varchar(50) NOT NULL,
	status varchar(20) NOT NULL,
	CONSTRAINT patient_movement_pkey PRIMARY KEY (id),
	CONSTRAINT patient_movement_admission_id_fkey FOREIGN KEY (admission_id) REFERENCES fhir.patient_admission(id),
	CONSTRAINT patient_movement_from_bed_id_fkey FOREIGN KEY (from_bed_id) REFERENCES fhir.bed(id),
	CONSTRAINT patient_movement_from_unit_id_fkey FOREIGN KEY (from_unit_id) REFERENCES fhir.unit(id),
	CONSTRAINT patient_movement_to_bed_id_fkey FOREIGN KEY (to_bed_id) REFERENCES fhir.bed(id),
	CONSTRAINT patient_movement_to_unit_id_fkey FOREIGN KEY (to_unit_id) REFERENCES fhir.unit(id)
);
CREATE INDEX idx_movement_time ON fhir.patient_movement USING btree (movement_time);

------------------------------------------------------------------------------
-- VIEWS
------------------------------------------------------------------------------

-- fhir.current_unit_census
CREATE OR REPLACE VIEW fhir.current_unit_census
AS
SELECT
    u.unit_name,
    COUNT(DISTINCT pa.id) AS patient_count,
    u.capacity_beds AS total_beds,
    u.capacity_beds - COUNT(DISTINCT pa.id) AS available_beds
FROM fhir.unit u
LEFT JOIN fhir.patient_movement pm ON pm.to_unit_id = u.id
LEFT JOIN fhir.patient_admission pa ON pm.admission_id = pa.id
WHERE pa.status::text = 'ADMITTED'
  AND NOT EXISTS (
      SELECT 1
      FROM fhir.patient_movement pm2
      WHERE pm2.admission_id = pa.id
        AND pm2.movement_time > pm.movement_time
  )
GROUP BY u.id, u.unit_name, u.capacity_beds;

-- fhir.ed_tracking_board
CREATE OR REPLACE VIEW fhir.ed_tracking_board
AS
SELECT 
    p.resource_json ->> 'id' AS patient_mrn,
    p.resource_json #>> '{name,0,given,0}' AS first_name,
    p.resource_json #>> '{name,0,family}' AS last_name,
    pl.status AS current_status,
    cw.current_phase AS ed_phase,
    cw.start_time AS ed_arrival,
    ca.area_type AS current_area,
    EXTRACT(EPOCH FROM CURRENT_TIMESTAMP - cw.start_time)/3600::numeric AS los_hours
FROM fhir.fhir_resource p
JOIN fhir.patient_location pl ON p.id = pl.patient_id
JOIN fhir.clinical_workflow cw ON p.id = cw.patient_id
JOIN fhir.care_area ca ON pl.care_area_id = ca.id
WHERE p.resource_type = 'Patient'
  AND cw.workflow_type = 'ED'
  AND pl.end_time IS NULL;

-- fhir.ed_waiting_times
CREATE OR REPLACE VIEW fhir.ed_waiting_times
AS
SELECT 
    date_trunc('hour', arrival_time) AS arrival_hour,
    AVG(EXTRACT(EPOCH FROM provider_time - arrival_time)/60::numeric) AS avg_wait_minutes,
    COUNT(*) AS patient_count
FROM fhir.ed_visit
WHERE arrival_time >= (CURRENT_DATE - INTERVAL '7 days')
GROUP BY date_trunc('hour', arrival_time)
ORDER BY date_trunc('hour', arrival_time);

-- fhir.fhir_patient_summary
CREATE OR REPLACE VIEW fhir.fhir_patient_summary
AS
SELECT 
    fr.resource_json AS fhir_resource,
    pa.admission_time,
    pa.admission_type,
    COALESCE(icu.vital_signs, '{}') AS latest_vitals,
    COALESCE(vm.settings, '{}') AS ventilator_settings,
    COALESCE(dr.dialysate_composition, '{}') AS dialysis_prescription,
    COALESCE(re.functional_scores, '{}') AS rehab_scores
FROM fhir.fhir_resource fr
JOIN fhir.patient_admission pa ON pa.patient_id = fr.id
LEFT JOIN fhir.icu_monitoring icu ON pa.id = icu.admission_id
LEFT JOIN fhir.ventilator_management vm ON pa.id = vm.admission_id
LEFT JOIN fhir.dialysis_treatment dt ON pa.patient_id = dt.prescription_id
LEFT JOIN fhir.dialysis_prescription dr ON dt.prescription_id = dr.id
LEFT JOIN fhir.rehab_evaluation re ON pa.id = re.admission_id
WHERE fr.resource_type = 'Patient'
  AND pa.status = 'ADMITTED';

-- fhir.nurse_patient_ratios
CREATE OR REPLACE VIEW fhir.nurse_patient_ratios
AS
SELECT 
    ca.area_type,
    na.shift_start::date AS shift_date,
    COUNT(DISTINCT na.nurse_id) AS nurse_count,
    COUNT(DISTINCT na.patient_id) AS patient_count,
    COUNT(DISTINCT na.patient_id)::float / COUNT(DISTINCT na.nurse_id)::float AS ratio
FROM fhir.nursing_assignment na
JOIN fhir.care_area ca ON na.care_area_id = ca.id
WHERE na.assignment_type = 'PRIMARY'
GROUP BY ca.area_type, na.shift_start::date;

-- fhir.or_utilization
CREATE OR REPLACE VIEW fhir.or_utilization
AS
SELECT
    r.room_number,
    date_trunc('day', sc.scheduled_start_time) AS surgery_date,
    COUNT(sc.id) AS case_count,
    SUM(EXTRACT(EPOCH FROM sc.actual_end_time - sc.actual_start_time)/3600::numeric) AS utilized_hours,
    8 AS available_hours,
    (SUM(EXTRACT(EPOCH FROM sc.actual_end_time - sc.actual_start_time)/3600::numeric)/8::numeric)*100::numeric AS utilization_percent
FROM fhir.room r
LEFT JOIN fhir.surgical_case sc ON r.id = sc.room_id
WHERE r.room_type = 'OR'
  AND sc.scheduled_start_time >= (CURRENT_DATE - INTERVAL '30 days')
GROUP BY r.id, r.room_number, date_trunc('day', sc.scheduled_start_time);

-- fhir.periop_schedule
CREATE OR REPLACE VIEW fhir.periop_schedule
AS
SELECT 
    p.resource_json ->> 'id' AS patient_mrn,
    p.resource_json #>> '{name,0,given,0}' AS first_name,
    p.resource_json #>> '{name,0,family}' AS last_name,
    ca.area_type AS current_area,
    cw.current_phase AS periop_phase,
    cw.start_time AS case_start,
    cw.expected_end_time AS expected_end,
    na.nurse_id AS primary_nurse
FROM fhir.fhir_resource p
JOIN fhir.patient_location pl ON p.id = pl.patient_id
JOIN fhir.clinical_workflow cw ON p.id = cw.patient_id
JOIN fhir.care_area ca ON pl.care_area_id = ca.id
LEFT JOIN fhir.nursing_assignment na ON p.id = na.patient_id
WHERE p.resource_type = 'Patient'
  AND cw.workflow_type = 'PERIOP'
  AND pl.end_time IS NULL
  AND na.assignment_type = 'PRIMARY';


------------------------------------------------------------------------------
-- 3) Create the NEW fhir.clinical_order table (not in your original script)
------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fhir.clinical_order (
    id UUID DEFAULT fhir.gen_random_uuid() PRIMARY KEY,
    admission_id UUID NOT NULL REFERENCES fhir.patient_admission(id),
    order_type VARCHAR(50) NOT NULL,         -- e.g. 'MEDICATION', 'LAB'
    order_time TIMESTAMPTZ NOT NULL,
    order_details TEXT,                      -- or JSONB if preferred
    status VARCHAR(20) NOT NULL             -- e.g. 'ACTIVE', 'COMPLETED', etc.
);

------------------------------------------------------------------------------
-- 4) Alter fhir.patient_location.encounter_id 
--    so it references fhir.patient_admission(id) 
--    (Per the Python insert logic)
------------------------------------------------------------------------------

ALTER TABLE fhir.patient_location
  DROP CONSTRAINT IF EXISTS patient_location_encounter_id_fkey;

ALTER TABLE fhir.patient_location
  ADD CONSTRAINT patient_location_encounter_id_fkey
  FOREIGN KEY (encounter_id)
  REFERENCES fhir.patient_admission(id);

COMMIT;

