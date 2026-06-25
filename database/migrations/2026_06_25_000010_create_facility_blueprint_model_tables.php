<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS hosp_ref;
            CREATE SCHEMA IF NOT EXISTS hosp_ingest;
            CREATE SCHEMA IF NOT EXISTS hosp_space;

            CREATE TABLE IF NOT EXISTS hosp_ref.facility_object_categories (
                category_code text PRIMARY KEY,
                category_name text NOT NULL,
                source_category text NOT NULL,
                canonical_space_category text NOT NULL,
                target_hosp_schema text NOT NULL,
                target_hosp_table text NOT NULL,
                prod_table text,
                import_notes text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            INSERT INTO hosp_ref.facility_object_categories (
                category_code,
                category_name,
                source_category,
                canonical_space_category,
                target_hosp_schema,
                target_hosp_table,
                prod_table,
                import_notes
            )
            VALUES
                ('floor', 'Floor or level plate', 'floor', 'floor', 'hosp_space', 'floor', NULL, 'Floor slabs and level metadata from IFC/DXF/catalog objects.'),
                ('corridor', 'Corridor or path segment', 'corridor', 'corridor', 'hosp_space', 'corridor_segment', NULL, 'Horizontal circulation segments with flow and contamination metadata.'),
                ('care_unit', 'Care unit or nursing pod', 'care_unit', 'unit', 'hosp_space', 'care_unit', 'prod.units', 'Canonical unit shell that can drive RTDC unit capacity.'),
                ('patient_room', 'Patient care room', 'patient_room', 'room', 'hosp_space', 'room', 'prod.rooms', 'Inpatient room geometry and features that can map to app rooms.'),
                ('bed', 'Licensed or staffed bed', 'bed', 'bed', 'hosp_space', 'bed', 'prod.beds', 'Bed asset and capability record that can map to RTDC beds.'),
                ('emergency_department', 'Emergency department position', 'emergency_department', 'bay', 'hosp_space', 'room', 'prod.rooms', 'ED triage, resuscitation, treatment, observation, decon, and behavioral-safe positions.'),
                ('procedure_room', 'Procedure room', 'procedure_room', 'room', 'hosp_clinical', 'procedure_room', 'prod.rooms', 'OR, hybrid OR, cath, EP, IR, endoscopy, bronchoscopy, and LDR procedure rooms.'),
                ('procedure_support', 'Procedure support area', 'procedure_support', 'room', 'hosp_space', 'room', NULL, 'PACU, sterile core, anesthesia workroom, and restricted support areas.'),
                ('imaging', 'Imaging modality room', 'imaging', 'room', 'hosp_clinical', 'imaging_modality', 'prod.rooms', 'CT, MRI, X-ray, ultrasound, and other imaging modality rooms.'),
                ('elevator', 'Elevator or vertical transport asset', 'elevator', 'vertical_transport', 'hosp_space', 'elevator', NULL, 'Public, bed, trauma, OR/ICU, clean, soiled, materials, food, and fire-service vertical transport.'),
                ('helipad', 'Helipad or exterior emergency arrival', 'helipad', 'exterior', 'hosp_space', 'path_node', NULL, 'Trauma arrival point that should route to ED, CT, OR, and ICU.'),
                ('support_infrastructure', 'Support or infrastructure area', 'support_infrastructure', 'utility', 'hosp_space', 'room', NULL, 'Loading dock, sterile processing, pharmacy, morgue, waste, central utility, research, and command spaces.')
            ON CONFLICT (category_code) DO UPDATE SET
                category_name = EXCLUDED.category_name,
                source_category = EXCLUDED.source_category,
                canonical_space_category = EXCLUDED.canonical_space_category,
                target_hosp_schema = EXCLUDED.target_hosp_schema,
                target_hosp_table = EXCLUDED.target_hosp_table,
                prod_table = EXCLUDED.prod_table,
                import_notes = EXCLUDED.import_notes,
                updated_at = now();

            CREATE TABLE IF NOT EXISTS hosp_ingest.blueprint_imports (
                blueprint_import_id bigserial PRIMARY KEY,
                import_uuid uuid,
                source_name text NOT NULL,
                source_type text NOT NULL,
                source_uri text,
                source_checksum text,
                facility_code text,
                facility_name text,
                coordinate_units text NOT NULL DEFAULT 'ft',
                coordinate_system text,
                floor_height_ft numeric(8,2),
                status text NOT NULL DEFAULT 'received',
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                imported_by text,
                started_at timestamptz NOT NULL DEFAULT now(),
                completed_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT blueprint_imports_import_uuid_unique UNIQUE (import_uuid),
                CONSTRAINT blueprint_imports_source_type_chk CHECK (source_type IN (
                    'ifc',
                    'dxf',
                    'revit',
                    'pdf',
                    'image',
                    'catalog_json',
                    'geojson',
                    '3d_tiles',
                    'glb',
                    'other'
                )),
                CONSTRAINT blueprint_imports_status_chk CHECK (status IN (
                    'received',
                    'parsed',
                    'classified',
                    'review_required',
                    'approved',
                    'published',
                    'rejected',
                    'superseded'
                ))
            );

            CREATE INDEX IF NOT EXISTS idx_blueprint_imports_facility_status
                ON hosp_ingest.blueprint_imports(facility_code, status);

            CREATE INDEX IF NOT EXISTS idx_blueprint_imports_metadata
                ON hosp_ingest.blueprint_imports USING gin(metadata);

            CREATE TABLE IF NOT EXISTS hosp_ingest.blueprint_objects (
                blueprint_object_id bigserial PRIMARY KEY,
                blueprint_import_id bigint NOT NULL REFERENCES hosp_ingest.blueprint_imports(blueprint_import_id) ON DELETE CASCADE,
                parent_blueprint_object_id bigint REFERENCES hosp_ingest.blueprint_objects(blueprint_object_id) ON DELETE SET NULL,
                source_object_id text,
                source_global_id text,
                object_code text NOT NULL,
                object_name text,
                object_category text NOT NULL REFERENCES hosp_ref.facility_object_categories(category_code),
                source_layer text,
                source_material text,
                floor_label text,
                floor_number integer,
                geometry_kind text NOT NULL DEFAULT 'box',
                position_ft jsonb NOT NULL DEFAULT '{}'::jsonb,
                size_ft jsonb NOT NULL DEFAULT '{}'::jsonb,
                bounds_ft jsonb NOT NULL DEFAULT '{}'::jsonb,
                centroid_x_ft numeric(12,4),
                centroid_y_ft numeric(12,4),
                centroid_z_ft numeric(12,4),
                gross_area_sqft numeric(14,2),
                net_area_sqft numeric(14,2),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                classification jsonb NOT NULL DEFAULT '{}'::jsonb,
                extraction_confidence numeric(5,4),
                review_status text NOT NULL DEFAULT 'unreviewed',
                canonical_schema text,
                canonical_table text,
                canonical_id bigint,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT blueprint_objects_code_unique UNIQUE (blueprint_import_id, object_code),
                CONSTRAINT blueprint_objects_geometry_kind_chk CHECK (geometry_kind IN (
                    'box',
                    'mesh',
                    'polyline',
                    'polygon',
                    'point',
                    'ifc_product',
                    'unknown'
                )),
                CONSTRAINT blueprint_objects_confidence_chk CHECK (
                    extraction_confidence IS NULL
                    OR extraction_confidence BETWEEN 0 AND 1
                ),
                CONSTRAINT blueprint_objects_review_status_chk CHECK (review_status IN (
                    'unreviewed',
                    'auto_accepted',
                    'needs_human_review',
                    'approved',
                    'rejected',
                    'superseded'
                ))
            );

            CREATE INDEX IF NOT EXISTS idx_blueprint_objects_import_category_floor
                ON hosp_ingest.blueprint_objects(blueprint_import_id, object_category, floor_number);

            CREATE INDEX IF NOT EXISTS idx_blueprint_objects_source_global
                ON hosp_ingest.blueprint_objects(source_global_id);

            CREATE INDEX IF NOT EXISTS idx_blueprint_objects_metadata
                ON hosp_ingest.blueprint_objects USING gin(metadata);

            CREATE INDEX IF NOT EXISTS idx_blueprint_objects_classification
                ON hosp_ingest.blueprint_objects USING gin(classification);

            CREATE TABLE IF NOT EXISTS hosp_space.facility_spaces (
                facility_space_id bigserial PRIMARY KEY,
                blueprint_object_id bigint REFERENCES hosp_ingest.blueprint_objects(blueprint_object_id) ON DELETE SET NULL,
                parent_space_id bigint REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE SET NULL,
                space_code text NOT NULL UNIQUE,
                space_name text NOT NULL,
                space_category text NOT NULL,
                floor_label text,
                floor_number integer,
                service_line_code text,
                acuity_level text,
                status text NOT NULL DEFAULT 'planned',
                geometry jsonb NOT NULL DEFAULT '{}'::jsonb,
                attributes jsonb NOT NULL DEFAULT '{}'::jsonb,
                source_system text NOT NULL DEFAULT 'blueprint',
                source_confidence numeric(5,4),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT facility_spaces_category_chk CHECK (space_category IN (
                    'campus',
                    'building',
                    'floor',
                    'zone',
                    'unit',
                    'room',
                    'bay',
                    'bed',
                    'corridor',
                    'vertical_transport',
                    'utility',
                    'exterior',
                    'helipad',
                    'procedure_room',
                    'imaging',
                    'support',
                    'equipment'
                )),
                CONSTRAINT facility_spaces_status_chk CHECK (status IN (
                    'planned',
                    'active',
                    'reserved',
                    'surge_ready',
                    'under_construction',
                    'closed',
                    'decommissioned'
                )),
                CONSTRAINT facility_spaces_confidence_chk CHECK (
                    source_confidence IS NULL
                    OR source_confidence BETWEEN 0 AND 1
                )
            );

            CREATE INDEX IF NOT EXISTS idx_facility_spaces_category_floor
                ON hosp_space.facility_spaces(space_category, floor_number);

            CREATE INDEX IF NOT EXISTS idx_facility_spaces_blueprint_object
                ON hosp_space.facility_spaces(blueprint_object_id);

            CREATE INDEX IF NOT EXISTS idx_facility_spaces_attributes
                ON hosp_space.facility_spaces USING gin(attributes);

            ALTER TABLE prod.locations ADD COLUMN IF NOT EXISTS facility_space_id bigint;
            ALTER TABLE prod.rooms ADD COLUMN IF NOT EXISTS facility_space_id bigint;
            ALTER TABLE prod.units ADD COLUMN IF NOT EXISTS facility_space_id bigint;
            ALTER TABLE prod.beds ADD COLUMN IF NOT EXISTS facility_space_id bigint;

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE table_schema = 'prod'
                      AND table_name = 'locations'
                      AND constraint_name = 'prod_locations_facility_space_fk'
                ) THEN
                    ALTER TABLE prod.locations
                        ADD CONSTRAINT prod_locations_facility_space_fk
                        FOREIGN KEY (facility_space_id)
                        REFERENCES hosp_space.facility_spaces(facility_space_id)
                        ON DELETE SET NULL;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE table_schema = 'prod'
                      AND table_name = 'rooms'
                      AND constraint_name = 'prod_rooms_facility_space_fk'
                ) THEN
                    ALTER TABLE prod.rooms
                        ADD CONSTRAINT prod_rooms_facility_space_fk
                        FOREIGN KEY (facility_space_id)
                        REFERENCES hosp_space.facility_spaces(facility_space_id)
                        ON DELETE SET NULL;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE table_schema = 'prod'
                      AND table_name = 'units'
                      AND constraint_name = 'prod_units_facility_space_fk'
                ) THEN
                    ALTER TABLE prod.units
                        ADD CONSTRAINT prod_units_facility_space_fk
                        FOREIGN KEY (facility_space_id)
                        REFERENCES hosp_space.facility_spaces(facility_space_id)
                        ON DELETE SET NULL;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE table_schema = 'prod'
                      AND table_name = 'beds'
                      AND constraint_name = 'prod_beds_facility_space_fk'
                ) THEN
                    ALTER TABLE prod.beds
                        ADD CONSTRAINT prod_beds_facility_space_fk
                        FOREIGN KEY (facility_space_id)
                        REFERENCES hosp_space.facility_spaces(facility_space_id)
                        ON DELETE SET NULL;
                END IF;
            END
            $$;

            CREATE INDEX IF NOT EXISTS idx_prod_locations_facility_space
                ON prod.locations(facility_space_id);

            CREATE INDEX IF NOT EXISTS idx_prod_rooms_facility_space
                ON prod.rooms(facility_space_id);

            CREATE INDEX IF NOT EXISTS idx_prod_units_facility_space
                ON prod.units(facility_space_id);

            CREATE INDEX IF NOT EXISTS idx_prod_beds_facility_space
                ON prod.beds(facility_space_id);

            CREATE TABLE IF NOT EXISTS hosp_space.operational_space_maps (
                operational_space_map_id bigserial PRIMARY KEY,
                facility_space_id bigint NOT NULL REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE CASCADE,
                location_id bigint REFERENCES prod.locations(location_id),
                room_id bigint REFERENCES prod.rooms(room_id),
                unit_id bigint REFERENCES prod.units(unit_id),
                bed_id bigint REFERENCES prod.beds(bed_id),
                mapping_type text NOT NULL DEFAULT 'imported',
                mapping_confidence numeric(5,4),
                evidence jsonb NOT NULL DEFAULT '{}'::jsonb,
                active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT operational_space_maps_target_chk CHECK (
                    num_nonnulls(location_id, room_id, unit_id, bed_id) = 1
                ),
                CONSTRAINT operational_space_maps_mapping_type_chk CHECK (mapping_type IN (
                    'imported',
                    'derived',
                    'manual',
                    'legacy',
                    'canonical'
                )),
                CONSTRAINT operational_space_maps_confidence_chk CHECK (
                    mapping_confidence IS NULL
                    OR mapping_confidence BETWEEN 0 AND 1
                )
            );

            CREATE INDEX IF NOT EXISTS idx_operational_space_maps_space_active
                ON hosp_space.operational_space_maps(facility_space_id, active);

            CREATE INDEX IF NOT EXISTS idx_operational_space_maps_location
                ON hosp_space.operational_space_maps(location_id)
                WHERE location_id IS NOT NULL;

            CREATE INDEX IF NOT EXISTS idx_operational_space_maps_room
                ON hosp_space.operational_space_maps(room_id)
                WHERE room_id IS NOT NULL;

            CREATE INDEX IF NOT EXISTS idx_operational_space_maps_unit
                ON hosp_space.operational_space_maps(unit_id)
                WHERE unit_id IS NOT NULL;

            CREATE INDEX IF NOT EXISTS idx_operational_space_maps_bed
                ON hosp_space.operational_space_maps(bed_id)
                WHERE bed_id IS NOT NULL;

            CREATE INDEX IF NOT EXISTS idx_operational_space_maps_evidence
                ON hosp_space.operational_space_maps USING gin(evidence);

            COMMENT ON TABLE hosp_ingest.blueprint_imports IS
                'One import/revision of a hospital blueprint, BIM, CAD, 3D Tiles, GLB, image, PDF, or model catalog source.';

            COMMENT ON TABLE hosp_ingest.blueprint_objects IS
                'Raw extracted blueprint/CAD/BIM objects with source geometry, floor coordinates, metadata, classification, and review state.';

            COMMENT ON TABLE hosp_space.facility_spaces IS
                'Canonical facility spaces derived from blueprint objects and stable enough to map into Zephyrus operational tables.';

            COMMENT ON TABLE hosp_space.operational_space_maps IS
                'Bridge from canonical facility spaces to existing Zephyrus prod.locations, prod.rooms, prod.units, and prod.beds rows.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS hosp_space.operational_space_maps;

            ALTER TABLE IF EXISTS prod.beds DROP CONSTRAINT IF EXISTS prod_beds_facility_space_fk;
            ALTER TABLE IF EXISTS prod.units DROP CONSTRAINT IF EXISTS prod_units_facility_space_fk;
            ALTER TABLE IF EXISTS prod.rooms DROP CONSTRAINT IF EXISTS prod_rooms_facility_space_fk;
            ALTER TABLE IF EXISTS prod.locations DROP CONSTRAINT IF EXISTS prod_locations_facility_space_fk;

            ALTER TABLE IF EXISTS prod.beds DROP COLUMN IF EXISTS facility_space_id;
            ALTER TABLE IF EXISTS prod.units DROP COLUMN IF EXISTS facility_space_id;
            ALTER TABLE IF EXISTS prod.rooms DROP COLUMN IF EXISTS facility_space_id;
            ALTER TABLE IF EXISTS prod.locations DROP COLUMN IF EXISTS facility_space_id;

            DROP TABLE IF EXISTS hosp_space.facility_spaces;
            DROP TABLE IF EXISTS hosp_ingest.blueprint_objects;
            DROP TABLE IF EXISTS hosp_ingest.blueprint_imports;
            DROP TABLE IF EXISTS hosp_ref.facility_object_categories;
            DROP SCHEMA IF EXISTS hosp_ingest;
        SQL);
    }
};
