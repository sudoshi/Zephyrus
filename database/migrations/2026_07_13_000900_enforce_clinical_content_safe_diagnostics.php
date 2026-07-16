<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raw.contains_clinical_content(candidate text)
            RETURNS boolean
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            AS $$
                SELECT candidate IS NOT NULL AND (
                    candidate ~* '\mZPHI[-_][A-Z0-9._-]+'
                    OR candidate ~* '\m(MSH|PID|PV1|OBX|ORC|RXA)\|'
                    OR candidate ~* '\mISA[^A-Za-z0-9\r\n].{20,}'
                    OR candidate ~* '<[[:space:]]*([A-Za-z][A-Za-z0-9_.-]*:)?ClinicalDocument\M'
                    OR candidate ~* 'urn:hl7-org:v3'
                    OR candidate ~* '<[[:space:]]*(NewRx|RxChange|RxRenewal|MedicationPrescribed)\M'
                    OR candidate ~* '<[[:space:]]*(Patient|Encounter|Observation|Condition|Procedure|DiagnosticReport|MedicationRequest|Coverage|Claim)\M[^>]*\mxmlns[[:space:]]*=[[:space:]]*["'']http://hl7\.org/fhir["'']'
                    OR candidate ~ 'DICM'
                    OR candidate ~* '\m(0010[,|]00(10|20)|00100010|00100020|PatientName|PatientID)\M'
                    OR (
                        candidate ~* '["\\]resourceType["\\][[:space:]]*:[[:space:]]*["\\](Patient|Encounter|Observation|Condition|Procedure|DiagnosticReport|MedicationRequest|Coverage|Claim)["\\]'
                        AND candidate ~* '["\\](identifier|name|subject|patient|telecom|address|birthDate|contained|text|code|valueString|note)["\\][[:space:]]*:'
                    )
                    OR candidate ~* '["\\](raw_hl7|patient|patient_name|patientName|mrn|medical_record_number|clinical_document|resource_data)["\\][[:space:]]*:'
                    OR candidate ~* '(^|[,;[:space:]])(mrn|patient_?name|patient_?id)([,;[:space:]]|$)'
                    OR candidate ~* '(Authorization[[:space:]]*:[[:space:]]*)?(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+/=:-]{8,}'
                    OR candidate ~* '\m(Cookie|Set-Cookie)[[:space:]]*:[[:space:]]*[^\r\n]{8,}'
                    OR candidate ~* '\m(api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|password)[[:space:]]*[:=][[:space:]]*["'']?[A-Za-z0-9._~+/=:-]{8,}'
                    OR candidate ~ '\meyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\M'
                    OR candidate ~* '-----BEGIN( [A-Z0-9]+)? PRIVATE KEY-----'
                )
            $$;

            CREATE OR REPLACE FUNCTION raw.reject_clinical_content_diagnostic()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF raw.contains_clinical_content(row_to_json(NEW)::text) THEN
                    RAISE EXCEPTION 'clinical content is prohibited in diagnostic and evidence authorities'
                        USING ERRCODE = '22000';
                END IF;

                RETURN NEW;
            END;
            $$;

            DO $$
            DECLARE
                authority regclass;
                contaminated boolean;
            BEGIN
                FOREACH authority IN ARRAY ARRAY[
                    'raw.dead_letters'::regclass,
                    'integration.event_projection_errors'::regclass,
                    'integration.configuration_audits'::regclass,
                    'governance.change_requests'::regclass,
                    'governance.change_decisions'::regclass,
                    'governance.change_executions'::regclass,
                    'governance.system_health_observations'::regclass,
                    'governance.access_review_campaigns'::regclass,
                    'governance.access_review_decisions'::regclass,
                    'governance.access_review_remediations'::regclass,
                    'audit.user_events'::regclass,
                    'jobs'::regclass,
                    'failed_jobs'::regclass
                ] LOOP
                    EXECUTE format(
                        'SELECT EXISTS (SELECT 1 FROM %s row WHERE raw.contains_clinical_content(row_to_json(row)::text))',
                        authority
                    ) INTO contaminated;

                    IF contaminated THEN
                        RAISE EXCEPTION 'existing clinical content detected in diagnostic authority %', authority
                            USING ERRCODE = '22000';
                    END IF;
                END LOOP;
            END;
            $$;

            DROP TRIGGER IF EXISTS dead_letters_clinical_content_guard ON raw.dead_letters;
            CREATE TRIGGER dead_letters_clinical_content_guard
                BEFORE INSERT OR UPDATE ON raw.dead_letters
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS event_projection_errors_clinical_content_guard ON integration.event_projection_errors;
            CREATE TRIGGER event_projection_errors_clinical_content_guard
                BEFORE INSERT OR UPDATE ON integration.event_projection_errors
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS configuration_audits_clinical_content_guard ON integration.configuration_audits;
            CREATE TRIGGER configuration_audits_clinical_content_guard
                BEFORE INSERT ON integration.configuration_audits
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS change_requests_clinical_content_guard ON governance.change_requests;
            CREATE TRIGGER change_requests_clinical_content_guard
                BEFORE INSERT ON governance.change_requests
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS change_decisions_clinical_content_guard ON governance.change_decisions;
            CREATE TRIGGER change_decisions_clinical_content_guard
                BEFORE INSERT ON governance.change_decisions
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS change_executions_clinical_content_guard ON governance.change_executions;
            CREATE TRIGGER change_executions_clinical_content_guard
                BEFORE INSERT ON governance.change_executions
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS system_health_clinical_content_guard ON governance.system_health_observations;
            CREATE TRIGGER system_health_clinical_content_guard
                BEFORE INSERT ON governance.system_health_observations
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS access_review_campaigns_clinical_content_guard ON governance.access_review_campaigns;
            CREATE TRIGGER access_review_campaigns_clinical_content_guard
                BEFORE INSERT OR UPDATE ON governance.access_review_campaigns
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS access_review_decisions_clinical_content_guard ON governance.access_review_decisions;
            CREATE TRIGGER access_review_decisions_clinical_content_guard
                BEFORE INSERT ON governance.access_review_decisions
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS access_review_remediations_clinical_content_guard ON governance.access_review_remediations;
            CREATE TRIGGER access_review_remediations_clinical_content_guard
                BEFORE INSERT ON governance.access_review_remediations
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS user_events_clinical_content_guard ON audit.user_events;
            CREATE TRIGGER user_events_clinical_content_guard
                BEFORE INSERT ON audit.user_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS jobs_clinical_content_guard ON jobs;
            CREATE TRIGGER jobs_clinical_content_guard
                BEFORE INSERT OR UPDATE ON jobs
                FOR EACH ROW
                EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            DROP TRIGGER IF EXISTS failed_jobs_clinical_content_guard ON failed_jobs;
            CREATE TRIGGER failed_jobs_clinical_content_guard
                BEFORE INSERT OR UPDATE ON failed_jobs
                FOR EACH ROW
                EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS failed_jobs_clinical_content_guard ON failed_jobs;
            DROP TRIGGER IF EXISTS jobs_clinical_content_guard ON jobs;
            DROP TRIGGER IF EXISTS user_events_clinical_content_guard ON audit.user_events;
            DROP TRIGGER IF EXISTS access_review_remediations_clinical_content_guard ON governance.access_review_remediations;
            DROP TRIGGER IF EXISTS access_review_decisions_clinical_content_guard ON governance.access_review_decisions;
            DROP TRIGGER IF EXISTS access_review_campaigns_clinical_content_guard ON governance.access_review_campaigns;
            DROP TRIGGER IF EXISTS system_health_clinical_content_guard ON governance.system_health_observations;
            DROP TRIGGER IF EXISTS change_executions_clinical_content_guard ON governance.change_executions;
            DROP TRIGGER IF EXISTS change_decisions_clinical_content_guard ON governance.change_decisions;
            DROP TRIGGER IF EXISTS change_requests_clinical_content_guard ON governance.change_requests;
            DROP TRIGGER IF EXISTS configuration_audits_clinical_content_guard ON integration.configuration_audits;
            DROP TRIGGER IF EXISTS event_projection_errors_clinical_content_guard ON integration.event_projection_errors;
            DROP TRIGGER IF EXISTS dead_letters_clinical_content_guard ON raw.dead_letters;
            DROP FUNCTION IF EXISTS raw.reject_clinical_content_diagnostic();
            DROP FUNCTION IF EXISTS raw.contains_clinical_content(text);
        SQL);
    }
};
