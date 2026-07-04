# Service Line and Physical Location Deployment Taxonomy

Date: 2026-07-04

Status: Research report and deployment-planning baseline

Scope: Tier 1 / quaternary academic medical centers, Level I trauma centers, tertiary regional referral centers, community hospitals, satellite emergency departments, ambulatory campuses, specialty hospitals, and integrated delivery networks (IDNs).

## Objective

Zephyrus needs a malleable but rigorous service-line and physical-location taxonomy that can be deployed into any hospital or IDN without assuming every customer looks like the current 500-bed Summit Regional model.

The deployment model must answer four operational questions:

1. What clinical service lines are present?
2. Where are those service lines physically delivered?
3. Which locations are operationally active in Zephyrus workflows?
4. How do geography, transfer patterns, trauma designation, academic status, and community-hospital scope change the mapping?

The output of this report is a deployable crosswalk from service line -> department/program -> physical infrastructure -> Zephyrus facility-space and operational mappings.

## Source Base

This report combines current Zephyrus repo truth with external healthcare planning, regulatory, accreditation, and health-system evidence.

### Zephyrus Sources Reviewed

- `docs/superpowers/plans/2026-06-25-hospital-blueprint-ingestion-digital-twin.md`
- `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md`
- `docs/HOSPITAL-1-SUMMIT-REGIONAL-PLAN.md`
- `config/hospital/hospital-1.php`
- `config/facility_models.php`
- `database/migrations/2026_06_25_000010_create_facility_blueprint_model_tables.php`
- `app/Support/Hospital/HospitalManifest.php`

Current Zephyrus already has the right facility mapping spine:

- `hosp_ingest.blueprint_imports`
- `hosp_ingest.blueprint_objects`
- `hosp_space.facility_spaces`
- `hosp_space.operational_space_maps`
- nullable `facility_space_id` links on `prod.locations`, `prod.rooms`, `prod.units`, and `prod.beds`
- patient-flow location resolution through `FacilitySpaceLocationResolver`
- a manifest-driven synthetic hospital at `config/hospital/hospital-1.php`

This report extends that spine from a single synthetic 500-bed facility to a reusable IDN deployment taxonomy.

### External Evidence Used

- AHA Fast Facts on U.S. Hospitals 2026: U.S. hospitals are overwhelmingly community hospitals, and most community hospitals are system-affiliated; this matters because Zephyrus deployments should expect multi-site IDNs, not only single flagship hospitals. Source: <https://www.aha.org/infographics/2026-02-05-fast-facts-us-hospitals-infographics>
- HCUP Summary Trend Tables: AHRQ groups inpatient service lines into maternal/neonatal, mental health/substance use, injuries, surgeries, and other medical conditions, plus ED treat-and-release. Source: <https://hcup-us.ahrq.gov/reports/trendtables/summarytrendtables.jsp>
- CMS Conditions of Participation for Hospitals: core hospital functions include nursing, medical records, pharmaceutical, radiologic, laboratory, food/dietetic, utilization review, physical environment, infection prevention, discharge planning, and organ procurement; optional services include surgery, anesthesia, nuclear medicine, outpatient, emergency, rehabilitation, respiratory care, swing beds, and obstetrics. Source: <https://www.law.cornell.edu/cfr/text/42/part-482/subpart-C> and <https://www.law.cornell.edu/cfr/text/42/part-482/subpart-D>
- CMS cost reporting: Medicare cost reports include facility characteristics, utilization data, and cost/charges by cost center. This validates keeping a service-line taxonomy separate from both billing cost centers and physical spaces. Source: <https://www.cms.gov/data-research/statistics-trends-and-reports/cost-reports>
- Medicare revenue-code groupings: revenue-code families identify inpatient room/board, ICU, therapy, emergency room, cardiology, ambulatory surgery, clinics, radiology, pharmacy, lab, and other operational cost/revenue surfaces. Source: <https://med.noridianmedicare.com/web/jea/topics/claim-submission/revenue-codes>
- Facility Guidelines Institute 2022 hospital/outpatient updates: modern facility programs explicitly call out behavioral health crisis units, low-acuity treatment stations, burn trauma ICU, imaging, neonatal ICU, mobile units, hyperbaric oxygen, sexual assault forensic exam rooms, and ambulatory/outpatient requirements. Source: <https://fgiguidelines.org/codes/editions/>
- American College of Surgeons trauma standards: the ACS 2022 standards are the current trauma verification standard set, and Level I/II programs require deep surgical, critical care, radiology, anesthesia, emergency medicine, performance-improvement, education, and system leadership capabilities. Source: <https://www.facs.org/quality-programs/trauma/quality/verification-review-and-consultation-program/standards/> and <https://www.facs.org/quality-programs/trauma/quality/verification-review-and-consultation-program/trauma-verification-qas/vrc-2022-standards-qas/>
- Joint Commission stroke certification: stroke programs range from Acute Stroke Ready Hospital to Primary Stroke Center, Thrombectomy-Capable Stroke Center, and Comprehensive Stroke Center. Source: <https://www.jointcommission.org/en-us/certification/stroke>
- CDC LOCATe: maternal and neonatal care should be mapped by level of care and by facility, not just by the presence of "OB." Source: <https://www.cdc.gov/maternal-infant-health/php/cdc-locate/index.html>
- ACOG maternal levels of care: maternal care is a levelled capability from basic care through regional perinatal centers. Source: <https://www.acog.org/clinical/clinical-guidance/obstetric-care-consensus/articles/2019/08/levels-of-maternal-care>
- American Burn Association verification: burn centers are verified across the continuum from emergency response through rehabilitation. Source: <https://www.ameriburn.org/quality-care/verification>
- HRSA OPTN: transplant service lines require national organ donation/procurement/transplant integration. Source: <https://www.hrsa.gov/optn>

## Core Deployment Principle

Do not model a hospital as "a list of departments."

Model it as a geography-aware graph of:

- enterprise service lines
- clinical programs
- departments and cost centers
- physical locations
- care units
- rooms, bays, chairs, procedure rooms, imaging rooms, and beds
- logistics paths
- clinical coverage capabilities
- transfer relationships
- operating modes by time of day and surge state

A service line can span many locations. A location can serve many service lines. A program can exist at one site as definitive care and another site as stabilization, consult, telehealth, or transfer.

## Vocabulary

### Service Line

An enterprise clinical business and operational grouping, such as emergency medicine, cardiovascular, neurosciences, oncology, women and infants, behavioral health, perioperative, or rehabilitation.

### Program

A clinically distinct capability within a service line, such as Level I trauma, comprehensive stroke, cardiac surgery, electrophysiology, kidney transplant, maternal-fetal medicine, or inpatient psychiatric stabilization.

### Department

An organizational, staffing, cost-center, or reporting unit, such as emergency department, operating room, PACU, cath lab, radiology, pharmacy, laboratory, NICU, or inpatient rehab.

### Physical Location

A specific spatial object or collection of objects: campus, building, floor, unit, room, bay, bed, chair, lab bench, OR, imaging suite, ambulance dock, helipad, clean core, soiled utility, loading dock, pharmacy, or command center.

### Capability Level

The degree to which a site can deliver a program:

- `none`: not offered.
- `screen`: intake, triage, or screening only.
- `stabilize`: immediate assessment and stabilization; definitive care may transfer.
- `routine`: common services for lower-acuity patients.
- `advanced`: higher complexity but not full quaternary capability.
- `definitive`: complete service for the expected case mix.
- `quaternary`: rare, complex, high-acuity, research/teaching-capable care.

### Location Role

How a physical space participates in the service line:

- `arrival`
- `triage`
- `diagnostic`
- `treatment`
- `procedure`
- `recovery`
- `inpatient`
- `critical_care`
- `observation`
- `rehabilitation`
- `outpatient`
- `support`
- `logistics`
- `command`
- `surge`
- `transfer`

### IDN Geography Role

How a facility participates in an IDN:

- `flagship_quaternary_hub`
- `academic_tertiary_hub`
- `regional_referral_hub`
- `community_hospital`
- `critical_access_or_rural_hospital`
- `specialty_hospital`
- `satellite_ed`
- `ambulatory_campus`
- `ambulatory_surgery_center`
- `urgent_care`
- `home_hospital`
- `post_acute`
- `behavioral_health_facility`
- `virtual_command_center`

## Zephyrus Canonical Mapping Model

Every deployment should populate four layers.

### Layer 1: IDN Geography

Recommended canonical entities:

- `organization`
- `market`
- `region`
- `county`
- `service_area`
- `campus`
- `facility`
- `building`
- `floor`

Minimum fields:

- `facility_key`
- `facility_name`
- `market`
- `state`
- `county`
- `lat`
- `lng`
- `idn_role`
- `license_type`
- `teaching_status`
- `trauma_level_adult`
- `trauma_level_pediatric`
- `stroke_level`
- `maternal_level`
- `neonatal_level`
- `burn_center_status`
- `transplant_center_status`
- `pediatric_capability`
- `behavioral_health_capability`
- `ambulatory_surgery_capability`
- `home_hospital_capability`

### Layer 2: Service-Line Catalog

Recommended service-line entity:

```text
service_line_code
display_name
clinical_domain
adult_or_pediatric
care_setting_default
requires_24_7
requires_inpatient_beds
requires_procedure_platform
requires_imaging
requires_lab
requires_pharmacy
requires_transport
requires_transfer_agreements
certification_or_designation
```

### Layer 3: Facility-Service Capability Matrix

One row per facility and service line:

```text
facility_key
service_line_code
capability_level
programs_present[]
departments_present[]
coverage_model
hours
transfer_out_targets[]
transfer_in_sources[]
telehealth_support
source_evidence_url
source_evidence_type
review_status
```

### Layer 4: Physical Location Mapping

One row per physical space and service-line role:

```text
facility_space_id
facility_key
service_line_code
program_code
department_code
location_role
space_category
unit_code
room_code
bed_code
capability_tags[]
operational_target_table
operational_target_id
routing_node_id
clean_soiled_flow_class
public_staff_patient_access_class
effective_start
effective_end
```

This should map into Zephyrus as:

- service-line and program metadata on `hosp_space.facility_spaces.attributes`
- service-line code on `hosp_space.facility_spaces.service_line_code` when one dominant service line owns the space
- many-to-many service-line use in a future bridge table when locations are shared
- `prod.units` for staffed inpatient and observation units
- `prod.beds` for operational bed positions
- `prod.rooms` for ORs, procedure rooms, imaging/procedure rooms, ED rooms, PACU bays, clinic rooms where Zephyrus needs operational visibility
- `prod.locations` for durable operational groupings, not every physical space
- `ops.nodes` and `ops.edges` or future routing graph entities for flow-time, separation, transport, logistics, and surge simulations

## Enterprise Service-Line Taxonomy

The taxonomy below is intentionally broader than the current Summit Regional manifest. It includes service lines common in Level I trauma academic medical centers, tertiary hubs, and community hospitals, plus optional specialty programs that should be represented cleanly when absent.

### Summary Matrix

| Code                                      | Service line                                                            | Typical Tier 1 / Level I academic                                        | Typical community hospital                           | Primary physical anchors                                                                                                     |
| ----------------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------ | ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `emergency`                               | Emergency medicine                                                      | Always present, 24/7, often trauma/stroke/STEMI capable                  | Usually present, 24/7 or limited at rural sites      | Ambulance bay, walk-in entrance, triage, resus, trauma bays, treatment rooms, fast track, behavioral crisis, observation/CDU |
| `trauma_acute_care_surgery`               | Trauma, acute care surgery, surgical critical care                      | Level I/II, 24/7 OR/ICU/specialists, research/education at Level I       | Stabilize/transfer or Level III/IV                   | ED trauma bays, CT, OR, SICU/TICU, blood bank, helipad, trauma nursing unit                                                  |
| `critical_care`                           | Adult critical care                                                     | MICU/SICU/CVICU/NSICU/Burn ICU, eICU                                     | ICU or mixed ICU/stepdown                            | ICU rooms, stepdown, respiratory therapy, central monitoring                                                                 |
| `hospital_medicine`                       | General medicine and observation                                        | Large inpatient footprint, subspecialty co-management                    | Core inpatient service                               | Med/surg units, observation/CDU, procedure support, discharge lounge                                                         |
| `adult_med_surg`                          | Medical/surgical nursing                                                | Multiple units, specialty-differentiated floors                          | Core inpatient service                               | Patient rooms, beds, nursing stations, clean/soiled utility                                                                  |
| `cardiovascular`                          | Cardiology, cardiac surgery, vascular, EP                               | Full cath/EP/cardiac surgery/CVICU, advanced HF                          | Cardiology, telemetry, stress, sometimes PCI         | ED chest pain, cath labs, EP labs, echo, stress, telemetry, CVOR, CVICU                                                      |
| `neurosciences`                           | Neurology, neurosurgery, stroke, spine                                  | Comprehensive stroke, thrombectomy, neuro ICU, neurosurgery              | Primary stroke, telestroke, transfer                 | ED stroke bay, CT/CTA/MRI, IR/neurointerventional, NSICU, stroke unit                                                        |
| `perioperative`                           | Surgery, anesthesia, procedural platform                                | Large OR platform, hybrid OR, transplant, trauma OR, robotic surgery     | General surgery, ortho, endoscopy, limited specialty | ORs, pre-op, PACU, sterile core, anesthesia workroom, SPD, surgical ICU                                                      |
| `orthopedics_spine`                       | Orthopedics, spine, sports medicine                                     | Trauma ortho, joint, spine, complex revision                             | Common elective/community line                       | ORs, imaging, inpatient ortho unit, rehab gym, clinic                                                                        |
| `oncology`                                | Cancer, hematology, infusion, radiation                                 | Comprehensive cancer center, BMT/cellular therapy, proton/radiation      | Medical oncology, infusion, screening, referral      | Infusion, radiation vaults, oncology unit, BMT/protective unit, imaging                                                      |
| `womens_health`                           | OB, gynecology, maternal-fetal medicine                                 | High-risk OB, L&D, antepartum, postpartum, OB OR, fetal testing          | OB may be present or absent; often routine L&D       | L&D, triage, OB OR, postpartum, antepartum, MFM clinic                                                                       |
| `neonatology`                             | NICU, newborn nursery                                                   | Level III/IV NICU, neonatal transport                                    | Nursery or special care nursery                      | NICU, special care nursery, resus rooms, milk room                                                                           |
| `pediatrics`                              | Pediatric acute, PICU, pediatric ED                                     | Children's hospital or major pediatric unit/PICU                         | Limited pediatric inpatient or ED stabilization      | Pediatric ED zone, pediatric unit, PICU, child-life spaces                                                                   |
| `behavioral_health`                       | Psychiatry, addiction, crisis                                           | Inpatient psych, ED crisis, consult-liaison, addiction medicine          | Inpatient psych may be absent; ED crisis common      | Secure psych unit, ED safe rooms, crisis unit, detox, outpatient                                                             |
| `rehabilitation`                          | Inpatient and outpatient rehab                                          | Acute inpatient rehab, neuro/trauma rehab, therapy gyms                  | Outpatient rehab, sometimes inpatient rehab          | Rehab unit, therapy gym, ADL apartment, outpatient therapy                                                                   |
| `imaging_diagnostics`                     | Radiology and diagnostics                                               | Full advanced imaging, IR, nuclear medicine                              | X-ray/CT/US/MRI variable                             | CT, MRI, X-ray, ultrasound, nuclear medicine, reading rooms                                                                  |
| `laboratory_pathology`                    | Lab, pathology, blood bank                                              | Full lab, pathology, blood bank, transfusion medicine                    | Core lab, send-out, blood storage                    | Core lab, blood bank, pathology, specimen receiving, phlebotomy                                                              |
| `pharmacy_medication`                     | Pharmacy and medication management                                      | Central pharmacy, IV compounding, OR/ED satellites, investigational drug | Central pharmacy, ADCs, maybe infusion pharmacy      | Central pharmacy, clean rooms, med rooms, ADCs                                                                               |
| `renal_dialysis`                          | Nephrology, dialysis, apheresis                                         | Inpatient dialysis, transplant nephrology, CRRT, apheresis               | Inpatient dialysis, outpatient partner               | Dialysis unit, ICU bedside CRRT, water treatment, transplant clinic                                                          |
| `gastroenterology`                        | GI, endoscopy, hepatology                                               | Advanced endoscopy, transplant hepatology                                | Endoscopy and GI clinic common                       | Endoscopy rooms, prep/recovery, fluoroscopy, clinic                                                                          |
| `pulmonary_respiratory`                   | Pulmonary, respiratory therapy, sleep                                   | Pulmonary ICU overlap, bronchoscopy, ECMO at quaternary                  | Pulmonary clinic, respiratory therapy, sleep         | Bronchoscopy suite, RT workrooms, sleep lab, PFT lab                                                                         |
| `infectious_disease_infection_prevention` | ID, isolation, infection prevention                                     | ID consults, transplant ID, high-level isolation plans                   | Infection prevention, ID consult variable            | Isolation rooms, negative pressure, lab, antimicrobial stewardship                                                           |
| `transplant`                              | Solid organ transplant                                                  | Kidney/liver/pancreas/heart/lung variable; OPTN integration              | Usually not present                                  | Transplant OR, ICU, transplant unit, clinic, HLA lab, pharmacy                                                               |
| `burn`                                    | Burn care                                                               | ABA-verified burn center, burn ICU/OR/rehab                              | Stabilize/transfer                                   | Burn ICU, burn OR, hydrotherapy/wound rooms, rehab                                                                           |
| `geriatrics_palliative`                   | Geriatrics, palliative care, hospice interface                          | Consult service, ACE units, palliative beds                              | Common consult or outpatient                         | ACE unit, palliative rooms, family meeting rooms                                                                             |
| `primary_ambulatory`                      | Primary care, urgent care, clinics                                      | Enterprise clinics and specialty practices                               | Core outpatient network                              | MOBs, clinics, urgent care, retail clinics, telehealth                                                                       |
| `home_post_acute`                         | Home health, hospital-at-home, SNF/LTACH network                        | Often integrated into IDN                                                | Often partner-network driven                         | Home, virtual command center, remote monitoring, post-acute partners                                                         |
| `logistics_support`                       | Transport, EVS, nutrition, supply chain, sterile processing, facilities | Large-scale centralized operations                                       | Present at every hospital, scaled down               | EVS closets, transport dispatch, kitchen, dock, SPD, materials management                                                    |
| `quality_research_education`              | Quality, research, graduate medical education                           | Required differentiator for academic/Level I programs                    | Quality present; research/education variable         | Simulation center, classrooms, research labs, PI offices                                                                     |

## Detailed Service-Line to Physical-Location Crosswalk

### 1. Emergency Medicine

Common in:

- Level I trauma centers
- tertiary hospitals
- community hospitals
- rural hospitals
- satellite emergency departments

Physical anchors:

- ambulance entrance
- public/walk-in entrance
- security vestibule
- waiting and registration
- triage rooms
- resuscitation rooms
- trauma bays
- low-acuity treatment stations
- fast-track rooms
- treatment rooms
- behavioral health safe rooms
- decontamination zone
- isolation rooms
- ED observation/CDU
- ED CT or immediate imaging path
- point-of-care lab
- medication rooms
- nurse/team stations
- EMS room
- helipad path where applicable

Zephyrus mapping:

- `service_line_code`: `emergency`
- `space_category`: `room`, `bay`, `support`, `corridor`, `exterior`
- `prod.rooms`: ED beds, bays, trauma rooms, observation rooms when operationally tracked
- `prod.locations`: ED, ED Observation, Fast Track, Behavioral Crisis
- `prod.units`: CDU/observation only if staffed like a bedded unit
- route graph priority paths: ambulance -> resus, helipad -> resus, ED -> CT, ED -> OR, ED -> ICU

Deployment questions:

- Does the ED receive ambulances?
- Is it a hospital-based ED, freestanding ED, satellite ED, or urgent care?
- Is trauma care definitive, stabilize-and-transfer, or absent?
- Is there pediatric ED capability?
- Is there behavioral health crisis capability?
- Is ED observation a licensed unit, ED-managed bay group, or separate CDU?

### 2. Trauma, Acute Care Surgery, and Surgical Critical Care

Common in:

- Level I and Level II trauma centers
- Level III/IV stabilization hospitals
- regional referral hubs

Physical anchors:

- ambulance bay
- helipad
- ED trauma/resuscitation rooms
- trauma CT
- interventional radiology
- emergency OR
- hybrid OR if present
- blood bank / massive transfusion storage
- SICU/TICU
- trauma stepdown or nursing unit
- inpatient rehab connection
- morgue/decedent care route
- family consultation spaces
- trauma program offices and PI meeting spaces

Zephyrus mapping:

- `service_line_code`: `trauma_acute_care_surgery`
- `program_code`: `adult_level_i_trauma`, `adult_level_ii_trauma`, `adult_level_iii_trauma`, `adult_level_iv_trauma`, `pediatric_trauma`
- `capability_level`: definitive for Level I/II, stabilize for Level III/IV
- `prod.rooms`: trauma bays, ORs, IR, CT, ICU rooms
- `prod.units`: trauma ICU, surgical ICU, trauma stepdown, trauma nursing unit
- route graph: helipad/ambulance -> trauma bay -> CT -> OR/IR/ICU

Deployment questions:

- What is the adult and pediatric trauma designation?
- Are neurosurgery, orthopedics, anesthesia, radiology, critical care, and blood bank available 24/7?
- Is the first OR immediately available, and what is the backup OR plan?
- Which hospitals are trauma transfer-in hubs and which are transfer-out spokes?

### 3. Critical Care

Common in:

- academic centers: multiple dedicated ICUs
- tertiary hubs: dedicated or mixed specialty ICUs
- community hospitals: general ICU or ICU/stepdown hybrid
- rural hospitals: limited ICU or tele-ICU support

Physical anchors:

- MICU
- SICU/TICU
- CVICU
- NSICU
- burn ICU
- PICU
- NICU
- mixed ICU
- stepdown/intermediate care
- respiratory therapy workrooms
- central monitoring
- family consult rooms
- isolation rooms
- ICU medication rooms

Zephyrus mapping:

- `service_line_code`: `critical_care` plus specialty overlay when applicable
- `prod.units`: ICU and stepdown units
- `prod.beds`: ventilator capable, isolation capable, telemetry, CRRT capable, negative pressure, lift, medical gas
- `space_category`: `unit`, `room`, `bed`, `support`

Deployment questions:

- Is each ICU physically distinct or a flexible mixed-acuity unit?
- Which beds can support vents, CRRT, pressors, negative pressure, protective isolation, ECMO, or invasive neuro monitoring?
- Is coverage in-house, tele-ICU, intensivist daytime only, or open ICU?

### 4. Hospital Medicine, Adult Med/Surg, and Observation

Common in:

- all general acute care hospitals

Physical anchors:

- med/surg units
- telemetry units
- observation units
- discharge lounge
- procedure rooms
- nursing stations
- medication rooms
- clean/soiled utility
- nourishment
- family waiting
- care management workspaces

Zephyrus mapping:

- `service_line_code`: `hospital_medicine`, `adult_med_surg`, `medicine`, or specialty overlay
- `prod.units`: all staffed inpatient units
- `prod.beds`: licensed/staffed beds
- `prod.rooms`: patient rooms only if operationally necessary beyond bed-level RTDC

Deployment questions:

- Which units are general medicine, surgery, mixed, telemetry, renal, oncology, orthopedics, or overflow?
- Are observation beds ED-owned, hospitalist-owned, or licensed inpatient-capable?
- Which units flex during surge?

### 5. Cardiovascular

Common in:

- Tier 1 academic centers
- tertiary hubs
- many community hospitals at lower capability levels

Physical anchors:

- ED chest-pain pathway
- telemetry units
- cardiac stepdown
- cath labs
- EP labs
- echo
- stress testing
- cardiac CT/MRI
- CVOR
- hybrid OR
- CVICU
- heart failure clinic
- cardiac rehab

Zephyrus mapping:

- `service_line_code`: `cardiovascular`
- `program_code`: `stemi_pci`, `cardiac_surgery`, `structural_heart`, `electrophysiology`, `heart_failure`, `vascular_surgery`, `cardiac_rehab`
- `prod.rooms`: cath labs, EP labs, CVORs, hybrid ORs, stress rooms
- `prod.units`: telemetry, cardiac stepdown, CVICU
- route graph: ED -> cath, cath -> CVICU, OR -> CVICU, telemetry -> cath

Deployment questions:

- Is PCI available 24/7?
- Is cardiac surgery on-site?
- Are TAVR/structural heart, EP ablation, mechanical circulatory support, ECMO, or transplant present?
- Which community hospitals transfer STEMI or complex cardiac cases to the hub?

### 6. Neurosciences and Stroke

Common in:

- academic centers and tertiary hubs as comprehensive or thrombectomy-capable programs
- community hospitals as primary stroke, acute stroke ready, telestroke, or transfer programs

Physical anchors:

- ED stroke intake
- CT/CTA
- MRI
- neurointerventional suite
- neurosurgical OR
- NSICU
- stroke unit
- epilepsy monitoring unit where present
- rehab connection

Zephyrus mapping:

- `service_line_code`: `neurosciences`
- `program_code`: `acute_stroke_ready`, `primary_stroke`, `thrombectomy_capable`, `comprehensive_stroke`, `neurosurgery`, `spine`, `epilepsy`
- `prod.rooms`: CT, MRI, angio/IR, neurosurgical ORs
- `prod.units`: NSICU, stroke unit, neuro stepdown
- route graph: ED -> CT -> IR/OR/ICU/stroke unit

Deployment questions:

- What is the stroke certification level?
- Is thrombectomy on-site 24/7?
- Is neurosurgery on-site or transfer?
- Is the stroke unit physically dedicated or mixed telemetry?

### 7. Perioperative, Procedural, and Anesthesia

Common in:

- all surgical hospitals, scaled by capability

Physical anchors:

- pre-op
- holding
- ORs
- trauma/emergency OR
- hybrid OR
- robotic OR
- cystoscopy/urology procedure rooms
- endoscopy
- cath/EP/IR as adjacent procedural platforms
- PACU Phase I/II
- sterile processing
- sterile core
- anesthesia workrooms
- implant storage
- blood refrigerators
- clean/soiled corridors

Zephyrus mapping:

- `service_line_code`: `perioperative`
- `program_code`: specialty procedure lines
- `prod.locations`: OR suite, procedural platform, endoscopy, IR, cath lab
- `prod.rooms`: each OR/procedure room/PACU bay when scheduled or operationally tracked
- route graph: patient transport, clean supply, sterile instruments, soiled instruments, waste, anesthesia meds

Deployment questions:

- How many rooms are licensed ORs versus procedure rooms?
- Which rooms are trauma priority, cardiac capable, hybrid, robotic, endoscopy, EP, IR, or outpatient?
- Is SPD on the same floor, basement, offsite, or shared?
- Are clean/soiled routes separated?

### 8. Orthopedics, Spine, and Sports Medicine

Common in:

- academic centers
- community hospitals
- ambulatory surgery centers

Physical anchors:

- orthopedic clinics
- imaging
- prehab/rehab gyms
- ORs
- outpatient surgery
- inpatient ortho unit
- therapy
- durable medical equipment storage

Zephyrus mapping:

- `service_line_code`: `orthopedics_spine`
- `program_code`: `trauma_orthopedics`, `joint_replacement`, `spine`, `sports_medicine`, `hand`, `foot_ankle`
- `prod.rooms`: ORs and clinic/procedure rooms if tracked
- `prod.units`: ortho/spine inpatient units

Deployment questions:

- Are joint replacements inpatient, outpatient, ASC-based, or mixed?
- Does trauma ortho support Level I/II trauma?
- Are spine cases done at the flagship, community hospital, or ASC?

### 9. Oncology, Hematology, BMT, and Cellular Therapy

Common in:

- academic centers: comprehensive cancer center, BMT/cellular therapy, radiation, trials
- community hospitals: infusion, medical oncology, screening, navigation, radiation in larger sites

Physical anchors:

- infusion center
- radiation oncology vaults
- linear accelerators
- proton therapy where present
- oncology clinics
- oncology inpatient unit
- BMT/protective environment unit
- apheresis
- pharmacy clean rooms
- imaging
- procedure rooms
- research/clinical trial offices

Zephyrus mapping:

- `service_line_code`: `oncology`
- `program_code`: `medical_oncology`, `radiation_oncology`, `surgical_oncology`, `bmt`, `cellular_therapy`, `infusion`, `clinical_trials`
- `prod.units`: oncology, BMT/protective units
- `prod.rooms`: infusion chairs, radiation treatment rooms, procedure rooms if capacity-tracked

Deployment questions:

- Does the facility provide infusion only, radiation, inpatient oncology, BMT, cellular therapy, or trials?
- Are oncology beds protective environment capable?
- Is oncology pharmacy local or centralized?

### 10. Women, Infants, Obstetrics, Gynecology, and Neonatology

Common in:

- academic centers and many community hospitals, but absent at some community hospitals
- high-risk maternal/neonatal programs concentrate at regional centers

Physical anchors:

- OB triage
- L&D rooms
- antepartum
- postpartum / mother-baby
- OB OR
- C-section rooms
- fetal testing
- lactation
- nursery
- special care nursery
- NICU
- MFM clinic
- gynecology OR/procedure rooms

Zephyrus mapping:

- `service_line_code`: `womens_health`, `neonatology`, `pediatrics`
- `program_code`: `routine_ob`, `mfm`, `regional_perinatal_center`, `nicu_level_ii`, `nicu_level_iii`, `nicu_level_iv`, `gyn_surgery`
- `prod.units`: L&D if bedded/operationally tracked, antepartum, postpartum, NICU, nursery, GYN
- `prod.beds`: maternal beds, bassinets, NICU beds
- `prod.rooms`: L&D rooms, OB OR, triage, NICU bays/rooms

Deployment questions:

- Does the hospital deliver babies?
- What are maternal and neonatal levels?
- Is NICU in the same facility as L&D?
- Are high-risk transfers inbound, outbound, or both?

### 11. Pediatrics

Common in:

- academic centers with children's hospitals
- community hospitals with pediatric ED, nursery, or limited pediatric inpatient
- many adult community hospitals stabilize and transfer high-acuity pediatrics

Physical anchors:

- pediatric ED zone
- pediatric inpatient unit
- PICU
- pediatric sedation/procedure area
- child-life spaces
- family rooms
- pediatric imaging accommodations

Zephyrus mapping:

- `service_line_code`: `pediatrics`
- `program_code`: `pediatric_ed`, `pediatric_inpatient`, `picu`, `pediatric_surgery`, `pediatric_subspecialty`
- `prod.units`: pediatric acute care, PICU
- `prod.rooms`: pediatric ED rooms, procedure rooms

Deployment questions:

- Is there a pediatric inpatient unit?
- Is there PICU?
- Is pediatric surgery performed on-site?
- Which pediatric cases transfer to an external children's hospital?

### 12. Behavioral Health, Addiction, and Crisis Services

Common in:

- academic and community hospitals, but often unevenly distributed
- dedicated psychiatric hospitals
- ED crisis and consult-liaison services even where inpatient psych is absent

Physical anchors:

- ED behavioral safe rooms
- behavioral crisis unit
- inpatient psychiatry
- geriatric psychiatry
- adolescent psychiatry where present
- addiction/detox unit
- outpatient behavioral health clinics
- security and ligature-resistant design features
- seclusion/restraint rooms where permitted

Zephyrus mapping:

- `service_line_code`: `behavioral_health`
- `program_code`: `ed_crisis`, `adult_inpatient_psych`, `geriatric_psych`, `adolescent_psych`, `detox`, `addiction_medicine`, `consult_liaison`
- `prod.units`: inpatient psych, detox if bedded
- `prod.rooms`: ED safe rooms, crisis unit rooms
- route graph: ED -> crisis -> inpatient psych/transfer

Deployment questions:

- Are behavioral health beds inside the general hospital or standalone?
- Are medical clearance and psychiatric placement tracked separately?
- Which sites accept involuntary holds?
- Are pediatric/adolescent behavioral cases transferred?

### 13. Rehabilitation, PM&R, and Post-Acute Interface

Common in:

- academic centers and regional hospitals
- standalone inpatient rehab facilities
- outpatient therapy networks

Physical anchors:

- acute inpatient rehab unit
- therapy gyms
- ADL apartment
- speech therapy
- outpatient PT/OT/ST clinics
- cardiac/pulmonary rehab
- prosthetics/orthotics

Zephyrus mapping:

- `service_line_code`: `rehabilitation`
- `program_code`: `acute_inpatient_rehab`, `outpatient_therapy`, `cardiac_rehab`, `pulmonary_rehab`, `neuro_rehab`, `trauma_rehab`
- `prod.units`: inpatient rehab
- `prod.rooms`: therapy rooms/gyms if operationally tracked
- IDN routing: acute -> AIR/SNF/LTACH/home health

Deployment questions:

- Is inpatient rehab on-campus, affiliated, or external?
- Are rehab beds part of hospital license or distinct provider?
- Does trauma/stroke discharge routing depend on rehab capacity?

### 14. Imaging, Interventional Radiology, and Diagnostics

Common in:

- all hospitals, but advanced modalities vary

Physical anchors:

- X-ray
- CT
- MRI
- ultrasound
- mammography
- fluoroscopy
- nuclear medicine
- PET/CT
- interventional radiology
- neurointerventional suite
- reading rooms
- contrast storage
- recovery bays

Zephyrus mapping:

- `service_line_code`: `imaging_diagnostics`, with program overlays from trauma, stroke, oncology, cardiovascular
- `prod.rooms`: modality rooms and IR suites when scheduling/capacity matters
- route graph: ED/trauma/stroke -> CT, inpatient -> imaging, OR -> imaging where hybrid

Deployment questions:

- Is CT available 24/7?
- Is MRI available 24/7 or business hours?
- Is interventional radiology diagnostic-only, body IR, neuro IR, or both?
- Which modalities are hospital-based versus outpatient imaging centers?

### 15. Laboratory, Pathology, Blood Bank, and Specimen Flow

Common in:

- all hospitals, directly or through contract

Physical anchors:

- core lab
- stat lab
- blood bank
- transfusion services
- pathology grossing
- histology
- microbiology
- specimen receiving
- phlebotomy
- pneumatic tube stations
- courier docks

Zephyrus mapping:

- `service_line_code`: `laboratory_pathology`
- `program_code`: `core_lab`, `blood_bank`, `transfusion`, `pathology`, `microbiology`, `point_of_care_testing`
- `space_category`: `support`, `utility`, `room`, `logistics`
- route graph: specimen source -> tube/courier -> lab -> result

Deployment questions:

- Is lab on-site, regionalized, or contracted?
- Is emergency lab available 24/7?
- Is blood bank massive-transfusion capable?
- Does the site support trauma, transplant, oncology, or OB hemorrhage?

### 16. Pharmacy and Medication Management

Common in:

- all hospitals

Physical anchors:

- central pharmacy
- IV clean room
- hazardous drug compounding
- investigational drug service
- OR pharmacy
- ED pharmacy
- ICU satellite pharmacy
- automated dispensing cabinets
- medication rooms
- outpatient/retail pharmacy
- mail-order pharmacy

Zephyrus mapping:

- `service_line_code`: `pharmacy_medication`
- `space_category`: `support`, `room`, `utility`
- route graph: pharmacy -> ADC/med room -> bedside/procedure room
- operations overlays: medication shortages, controlled substances, chemo, transplant meds, anesthesia carts

Deployment questions:

- Is sterile compounding local or centralized?
- Are oncology, transplant, investigational drug, and OR pharmacy capabilities present?
- Which locations have ADCs and emergency medication access?

### 17. Renal, Dialysis, and Apheresis

Common in:

- academic centers and community hospitals
- outpatient dialysis often external but operationally relevant

Physical anchors:

- inpatient dialysis room
- ICU bedside CRRT
- outpatient dialysis center
- water treatment room
- apheresis
- transplant nephrology clinic

Zephyrus mapping:

- `service_line_code`: `renal_dialysis`
- `program_code`: `inpatient_dialysis`, `crrt`, `apheresis`, `transplant_nephrology`, `outpatient_dialysis`
- `prod.rooms`: dialysis rooms/chairs if capacity-tracked
- `prod.units`: renal med/surg when distinct

Deployment questions:

- Is dialysis in-house, contracted, or bedside only?
- Can ICU provide CRRT?
- Is transplant nephrology part of the facility?

### 18. GI, Endoscopy, and Hepatology

Common in:

- academic centers
- community hospitals
- ambulatory surgery/endoscopy centers

Physical anchors:

- endoscopy rooms
- procedure prep
- recovery
- fluoroscopy if advanced endoscopy
- GI clinic
- hepatology/transplant clinic

Zephyrus mapping:

- `service_line_code`: `gastroenterology`
- `program_code`: `endoscopy`, `advanced_endoscopy`, `hepatology`, `gi_bleed`, `motility`
- `prod.locations`: endoscopy suite
- `prod.rooms`: endoscopy rooms and recovery bays

Deployment questions:

- Is GI bleed coverage 24/7?
- Are ERCP/EUS/advanced endoscopy available?
- Is endoscopy hospital-based, ASC-based, or mixed?

### 19. Pulmonary, Respiratory Therapy, Sleep, and ECMO

Common in:

- academic and tertiary hospitals; scaled-down pulmonary/RT functions in community hospitals

Physical anchors:

- ICU bedside respiratory care
- respiratory therapy workroom
- bronchoscopy suite
- PFT lab
- sleep lab
- pulmonary clinic
- ECMO locations where present

Zephyrus mapping:

- `service_line_code`: `pulmonary_respiratory`
- `program_code`: `respiratory_therapy`, `bronchoscopy`, `pft`, `sleep`, `ecmo`, `pulmonary_rehab`
- `prod.rooms`: bronchoscopy, sleep, PFT if operationally tracked
- `prod.beds`: ventilator, high-flow, negative-pressure, ECMO capable flags

Deployment questions:

- Are respiratory therapists 24/7 on-site?
- Does the site support vents, high-flow oxygen, bronchoscopy, or ECMO?
- Are pulmonary rehab and sleep outpatient services on-campus or distributed?

### 20. Transplant

Common in:

- quaternary academic and selected tertiary centers
- rarely present in standard community hospitals

Physical anchors:

- transplant OR
- ICU
- transplant inpatient unit
- transplant clinic
- HLA/tissue typing lab or reference lab path
- apheresis
- specialty pharmacy
- organ receiving logistics
- donor services interface

Zephyrus mapping:

- `service_line_code`: `transplant`
- `program_code`: organ-specific programs: `kidney`, `liver`, `pancreas`, `heart`, `lung`, `multi_organ`
- `capability_level`: definitive/quaternary only where active
- `prod.units`: transplant unit, ICU
- `prod.rooms`: transplant ORs when dedicated/priority
- route graph: organ receipt -> OR -> ICU -> transplant unit

Deployment questions:

- Which organs are performed at the facility?
- Is transplant inpatient care on a distinct unit?
- Which pharmacy, lab, blood bank, ICU, and OR dependencies are mandatory?

### 21. Burn

Common in:

- selected Level I/II trauma and quaternary centers
- absent from most community hospitals except stabilization/transfer

Physical anchors:

- burn resuscitation in ED
- burn ICU
- burn OR
- wound care rooms
- hydrotherapy if present
- therapy/rehab
- outpatient burn clinic

Zephyrus mapping:

- `service_line_code`: `burn`
- `program_code`: `burn_center`, `burn_icu`, `burn_outpatient`, `burn_stabilize_transfer`
- `prod.units`: burn ICU/unit
- `prod.rooms`: burn OR, wound rooms, ED burn rooms

Deployment questions:

- Is the facility ABA-verified?
- Does it support adult, pediatric, or both?
- Are burns stabilized and transferred, or treated definitively through rehab?

### 22. Primary, Ambulatory, Urgent, and Home-Based Care

Common in:

- every IDN; often the largest geographic footprint

Physical anchors:

- primary care offices
- specialty clinics
- urgent care
- retail clinics
- ambulatory surgery centers
- freestanding imaging
- infusion centers
- home health
- hospital-at-home command center
- telehealth

Zephyrus mapping:

- `service_line_code`: `primary_ambulatory`, `home_post_acute`
- `idn_role`: `ambulatory_campus`, `urgent_care`, `home_hospital`, `virtual_command_center`
- `space_category`: `room`, `support`, `virtual`
- operational targets: only map rooms/chairs when capacity, flow, or staffing must be managed

Deployment questions:

- Which ambulatory sites are hospital outpatient departments versus physician offices?
- Which ASCs are owned, joint-ventured, or affiliated?
- Does the IDN operate hospital-at-home or home health?
- What referrals flow from ambulatory to acute sites?

### 23. Logistics, EVS, Transport, Nutrition, Materials, Sterile Processing, Facilities

Common in:

- every hospital

Physical anchors:

- transport dispatch
- EVS closets
- bed-cleaning zones
- sterile processing
- loading dock
- materials management
- clean supply
- soiled holding
- waste rooms
- kitchen
- diet offices
- morgue/decedent care
- facilities shops
- emergency power
- HVAC/mechanical
- medical gas
- water
- command center

Zephyrus mapping:

- `service_line_code`: `logistics_support`
- `space_category`: `support`, `utility`, `corridor`, `vertical_transport`, `equipment`, `room`
- route graph: clean supply, soiled, waste, nutrition, specimen, transport, decedent, patient
- operational targets: `transport_requests`, `evs_requests`, future supply chain and SPD workflow tables

Deployment questions:

- Are clean, soiled, waste, food, patient, and public flows separated?
- Which elevators are public, bed, service, clean, soiled, trauma, or fire-service?
- Is SPD on-site or off-site?
- Does EVS capacity constrain patient flow?

## Physical Infrastructure Families

Each deployment should inventory physical spaces using these families. This is more stable than department names, which vary by hospital.

| Family                             | Space categories                             | Examples                                                              |
| ---------------------------------- | -------------------------------------------- | --------------------------------------------------------------------- |
| Arrival and access                 | exterior, entrance, dock, helipad, vestibule | ambulance bay, walk-in ED entrance, main lobby, loading dock, helipad |
| Public circulation                 | corridor, elevator, stair, waiting           | public elevators, visitor corridors, atrium                           |
| Staff/patient clinical circulation | corridor, elevator, transfer path            | bed elevators, restricted OR corridors, ICU-to-CT path                |
| Inpatient bedded care              | unit, room, bed                              | med/surg, telemetry, ICU, psych, rehab, L&D, NICU                     |
| Emergency and observation          | bay, room, unit                              | ED treatment, trauma, resus, fast track, CDU                          |
| Procedure platform                 | room, bay, support                           | OR, hybrid OR, cath, EP, IR, endoscopy, bronchoscopy                  |
| Diagnostic platform                | room, equipment                              | CT, MRI, X-ray, ultrasound, nuclear medicine, lab                     |
| Recovery and prep                  | bay, room                                    | pre-op, PACU, procedure recovery, infusion prep                       |
| Ambulatory care                    | clinic room, chair, support                  | exam room, infusion chair, urgent care room, ASC                      |
| Behavioral health                  | secure unit, safe room, crisis room          | inpatient psych, ED behavioral safe room, detox                       |
| Maternal/neonatal                  | LDR, OR, nursery, NICU                       | L&D, OB OR, postpartum, NICU                                          |
| Logistics and support              | utility, support, route                      | SPD, clean supply, soiled, waste, pharmacy, kitchen                   |
| Infrastructure                     | utility, equipment                           | mechanical, electrical, emergency power, med gas, water               |
| Digital and command                | command, equipment, network                  | command center, data closets, telemetry monitoring                    |
| Education and research             | classroom, lab, office                       | simulation center, wet lab, clinical research unit                    |

## Deployment Archetypes

### Archetype A: Quaternary Academic Level I Trauma Flagship

Examples:

- Penn Presbyterian Medical Center for Level I trauma within Penn Medicine.
- Hospital of the University of Pennsylvania for quaternary academic specialty care.
- Geisinger Medical Center in Danville as a rural quaternary hub with Level I trauma.
- Geisinger Wyoming Valley as a specialty regional Level I trauma hub in northeastern Pennsylvania.

Expected service lines:

- all core acute service lines
- Level I trauma
- advanced critical care
- comprehensive stroke or advanced stroke capability
- cardiac cath/EP, cardiac surgery, CVICU
- advanced perioperative platform
- transplant where the system offers it
- oncology, BMT/cellular therapy where present
- high-level maternal/neonatal/pediatric services where present
- behavioral health and addiction services
- research, GME, simulation, quality, PI

Deployment emphasis:

- route graph quality matters as much as bed inventory
- specialty capability tags must be precise
- activation pathways must be modeled: trauma, stroke, STEMI, sepsis, OB hemorrhage, massive transfusion, transplant, burn
- on-call coverage and room availability should become operating-state inputs
- transfer-in and regional command functions must be first-class

### Archetype B: Rural or Semi-Rural Regional Hub-and-Spoke IDN

Example:

- Geisinger, with central and northeastern Pennsylvania hospitals, Level I hubs at Danville and Wyoming Valley, Level II at Geisinger Community Medical Center, and Level IV/rural access facilities such as Muncy and Lewistown.

Expected pattern:

- flagship and regional hubs hold definitive trauma/specialty capabilities
- smaller hospitals provide ED, inpatient, imaging, lab, routine surgery, and stabilization
- helicopters and ground critical transport are operationally central
- outpatient clinics, urgent care, pharmacies, health-plan geography, and telehealth are large parts of the care network

Deployment emphasis:

- model catchment geography, not only campus floor plans
- represent each spoke as stabilize/routine/transfer rather than absent
- model helipad and ambulance routing at rural sites
- model transfer targets and expected time/distance
- support facility capability matrix by county/region

### Archetype C: Suburban/Community Academic Health System with Tertiary Specialty Hub

Example:

- Virtua Health in South Jersey: five hospitals, two satellite EDs, 40+ ambulatory surgery centers, and 400+ locations, with tertiary cardiovascular and transplant capabilities concentrated at Virtua Our Lady of Lourdes, high-volume maternity/NICU/perinatal services at Virtua Voorhees, and community/behavioral/surgical services at sites such as Virtua Willingboro.

Expected pattern:

- broad community access footprint
- selected tertiary programs at one or two hospitals
- OB and neonatal capabilities distributed selectively
- ASCs and outpatient centers are major procedural locations
- external Level I trauma transfer relationships may matter more than owned trauma designation

Deployment emphasis:

- do not assume owned Level I trauma just because the IDN is academic
- model external referral/transfer partners where definitive trauma or pediatrics sits outside the IDN
- distinguish regional perinatal, NICU, cardiac surgery, transplant, stroke, and behavioral-health capabilities by site
- include satellite EDs and ASCs as operational sites with different location roles

### Archetype D: Dense Multi-Hospital Academic IDN with Extensive Geography

Example:

- Penn Medicine, spanning Philadelphia academic campuses, regional hospitals, suburban hospitals, Princeton/NJ presence, Lancaster County, Bucks County/Doylestown, outpatient pavilions, home care, and specialty centers.

Expected pattern:

- multiple acute-care hospitals with different roles
- academic/quaternary facilities in dense urban campus geography
- Level I trauma at Penn Presbyterian and Lancaster General
- regional and suburban hospitals with advanced but variable services
- ambulatory specialty centers across state lines
- transport, referral, and home-care networks across Pennsylvania, New Jersey, and Delaware

Deployment emphasis:

- facility identity and geography must be explicit
- campus-level modeling is not enough; the IDN needs market and regional mapping
- service lines need system-level ownership and site-level capability rows
- interfacility transfer and specialty referral should be modeled as edge relationships
- state regulatory differences should be captured in source evidence metadata

### Archetype E: Community Hospital

Expected service lines:

- emergency
- adult med/surg
- hospital medicine
- ICU or stepdown
- general surgery/periop
- imaging
- lab
- pharmacy
- cardiology/telemetry
- orthopedics
- GI/endoscopy
- outpatient clinics
- OB/maternity if retained
- behavioral crisis, consult, or inpatient psych depending on facility

Deployment emphasis:

- many "service lines" are coverage patterns rather than dedicated units
- OR, ED, med/surg, ICU, and imaging are the core operational surfaces
- transfer-out relationships are essential for trauma, stroke, STEMI, NICU/PICU, transplant, burn, complex oncology, and neurosurgery

### Archetype F: Satellite ED, Freestanding ED, and Urgent Care Network

Expected service lines:

- emergency or urgent unscheduled care
- imaging
- point-of-care lab
- pharmacy/medication storage
- transfer coordination

Deployment emphasis:

- no inpatient bed assumptions
- map arrival, triage, treatment, observation, and transfer spaces
- route graph ends at transfer-out destination
- staff coverage, ambulance access, and imaging/lab availability define capability

### Archetype G: Ambulatory Surgery and Outpatient Specialty Campus

Expected service lines:

- perioperative/procedural
- orthopedics
- GI/endoscopy
- ophthalmology
- pain
- urology
- imaging
- infusion
- rehab
- clinic-based specialties

Deployment emphasis:

- model rooms, chairs, pre-op/PACU, turnover, sterile flow, and supplies
- no inpatient unit assumptions
- escalation/transfer relationship to acute hospital must be represented

### Archetype H: Behavioral Health, Rehab, LTACH, or Specialty Hospital

Expected service lines:

- one dominant specialty line plus general support services
- transfer links to acute care
- specialized environmental requirements

Deployment emphasis:

- physical environment and safety requirements dominate
- Zephyrus should map these as facilities with service-specific space roles, not as generic med/surg units

## Worked IDN Examples

These examples are not implementation inventories. They are deployment-pattern examples that show how the taxonomy should behave when a real health system has multiple campuses, variable specialties, and geography-driven referral flows.

### Geisinger

Evidence:

- Geisinger's public location list includes Geisinger Medical Center, Geisinger Community Medical Center, Geisinger Wyoming Valley Medical Center, Janet Weis Children's Hospital, Muncy, Bloomsburg, Lewistown, Shamokin, South Wilkes-Barre, Geisinger St. Luke's, Jersey Shore, Marworth, and cancer/behavioral locations. Source: <https://www.geisinger.org/patient-care/find-a-location>
- Geisinger's system map shows central, northeast, north-central, western, south-central, and southeast service areas and identifies inpatient facilities plus behavioral health and Marworth treatment locations. Source: <https://www.geisinger.org/-/media/onegeisinger/pdfs/ghs/about-geisinger/pdfs/geisinger-system-map---august-2020.pdf?sc_lang=en>
- Geisinger Medical Center is a Level I Adult Trauma Center and serves 42 counties, with tertiary/quaternary care, rural trauma support, and helicopter support. Source: <https://www.ptsf.org/trauma-center/geisinger-medical-danville/>
- Geisinger Medical Center's own page identifies comprehensive stroke, Level I trauma, pediatric emergency care, and Janet Weis Children's Hospital on campus. Source: <https://www.geisinger.org/patient-care/find-a-location/geisinger-medical-center>
- Geisinger Wyoming Valley Medical Center is listed as a Level I Adult Trauma Center and a specialty-care destination in Wilkes-Barre. Source: <https://www.ptsf.org/trauma-center/geisinger-wyoming-valley/>
- Geisinger Community Medical Center is a Level II Adult Trauma Center and has an electronic ICU, plus specialties including general surgery, cardiology, IR, GI, oncology, pulmonary, and orthopedics. Source: <https://www.ptsf.org/trauma-center/geisinger-community/>
- Geisinger Medical Center Muncy and Geisinger Lewistown are Level IV adult trauma centers, with ED/inpatient/outpatient services and stabilization roles. Sources: <https://www.ptsf.org/trauma-center/geisinger-medical-center-muncy-level-iv-adult-trauma-center/> and <https://www.ptsf.org/trauma-center/geisinger-lewistown-hospital-level-4-adult-trauma-center/>

Deployment interpretation:

| Facility role                                | Example sites                                   | Service-line implications                                                                                               |
| -------------------------------------------- | ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Rural quaternary flagship                    | Geisinger Medical Center, Danville              | definitive trauma, comprehensive stroke, pediatric specialty, critical care, major periop, tertiary/quaternary referral |
| Regional specialty hub                       | Geisinger Wyoming Valley                        | Level I trauma, specialty cardiac/orthopedic/cancer services, regional referral                                         |
| Urban/community tertiary support             | Geisinger Community Medical Center              | Level II trauma, eICU, broad specialties, transfer-in/out depending on case                                             |
| Rural access/stabilization                   | Muncy, Lewistown, other regional hospitals      | ED, routine inpatient/outpatient, imaging/lab, helipad, Level IV trauma stabilization                                   |
| Distributed outpatient/health plan geography | ConvenientCare, 65 Forward, pharmacies, clinics | ambulatory, urgent, pharmacy, telehealth, population-health links                                                       |

Zephyrus deployment rules:

- Build an IDN graph first: region -> facility -> campus -> building -> floor -> unit/room/bed.
- Set `idn_role` separately from service lines.
- Set `trauma_level_adult` per facility, not per system.
- Set `capability_level` per facility-service line. For example, `trauma_acute_care_surgery=definitive` at GMC/GWV, `advanced` at GCMC, `stabilize` at Muncy/Lewistown.
- Add transfer edges from Level IV sites to Level I/II hubs.
- Add helipad and critical-care transport nodes as first-class physical and routing objects.
- Keep outpatient, pharmacy, behavioral, and health-plan geography in the facility catalog even if inpatient RTDC does not use every site.

### Virtua Health

Evidence:

- Virtua describes itself as an academic health system in South Jersey with five hospitals, two satellite EDs, 40+ ambulatory surgery centers, and more than 400 other locations. Source: <https://www.virtua.org/About>
- Virtua location pages list Marlton, Mount Holly, Our Lady of Lourdes, Voorhees, Willingboro, and satellite EDs in Berlin and Camden. Source: <https://www.virtua.org/Locations>
- Virtua Our Lady of Lourdes is a 358-bed regional referral center, with cardiovascular care, kidney/liver/pancreas transplants, neurosurgery, stroke care, maternity, emergency care, and critical care. Source: <https://www.virtua.org/locations/our-lady-of-lourdes-hospital>
- Virtua Voorhees is a 408-bed hospital with high-volume deliveries, Level III NICU, regional perinatal center role, surgery, primary stroke, and pediatric care. Source: <https://www.virtua.org/locations/voorhees-hospital>
- Virtua Willingboro is a 169-bed hospital with 24/7 ED, seven surgical suites, behavioral health, wound care, dialysis, GI, orthopedics, vascular, colorectal, urologic, and general surgery. Source: <https://www.virtua.org/locations/willingboro-hospital>
- New Jersey's trauma center list identifies Cooper University Hospital in Camden as Level I, and does not list Virtua as an NJ Level I/II trauma center in that source. Source: <https://www.nj.gov/health/ems/documents/NJ%20Trauma%20Centers.pdf>
- A New Jersey acute-care services listing identifies selected capabilities such as Regional Perinatal Center, NICU, PICU, cardiac surgery, Level I/II trauma, and primary angioplasty; it lists Virtua Our Lady of Lourdes as RPC/NICU/cardiac/angioplasty and Virtua Voorhees as RPC/NICU/PICU. Source: <https://www.nj.gov/dobi/division_insurance/managedcare/genacutehospitals.pdf>

Deployment interpretation:

| Facility role                           | Example sites                                                           | Service-line implications                                                                        |
| --------------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Tertiary specialty hub                  | Virtua Our Lady of Lourdes                                              | cardiovascular, transplant, neurosurgery/stroke, ED, maternity, critical care                    |
| Women's/neonatal/pediatric regional hub | Virtua Voorhees                                                         | high-volume L&D, regional perinatal, Level III NICU, pediatric services, primary stroke, surgery |
| Community/specialty hospital            | Virtua Willingboro                                                      | ED, surgery, behavioral health, dialysis, wound care, GI/ortho/vascular/urology                  |
| Community hospitals                     | Marlton, Mount Holly                                                    | routine acute, ED, surgical, med/surg capabilities depending on site evidence                    |
| Distributed access network              | satellite EDs, urgent care, ASCs, clinics, Hospital at Home, home care  | ambulatory, unscheduled care, procedural, home-based care                                        |
| External trauma relationship            | Cooper University Hospital and other NJ trauma centers where applicable | transfer edge, not owned capability                                                              |

Zephyrus deployment rules:

- Do not infer `trauma_level_adult=Level I` from academic status. For Virtua, model trauma as ED stabilization plus transfer unless site-specific evidence proves a designation.
- Represent transplant as site-specific at Our Lady of Lourdes, not as a system-wide capability.
- Represent perinatal/NICU/PICU capability separately at Voorhees and Our Lady of Lourdes.
- Represent ASCs as procedural facilities with no inpatient bed assumptions.
- Represent satellite EDs as arrival/triage/treatment/transfer spaces without inpatient units.
- Use `external_transfer_target` relationships for services that leave Virtua's owned footprint.

### Penn Medicine

Evidence:

- Penn Medicine's location page describes hospitals and outpatient care locations throughout the region, with services including imaging/radiology, lab, mental/behavioral health, at-home care, pharmacy, and telehealth. Source: <https://www.pennmedicine.org/locations>
- Penn Medicine describes its specialty breadth across cancer, heart and vascular, neurology, orthopaedics, primary care, and women's health. Source: <https://www.pennmedicine.org/>
- The Hospital of the University of Pennsylvania page identifies the University City flagship and specialties including anesthesia, cancer, gynecology, heart valve, obstetrics/maternity, physical medicine/rehab, sleep, stroke, and women's health. Source: <https://www.pennmedicine.org/locations/hospital-of-the-university-of-pennsylvania>
- Penn Presbyterian's trauma center is a Level I Adult Trauma Center and a regional resource; the PTSF listing notes PennSTAR aeromedical and ground transport coverage across 11 counties in Pennsylvania, New Jersey, and Delaware. Source: <https://www.ptsf.org/trauma-center/trauma-center-at-penn-presbyterian/>
- Penn Medicine Lancaster General Health is a Level I Adult Trauma Center with dedicated trauma bays, OR/team, and trauma nursing unit. Source: <https://www.ptsf.org/trauma-center/lancaster-general/>
- Doylestown Health officially joined Penn Medicine in April 2025 and became Penn Medicine's seventh hospital; Doylestown Hospital is described as a 245-bed teaching hospital with advanced surgical procedures, comprehensive specialty care, outpatient, urgent/primary, rehab, specialty, and community services. Sources: <https://www.pennmedicine.org/news/doylestown-health-joins-university-of-pennsylvania-health-system> and <https://www.pennmedicine.org/locations/entity/doylestown-health>
- Penn's 2023 announcement about discontinuing active U.S. News participation explicitly notes that most care is now delivered outside hospitals, underscoring the need for ambulatory and home-care geography. Source: <https://www.pennmedicine.org/news/penn-medicine-stops-participation-in-us-news-and-world-report>

Deployment interpretation:

| Facility role                            | Example sites                                                                            | Service-line implications                                                                      |
| ---------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Urban quaternary academic campus         | HUP / University City                                                                    | cancer, heart/vascular, neuro, OB, stroke, specialty inpatient, research/GME                   |
| Urban Level I trauma hub                 | Penn Presbyterian                                                                        | Level I trauma, orthopedics, ophthalmology, critical care transport, regional trauma           |
| Historic/urban acute specialty campus    | Pennsylvania Hospital                                                                    | maternity, surgery, behavioral/women's and other services depending on implementation evidence |
| Regional Level I trauma hub              | Lancaster General                                                                        | Level I trauma, ED, dedicated trauma bays, OR/team, trauma nursing, regional acute care        |
| Suburban/community teaching hospital     | Chester County, Princeton, Doylestown                                                    | broad acute and specialty care, variable tertiary services, local access                       |
| Distributed ambulatory/specialty network | Perelman Center, Radnor, Cherry Hill, Woodbury Heights, Doylestown outpatient, home care | outpatient specialty, oncology, imaging, lab, pharmacy, telehealth, home care                  |

Zephyrus deployment rules:

- Model Penn as a multi-market IDN, not a single-campus academic center.
- Use `market` and `region` fields for Philadelphia, Lancaster, Bucks County, Chester County, Princeton/Central NJ, South Jersey sites, and home-care geography.
- Assign trauma program capability to PPMC and Lancaster General specifically.
- Keep HUP and PPMC separate physical campuses even when some external rankings or public narratives group them together.
- Represent PennSTAR/critical transport as a regional routing and transfer resource, not simply as a hospital department.
- Represent ambulatory and at-home care as operational geography; do not restrict deployment inventory to inpatient hospitals.

## Geography-Aware Deployment Inventory Template

Use this template for each IDN.

### Facility Inventory

| Field                    | Required    | Example                                      |
| ------------------------ | ----------- | -------------------------------------------- |
| `facility_key`           | yes         | `PENN_PPMC`                                  |
| `facility_name`          | yes         | `Penn Presbyterian Medical Center`           |
| `parent_system`          | yes         | `Penn Medicine`                              |
| `market`                 | yes         | `Philadelphia`                               |
| `state`                  | yes         | `PA`                                         |
| `county`                 | yes         | `Philadelphia`                               |
| `idn_role`               | yes         | `academic_tertiary_hub`                      |
| `campus_type`            | yes         | `urban_academic`                             |
| `teaching_status`        | recommended | `teaching`                                   |
| `licensed_beds`          | recommended | source-specific                              |
| `trauma_level_adult`     | recommended | `Level I`                                    |
| `trauma_level_pediatric` | recommended | `none`                                       |
| `stroke_level`           | recommended | `comprehensive`, `primary`, etc.             |
| `maternal_level`         | recommended | state/ACOG-derived                           |
| `neonatal_level`         | recommended | Level I-IV                                   |
| `burn_status`            | optional    | `verified`, `stabilize_transfer`, `none`     |
| `transplant_programs`    | optional    | `kidney,liver,pancreas`                      |
| `source_evidence`        | yes         | official URL, state list, accreditation page |

### Service-Line Capability Inventory

| Field                   | Required    | Example                                                    |
| ----------------------- | ----------- | ---------------------------------------------------------- |
| `facility_key`          | yes         | `VIRTUA_OLOL`                                              |
| `service_line_code`     | yes         | `transplant`                                               |
| `capability_level`      | yes         | `definitive`                                               |
| `program_codes`         | yes         | `kidney,liver,pancreas`                                    |
| `clinical_locations`    | yes         | `OR, ICU, transplant unit, clinic, pharmacy`               |
| `operational_locations` | yes         | `prod.locations: OR, ICU; prod.units: transplant unit`     |
| `hours`                 | recommended | `24/7 inpatient, clinic business hours`                    |
| `transfer_relationship` | recommended | `transfer_in regional, transfer_out rare`                  |
| `source_evidence`       | yes         | official service page                                      |
| `review_status`         | yes         | `source_verified`, `client_verified`, `assumed`, `unknown` |

### Physical Location Inventory

| Field                   | Required    | Example                                        |
| ----------------------- | ----------- | ---------------------------------------------- |
| `facility_space_id`     | generated   | `12345`                                        |
| `source_object_code`    | recommended | `ED-TRAUMA-01`                                 |
| `campus/building/floor` | yes         | `Main/PAC/L1`                                  |
| `space_category`        | yes         | `bay`                                          |
| `space_name`            | yes         | `Trauma Bay 1`                                 |
| `department_code`       | yes         | `ED`                                           |
| `service_line_codes`    | yes         | `emergency,trauma_acute_care_surgery`          |
| `location_role`         | yes         | `treatment`                                    |
| `capability_tags`       | recommended | `resus,trauma,negative_pressure,bed_stretcher` |
| `operational_map`       | recommended | `prod.rooms.room_id=...`                       |
| `route_node`            | recommended | `ops.nodes.node_id=...`                        |
| `flow_restrictions`     | recommended | `patient,staff,no_public`                      |
| `surge_role`            | recommended | `normal`, `surge_convertible`, `downtime_only` |

## Service-Line Presence by Hospital Type

| Service line         | Quaternary academic               | Level I trauma                              | Tertiary regional              | Community hospital              | Rural/critical access | Satellite ED/ASC/ambulatory |
| -------------------- | --------------------------------- | ------------------------------------------- | ------------------------------ | ------------------------------- | --------------------- | --------------------------- |
| Emergency            | yes                               | yes                                         | yes                            | usually                         | often                 | satellite ED/urgent only    |
| Trauma               | Level I/II common                 | definitive                                  | variable                       | stabilize/transfer or Level III | stabilize/transfer    | transfer                    |
| Critical care        | multi-ICU                         | required                                    | ICU/stepdown                   | ICU/mixed                       | limited/tele-ICU      | no                          |
| Med/surg             | yes                               | yes                                         | yes                            | yes                             | limited               | no                          |
| Cardiovascular       | advanced                          | support required                            | advanced/routine               | routine/PCI variable            | stabilize/routine     | clinic/ASC                  |
| Neurosciences/stroke | advanced                          | support required                            | primary/comprehensive variable | primary/telestroke              | telestroke/transfer   | clinic/imaging              |
| Perioperative        | advanced                          | emergency OR required                       | yes                            | yes                             | limited               | ASC/procedure               |
| Oncology             | advanced                          | variable                                    | yes                            | infusion/referral               | limited               | infusion/clinic             |
| Women/neonatal       | variable high-level               | variable                                    | regional/routine               | variable                        | often absent/limited  | clinic                      |
| Pediatrics           | children's hospital/PICU variable | pediatric trauma variable                   | variable                       | limited                         | stabilize/transfer    | clinic                      |
| Behavioral health    | yes/variable                      | ED crisis required operationally            | variable                       | variable                        | variable              | crisis/clinic               |
| Rehab/post-acute     | yes                               | required continuum                          | yes                            | variable                        | outpatient            | outpatient/home             |
| Imaging/lab/pharmacy | advanced                          | 24/7 support                                | yes                            | yes                             | basic/contracted      | limited                     |
| Transplant           | selected                          | not inherent                                | rare                           | no                              | no                    | clinic only                 |
| Burn                 | selected                          | not inherent                                | rare                           | transfer                        | transfer              | no                          |
| Research/GME         | yes                               | Level I requires education/research posture | variable                       | limited                         | no                    | no                          |

## Common Crosswalk to Billing and Cost Centers

Do not use billing codes as the primary service-line ontology, but do preserve mappings because finance, utilization, and cost-reporting users will expect them.

Examples:

- Room and board revenue categories can map to med/surg, OB, pediatric, psychiatric, oncology, rehabilitation, ICU, and nursery cost centers.
- Emergency room revenue categories map to ED and urgent unscheduled care spaces.
- Cardiology revenue categories can map to cath, stress, echo, and cardiology diagnostics.
- Ambulatory surgical care maps to ASCs and outpatient procedural platforms.
- Lab, radiology, pharmacy, therapy, respiratory, and supply categories map to ancillary service departments rather than single clinical service lines.

Recommended rule:

```text
service_line != department != revenue_code != cost_center != physical_space
```

Zephyrus should store crosswalks among them, not collapse them.

## Deployment Discovery Checklist

### Pre-Interview Document Request

Ask the client for:

- hospital and IDN facility list
- licensed bed roster by facility/unit
- trauma/stroke/STEMI/perinatal/NICU/burn/transplant designations
- department list and cost-center list
- service-line org chart
- campus maps/floor plans/CAD/BIM exports
- ED room and zone roster
- OR/procedural room roster
- imaging modality roster
- ICU/stepdown/telemetry/med-surg unit roster
- L&D/NICU/pediatric roster
- behavioral health and crisis location roster
- ambulatory, urgent care, ASC, and infusion center list
- home health/hospital-at-home/post-acute network
- transport modes and transfer agreements
- helicopter/ambulance landing and routing information
- sterile processing and supply-chain flow maps
- EVS, bed-turn, transport, and discharge workflows
- EHR location master
- ADT location hierarchy
- billing cost-center master
- scheduling location master
- RTLS location hierarchy if present
- nurse staffing units
- bed management units

### Service-Line Interview Questions

For every service line:

- Which facilities offer the service?
- Which facilities provide definitive care versus stabilization/transfer?
- Which physical spaces are used?
- Which rooms/beds/chairs are capacity constrained?
- What is 24/7 versus business-hours only?
- What external accreditation or designation applies?
- Which patients transfer in?
- Which patients transfer out?
- Which locations can flex in surge?
- Which locations close nights/weekends?
- Which operational systems hold the source of truth?

### Physical Walkthrough Questions

For every high-impact route:

- How does a patient arrive?
- Where are they triaged?
- Where are they diagnosed?
- Where are they treated?
- Where do they recover?
- Where do they board?
- Where are supplies staged?
- Which elevators/corridors are allowed?
- Which clean/soiled/waste routes are separated?
- Which route fails during downtime or construction?
- Which spaces are hidden from public maps but operationally critical?

## Implementation Recommendations for Zephyrus

### 1. Add a Service-Line Registry

Create a tracked registry, likely under `config/hospital/service-lines.php` or a database-backed `hosp_org.service_lines` table when the full ontology is promoted.

Minimum fields:

- code
- display name
- domain
- aliases
- HCUP grouping
- default Zephyrus workflow
- default physical roles
- optional accreditation fields

### 2. Add Facility-Service Capability Rows

Do not encode service availability only in `config/hospital/hospital-1.php`.

Add a matrix concept:

```text
facility_key + service_line_code + capability_level + evidence
```

This lets Virtua Our Lady of Lourdes have transplant, Virtua Voorhees have high-level perinatal/NICU, Penn Presbyterian have Level I trauma, Lancaster General have Level I trauma, and Geisinger Muncy have Level IV trauma stabilization without inventing false uniformity across the IDN.

### 3. Support Many-to-Many Service-Line Use of Spaces

The existing `hosp_space.facility_spaces.service_line_code` is useful for dominant ownership, but shared spaces need a bridge:

```text
hosp_space.facility_space_service_lines
- facility_space_service_line_id
- facility_space_id
- service_line_code
- program_code
- location_role
- primary_flag
- effective_start
- effective_end
- evidence jsonb
```

Examples:

- CT scanner: emergency, trauma, stroke, oncology, inpatient diagnostics.
- Hybrid OR: cardiovascular, vascular, trauma, transplant, neurosurgery.
- ICU bed: critical care plus trauma, neuro, cardiac, transplant depending on assignment.

### 4. Add Capability Tags to Beds and Rooms

Capability tags should not be buried in free text.

Suggested tags:

- `telemetry`
- `ventilator`
- `negative_pressure`
- `protective_environment`
- `bariatric`
- `lift`
- `medical_gas`
- `hemodialysis`
- `crrt`
- `behavioral_safe`
- `pediatric`
- `neonatal`
- `ob`
- `trauma_resus`
- `burn`
- `chemo`
- `transplant`
- `neuro_monitoring`
- `ecmo`
- `hybrid_or`
- `robotic`
- `fluoro`
- `mri`
- `ct`
- `stroke_priority`
- `stemi_priority`

### 5. Make Transfer Edges First-Class

For IDNs and regional deployments, interfacility transfers are as important as intrahospital routes.

Create or extend graph entities for:

- source facility
- destination facility
- service line
- transfer reason
- transport mode
- typical time
- acceptance constraints
- escalation contacts
- external partner flag

Examples:

- Virtua community/satellite ED -> Cooper Level I trauma for major trauma, if clinically routed that way by local agreements.
- Geisinger Level IV site -> Geisinger Medical Center or Geisinger Wyoming Valley for Level I trauma.
- Penn regional hospital -> HUP/PPMC/LGH depending on specialty.

### 6. Separate Designation Evidence from Marketing Evidence

Evidence classes:

- `state_designation`
- `accreditation_body`
- `official_health_system_page`
- `public_location_page`
- `client_roster`
- `EHR_location_master`
- `facility_map`
- `interview`
- `assumption`

Use official accreditation/state designation for trauma, stroke, perinatal, transplant, burn, and NICU whenever possible. Use health-system marketing pages to locate service lines, but require state/accreditation/client confirmation for regulated designations.

### 7. Keep Summit Regional as a Reference Model, Not a Universal Model

`config/hospital/hospital-1.php` is a useful 500-bed reference hospital, but real clients will differ:

- Virtua-like systems may have transplant and perinatal care but no owned Level I trauma.
- Geisinger-like systems may have rural Level I trauma hubs plus Level IV stabilization spokes.
- Penn-like systems may have multiple academic/regional hubs, cross-state outpatient geography, and service lines split across facilities.
- Community hospitals may have no OB, no ICU, no inpatient psych, or no cath lab.

The Zephyrus deployment plan should therefore generate a client-specific manifest from the capability matrix and facility-space import, not copy the Summit manifest.

## Acceptance Criteria for a Deployment-Ready Service-Line Map

A deployment is not ready until all of these are true:

- Every facility has an `idn_role`.
- Every service line has a `capability_level` per facility.
- Every regulated designation has source evidence.
- Every staffed inpatient bed maps to a facility space or is explicitly marked unmapped with a reason.
- Every ED, OR, ICU, imaging, cath/IR, L&D/NICU, behavioral, and observation space has a physical-location record.
- Every operationally important room/chair/bay maps to `prod.rooms` or an equivalent operational table.
- Every staffed unit maps to `prod.units`.
- Every operational bed maps to `prod.beds`.
- Shared spaces have many-to-many service-line mappings.
- Transfer-out and transfer-in relationships are represented for trauma, stroke, STEMI, OB/NICU, pediatrics, burn, transplant, and complex surgery.
- Internal route graph covers at least ED -> CT -> OR/IR/ICU, OR -> PACU/ICU/unit, L&D -> OB OR/NICU, inpatient -> imaging/procedure, discharge -> transport, clean/soiled/SPD routes.
- Client stakeholders have reviewed low-confidence mappings.

## Recommended Phased Plan

### Phase 0: Source Harvest

- Import facility list.
- Import service-line/cost-center roster.
- Import EHR location master.
- Import bed/unit roster.
- Collect accreditation/state designation sources.
- Collect maps/CAD/BIM/PDF floor plans.

### Phase 1: IDN Geography and Capability Matrix

- Build facility records.
- Assign `idn_role`.
- Build service-line registry.
- Build facility-service capability rows.
- Mark evidence source and confidence.

### Phase 2: Physical Space Import

- Load CAD/BIM/catalog/map sources into `hosp_ingest.blueprint_imports` and `hosp_ingest.blueprint_objects`.
- Promote reviewed objects into `hosp_space.facility_spaces`.
- Preserve source evidence and confidence.

### Phase 3: Operational Mapping

- Map facility spaces to `prod.locations`, `prod.rooms`, `prod.units`, and `prod.beds`.
- Add many-to-many service-line mapping for shared rooms and support areas.
- Add room/bed capability tags.

### Phase 4: Flow and Route Graph

- Build graph nodes/edges for patient, staff, clean, soiled, waste, supply, specimen, food, medication, decedent, and emergency transport flows.
- Add interfacility transfer edges.
- Validate high-acuity paths.

### Phase 5: Workflow Activation

- Activate RTDC for units/beds.
- Activate ED flow for ED spaces.
- Activate perioperative flow for OR/procedural rooms.
- Activate transport/EVS/logistics.
- Activate command-center metrics by facility, service line, and IDN geography.

### Phase 6: Client Review and Governance

- Review all low-confidence mappings.
- Validate designations.
- Validate surge spaces.
- Validate external transfer relationships.
- Freeze a deployment manifest.

## Bottom Line

The durable Zephyrus model is not "one hospital, one set of service lines." It is:

```text
IDN geography
  -> facility roles
  -> facility-specific service-line capabilities
  -> physical spaces
  -> operational mappings
  -> route and transfer graph
  -> workflow activation
```

That structure supports:

- Geisinger-style rural/regional hub-and-spoke care.
- Virtua-style suburban/community access with selected tertiary specialty hubs and external trauma relationships.
- Penn-style dense academic, regional, suburban, and cross-state geography.
- Single community hospitals.
- Satellite EDs and ASCs.
- Specialty hospitals, rehab, behavioral health, home care, and post-acute networks.

This should become the foundation for any Zephyrus hospital or IDN deployment plan.
