# Hospital Blueprint Ingestion and Digital-Twin Data Model Plan

Date: 2026-06-25

## Objective

Zephyrus should be able to ingest a hospital blueprint, CAD model, BIM model, or generated 3D facility catalog and convert it into a hospital operations data model detailed enough to support:

- RTDC capacity and bed placement.
- ED and trauma flow modeling.
- Perioperative room/platform analytics.
- Patient, staff, supply, sterile, soiled, waste, pharmacy, food, deceased, emergency, and research-specimen routing.
- Facility, infrastructure, utilities, environmental, digital, safety, quality, accreditation, and surge-readiness traceability.
- Digital-twin visualization and simulation.
- Multi-hospital comparison across a health system.

The immediate repository change creates the first durable bridge between Zephyrus' existing `prod` operational tables and a richer facility ontology. The full target state should progressively materialize the much larger `hosp_*` ontology encoded in `500-bed-tier1-trauma1-academic-medical-center.ddl.sql`.

## Local Artifacts Examined

### `500-bed-tier1-trauma1-academic-medical-center.ddl.sql`

The DDL is a PostgreSQL 15+ planning, simulation, optimization, regulatory traceability, and digital-twin ontology for a 500-bed Tier 1 / ACS Level I trauma academic medical center. It is not a construction-ready or AHJ-approved design.

Its structure:

- 10 schemas:
  - `hosp_ref`
  - `hosp_org`
  - `hosp_space`
  - `hosp_clinical`
  - `hosp_logistics`
  - `hosp_infra`
  - `hosp_digital`
  - `hosp_quality`
  - `hosp_research`
  - `hosp_opt`
- 4 domains:
  - `percent`
  - `nonnegative_num`
  - `positive_num`
  - `floor_area_sqft`
- 13 enum vocabularies:
  - `acuity_level`
  - `air_pressure_relationship`
  - `availability_model`
  - `bed_status`
  - `contamination_class`
  - `criticality`
  - `elevator_class`
  - `flow_class`
  - `room_status`
  - `space_category`
  - `utility_system_class`
  - `waste_stream`
  - `cyber_control_family`
- 92 tables.
- 13 planning/reference views.
- 13 explicit indexes, including path-edge routing indexes and operational filtering indexes.

High-value DDL domains:

- `hosp_ref`: standards, design requirements, assumptions, room types, equipment types, role types, current research basis, and generated planning manifests.
- `hosp_org`: organization, AHJ, campus, service lines, and departments.
- `hosp_space`: building, floor, life-safety zones, infection-control zones, access-control zones, care units, rooms, beds, features, hand hygiene, graph nodes/edges, corridors, elevators, stairs, doors, and signage.
- `hosp_clinical`: trauma program, trauma response zones, coverage, staffing ratios, ED program, procedure platforms/rooms, imaging, blood product targets, medication zones, and isolation capabilities.
- `hosp_logistics`: inventory, PAR locations/levels, logistics routes, carts, pneumatic tubes, waste points, sterile processing, instruments, and nutrition routing.
- `hosp_infra`: utility systems/components, electrical, emergency power, HVAC, environmental requirements, medical gas, water, fire/life safety, and BMS sensors.
- `hosp_digital`: network zones, application systems, interfaces, medical devices, RTLS tags, cybersecurity controls, control mappings, and downtime plans.
- `hosp_quality`: quality metrics, committees, accreditation traceability, environmental rounding, emergency plans, disaster exercises, and surge spaces.
- `hosp_research`: academic programs, research spaces, and specimen flow.
- `hosp_opt`: capacity targets, adjacency targets, flow separation, transport SLAs, queue policies, design scenarios, and simulation results.

Important generated views:

- `hosp_ref.recommended_500_bed_unit_program`
- `hosp_ref.v_generated_500_bed_manifest`
- `hosp_ref.recommended_floor_program`
- `hosp_ref.recommended_hallway_program`
- `hosp_ref.v_generated_hallway_manifest`
- `hosp_ref.recommended_elevator_program`
- `hosp_ref.v_generated_elevator_manifest`
- `hosp_ref.recommended_ed_room_program`
- `hosp_ref.v_generated_ed_room_manifest`
- `hosp_ref.recommended_procedure_room_program`
- `hosp_ref.v_generated_procedure_room_manifest`
- `hosp_ref.v_planning_quantity_checks`

The DDL's generated manifests establish baseline quantities:

- 500 planned licensed inpatient beds.
- 23 care units.
- 16 floor/level concepts: B2, B1, L1-L13, PH.
- 178 corridor segments.
- 35 elevators.
- 148 ED treatment/support positions.
- 44 procedure rooms.

### `hospital-cad-model`

The CAD model directory is a deterministic concept-model bundle for the same 500-bed facility model.

Files evaluated:

- `README.md`
- `generate_hospital_cad_model.py`
- `data/model_catalog.json`
- `bim/hospital_model.ifc`
- `cad/hospital_model.dxf`
- `model/hospital_model.glb`
- `3dtiles/tileset.json`
- `viewer/index.html`
- `viewer/app.js`
- `viewer/styles.css`
- `verification/results.json`
- desktop and mobile verification screenshots

The generated model outputs:

- IFC4 semantic model.
- DXF AC1027 CAD mesh exchange.
- GLB/glTF runtime model.
- OGC 3D Tiles wrapper.
- JSON object catalog.
- Three.js viewer.
- Playwright verification artifacts.

The JSON catalog has:

- 1,472 total objects.
- 500 bed objects.
- 500 patient-room objects.
- 178 corridor objects.
- 35 elevator objects.
- 148 ED-position objects.
- 44 procedure-room objects.
- 6 imaging objects.
- 23 care-unit objects.
- 16 floor objects.
- 14 support/infrastructure objects.
- 4 helipad/marking objects.
- 4 procedure-support objects.

Catalog object shape:

- `code`
- `name`
- `category`
- `material`
- `floor`
- `position_ft`
- `size_ft`
- `metadata`

Examples:

- `UNIT-MS4A`: care unit with service line, acuity, planned beds, isolation target, and optimization notes.
- `MS4A-R001`: patient room with unit code, bed code, acuity, same-handed room flag, hand-hygiene flag, isolation candidate flag, and ceiling-lift flag.
- `MS4A-B001`: bed with licensed/staffed flags, acuity, ICU/telemetry/isolation capabilities, medical gas headwall, and nurse-call endpoint.
- `B2-utility_tunnel-01`: corridor with flow class, contamination class, width, public access, and clean/soiled separation.
- `ED-TRAUMA-*`, `ED-AII-*`, `ED-DECON-*`: ED positions with trauma, isolation, decontamination, behavioral-safety, observation, and nurse-call metadata.
- `GENOR-*`, `HYBOR-*`, `CATH-*`, `IR-*`: procedure rooms with platform type, specialty focus, trauma priority, restricted-zone and anesthesia flags.
- `CT-TRAUMA-01`, `MRI-ED-01`: imaging rooms with modality, trauma/stroke priority, shielding, and downtime routing notes.
- `PUB-01`, `TRM-01`, `OIC-01`, etc.: elevators with class, capacity, helipad support, public access, and bed-stretcher capability.

The generator repeats the DDL's planning programs in Python constants, then emits geometry and metadata. That makes `model_catalog.json` the most practical first ingestion interface because it already binds geometry to operational semantics.

### Current Zephyrus Data Model

Current operational placement concepts:

- `prod.locations`: perioperative/service locations.
- `prod.rooms`: perioperative rooms and other room-level resources.
- `prod.units`: RTDC units.
- `prod.beds`: RTDC beds.
- `prod.transport_requests`: free-text origin/destination plus transport metadata.
- `prod.or_cases`, `prod.block_templates`, `prod.room_utilization`: perioperative workflows.
- `prod.encounters`, `prod.census_snapshots`, `prod.rtdc_predictions`, `prod.bed_requests`, `prod.bed_placement_decisions`: RTDC workflows.

Gaps before this change:

- No canonical campus/building/floor/unit/room/bed hierarchy.
- No way to store source CAD/BIM object evidence.
- No coordinate, dimension, floor, or geometry metadata on rooms/beds/units.
- No imported-object review state.
- No explicit mapping from CAD/BIM spaces to app operational IDs.
- No route graph that can drive transport time or flow-separation policies.
- Transport requests store origin/destination as strings rather than references to canonical spaces or graph nodes.

## Data Model Incorporated Now

Migration added:

- `database/migrations/2026_06_25_000010_create_facility_blueprint_model_tables.php`

New schemas/tables:

- `hosp_ref.facility_object_categories`
  - Maps generated CAD/catalog categories to canonical space categories, target `hosp_*` entities, and existing `prod` tables.
- `hosp_ingest.blueprint_imports`
  - One row per source import or revision.
  - Tracks source type, source URI/checksum, facility code/name, coordinate system, status, metadata, and timestamps.
- `hosp_ingest.blueprint_objects`
  - Stores extracted object evidence from IFC/DXF/PDF/image/catalog sources.
  - Tracks source IDs, object code/name/category, floor, geometry kind, coordinates, bounds, area, raw metadata, classification, confidence, review status, and eventual canonical target.
- `hosp_space.facility_spaces`
  - Canonical spaces stable enough to join to Zephyrus operations.
  - Stores hierarchy, category, floor, service line, acuity, status, geometry, attributes, and source confidence.
- `hosp_space.operational_space_maps`
  - Bridge from canonical facility spaces to exactly one of:
    - `prod.locations`
    - `prod.rooms`
    - `prod.units`
    - `prod.beds`

Existing `prod` tables extended with nullable `facility_space_id`:

- `prod.locations`
- `prod.rooms`
- `prod.units`
- `prod.beds`

New Eloquent models:

- `App\Models\Facility\BlueprintImport`
- `App\Models\Facility\BlueprintObject`
- `App\Models\Facility\FacilitySpace`
- `App\Models\Facility\OperationalSpaceMap`

Existing Eloquent models now expose `facilitySpace()`:

- `Location`
- `Room`
- `Unit`
- `Bed`

New test:

- `tests/Feature/FacilityModelSchemaTest.php`

The test verifies:

- New `hosp_ingest` tables and key columns.
- New `hosp_space` tables and key columns.
- `facility_space_id` links on the four operational `prod` tables.
- Category catalog rows matching the generated CAD model classes.
- A real `hosp_ingest.blueprint_imports` -> `hosp_ingest.blueprint_objects` -> `hosp_space.facility_spaces` -> `prod.units` mapping path.

## Why The Data Model Is Split This Way

Zephyrus should not collapse every blueprint object directly into `prod.rooms` or `prod.beds`.

The correct model has four layers:

1. Source evidence
   - Raw files, checksums, source object IDs, IFC GUIDs, CAD layers, OCR text, geometry, and extraction confidence.
   - Stored in `hosp_ingest`.

2. Canonical facility spaces
   - Stable physical/semantic spaces: campus, building, floor, unit, room, bay, bed, corridor, elevator, utility, exterior, helipad, support, equipment.
   - Stored in `hosp_space.facility_spaces` now, and later expanded into the full DDL tables.

3. Operational app mappings
   - Mappings to `prod.locations`, `prod.rooms`, `prod.units`, and `prod.beds`.
   - Stored in `hosp_space.operational_space_maps` and nullable direct FKs.

4. Workflow facts and analytics
   - Encounters, OR cases, transport requests, census snapshots, predictions, queues, and performance metrics.
   - Continue living in `prod` and `star`.

This separation preserves evidence, enables human review, supports multiple blueprint revisions, and prevents source-parser mistakes from corrupting operational IDs.

## Target End-To-End Blueprint Ingestion Architecture

### 1. Intake and Source Registration

Inputs to support:

- IFC.
- Revit export packages.
- DWG/DXF.
- PDF floor plans.
- Raster blueprint images.
- SVG/vector floor plans.
- GeoJSON/GIS floor plates.
- Existing asset-management exports.
- 3D Tiles / GLB / glTF.
- Zephyrus generated `model_catalog.json` files.

For each import:

- Create a `hosp_ingest.blueprint_imports` row.
- Store:
  - source name
  - source type
  - original URI
  - checksum
  - facility code
  - facility name
  - coordinate units
  - coordinate system
  - floor-height assumptions
  - status
  - importer version
  - parser version
  - extraction configuration
  - user or system actor
- Store the raw file outside the relational database in an object store or versioned filesystem.
- Keep the database row as the immutable manifest for the source revision.

Never overwrite an import in place. New drawings, new exports, and reviewed corrections should create new import revisions or child revisions.

### 2. Source Normalization

Each source family needs a dedicated adapter.

IFC adapter:

- Parse `IfcProject`, `IfcSite`, `IfcBuilding`, `IfcBuildingStorey`, `IfcSpace`, `IfcZone`, `IfcDoor`, `IfcStair`, `IfcTransportElement`, `IfcMedicalDevice` where present, `IfcDistributionElement`, and geometry placements.
- Preserve IFC GlobalId as `source_global_id`.
- Map IFC property sets to `metadata`.
- Extract object placement into feet.
- Preserve original units in import metadata.

DXF/DWG adapter:

- Parse layers, blocks, polylines, text labels, hatches, and 3D faces.
- Use layer naming and block naming to infer category.
- Reconstruct rooms from closed polylines or wall boundaries.
- Attach nearby text to nearest room polygon.
- Convert CAD units to feet.
- Preserve layer as `source_layer`.

PDF/vector adapter:

- Extract vector paths and embedded text where available.
- Identify floor title blocks, room numbers, room names, scale bars, legends, and north arrows.
- Segment walls, doors, stairs, shafts, elevators, and room boundaries.
- Convert drawing coordinates into a normalized local floor coordinate system.

Raster blueprint adapter:

- Run image preprocessing:
  - deskew
  - denoise
  - contrast normalization
  - line thinning
  - symbol detection
  - OCR
- Detect:
  - rooms
  - doors
  - corridors
  - stairs
  - elevators
  - shafts
  - labels
  - dimensions
  - legends
- Use the scale bar or known room dimensions to calculate feet.
- Assign confidence lower than native BIM/CAD until reviewed.

Generated catalog adapter:

- Load `model_catalog.json`.
- Treat each `objects[]` entry as a pre-classified object.
- Map `category` to `hosp_ref.facility_object_categories`.
- Store:
  - object code
  - name
  - category
  - material
  - floor
  - `position_ft`
  - `size_ft`
  - derived bounds
  - metadata
- Use the catalog summary for import-level quality checks.

### 3. Object Extraction Into `hosp_ingest.blueprint_objects`

Every extracted candidate becomes a source object.

Minimum required fields:

- `blueprint_import_id`
- `object_code`
- `object_name`
- `object_category`
- `floor_number`
- `geometry_kind`
- `position_ft`
- `size_ft`
- `bounds_ft`
- `metadata`
- `classification`
- `extraction_confidence`
- `review_status`

Category mapping:

| Source category | Canonical space category | Full DDL target | Zephyrus operational target |
| --- | --- | --- | --- |
| `floor` | `floor` | `hosp_space.floor` | none |
| `corridor` | `corridor` | `hosp_space.corridor_segment` | future transport graph |
| `care_unit` | `unit` | `hosp_space.care_unit` | `prod.units` |
| `patient_room` | `room` | `hosp_space.room` | `prod.rooms` where applicable |
| `bed` | `bed` | `hosp_space.bed` | `prod.beds` |
| `emergency_department` | `bay` or `room` | `hosp_space.room`, `hosp_clinical.emergency_department_program` | ED operations, future `prod.rooms` mapping |
| `procedure_room` | `room` | `hosp_clinical.procedure_room` | `prod.rooms` |
| `procedure_support` | `room` | `hosp_space.room`, `hosp_logistics.*` where relevant | none initially |
| `imaging` | `room` | `hosp_clinical.imaging_modality` | `prod.rooms` where scheduled |
| `elevator` | `vertical_transport` | `hosp_space.elevator` | future transport graph |
| `helipad` | `exterior` | `hosp_space.path_node`, `hosp_clinical.trauma_response_zone` | future transport graph |
| `support_infrastructure` | `utility` or `support` | `hosp_infra.*`, `hosp_logistics.*`, `hosp_research.*`, `hosp_quality.*` | future operations |

Confidence strategy:

- 0.95-1.00: generated catalog or native BIM object with explicit category.
- 0.85-0.95: IFC object with reliable class and property sets.
- 0.70-0.90: DXF layer/block classification with matching labels.
- 0.50-0.80: PDF vector extraction with good label association.
- 0.30-0.70: raster extraction requiring human review.

### 4. Canonical Facility Space Promotion

Promotion should be explicit, versioned, and review-aware.

Promotion algorithm:

1. Read all `blueprint_objects` for an approved import.
2. Create or update facility hierarchy:
   - facility/campus
   - building
   - floor
   - unit/zone
   - room/bay
   - bed/equipment
   - corridor/path/elevator
3. Use source categories, names, codes, floor numbers, parent-child relationships, and geometry containment to infer hierarchy.
4. Create `hosp_space.facility_spaces`.
5. Preserve source references through `blueprint_object_id`.
6. Store geometry and attributes as JSONB.
7. Mark any low-confidence or conflicting object as review-required.

Promotion should not immediately mutate `prod` workflows. It should first produce canonical spaces and a proposed mapping set.

### 5. Materialization Into The Full `hosp_*` Ontology

The current repository change creates a compact bridge. The full target is to migrate the DDL into first-class Laravel migrations and populate its tables from the canonical facility spaces.

Recommended implementation order:

1. `hosp_ref`
   - Domains/enums/reference tables.
   - Room types, equipment types, role types, standards, assumptions.
   - Generated manifest views.
2. `hosp_org`
   - Organization, campus, service lines, departments, AHJ.
3. `hosp_space`
   - Buildings, floors, zones, care units, rooms, beds, corridors, path nodes/edges, elevators, stairs, doors, wayfinding.
4. `hosp_clinical`
   - Trauma program, ED program, procedure platforms/rooms, imaging, isolation, medication safety, staffing/coverage.
5. `hosp_logistics`
   - PAR, carts, routes, tube stations, waste, sterile processing, food/nutrition routes.
6. `hosp_infra`
   - Utilities, HVAC, environment requirements, electrical, emergency power, water, medical gas, fire/life safety, BMS.
7. `hosp_digital`
   - Network zones, devices, RTLS, application systems, interfaces, cybersecurity, downtime plans.
8. `hosp_quality`
   - Traceability, rounding, emergency plans, surge capacity, committees, disaster exercises.
9. `hosp_research`
   - Academic programs, research spaces, specimen-flow policies.
10. `hosp_opt`
    - Capacity targets, adjacency targets, flow separation, transport SLAs, queue policies, design scenarios, simulation results.

Do not run the full DDL as an ignored root SQL file. It should be promoted to tracked migrations or a tracked allowlisted SQL payload under database ownership, with validation tests and rollback guidance.

### 6. Operational Mapping Into Existing Zephyrus Tables

Mapping rules:

- `care_unit` -> `prod.units`
  - `name` from unit name.
  - `abbreviation` from unit code.
  - `type` from acuity/service-line mapping.
  - `staffed_bed_count` from planned staffed beds.
  - `facility_space_id` from canonical space.
- `bed` -> `prod.beds`
  - `unit_id` from parent care unit mapping.
  - `label` from bed code/label.
  - `status` default `available` or `planned` mapping.
  - `bed_type` from acuity.
  - `isolation_capable` from negative/protective metadata.
  - `facility_space_id` from canonical space.
- `procedure_room` and relevant `patient_room`/`imaging` -> `prod.rooms`
  - `location_id` from mapped department/platform location.
  - `name` from object name/code.
  - `type` from room/platform category.
  - `facility_space_id` from canonical space.
- department/platform groupings -> `prod.locations`
  - Create only when a durable operational location is needed.
  - Avoid making every floor or support area a `prod.location`.

Mapping should be proposed, scored, reviewed, then published.

Mapping confidence inputs:

- exact code match
- exact room number match
- unit code match
- floor match
- geometry containment
- text-label proximity
- service-line match
- current operational row already has cases/encounters using the code
- human approval

### 7. Routing Graph Construction

The DDL's `hosp_space.path_node`, `hosp_space.corridor_segment`, and `hosp_space.path_edge` tables are the foundation for routing.

Graph extraction:

- Create nodes at:
  - room entries
  - bed bay entries where needed
  - corridor intersections
  - elevator lobbies
  - stair landings
  - security checkpoints
  - department entries
  - loading dock points
  - helipad arrival
  - exterior entry points
- Create edges for:
  - corridor centerlines
  - room-to-corridor connections
  - elevator vertical travel by flow class
  - stair vertical travel
  - restricted access doors
  - after-hours restrictions
- Annotate edges with:
  - distance
  - estimated seconds
  - allowed flow class
  - one-way flag
  - restricted-after-hours flag
  - contamination class
  - public access
  - bed/stretcher capability

Routing use cases:

- ED door to CT.
- ED door to OR.
- Helipad to trauma bay.
- Trauma bay to CT.
- Trauma bay to OR.
- OR to ICU.
- ICU to imaging.
- Bed cleaning team dispatch.
- Discharge transport.
- Soiled instrument route to SPD decontamination.
- Sterile instrument route to OR sterile core.
- Pharmacy route.
- Waste route.
- Decedent route.
- Food/nutrition delivery route.
- Research specimen route.

The transport module should evolve from string `origin`/`destination` fields to optional canonical-space references and route snapshots.

### 8. Standards and Traceability Engine

The DDL correctly treats standards as data:

- `design_standard`
- `design_requirement`
- `design_assumption`
- `accreditation_traceability`
- `room_environment_requirement`
- `cybersecurity_control`
- `system_control_mapping`

The ingestion system should never hard-code regulatory compliance as a one-time rule set. It should:

- store each adopted standard and edition;
- bind requirements to rooms, room types, utilities, systems, devices, and policies;
- preserve source URLs and current-as-of dates;
- track local AHJ adoption separately from national model guidance;
- separate "model recommends" from "licensed/approved for this hospital";
- store verification method and evidence requirements;
- flag conflicts by jurisdiction.

Important source families to keep versioned:

- FGI Guidelines/Codes for hospital design and construction.
- ACS trauma standards for Level I trauma capabilities, PIPS, registry, research, education, and system leadership.
- CMS Conditions of Participation, including physical environment, infection prevention, emergency preparedness, radiology, labs, pharmacy, nursing, medical records, and QAPI.
- CMS Life Safety Code and Health Care Facilities Code expectations.
- ASHRAE/ASHE Standard 170 as adopted by applicable guidelines/AHJ.
- CDC infection control and water-management guidance.
- HIPAA Security Rule, NIST CSF, and ONC interoperability/security requirements for digital and medical-device mapping.

The application should present these as traceable evidence and readiness checks, not as legal certification.

### 9. Human Review Workflow

Required review queues:

- Low-confidence room boundaries.
- Duplicate room numbers.
- Rooms without floor assignment.
- Beds without parent unit.
- Units with planned beds that do not equal child bed count.
- Rooms with impossible area/dimension values.
- Doors not attached to rooms/corridors.
- Corridors without graph connectivity.
- Clean and soiled flow conflicts.
- Missing elevator service for critical paths.
- Unmapped operational `prod.rooms`, `prod.units`, or `prod.beds`.
- Imported spaces not mapped to any operational object where mapping is expected.
- Standards requirements without evidence.

Reviewer roles:

- facilities/BIM manager
- nursing operations
- perioperative operations
- ED/trauma leadership
- infection prevention
- safety/security
- biomedical engineering
- IT/security
- emergency management
- quality/accreditation

Review actions:

- approve object
- reject object
- merge duplicates
- split object
- assign category
- assign parent
- assign floor
- attach operational mapping
- override metadata
- mark as exception
- request updated source drawing

### 10. Multi-Hospital Generalization

To support any hospital system, Zephyrus should model each source import as facility-specific and each ontology output as organization/campus/building-scoped.

Required concepts:

- health system organization
- legal entity
- campus
- building
- floor
- service line
- department
- unit
- room
- bed
- corridor/transport graph
- support/logistics route
- utility system
- digital network/device context
- standards profile and AHJ profile

Facility-specific configuration:

- code and naming conventions
- room numbering patterns
- floor labels and skipped floor numbers
- unit abbreviation conventions
- adopted code edition
- state/local AHJ
- trauma designation
- pediatric/adult scope
- licensed versus staffed beds
- campus coordinates
- local coordinate system
- measurement units
- operational source systems

Health-system comparison should normalize to the canonical categories while preserving local names and source evidence.

### 11. Data Quality Gates

Import-level checks:

- source checksum present
- source type supported
- coordinate unit known
- at least one floor detected
- objects count by category within expected bounds
- parser version recorded
- no fatal geometry errors

Floor-level checks:

- floor labels unique within building
- floor numbers and elevations consistent
- each floor has a bounding plate or known footprint
- rooms/corridors assigned to one floor
- vertical transport spans valid floor range

Room-level checks:

- room code unique per floor
- room area nonnegative
- clear dimensions plausible
- room has category/type
- patient-care rooms have expected features when required
- procedure rooms have required support metadata
- isolation rooms have pressure/exhaust metadata or review exception

Bed-level checks:

- bed code unique.
- each bed belongs to exactly one room and one unit.
- bed acuity aligns with unit acuity or has documented swing use.
- staffed/licensed/surge status is explicit.
- isolation-capable flags reconcile with room isolation flags.

Graph-level checks:

- all patient-care rooms reachable.
- ED trauma route to CT/OR/ICU under target threshold or flagged.
- clean and soiled routes do not share restricted edges unless exception approved.
- public paths do not enter restricted staff/sterile zones.
- bed/stretcher routes use bed-capable corridors/elevators.

Operational mapping checks:

- every active `prod.unit` has zero or one active canonical facility mapping.
- every active `prod.bed` has zero or one active canonical facility mapping.
- every mapped bed's parent canonical unit matches the mapped `prod.beds.unit_id`.
- every mapped OR/procedure room can be reached from procedure platform support paths.
- no active mapping points to deleted operational rows.

### 12. APIs And User Experience

Backend APIs to add:

- `POST /api/facility/imports`
  - register an import.
- `POST /api/facility/imports/{id}/parse`
  - start parser job.
- `GET /api/facility/imports/{id}`
  - import status, summary counts, quality gates.
- `GET /api/facility/imports/{id}/objects`
  - extracted objects with filters.
- `PATCH /api/facility/objects/{id}`
  - review and classification edits.
- `POST /api/facility/imports/{id}/promote`
  - create canonical spaces.
- `GET /api/facility/spaces`
  - canonical spaces with floor/category filters.
- `GET /api/facility/spaces/{id}`
  - detailed space page.
- `POST /api/facility/spaces/{id}/map`
  - map to operational location/room/unit/bed.
- `GET /api/facility/routes`
  - route calculation.
- `GET /api/facility/quality-gates`
  - import and canonical model quality checks.

UI views to add:

- Import dashboard.
- Floor plan/object review.
- 3D viewer with object inspector.
- Mapping workbench.
- Unit/bed reconciliation.
- Corridor/flow graph review.
- Standards traceability matrix.
- Simulation scenario dashboard.

The first UI should not be a marketing landing page. It should be an operational workbench: import list, object counts, review queue, floor/category filters, and mapping status.

### 13. Simulation And Optimization

The DDL's `hosp_opt` schema should become the simulation contract.

Initial simulations:

- ED arrival to trauma CT and OR.
- Bed request to best available bed.
- Transport staffing and route time.
- Elevator capacity and priority conflicts.
- OR to ICU bed contention.
- Discharge transport and room cleaning turnover.
- Clean/sterile/soiled logistics route pressure.
- Surge conversion capacity by unit/floor.
- Downtime route feasibility.

Outputs:

- scenario definition
- assumptions
- affected facility spaces
- routing graph version
- input demand
- result metrics
- bottlenecks
- recommendation list
- audit trail

Optimization targets:

- reduce ED boarding.
- protect trauma time-to-CT/OR.
- reduce patient transport delays.
- improve OR utilization without ICU blocking.
- separate clean/soiled/waste/public flows.
- identify high-value adjacency changes.
- quantify surge capacity.

### 14. Security, Privacy, And Operational Safety

Blueprint/CAD/BIM data can be sensitive even without PHI.

Controls:

- Treat facility maps, utility rooms, security zones, network closets, emergency power, medical gas, and downtime plans as restricted.
- Store source files in access-controlled storage.
- Keep import logs and review actions auditable.
- Redact public exports.
- Apply least-privilege RBAC by role.
- Do not expose detailed security, utility, and network layers in public or broad-user views.
- Keep cybersecurity controls and medical-device segmentation evidence in restricted admin workflows.
- Avoid putting PHI in blueprint metadata.

### 15. Implementation Phases

Phase 0: Done in this change

- Add ingestion/canonical bridge schemas.
- Add category mapping table.
- Add source object table.
- Add canonical facility space table.
- Add operational mapping table.
- Add nullable `facility_space_id` on operational location/room/unit/bed tables.
- Add Eloquent models and relationships.
- Add schema tests.

Phase 1: Generated catalog importer and summary API

- Implemented in this pass:
  - `php artisan facility:import-catalog hospital-cad-model/data/model_catalog.json`
  - `GET /api/facility/model/summary`
  - `GET /api/facility/model/summary?facility_code=ZEPHYRUS-500`
- Added `App\Services\Facility\ModelCatalogImporter`.
- Added `App\Console\Commands\FacilityImportCatalogCommand`.
- Added `App\Http\Controllers\Api\Facility\FacilityModelController`.
- Added compact fixture coverage in `tests/Fixtures/facility/model_catalog_fixture.json`.
- Added command tests in `tests/Feature/FacilityCatalogImportCommandTest.php`.
- Added API tests in `tests/Feature/FacilityModelApiTest.php`.
- Parses the existing generated catalog shape.
- Populates `hosp_ingest.blueprint_imports`.
- Populates `hosp_ingest.blueprint_objects`.
- Promotes every catalog object to `hosp_space.facility_spaces`.
- Optionally creates/attaches `prod.units` and `prod.beds` through `--map-operational`.
- Creates `hosp_space.operational_space_maps` for mapped care units and beds.
- Is idempotent for the same source checksum, source name, and facility code.
- Reports latest import state, object/category/floor/review counts, canonical-space counts, active mapping coverage, unmapped spaces, and linked operational rows.

Validation against `hospital-cad-model/data/model_catalog.json` in the isolated `zephyrus_test` database imported:

- 1 blueprint import.
- 1,472 blueprint objects.
- 1,472 canonical facility spaces.
- 23 operational units.
- 500 operational beds.
- 523 operational maps.

Phase 2: Full DDL migration package

- Convert the root DDL into tracked migrations.
- Preserve domains, enums, tables, views, indexes, and comments.
- Add schema tests by schema/table count and key columns.
- Keep generated manifest views.
- Add migration rollback guidance.
- Add development seed data from the DDL views.

Phase 3: Canonical materializers

- Materialize:
  - `hosp_org.organization`, `campus`, `service_line`, `department`
  - `hosp_space.building`, `floor`, `care_unit`, `room`, `bed`
  - `hosp_space.corridor_segment`, `path_node`, `path_edge`
  - `hosp_space.elevator_bank`, `elevator`, `stair`, `door`
- Link materialized rows back to `blueprint_objects`.
- Add deterministic idempotency keys.

Phase 4: Operational reconciliation

- Build a mapping workbench.
- Auto-map existing `prod.units`, `prod.beds`, and `prod.rooms`.
- Add review states and conflict resolution.
- Add API endpoints.
- Add import status and mapping completeness metrics.

Phase 5: Real CAD/BIM/PDF adapters

- Add IFC parser.
- Add DXF parser.
- Add PDF/vector parser.
- Add raster/OCR parser.
- Add adapter-specific fixtures and confidence scoring.
- Add source checksum/version management.

Phase 6: Routing graph and transport integration

- Generate path nodes/edges from corridors, doors, elevators, stairs, and room entries.
- Add route calculator service.
- Add optional canonical origin/destination references to `prod.transport_requests`.
- Snapshot route and SLA evidence into transport events.

Phase 7: Standards and quality traceability

- Populate `hosp_ref.design_standard`, `design_requirement`, and `design_assumption`.
- Add room/bed/infrastructure/equipment requirement checks.
- Add evidence upload/linking.
- Add readiness dashboard.

Phase 8: Multi-hospital operations

- Add organization/campus/building scoping to imports, spaces, and mappings.
- Support multiple current approved model versions.
- Add comparison dashboards.
- Add health-system-wide capacity and route simulation.

## Open Risks And Decisions

- The full DDL has many schemas and enums; it should be migrated deliberately rather than pasted into a one-off local-only SQL file.
- The generated CAD model is concept-level and rectangular-box based. Real hospital blueprints will have noisy geometry, inconsistent naming, and missing semantics.
- Revit/IFC can preserve semantics, but PDF/raster imports require lower-confidence AI/CV extraction and human review.
- Transport graph extraction is not trivial. Door swings, controlled access, elevator service classes, after-hours restrictions, and bed/stretcher clearance all matter.
- Regulatory traceability must remain versioned and jurisdiction-specific. Zephyrus can store evidence and readiness checks but cannot certify compliance.
- Facility maps may expose sensitive security and infrastructure details. RBAC and redaction are mandatory before broad access.
- Existing `prod.rooms` has a restrictive `type` check; procedure/imaging/ED mappings may require a future room-type vocabulary expansion.
- `prod.transport_requests.origin` and `destination` are currently strings. Canonical-space references should be added without removing the strings until downstream workflows are migrated.

## Immediate Next Development Tasks

1. Add a facility import review page backed by `/api/facility/model/summary`.
2. Convert the complete DDL into tracked migrations.
3. Add graph generation for corridors, room entries, elevators, stairs, helipad, and loading dock.
4. Extend transport requests with optional canonical origin/destination space references.
5. Add review-state workflows for mapping conflicts and source-object approvals.
6. Add IFC and DXF parser adapters after the generated catalog importer remains stable.
