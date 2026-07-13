<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration.sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('integration.sources', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('source_key');
            }
            if (! Schema::hasColumn('integration.sources', 'facility_id')) {
                $table->unsignedBigInteger('facility_id')->nullable()->after('organization_id');
            }
        });

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'integration_sources_organization_fk'
                      AND conrelid = 'integration.sources'::regclass
                ) THEN
                    ALTER TABLE integration.sources
                    ADD CONSTRAINT integration_sources_organization_fk
                    FOREIGN KEY (organization_id)
                    REFERENCES hosp_org.organizations(organization_id)
                    ON DELETE RESTRICT;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'integration_sources_facility_fk'
                      AND conrelid = 'integration.sources'::regclass
                ) THEN
                    ALTER TABLE integration.sources
                    ADD CONSTRAINT integration_sources_facility_fk
                    FOREIGN KEY (facility_id)
                    REFERENCES hosp_org.facilities(facility_id)
                    ON DELETE RESTRICT;
                END IF;
            END;
            $$;

            CREATE INDEX IF NOT EXISTS integration_sources_organization_idx
                ON integration.sources (organization_id);
            CREATE INDEX IF NOT EXISTS integration_sources_facility_idx
                ON integration.sources (facility_id);

            UPDATE integration.sources AS source
            SET facility_id = facility.facility_id,
                organization_id = facility.organization_id,
                facility_key = facility.facility_key,
                updated_at = now()
            FROM hosp_org.facilities AS facility
            WHERE source.facility_id IS NULL
              AND source.facility_key IS NOT NULL
              AND source.facility_key = facility.facility_key;

            UPDATE integration.sources AS source
            SET organization_id = organization.organization_id,
                tenant_key = organization.organization_key,
                updated_at = now()
            FROM hosp_org.organizations AS organization
            WHERE source.organization_id IS NULL
              AND source.tenant_key = organization.organization_key;

            UPDATE integration.sources AS source
            SET organization_id = facility.organization_id,
                facility_key = facility.facility_key,
                tenant_key = organization.organization_key,
                updated_at = now()
            FROM hosp_org.facilities AS facility
            JOIN hosp_org.organizations AS organization
              ON organization.organization_id = facility.organization_id
            WHERE source.facility_id = facility.facility_id
              AND (
                  source.organization_id IS DISTINCT FROM facility.organization_id
                  OR source.facility_key IS DISTINCT FROM facility.facility_key
                  OR source.tenant_key IS DISTINCT FROM organization.organization_key
              );

            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM integration.sources
                    WHERE (active_status = 'active' OR go_live_status = 'live')
                      AND (organization_id IS NULL OR facility_id IS NULL)
                ) THEN
                    RAISE EXCEPTION
                        'active/live integration sources must be mapped to canonical organization and facility IDs before migration';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_enterprise_scope()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                canonical_organization_id bigint;
                canonical_organization_key text;
                canonical_facility_key text;
                canonical_facility_active boolean;
            BEGIN
                IF NEW.facility_id IS NOT NULL THEN
                    SELECT facility.organization_id,
                           facility.facility_key,
                           facility.is_active,
                           organization.organization_key
                    INTO canonical_organization_id,
                         canonical_facility_key,
                         canonical_facility_active,
                         canonical_organization_key
                    FROM hosp_org.facilities AS facility
                    JOIN hosp_org.organizations AS organization
                      ON organization.organization_id = facility.organization_id
                    WHERE facility.facility_id = NEW.facility_id;

                    IF canonical_organization_id IS NULL THEN
                        RAISE EXCEPTION 'integration source facility scope does not exist';
                    END IF;
                    IF NEW.organization_id IS NOT NULL
                       AND NEW.organization_id <> canonical_organization_id THEN
                        RAISE EXCEPTION 'integration source organization and facility scopes do not match';
                    END IF;
                    IF NEW.facility_key IS NOT NULL
                       AND NEW.facility_key <> canonical_facility_key THEN
                        RAISE EXCEPTION 'integration source facility key does not match its canonical facility';
                    END IF;
                    IF NEW.tenant_key IS NOT NULL
                       AND NEW.tenant_key <> canonical_organization_key THEN
                        RAISE EXCEPTION 'integration source tenant key does not match its canonical organization';
                    END IF;

                    NEW.organization_id := canonical_organization_id;
                    NEW.facility_key := canonical_facility_key;
                    NEW.tenant_key := canonical_organization_key;
                ELSIF NEW.organization_id IS NOT NULL THEN
                    SELECT organization.organization_key
                    INTO canonical_organization_key
                    FROM hosp_org.organizations AS organization
                    WHERE organization.organization_id = NEW.organization_id;

                    IF canonical_organization_key IS NULL THEN
                        RAISE EXCEPTION 'integration source organization scope does not exist';
                    END IF;
                    IF NEW.tenant_key IS NOT NULL
                       AND NEW.tenant_key <> canonical_organization_key THEN
                        RAISE EXCEPTION 'integration source tenant key does not match its canonical organization';
                    END IF;

                    NEW.tenant_key := canonical_organization_key;
                END IF;

                IF NEW.active_status = 'active' OR NEW.go_live_status = 'live' THEN
                    IF NEW.organization_id IS NULL OR NEW.facility_id IS NULL THEN
                        RAISE EXCEPTION 'active/live integration sources require canonical organization and facility scopes';
                    END IF;
                    IF canonical_facility_active IS DISTINCT FROM true THEN
                        RAISE EXCEPTION 'active/live integration sources require an active canonical facility';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS integration_sources_enterprise_scope ON integration.sources;
            CREATE TRIGGER integration_sources_enterprise_scope
                BEFORE INSERT OR UPDATE OF organization_id, facility_id, tenant_key, facility_key, active_status, go_live_status
                ON integration.sources
                FOR EACH ROW
                EXECUTE FUNCTION integration.enforce_source_enterprise_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS integration_sources_enterprise_scope ON integration.sources;
            DROP FUNCTION IF EXISTS integration.enforce_source_enterprise_scope();
            ALTER TABLE integration.sources DROP CONSTRAINT IF EXISTS integration_sources_facility_fk;
            ALTER TABLE integration.sources DROP CONSTRAINT IF EXISTS integration_sources_organization_fk;
            DROP INDEX IF EXISTS integration.integration_sources_facility_idx;
            DROP INDEX IF EXISTS integration.integration_sources_organization_idx;
        SQL);

        Schema::table('integration.sources', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['facility_id', 'organization_id'],
                fn (string $column): bool => Schema::hasColumn('integration.sources', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
