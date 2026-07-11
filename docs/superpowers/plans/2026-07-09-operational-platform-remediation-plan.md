# Operational Platform Remediation Plan

Date: 2026-07-09
Status: Reconciled 2026-07-10 - core release/security/wall/integrations/staffing/transport tranches shipped; bounded external and extended-roadmap gates remain
Repository: `/home/smudoshi/Github/Zephyrus`
Branch originally audited: `feat/hummingbird-4d-service-line-eddy`
Current verified production history: `main` through `12e1a12d686c85598bd5f6cfbd9bddad5b990a1a`
Production surface audited: `https://zephyrus.acumenus.net`
Scope owners: Staffing, Transport, Patient Flow, Navigation, Integrations, Data, Security, QA, Operations

## 1. Purpose

This plan addresses five related failures in the current Zephyrus application:

1. Staffing Office is not a credible hospital staffing operation and does not render its existing rows.
2. The top navigation attempts to expose the whole product at once and hides important destinations in an invisible horizontal overflow area.
3. Deployment is not an integration control plane, is not superuser-only, and currently depends on undeployed schema.
4. Patient Flow 4D has geometry and historical events but does not show current movements, patient trails, sourced barriers, or useful detail.
5. Transport has existing rows but the browser rejects them, and the underlying demo scenarios and resource model are not operationally plausible.

The user authorized local implementation after the audit. At plan opening, production data changes and deployment were separately gated by the migration, clone-rehearsal, and release checks below. Section 1.2 records which gates subsequently closed and which still remain.

### 1.1 Implementation checkpoint - 2026-07-09

Implemented locally in this tranche:

- Truthful Staffing and Transport contracts, loading/error/retry states, freshness/provenance, null coverage semantics, corrected completion/vendor metrics, and active/history filtering before pagination.
- A guarded, deterministic `summit-500-current-operations-v1` generator with 4,529 synthetic staff members, 25 units across all three shifts, 343 staffing plans, five intentional coverage gaps, 20 active transport requests, and 180 historical requests across 60 days.
- A populated Staffing Office workforce directory and plausible Transport worklists/resources/analytics derived from canonical rows rather than UI-only arrays.
- A section-based navigation shell with Cockpit, Workspaces, Study, capability-gated Integrations, an accessible mobile drawer, shared Transport navigation, and viewport coverage from 375 through 1920 px.
- A strict superuser-only `/integrations` control plane with 11 administrative views, connector templates, configuration CRUD, masked credential references, append-only configuration audit, SSRF policy, and truthful unconfigured/stale states. Fabricated FHIR discovery was removed and returns `501` until a real checker is implemented.
- Patient Flow historical replay instead of discard-to-empty behavior, actual data extent/freshness, recent events, markers/trails/occupancy, verified operational barrier projection, duration-risk separation, detail provenance, and a corrected superuser flow-lens authorization path.
- Hummingbird OpenAPI parity for the shipped demo-scenario and occupancy-history routes.

Local validation evidence:

- Focused Staffing, Transport, Patient Flow, Integrations, authorization, demo, deployment, and route-contract tests pass, including the superuser flow-lens regression.
- Frontend suite: 71 files and 308 tests pass; TypeScript `--noEmit` and the production asset build pass.
- Navigation browser suite: 20 Playwright tests pass, including seven target viewport widths.
- Authenticated browser smoke renders Staffing, Transport, Integrations, and Patient Flow without page errors or HTTP 5xx responses. Patient Flow returned 652 seeded events, loaded the latest 180-event replay, rendered 87 patient tracks, and produced nonblank desktop/mobile canvases.
- Full PHP suite on an isolated release database: 700 passed, 1 intentionally skipped, and 7,271 assertions. The initially exposed governance, mobile contract, clean-schema FK fixture, and parity assertion drift was corrected before this green run.

At this 2026-07-09 checkpoint, production remained gated on the targeted July migration chain, the two new integration-audit migrations, a production-clone rehearsal, facility/source allowlists, asynchronous integration workers, and authenticated production smoke. No production deployment had been performed at that checkpoint. This paragraph is retained as historical evidence; it is superseded by the verified 2026-07-10 state below.

### 1.2 Production reconciliation - 2026-07-10

The core remediation sequence is merged through normal pull requests, reachable from `main`, exact-head CI green, and deployed. The authoritative per-tranche evidence and remaining gates are maintained in `docs/superpowers/plans/2026-07-10-operational-platform-closure-checklist.md`.

| Tranche | Pull request and merge | Verified production result |
| --- | --- | --- |
| Release-history reconciliation | [PR #14](https://github.com/sudoshi/Zephyrus/pull/14), merge `23cb99eff90b2f6af9428cf8f6359defe9316c3e` | The fourteen deployed feature commits remain intact and reachable from `main`; CI/deployment now originate from normal release history. |
| Patient Flow authorization/ingress | [PR #15](https://github.com/sudoshi/Zephyrus/pull/15), merge `2905664a058e7ba15ff9c6d941e67c090f562c09` | Flow-lens scope/redaction, authorized FHIR construction, and exact-ability machine HL7 ingress are deployed; the retired browser route is absent. |
| Patient Flow wall lockdown | [PR #16](https://github.com/sudoshi/Zephyrus/pull/16), merge `356b5004d856eac0cb67c1b05cb07f23a309c935` | Wall mode is chromeless/render-only while desk-mode drill, lens, patient, inbox, and Eddy interactions remain available. |
| Integrations operational runtime | [PR #17](https://github.com/sudoshi/Zephyrus/pull/17), merge `3b41fe241359d3041bb1ab12ab3af5a294b54f0c` | Database queues, supervised workers, protocol health, bounded replay/dead-letter controls, Epic FHIR R4/SMART discovery, and governed HL7 ADT machinery are deployed. |
| Canonical staffing fulfillment | [PR #18](https://github.com/sudoshi/Zephyrus/pull/18), merge `9f2795f80edc5e222d0be7f53287aefca281a139` | Qualifications, availability windows, shift assignments, governed fulfillment, web/Hummingbird parity, and the materialization schedule are deployed. |
| Governed transport lifecycle | [PR #19](https://github.com/sudoshi/Zephyrus/pull/19), merge `12e1a12d686c85598bd5f6cfbd9bddad5b990a1a` | Server transition rules, immutable idempotency receipts/events/evidence, resource capacity, handoff enforcement, web/mobile parity, and cursor pagination are deployed. |
| Immutable release source | [PR #25](https://github.com/sudoshi/Zephyrus/pull/25), merge `1d3b4d5020080350127ce6357641faf60c4f9789` | The canonical deploy path archives, builds, and publishes the exact `origin/main` commit from an isolated release tree, revalidates the remote before rsync, and records the deployed SHA. |

All seven implementation pull requests passed the same five required checks: two Laravel/PHPUnit jobs, Node/Vite, Vitest/Vite, and Arena pytest. Final live evidence includes:

- July foundation migrations `2026_07_04_000110` through `000170` are recorded; 23 active inpatient units and 500 active beds are mapped, and 1,150 occupancy snapshots span 23 spaces through 2026-07-10 19:00 EDT.
- Integration migration `000200` is production batch 23. The database worker is enabled/active, scheduled health/poll dispatchers are registered, 117 protocol-health jobs completed, and the queue had zero pending/failed jobs at reconciliation. Epic discovery is healthy against three active `fhir.epic.com` endpoints; the synthetic HL7 file source is truthfully degraded because no machine-ingress identity is configured. Credentialed Epic polling remains activation-gated and therefore has no clinical watermark.
- Staffing migration `000300` is production batch 25. The runtime contains 4,529 canonical members/assignments, 4,517 verified qualifications, and 124,275 future materializer windows, with zero overfilled requests and zero overlapping active shift assignments.
- Transport migration `000400` is production batch 26. The runtime contains 202 requests, 10 resources, 20 active assignments, and 144 explicitly grandfathered terminal records; every runbook capacity, assignment, handoff, idempotency, version, and escalation invariant returns zero violations.
- Apache and `zephyrus-queue-worker.service` are active, the public login boundary is intact, and deploy-eligible tracked production files match merged `main` byte-for-byte.
- Release-source hardening merged through PR #25 and was exercised by its first `main` deployment. Local `HEAD`, `origin/main`, and the production `.release-commit` marker matched merge `1d3b4d5020080350127ce6357641faf60c4f9789`; Apache, the queue worker, and Arena were active, and the public authentication boundary remained intact.

The remaining gates are deliberately narrower than the original production gate:

1. Supply approved Epic non-production backend credentials outside Git, complete a live token exchange and bounded Encounter/Location poll, and advance a clinical watermark.
2. Complete interface governance for the first production HL7 v2 ADT sender, issue a bounded machine token, and prove one test ADT through raw, canonical, Patient Flow, and provenance records.
3. Exercise credential rotation, failure/dead-letter recovery, staleness, worker restart, and rollback after real Epic/HL7 activation.
4. Continue the explicitly unchecked extended-roadmap items in Sections 9 and 13; shipped core behavior must not be relabeled incomplete because optional later depth remains open.

## 2. Executive Verdict

The reported blank and implausible screens are not caused by one missing seeder. They are the combined result of contract breakage, stale date-relative data, disconnected domain models, pending migrations, incomplete authorization, and UI behavior that converts failures into reassuring empty states.

| Area | What exists | What the user sees | Primary cause |
| --- | --- | --- | --- |
| Staffing Office | 72 plans and 2 requests in production | Infinite `Loading staffing posture...` | JSON map fields are emitted as arrays and rejected by Zod; all plans are five days stale |
| Transport | 22 requests and 87 events in production | Empty/clear dispatch queue | The same JSON object/array mismatch rejects the whole result; the page treats rejected data as `[]` |
| Patient Flow 4D | 3,779 events, 934 patients, 2,663 movements, 1,220 locations | Geometry and heat, but 0 replay events/tokens/trails and no meaningful barriers | The newest event is four days old and React discards the entire replay outside its fixed 48-hour window |
| Deployment | IDN/readiness UI, staffing wizard code, integration schemas | Incomplete panels and failing API calls | July 4 deployment/staffing schema chain was not migrated; the surface is not an integration console |
| Integrations | Strong raw/canonical schema foundation and a synthetic connector | Catalog entries that appear ready without live endpoints | Only the synthetic connector is implemented; GET requests seed status; health is configuration state, not observed health |
| Navigation | One config with 68 unique destinations | Important sections are clipped offscreen | Every domain is rendered inline and overflow is handled by hidden horizontal scrolling |

The remediation order is therefore:

1. Stop false loading, false empty, false healthy, and false 100 percent states.
2. Repair schema and source-of-truth boundaries.
3. Generate realistic, current, explicitly synthetic data from those boundaries.
4. Rebuild the navigation shell around sections and workspaces.
5. Complete Integrations as a secured operational control plane.
6. Deploy through targeted migrations, backfills, browser proof, and runtime monitoring.

## 3. Audit Evidence

This section is the immutable 2026-07-09 pre-release audit snapshot. Statements such as pending migrations, synchronous queues, zero endpoints/watermarks, and stale rows explain the original decisions; they are not claims about current production. Use Section 1.2 and the closure checklist for the verified 2026-07-10 state.

### 3.1 Repository and deployment position

- The checkout already had an unrelated user modification in `docs/ZEPHYRUS-2.0-PLAN.md`; this plan does not alter it.
- The canonical application deploy path is `./deploy.sh`.
- `deploy.sh` does not run Laravel migrations.
- Production intentionally left `2026_07_04_000110` through `2026_07_04_000170` pending.
- Production also has older baseline migrations reported pending, so a blanket `php artisan migrate --force` is not safe.
- The Patient Flow snapshot detail migration was applied separately, but the facility/unit mapping backfill was not.
- The scheduler and host cron are running, but there is no integration polling/replay/reconciliation schedule and no demo roll-forward schedule.
- Production reports `QUEUE_CONNECTION=sync`; real connector polling and backfills require an asynchronous supervised queue.

### 3.2 Live production facts captured on 2026-07-09

Staffing:

- `prod.staffing_plans`: 72 rows, all dated 2026-07-04.
- `prod.staffing_requests`: 2 rows, both dated 2026-07-04.
- The API calculates zero required staff, zero available staff, zero at-risk units, and 100 percent coverage for 2026-07-09.
- The browser receives HTTP 200 from `/api/staffing/overview` but never leaves the loading state.
- `hosp_ref.staff_roles`, `hosp_org.staff_members`, `hosp_org.staff_assignments`, and `hosp_org.staffing_sources` do not exist in production because their migration chain is pending.

Transport:

- `prod.transport_requests`: 22 rows.
- `prod.transport_events`: 87 rows.
- All requests were generated on 2026-07-04.
- Eight requests remain active and are more than 7,000 minutes overdue.
- The API returns all 22 rows, while Dispatch displays 0 in queue, 0 unassigned, 0 at risk, and 0 assigned.
- Completed-today is zero because the demo has not rolled forward and the service uses the wrong date field.

Patient Flow:

- `flow_core.flow_events`: 3,779 rows.
- Unique patients/encounters: 934.
- Movement events: 2,663.
- Event time range: 2026-07-02 00:36 UTC through 2026-07-05 00:24 UTC.
- `/api/patient-flow/occupancy` reconstructs 484 active patients and labels all 484 delayed from stale duration alone.
- Actual transport delays: 0.
- Actual EVS delays: 0.
- Actual `prod.barriers`: 0.
- Forward projections: 0.
- `flow_core.occupancy_snapshots`: 0 rows.
- All 25 active `prod.units.facility_space_id` values are null.
- `facility:link-operational --dry-run` reports that 23 inpatient units and 500 beds can be linked.
- The live browser reports 934 patients / 3,779 events in the header, but `0 Events` in the active window and `No replay events inside the 48h window`.

Integrations:

- `integration.sources`: one synthetic sandbox source.
- Source endpoints: 0.
- Credential references: 0.
- Connector watermarks: 0.
- FHIR resource versions: 0.
- FHIR client connections: 0.
- SMART backend credentials: 0.
- Writeback drafts: 0.
- The latest synthetic source activity is stale, but the source can still be reported active from its configuration flag.
- A GET-driven catalog can mark a sandbox interface engine and Epic, Oracle Health, and MEDITECH playbooks ready without configured endpoints.

Navigation:

- `resources/js/config/navigationConfig.ts` defines five sections, ten dropdown domains, and 68 unique destinations.
- At 1440 px, the navigation scroll container is 837 px wide but its content is 1,604 px wide.
- At that viewport, Staffing is partially clipped and Patient Flow, Analytics, Improvement, and Deployment are fully hidden.
- The scrollbar is hidden and there is no overflow button or drawer.
- At mobile width, even Cockpit can be clipped.
- The current mobile E2E test calls the layout mobile-friendly when only `<main>` is visible.

## 4. Standards and Design Constraints

The target is a credible operations demo and a safe production architecture, not an assertion that one fixed staffing ratio applies to every hospital.

- [42 CFR 482.23](https://www.ecfr.gov/current/title-42/chapter-IV/subchapter-G/part-482/subpart-C/section-482.23) requires organized 24-hour nursing services, adequate personnel, current licensure, and assignments based on patient need plus staff qualification and competence.
- FHIR R4 [PractitionerRole](https://www.hl7.org/fhir/R4/PractitionerRole.html) provides the interoperable link among practitioner, organization, role, specialty, location, service, and availability. It is an exchange model, not a complete internal scheduling engine.
- FHIR R4 `ServiceRequest` and `Task` are appropriate external representations for requested and executed transport work; the internal state ledger remains `prod.transport_events`.
- [AHRQ intrahospital transport guidance](https://psnet.ahrq.gov/web-mm/check-twice-transport-once) emphasizes standardized workflows, identity and destination verification, equipment checks, risk-sensitive staffing, and sending/receiving handoff.
- [AHRQ patient flow guidance](https://www.ahrq.gov/sites/default/files/publications/files/ptflowguide.pdf) treats flow as a hospital-wide operating problem rather than an ED-only display.
- [SMART App Launch 2.2](https://hl7.org/fhir/smart-app-launch/) defines capability discovery, backend-service authorization, private-key JWT as the preferred client authentication pattern, and scoped access.
- The existing `docs/superpowers/plans/2026-06-25-real-time-healthcare-data-acquisition.md` remains the transactional-system coverage source. This plan turns its architecture into an administrable and observable product surface.

Hard constraints:

- Do not store connector secrets in ordinary application columns. Store external secret references and masked metadata only.
- Do not run generic demo seeders against a live tenant.
- Do not delete real rows while refreshing synthetic scenarios.
- Do not report missing data as healthy, 100 percent, clear, or live.
- Do not allow frontend-only role checks to define access.
- Do not route machine ingress through browser session authentication.
- Do not add a third staffing roster.
- Do not replace the existing `integration.*`, `raw.*`, `fhir.*`, or canonical event foundations.
- Maintain Zephyrus/Hummingbird persona, redaction, and contract parity for Patient Flow changes.

## 5. Root Cause Analysis

### 5.1 Staffing Office

#### Rendering failure

`app/Services/Staffing/StaffingOperationsService.php` serializes empty `resolution_payload` and `metadata` values as PHP arrays. JSON encodes them as `[]`. `resources/js/features/staffing/api.ts` requires JSON objects through `z.record(...)`, so the response is rejected even though the HTTP request succeeds.

`resources/js/Pages/Staffing/StaffingOffice.tsx` only distinguishes loading/data. When React Query reaches an error state with no data, the page continues to render `Loading staffing posture...` forever.

#### Freshness and coverage failure

- `todaysPlans()` filters strictly to the current date.
- When there are no current plans, the coverage denominator is zero and the service returns 100 percent.
- The two old requests are still displayed as open and thousands of minutes overdue.
- There is no `fresh`, `stale`, or `missing` source state.
- There is no scheduled demo roll-forward.

#### Domain model failure

There are two disconnected staffing systems:

1. Operational Staffing Office uses `prod.staffing_plans`, `prod.staffing_requests`, and `prod.staffing_events`, with seven legacy role codes.
2. Staffing Alignment uses `hosp_ref.staff_roles`, `hosp_org.staff_members`, `hosp_org.staff_assignments`, source imports, mapping rules, and review queues.

The legacy role codes do not match the canonical roles. Examples:

| Legacy | Canonical |
| --- | --- |
| `rn` | `staff_nurse` |
| `charge` | `charge_nurse` |
| `respiratory` | `respiratory_therapist` |
| `unit_secretary` | `unit_clerk` |
| `provider` | specialty-specific physician/APN role |

The alignment model is the correct identity and assignment foundation. Staffing Office must project operational coverage from it instead of owning a parallel anonymous headcount universe.

#### Demo model failure

- The seeder creates day-shift RN, tech, and charge rows only.
- ED is excluded and perioperative areas are treated like wards.
- ICU requirements are fixed rather than derived from beds, census, acuity, qualifications, and local rules.
- Staffing sources and available pools are hard-coded counts.
- Requests do not resolve to actual qualified, available staff.
- The current seeder deletes all current-day staffing plans, not only rows it owns.

### 5.2 Navigation

The configuration is no longer the main problem. `navigationConfig.ts` is the current navbar and command-palette source of truth. The old `DashboardContext` mirror has been removed, and repository instructions that still require updating it are stale.

The rendering model is the problem:

- Every domain is an inline top-bar control.
- Right-side persona and utility controls consume fixed width.
- Overflow uses a horizontally scrollable area with a hidden scrollbar.
- Analytics alone has 23 leaves across seven unwrapped mega-menu columns.
- Patient Flow is duplicated as an RTDC item and a separate top-level domain.
- Transport repeats ten destinations in a local tab strip.
- Secondary route lists in UserMenu, Transport, Analytics, and MobilePersonaCatalog can drift from the main config.
- `/rtdc/patient-flow-navigator` can activate both RTDC and Patient Flow.
- Mobile navigation has no usable drawer or overflow affordance.

### 5.3 Deployment and Integrations

The existing Deployment section mixes unrelated responsibilities:

- IDN geography and facility readiness.
- Capability matrices and transfer relationships.
- Staffing alignment and source import.
- A separate Transport Integrations page.
- Backend integration health endpoints with no primary UI.

Renaming the label alone would make the information architecture more misleading. The current facility/readiness and staffing alignment surfaces must be relocated deliberately while Integrations receives its own control-plane model.

Authorization is also wrong for the requested boundary:

- `viewDeploymentConsole` allows `super-admin`, `admin`, `superuser`, and `ops-leader`.
- `manageDeploymentConfig` allows `super-admin`, `superuser`, and `ops-leader`.
- `/transport/settings/integrations` is available to any authenticated user.
- Navigation visibility uses a mix of scalar roles and a coarse `is_admin` flag.
- The web route, API, navbar, and command palette do not share one strict integration capability.

Implementation gaps:

- Only `SyntheticHealthcareConnector` implements `HealthcareConnector`.
- FHIR discovery fabricates a local CapabilityStatement instead of calling `.well-known/smart-configuration` and `/metadata`.
- GET summary calls can create catalog rows and mark them ready.
- The health service reflects configured active state, not observed freshness or delivery behavior.
- The browser-session HL7 endpoint bypasses the raw/canonical ledger and lacks machine authentication and ACK/NACK semantics.
- Outbound integration ends at draft creation; transmit, acknowledgement, retry, and reconciliation do not exist.

### 5.4 Patient Flow 4D

The Three.js facility model is loading. The primary failure is the time and source contract:

- The viewer fixes its window to page-load time minus/plus 24 hours.
- It fetches the stored events without a bounded time query.
- If the newest event is older than the window start, it replaces the entire event set with `[]`.
- The status text says demo barriers loaded even though production demo barriers are disabled and the `/events` payload contains no demo scenario.
- Patient tokens, trails, movement replay, service filters, and the initial feed all depend on the discarded event array.

Additional failures:

- The occupancy service can reconstruct old active encounters at current time, so all 484 are labeled delayed by the generic 18-hour duration timer.
- Duration risk is not the same thing as a verified operational barrier.
- `top_barriers` excludes the stay timer, while no real RTDC barriers exist.
- The demo barrier scenario exists only inside `/occupancy`; it does not populate `/events`, trails, the feed, or SSE.
- SSE is a finite replay of stored rows, not a live connector stream.
- The repository applies ascending order before limit, so a large store can return the oldest rows rather than the latest bounded window.
- Snapshot scheduling runs, but snapshot persistence skips every unit because `facility_space_id` is null.
- Occupancy disks can be occluded by the default stacked floor geometry.
- Barrier details are below the scroll fold in a fixed toolbar.
- Mobile CSS hides metrics, occupancy rollups, and the feed.
- Patient-level routes enforce only whether dots are allowed; unit/task scope redaction needs stricter server enforcement.

### 5.5 Transport

#### Rendering failure

`TransportOperationsService::serializeRequest()` emits empty `handoff` and `metadata` maps as `[]`. The frontend requires objects. Zod rejects the list, `WorklistPage` converts absent data to an empty array, and the UI reports a clear queue.

#### Domain and analytics failures

- Resources and vendors are hard-coded arrays, not current capacity.
- Active requests lack encounter links, canonical location IDs, segments, risk flags, equipment requirements, handoff content, and external correlation IDs.
- Generic scenario generation produces invalid stories, such as EMS requests between internal units and interfacility transfers between units in the same hospital.
- UI actions expose only a subset of the declared lifecycle.
- Event names written by ordinary status updates do not consistently match analytics event names.
- Vendor share classifies the internal `Summit Patient Transport` team as a vendor because its name contains `transport`.
- Completed-today is based on request date rather than completion event/time.
- The API mixes active work and terminal history in one first-50 response, and the frontend ignores pagination.
- Resource availability cannot reconcile on-duty staff, assignments, equipment use, outages, or vendor acceptance.

## 6. Target Product and Architecture

### 6.1 Shared truth contract

Every operational response must include a common source envelope:

```json
{
  "source": {
    "mode": "live|synthetic|seeded|derived|fallback",
    "system": "source-key",
    "scenario_id": "nullable",
    "generated_at": "ISO-8601",
    "last_event_at": "ISO-8601 or null",
    "expected_cadence_seconds": 60,
    "freshness": "fresh|stale|missing|degraded",
    "stale_after_seconds": 300,
    "lineage": []
  }
}
```

Rules:

- `missing` never becomes 100 percent, zero risk, clear, or healthy.
- `stale` data may remain visible for historical review but may not be labeled live.
- Synthetic data is visibly labeled at page and row level.
- A parser/contract failure produces an explicit error state with retry and correlation ID.
- Domain metrics use `value: null` plus reason when they cannot be computed.

### 6.2 Canonical workforce model

Reuse the pending staffing alignment foundation:

- `hosp_ref.staff_roles` for role definitions.
- `hosp_org.staff_members` for workforce identities.
- `hosp_org.staff_assignments` for effective organization/facility/unit/service-line assignments.
- `hosp_org.staffing_sources` and import runs for source provenance.
- `prod.workforce_actuals` for time-and-attendance facts.

Add operational tables through additive migrations:

| Table | Purpose | Natural/idempotency key |
| --- | --- | --- |
| `hosp_ref.staff_competencies` | Canonical skills/competencies | competency code |
| `hosp_org.staff_credentials` | Licensure, certification, privilege, expiry | member + credential type + issuer + identifier hash |
| `hosp_org.staff_member_competencies` | Effective-dated skill link and verification | member + competency + effective start |
| `prod.staffing_requirements` | Required coverage by facility/unit/role/shift/scenario | facility + unit + role + shift start + requirement version |
| `prod.staff_shift_assignments` | Scheduled person-to-slot assignment | member + shift start + unit + role |
| `prod.staff_availability_events` | Callout, leave, on-call, available, unavailable | member + effective time + event UUID |
| `prod.staffing_pools` | Float, internal resource, on-call, agency, traveler pools | facility + pool key |
| `prod.staffing_pool_memberships` | Member eligibility and home/float rules | pool + member + effective start |
| `prod.staffing_offers` | Gap coverage offer | request + member + offer UUID |
| `prod.staffing_offer_responses` | Accept/decline/expire audit | offer + response UUID |
| `prod.staffing_request_fulfillments` | Filled gap tied to person/shift | request + member + shift assignment |

Compatibility:

- Add an explicit legacy-to-canonical role map.
- Migrate service logic to canonical roles before removing legacy constraints.
- Keep compatibility reads during the transition.
- Never auto-escalate an application authorization role from a workforce import.

Requirement calculation inputs:

- Facility and unit.
- Operational date, timezone, day type, and shift.
- Staffed and occupied beds.
- Census forecast.
- Acuity/workload mix.
- Admissions, transfers, discharges, observation load, and procedures.
- Required charge/supervisory coverage.
- Skill and credential constraints.
- Isolation, sitter, 1:1, telemetry, ventilator, and specialty needs.
- Locally approved coverage rules and exceptions.
- Current scheduled, clocked-in, called-out, reassigned, and available staff.

Do not encode a nationwide clinical staffing ratio in application code. Store versioned, approved facility rules with effective dates and audit history.

### 6.3 Workforce role taxonomy

Expand the current taxonomy without turning each specialty skill into a separate authorization role.

Required categories:

- Physician and medical staff: hospitalist, intensivist, emergency physician, surgeons, anesthesiologist, residents/fellows, procedural specialists.
- Advanced practice: nurse practitioner, physician assistant, CRNA, certified nurse midwife, clinical nurse specialist.
- Nursing leadership: CNO/designee, nursing director, house supervisor, nurse manager, clinical coordinator, charge nurse.
- Nursing care: staff RN, LPN/LVN, nursing assistant/PCT, monitor technician, sitter, unit clerk/HUC.
- Perioperative: circulating RN, scrub role, surgical technologist, PACU RN, anesthesia technician, sterile processing technician.
- Emergency/critical care: ED RN, trauma RN, critical care RN, rapid response/code team competencies.
- Allied health: respiratory therapy, PT, OT, speech therapy, dietitian, pharmacy, social work, case management, care coordination.
- Diagnostics: phlebotomy, laboratory technologist, pathology, radiology technologist, CT/MRI/ultrasound, nuclear medicine, cardiology diagnostics.
- Ancillary/logistics: patient transport, EVS, food/nutrition, materials management, supply chain, courier, equipment distribution.
- Facility operations: facilities/engineering, biomedical engineering, security, emergency management, telecommunications.
- Operational leadership: bed manager, transfer center, staffing coordinator, capacity lead, medical/nursing director, administrator.

Specialty and competency belong in linked competency data, not in an ever-expanding application RBAC list.

### 6.4 Canonical transport model

Keep `prod.transport_requests` and append-only `prod.transport_events`. Add:

| Table/change | Purpose |
| --- | --- |
| `origin_facility_space_id` / `destination_facility_space_id` | Canonical locations instead of labels only |
| `source_id`, `external_id`, `idempotency_key` | Integration lineage and duplicate protection |
| `requested_skill_codes` | Required transporter/clinical escort competencies |
| `required_equipment_codes` | Wheelchair, stretcher, oxygen, monitor, ventilator, bariatric, isolation equipment |
| `transport_precautions` | Fall, isolation, elopement, behavioral, code status, line/tube considerations |
| `prod.transport_resources` | Staff/team/vehicle/equipment/vendor resource registry |
| `prod.transport_resource_shifts` | On-duty and capacity windows |
| `prod.transport_assignments` | Request-to-resource assignment history |
| `prod.transport_delay_events` | Structured delay reason, owner, start/end, avoidability |
| `prod.transport_checklists` | Pre-move, pickup, destination, and handoff verification |
| `prod.transport_vendor_events` | Tender, accept/decline, ETA, cancel, webhook correlation |

One transition graph must be shared by web, mobile, services, analytics, seeders, and integrations:

```text
requested
  -> accepted/queued
  -> assigned
  -> dispatched
  -> arrived_pickup
  -> patient_ready | patient_not_ready
  -> picked_up
  -> en_route
  -> arrived_destination
  -> handoff_started
  -> handoff_complete
  -> completed

any active state -> escalated | canceled | failed
```

The transition service must enforce allowed transitions, optimistic concurrency, idempotency, actor, source, timestamps, and structured reason.

### 6.5 Navigation information architecture

Desktop top utility bar:

- Zephyrus brand/home.
- Current workspace/title and breadcrumb.
- Facility/scope selector.
- Source freshness indicator.
- Global command search.
- Persona selector where applicable.
- Alerts, theme, and user menu.

Primary navigation:

- Render section-level controls only: `Cockpit`, `Workspaces`, `Study`, and superuser-only `Integrations`.
- `Workspaces` contains RTDC, Emergency, Perioperative, Transport, and Staffing.
- Patient Flow 4D remains under `RTDC > Operations`, immediately after Bed Tracking.
- Remove the separate Patient Flow top-level trigger.
- `Study` contains Analytics and Improvement.
- Integrations is not in Transport and is not visible to non-superusers.
- User management, cockpit thresholds, enterprise setup, and other general administration live under `User menu > Administration`, not as another always-visible top-bar domain.
- Organization/facility readiness moves to `Admin > Enterprise Setup`.
- Staffing Alignment moves to `Staffing > Administration` for authorized staffing admins, with integration source setup linked to Integrations.

Responsive behavior:

- Desktop: section-level menu plus optional collapsible workspace rail.
- Tablet/mobile: menu button opens a Headless UI drawer with nested disclosures.
- No hidden horizontal navigation scrolling.
- Local workspace tabs are generated from the same exported domain groups or protected by parity tests.
- Use a `More` menu when a local tab set exceeds the available width.
- Command search remains the complete page/command escape hatch.

### 6.6 Patient Flow target contract

Replace independent page bootstrap calls with a coherent scene/bootstrap response or a coordinated query layer that returns:

- Source/freshness metadata.
- Actual replay window and suggested initial time.
- Locations and geometry version.
- Latest bounded events in the selected scope.
- Current patient states after server-side scope/redaction.
- Occupancy details and history.
- Projections.
- Verified operational barriers.
- Named demo scenario metadata when applicable.
- Cursor for new events.

Viewer behavior:

- Preserve stale events as history; do not discard them.
- If data is stale, center the chronobar on actual data and show `Historical - last event ...`.
- If a named demo is selected, use the same scenario across events, occupancy, feed, trails, barriers, projections, and Eddy context.
- A live mode requires an actual cursor/event feed and observed source freshness.
- Duration risk and verified barrier are separate concepts.
- Add floor isolation/explode controls, marker auto-frame, legend, barrier queue, row-to-marker focus, and accessible mobile bottom sheet.
- Persist hourly snapshots only after operational units are linked to facility spaces.

### 6.7 Integrations control plane

New route: `/integrations`.

Superuser-only local navigation:

1. Overview.
2. Source Systems.
3. FHIR R4 / SMART.
4. HL7 v2 Interfaces.
5. Transactional Applications.
6. Mappings and Terminology.
7. Runs and Watermarks.
8. Dead Letters and Replay.
9. Outbound / Writeback.
10. Credentials and Certificates.
11. Audit.

Reuse from Zephyrus:

- `integration.sources`, capabilities, endpoints, credential references.
- `raw.ingest_runs`, `raw.inbound_messages`, `raw.dead_letters`.
- `integration.canonical_events`, projection offsets/errors, replay jobs.
- `fhir.resource_versions`, links, identity, terminology, provenance.
- `integration.fhir_client_connections`, SMART credentials, interface engines, connector playbooks, writeback drafts.
- RTDC canonical event/projector boundary.

Reuse conceptually from Parthenon:

- Dense admin overview with health summaries and drilldowns.
- Real FHIR connection CRUD and connection tests.
- Separate sync/run dashboard.
- Tiered health and sanitized operational logs.
- Masked secret serialization and tests that raw keys never leave the server.

Do not copy from Parthenon:

- Its OMOP/research-specific FHIR projection pipeline.
- Private-key storage in application tables.
- PACS model serialization that can expose encrypted credential fields.
- Monolithic protocol-specific health controllers.
- Jobs that sleep/poll inside a worker for hours.

Connector status is derived, not manually optimistic:

```text
template -> unconfigured -> testing -> healthy
                               |          |
                               v          v
                             failed    degraded -> stale

any configured state -> disabled
```

Observed health dimensions:

- Last successful connection and message.
- Expected cadence and next expected event.
- Throughput and latency percentiles.
- Error and ACK/NACK rates.
- Queue depth and oldest backlog age.
- Watermark/cursor position.
- Projection lag and reconciliation drift.
- Certificate/token expiry.
- Dead-letter count and oldest age.
- Source contract, BAA, PHI permission, owner, and go-live stage.

## 7. Transactional Integration Coverage

Implement connector families in this order, using the detailed coverage map in the existing acquisition plan.

| Priority | Family | Initial protocols/resources | Zephyrus value |
| --- | --- | --- | --- |
| 1 | Enterprise EHR/registration | HL7 v2 ADT; FHIR R4 Patient, Encounter, Location, Organization | Identity, census, movements, merges |
| 1 | Bed/flow system | ADT, vendor events, FHIR Encounter/Location/Task/ServiceRequest | Bed state, placement, barriers |
| 1 | Workforce | Vendor API/files, FHIR Practitioner/PractitionerRole/Schedule, HL7 MFN | Current staffing and safe capacity |
| 1 | Internal transport/EVS | REST/webhook, Task/ServiceRequest, canonical events | Current work, delays, handoff |
| 2 | Orders/results | HL7 ORM/OML/ORU; FHIR ServiceRequest, Observation, DiagnosticReport | Ancillary delays and readiness |
| 2 | Perioperative | SIU, ORM/OML, ORU, FHIR Appointment/Schedule/Procedure | OR status and downstream pressure |
| 2 | Pharmacy/MAR | RDE/RDS/RAS, FHIR Medication* | Medication/discharge barriers |
| 2 | Imaging/PACS | ORM/ORU, DICOMweb QIDO/WADO/STOW/UPS | Imaging wait and result milestones |
| 3 | EMS/NEMT/care transitions | Vendor REST/webhooks, ADT, Task, Communication, documents | External transfer and discharge flow |
| 3 | Facilities/RTLS/nurse call | REST/webhook, MQTT/gateway, Device/Task/Location | Bed/equipment/resource readiness |
| 3 | ERP/supply chain | Vendor API, SupplyRequest/SupplyDelivery, GS1 | Constraint and shortage signals |
| 3 | Payer/prior authorization | X12 270/271/276/277/278/835/837, Da Vinci APIs | Avoidable days and authorization barriers |
| 4 | HIE/documents/public health | C-CDA, Direct, FHIR, TEFCA/HIE interfaces | External context and transition evidence |

FHIR R4/SMART implementation requirements:

- Real `.well-known/smart-configuration` discovery.
- Real `/metadata` CapabilityStatement test.
- SMART Backend Services private-key JWT.
- Scope verification and least privilege.
- Pagination, rate-limit handling, retry/backoff, and OperationOutcome normalization.
- Conditional requests and version/watermark storage.
- Bulk Data as an asynchronous state machine, not an in-process polling loop.
- HAPI/vendor sandbox contract tests before production activation.

HL7 v2 implementation requirements:

- Terminate MLLP in a supported interface engine or gateway.
- Use a maintained HL7 parser/profile validator.
- Authenticate the gateway with mTLS or scoped service credentials.
- Persist immutable raw messages before normalization.
- Idempotency by source/sending facility/message control ID.
- AA/AE/AR acknowledgement tracking.
- Sequence/gap detection and replay.
- Start with ADT, then ORM/OML/ORU/SIU/RDE/RAS/DFT/MDM/MFN according to source inventory.

## 8. Demo Data Specification

### 8.1 Safety boundary

Create a dedicated command, provisionally `zephyrus:demo-roll-forward`, guarded by all of the following:

- `DEMO_DATA_ENABLED=true`.
- Explicit synthetic tenant/facility allowlist.
- Refuse if a target source is not marked synthetic.
- Refuse destructive behavior unless every target row carries the matching `scenario_id` and `data_origin=synthetic`.
- `--dry-run` count and date report.
- Idempotency key per facility + scenario + anchor time.
- Audit event and validation report.

Do not schedule `db:seed` in production.

The roll-forward workflow should orchestrate:

1. Validate migrations and required tables.
2. Import/update the facility catalog.
3. Link operational units and beds to facility spaces.
4. Rebase only the synthetic Patient Flow source.
5. Generate current staffing roster, requirements, shifts, callouts, gaps, offers, and fulfillments.
6. Generate current and historical transport requests/resources/events.
7. Build 24-48 hours of occupancy snapshots.
8. Refresh cockpit/materialized projections.
9. Run the demo validation command.
10. Emit source/freshness metadata.

Schedule only for the demo environment, in the facility timezone, before the daily rehearsal window. Real tenants use connector freshness, not data rebasing.

### 8.2 Facility baseline

The Summit demo baseline is:

- 23 inpatient units.
- 500 staffed inpatient beds.
- Five ICU units with 96 staffed beds.
- Two step-down units with 64 staffed beds.
- Sixteen med/surg units with 340 staffed beds.
- One ED operational area with 148 treatment/flow spaces.
- One perioperative operational area with 44 rooms/bays/positions.
- Target inpatient occupancy around 82-88 percent, with explicit surge scenarios.

The UI must distinguish inpatient beds from ED/perioperative positions so totals do not appear to contradict the 500-bed identity.

### 8.3 Workforce generator

Generate staffing from required coverage hours rather than an arbitrary employee count.

For each role/unit/shift requirement:

```text
annual coverage hours = required people per shift * shift hours * covered days
base FTE = annual coverage hours / productive hours per FTE
roster FTE = base FTE * configurable relief factor
```

The relief factor accounts for leave, education, orientation, vacancy, and nonproductive time and must be a configurable demo assumption, not a clinical rule.

Required demo characteristics:

- All three shifts for a 28-day window.
- Weekday/weekend and day/night differences.
- Full-time, part-time, per-diem, float, traveler/agency, on-call, and inactive records.
- Home unit plus eligible float units.
- Effective credentials and competency coverage.
- A small number of credential expirations and unavailable/leave records for admin workflows.
- Current scheduled, clocked-in, called-out, reassigned, and open shifts.
- Intentional but explainable gaps.
- Every filled operational slot resolves to a qualified and available staff member.
- Only selected synthetic workers receive application accounts/personas.
- No imported workforce record can grant superuser access.

Required staffing scenarios:

| Scenario | Expected visible behavior |
| --- | --- |
| MICU callout | Critical RN gap, float search, qualification filter, escalation |
| Med/surg evening surge | Census-driven gap with overtime offers and partial fill |
| ED boarding surge | ED nurse/PCT/transport demand rises with boarding |
| Behavioral health observation | Sitter/security need without misclassifying bedside RN coverage |
| OR/PACU hold | PACU RN/transport/bed constraints compound |
| Respiratory surge | RT competency shortage affects ICU capacity |
| Weekend therapy/case management | Discharge barriers reflect reduced ancillary coverage |
| Agency fallback | Internal pools exhausted before external assignment |

### 8.4 Transport generator

Generate 60-90 days of history plus a current operational workload.

Current workload target:

- Enough requests to exercise every board without making the demo unreadable.
- Requests distributed across valid active states.
- Mix of routine, urgent, and stat.
- Internal inpatient diagnostic/procedural moves.
- Bed-to-bed moves.
- Discharge lobby/ride/NEMT flows.
- True interfacility transfers with external facility endpoints.
- EMS inbound flows with receiving readiness.
- Care-transition/referral handoffs.
- Specimen/equipment courier work only if represented as a separate non-patient task type.

Every synthetic request must include:

- Canonical origin and destination.
- Encounter/source correlation appropriate to request type.
- Requested-by role.
- Required mode, skills, equipment, and precautions.
- Valid timestamps and lifecycle sequence.
- Patient-ready/not-ready state where applicable.
- Structured delay reason and owner when delayed.
- Assignment to an on-duty resource or explicit unassigned state.
- Pickup and destination verification.
- Structured handoff for completed patient moves.
- `scenario_id`, `data_origin`, and source lineage.

Resource model:

- Multiple internal transport teams across three shifts.
- Dedicated critical-care transport capability.
- Wheelchair, stretcher, bed, bariatric, oxygen, monitor, and isolation equipment pools.
- Known equipment outages/unavailability.
- Contracted vendors with capabilities, service areas, hours, acceptance rates, and current capacity.
- Vendor tender/accept/decline/cancel events.
- Availability equals on-duty capacity minus assigned/busy/unavailable resources.

Data invariants:

- EMS origin or destination is external/ED appropriate.
- Interfacility transfer endpoints are different facilities.
- Internal unit moves are not labeled interfacility transfer.
- A request cannot complete before it is requested.
- Terminal requests are not at-risk solely because their historical needed time is past.
- Internal teams never contribute to vendor-share metrics.
- Completed-today uses completion time/event.

### 8.5 Patient Flow scenarios

Use named, coherent scenarios across all contracts:

- ED boarder to inpatient bed.
- ICU downgrade blocked by step-down capacity.
- OR/PACU hold.
- EVS backlog delaying bed release.
- Weekend staffing gap reducing safe capacity.
- Post-acute authorization/discharge gridlock.
- Transport delay to diagnostic/procedural destination.

Each scenario must produce:

- Recent events and movement trails.
- Current patient states.
- Occupancy and history.
- Multiple barrier types with owner, reason, age, SLA, source, confidence, and blocking relationship.
- Forward projections.
- Persona-lensed Eddy context.
- Web and Hummingbird parity evidence.

## 9. Phased Implementation TODO

Checkboxes in this section were re-audited on 2026-07-10 against merged code, exact-head CI, production migrations, runtime counts, and service health. Checked items are shipped for the scope stated; unchecked items remain real backlog and are not prerequisites retroactively applied to the narrower shipped core.

### Phase 0 - Truthful rendering and contract repair

Goal: Existing production rows render, and failure/staleness cannot masquerade as loading, clear, healthy, or 100 percent.

- [x] Create a shared backend JSON-map serialization helper that emits `{}` for empty maps, not `[]`.
- [x] Apply it to Staffing `resolution_payload` and `metadata`.
- [x] Apply it to Transport `handoff` and `metadata`.
- [ ] Audit other Zod `z.record` contracts for the same PHP empty-array behavior.
- [x] Preserve strict frontend schemas; normalize legacy `[]` only in a documented compatibility adapter if required during rollout.
- [x] Add Staffing `isError` UI with retry, plain-language message, and correlation ID.
- [x] Add Transport `isError` UI; never substitute `[]` after a failed query.
- [x] Add source/freshness envelopes to Staffing, Transport, Patient Flow, and Integrations.
- [x] Return null/unknown coverage when no current staffing denominator exists.
- [x] Mark old staffing requests stale/expired in demo mode rather than carrying them indefinitely.
- [x] Correct Transport `completed_today` to use completion time/events.
- [x] Correct internal-team versus vendor classification using resource type/ID, never name matching.
- [x] Align normal Transport transition event names with analytics vocabulary.
- [x] Add browser-visible degraded states.

Primary files:

- `app/Services/Staffing/StaffingOperationsService.php`
- `resources/js/features/staffing/api.ts`
- `resources/js/Pages/Staffing/StaffingOffice.tsx`
- `app/Services/Transport/TransportOperationsService.php`
- `resources/js/features/transport/api.ts`
- `resources/js/Pages/Transport/WorklistPage.tsx`
- `resources/js/Pages/Transport/Dashboard.tsx`
- shared source/freshness DTO/types

Exit gate:

- Production API fixtures parse in JavaScript tests.
- Staffing shows a truthful stale/missing state rather than infinite loading.
- Transport shows the 22 existing rows or a truthful stale state rather than a clear queue.
- Contract errors are visible and covered by Playwright.

### Phase 1 - Migration and canonical data-spine readiness

Goal: Deploy the already-built service-line/location/staffing alignment foundation safely and link operational units to facility geometry.

- [x] Capture logical backup and migration ledger snapshot for schema-bearing production release work.
- [ ] Restore production backup into an isolated clone.
- [ ] Run the explicit July migration chain `000110` through `000170` on the clone.
- [x] Validate current FK/data assumptions and migration idempotency through migration suites plus live invariant checks.
- [x] Resolve the production migration ledger through the reviewed additive chain without destructive baseline rollback.
- [x] Apply the reviewed July paths in production; `000110` through `000170` are recorded in batches 14-20.
- [x] Run the staff-role registry seed idempotently.
- [x] Validate role/category counts and regulated-role flags; production currently has 87 canonical staff roles.
- [x] Run `facility:link-operational --dry-run` and archive/reconcile output; the post-backfill dry run reports zero remaining links.
- [x] Run the approved operational link backfill.
- [x] Run `facility:export-plates --check`; 23 active inpatient units and the intended 500 inpatient beds are mapped, while 44 separately modeled OR/PACU staffed beds remain explicitly reported outside that plate mapping.
- [x] Run the occupancy snapshot backfill and retain hourly capture.
- [x] Verify nonzero `flow_core.occupancy_snapshots`; production has 1,150 snapshots across 23 spaces at reconciliation.
- [ ] Add migration/backfill readiness to the deployment runbook.

Exit gate:

- July migration chain is marked ran.
- Staffing alignment tables exist and APIs no longer fail from missing relations.
- 23 inpatient units and 500 beds are mapped to facility spaces.
- Snapshot history is nonempty and hourly capture continues.

### Phase 2 - Canonical Staffing Office and 500-bed workforce demo

Goal: One workforce identity/assignment model drives current staffing operations.

- [x] Add effective-dated qualification requirements/member qualifications, availability, shift assignment, offer/accept/fill/release fulfillment, and immutable command/event migrations. Broader credential/pool depth remains a later slice.
- [x] Add legacy-to-canonical role resolution and compatibility tests for the operational fulfillment path.
- [ ] Refactor Staffing Office reads to canonical member/assignment/shift data.
- [x] Preserve `prod.staffing_events` for legacy operational transitions and add append-only canonical fulfillment command/event ledgers rather than rewriting history.
- [ ] Replace `max(scheduled, actual)` with explicit scheduled/clocked/available semantics.
- [x] Add facility-timezone operational shift resolution with DST/materialization reconciliation tests.
- [ ] Add versioned facility staffing rules and approval history.
- [ ] Add census/acuity/workload inputs and reason codes to requirements.
- [x] Replace fulfillment eligibility's hard-coded resource counts with named canonical people, verified qualifications, covering availability, leave/conflict exclusion, and reserved headcount.
- [x] Link requests, offers, acceptance/fill/release transitions, canonical assignments, and fulfillments through one locked/idempotent service.
- [ ] Add unit/role/shift filters, roster drilldown, qualification badges, callouts, offer status, and source freshness.
- [ ] Add current, next-shift, and 24-hour forecast views.
- [x] Build deterministic synthetic roster/28-day schedule generator.
- [x] Ensure synthetic generator touches only scenario-owned rows.
- [ ] Add FHIR R4 Practitioner/PractitionerRole/Schedule export/import mapping tests.
- [x] Preserve the boundary between workforce role and application authorization.

Exit gate:

- Every filled staffing slot resolves to an active, qualified, available member.
- Every open gap resolves to a requirement and can produce governed offers.
- All three shifts and every operational unit have current data.
- UI exposes source, freshness, rule version, and reason for each requirement.
- Seed-twice count/hash validation is stable.

### Phase 3 - Transport operations and realistic demo

Goal: Transport boards show coherent current work, resource capacity, safety requirements, and audited lifecycle data.

- [ ] Add canonical location, source, skill, equipment, and precaution fields.
- [x] Add canonical resource, assignment, handoff-evidence, and idempotent-command tables. Resource-shift, dedicated delay/checklist, and vendor-tender event depth remain future work.
- [x] Implement the shared server-enforced transition graph across web and Hummingbird.
- [x] Add lifecycle versioning, row/advisory locking, mandatory idempotency keys, and immutable original-response replay.
- [x] Replace literal resource/vendor availability with configured, synchronized database-backed resources and capacity accounting.
- [x] Implement server-side filters and deterministic nullable-deadline cursor pagination for web/mobile queues.
- [x] Make React, Android, and iOS consume pagination, filters, allowed transitions, ownership, claimability, and lifecycle version.
- [ ] Separate Dispatch, Active/In Transit, Handoff, History, and Exceptions views.
- [x] Implement assignment, dispatch, pickup/readiness, movement, arrival, escalation/recovery, handoff, completion, cancellation, and failure actions with server validation.
- [ ] Add pre-transport risk/equipment checklist and destination verification.
- [x] Add structured receiver role, accepted/accepted-with-risks evidence, risk detail, actor, and timing gates before required completion.
- [x] Derive transport analytics from canonical pickup/destination lifecycle events with explicit legacy fallbacks; advanced vendor-tender analytics remain tied to the unchecked vendor-event slice.
- [x] Build deterministic current plus 60-90 day generator.
- [x] Remove or demo-gate `Create sample request` in ordinary production mode.
- [x] Add source/freshness and synthetic labels to every Transport surface.

Exit gate:

- Every Transport tab has plausible current and historical rows.
- Resource availability reconciles to on-duty minus busy/unavailable.
- Lifecycle and analytics derive from the same events.
- Internal team/vendor metrics are correct.
- Safety/equipment/handoff fields are present for required scenarios.

### Phase 4 - Patient Flow current-time, barriers, and visual proof

Goal: The 4D viewer visibly explains current movement, occupancy, barriers, and forecasts.

- [x] Bound patient-event queries by validated windows/limits, return latest rows correctly, and apply the effective lens before serialization.
- [x] Preserve stale data as historical; remove the discard-to-empty branch.
- [x] Add suggested initial time and actual data extent to the scene contract.
- [ ] Add new-event cursor API/SSE or Reverb channel with latest-first/cursor semantics.
- [x] Never label finite replay as live.
- [ ] Unify named demo scenarios across events, occupancy, projections, feed, trails, snapshots, and Eddy.
- [ ] Project verified barriers from RTDC, bed placement, discharge, transport, EVS, staffing, and ancillary dependencies.
- [x] Separate long-stay/duration risk from operational barriers.
- [ ] Replace the universal duration threshold with service/encounter-specific expectations and source lineage.
- [x] Enforce house/unit/task scope, task expiry, opaque patient context tokens, and identity redaction server-side across JSON, FHIR, and SSE.
- [x] Initialize the feed from recent events.
- [ ] Add floor isolate/explode, auto-frame, legend, barrier queue, and row-to-marker focus.
- [ ] Reduce slab occlusion or provide an occupancy-first rendering mode.
- [ ] Replace fixed-toolbar hidden details with responsive panels/bottom sheet.
- [x] Fetch and render persisted occupancy history; production has 1,150 snapshots across 23 mapped spaces at reconciliation.
- [x] Preserve Hummingbird web/mobile contract, persona scope, and redaction parity for the shipped routes.
- [x] Make wall mode chromeless/render-only without mounting drill, patient, lens, inbox, alert-engagement, or Eddy interactions; preserve desk-mode behavior.

Exit gate:

- Initial load shows nonzero current events, patient tokens, trails, movement, occupied spaces, history, and sourced barriers.
- A stale source shows historical mode and its actual last event.
- Selected barrier updates visual focus, details, owner, SLA, and Eddy context.
- Desktop and mobile screenshots plus canvas-pixel checks prove nonblank, visible markers.

### Phase 5 - Navigation shell replacement

Goal: All destinations remain discoverable without rendering every domain in the top bar.

- [x] Refactor navigation config to expose section/domain/local-nav projections from one source.
- [x] Render only Cockpit, Workspaces, Study, and authorized Integrations at top level.
- [x] Remove separate top-level Patient Flow domain while retaining contextual shortcuts.
- [x] Implement desktop workspace menu/rail.
- [x] Implement accessible mobile drawer with focus trap, escape, outside click, and nested disclosures.
- [ ] Add current workspace breadcrumb and facility/source context.
- [ ] Generate Transport and Analytics local navigation from shared config or enforce parity tests.
- [x] Remove dead `/rtdc/barriers` persona link.
- [x] Replace role-string visibility with server capability props.
- [x] Add exactly-one-active-owner tests for every configured URL.
- [ ] Add route existence and authorization parity tests for every nav leaf.
- [x] Add viewport tests at 375, 390, 768, 1024, 1280, 1440, and 1920 px.
- [x] Verify no horizontal nav scrolling and no clipped controls.
- [x] Update AGENTS.md navigation guidance to remove the deleted DashboardContext requirement.

Exit gate:

- Every authorized page is reachable in at most three navigation actions or command search.
- No top-level navigation is clipped or horizontally hidden.
- Mobile drawer provides full keyboard and touch navigation.
- One route has one active navigation owner.

### Phase 6 - Integrations route, RBAC, and control plane

Goal: Replace Deployment with a superuser-only integration administration and monitoring product.

- [x] Define one strict `manageIntegrations`/`viewIntegrations` capability from canonical server authorization.
- [x] Allow only `superuser` and explicitly mapped `super-admin`; exclude plain `admin` and `ops-leader`.
- [ ] Audit/promote intended production superusers before enabling the route.
- [x] Share `auth.can.view_integrations` and `auth.can.manage_integrations` from Laravel.
- [x] Gate Inertia routes, APIs, command palette, navigation, commands, and replay actions with the same policy.
- [x] Add `/integrations` route and section shell.
- [x] Remove Integrations from Transport local navigation.
- [x] Redirect legacy `/transport/settings/integrations` after authorization.
- [x] Relocate Deployment facility/readiness to Admin > Enterprise Setup.
- [x] Relocate Staffing Alignment to Staffing > Administration.
- [x] Remove write-on-GET catalog seeding.
- [x] Create explicit connector-template seeder with `template` status.
- [x] Add source/endpoint/capability/credential-reference CRUD with audit.
- [x] Add protocol-aware FHIR R4/SMART and HL7 v2 health checking; configuration presence is no longer treated as observed health.
- [x] Add protocol health, runs/watermarks, dead-letter review, bounded audited replay, mappings, credential-reference, and audit controls. Outbound transmission and live credential-rotation exercises remain Phase 7 work.
- [x] Add SSRF controls for operator-entered URLs: TLS, allowlist, DNS/IP validation, redirect limits, loopback/link-local/metadata blocking.
- [x] Add secret-safe serializers and leak tests.

Exit gate:

- Guest, frontline, admin, and ops-leader receive 403 and cannot see the route in navigation/palette.
- Approved superuser receives 200 and every mutation is audited.
- No template is displayed as ready/healthy.
- Stale synthetic source is reported stale.
- No secret or PHI payload is present in API/log/error responses.

### Phase 7 - Real connectors and governed outbound lifecycle

Goal: Make health and status reflect real integration behavior.

- [x] Configure a dedicated supervised database worker for `integrations,default` with bounded timeout, retry/backoff, memory, and deploy-time active verification.
- [x] Add schedule entries for protocol probes and eligible FHIR polling.
- [ ] Add the remaining reconciliation, replay, retention, and credential-alert schedules as their connector families become active.
- [x] Implement the Epic FHIR R4/SMART Backend Services client, live public-sandbox discovery, RS384 assertion flow, versioned persistence/provenance/watermarks, and sandbox/integration tests. Credentialed polling remains externally gated.
- [ ] Implement FHIR Bulk Data state-machine jobs.
- [x] Implement exact-ability machine-authenticated HL7 ingress through immutable raw message -> canonical event -> Patient Flow projector -> provenance.
- [x] Implement the governed ADT path and source-scoped idempotency first.
- [ ] Implement the required order/result/schedule/workforce HL7 message families after the first production ADT source is activated.
- [ ] Add workforce connector family for API/file/FHIR/MFN sources.
- [ ] Add Transport/EVS webhook connector family.
- [ ] Add remaining transactional families by the coverage matrix.
- [ ] Complete outbound draft -> approve -> transmit -> acknowledge -> reconcile.
- [ ] Enforce separation of duties for outbound approvals.
- [ ] Add connector-specific reconciliation and shadow-mode cutover.

Exit gate:

- Health derives from real protocol checks and message behavior.
- Raw, canonical, projection, and target rows retain lineage.
- Replay is deterministic.
- Outbound sends are idempotent, acknowledged, reconciled, and auditable.

### Phase 8 - Release hardening and deployment

Goal: Ship without schema surprises, hidden errors, or unverified visual states.

- [x] Run all focused backend, frontend, browser, and security tests.
- [x] Run full PHP and JavaScript suites sequentially against isolated test schemas.
- [x] Build production assets.
- [x] Capture pre-deploy database backup plus migration/count/watermark/route/queue/scheduler evidence for the schema-bearing release slices.
- [x] Deploy compatibility code with externally gated connectors left inactive.
- [x] Apply the reviewed additive migrations/backfills through the merged `main` release sequence and verify their live ledger entries/invariants.
- [x] Seed/synchronize reference templates and transport resources only through explicit idempotent commands.
- [ ] Run demo roll-forward only against the synthetic demo tenant.
- [x] Keep activation flags closed until schema/data/protocol validation; Epic clinical polling and production HL7 remain explicitly inactive.
- [x] Run authenticated route/API/browser smoke for the shipped Patient Flow, Staffing, Transport, Integrations, and wall/desk surfaces.
- [x] Monitor Apache, the queue worker, completed jobs, queue failures, protocol health, scheduler registration, and user-facing boundaries across the release window.
- [x] Archive per-tranche evidence in the closure checklist/runbooks and reconcile this current-state plan.

Exit gate:

- All definition-of-done checks in Section 13 pass in production.

## 10. Test and Evidence Matrix

### Backend

- Staffing serializer returns JSON objects for empty map fields.
- Transport serializer returns JSON objects for empty map fields.
- Missing staffing requirements return unknown/missing, not 100 percent.
- Frozen-clock freshness tests cover fresh, stale, missing, and degraded.
- Legacy/canonical role compatibility tests.
- Qualification/availability/fulfillment invariants.
- Transport transition table tests for every allowed and forbidden edge.
- Transport chronology/property tests.
- Vendor/internal resource classification tests.
- Completed-today event-time tests.
- Patient Flow latest-window and stale-history tests.
- Snapshot mapping/persistence tests.
- Flow lens scope/redaction tests for house, unit, task, and no-patient personas.
- Integration page/API strict authorization matrix.
- FHIR discovery/token/metadata tests with HTTP fakes and sandbox contract suite.
- HL7 idempotency, ACK/NACK, sequence, replay, and lineage tests.
- SSRF and secret-leak tests.

### Frontend

- Staffing production fixture parses.
- Transport production fixture parses.
- Contract failure renders error, not loading/empty.
- Freshness/source badges render all states.
- Staffing filters, drilldowns, offers, and fulfillment interactions.
- Transport lifecycle and pagination interactions.
- Navigation projection and exactly-one-owner tests.
- Patient Flow stale/current/scenario bootstrap tests.
- Barrier queue and marker focus tests.
- Integrations permission and masked-secret UI tests.

### Browser and visual

- Desktop/mobile Staffing populated and error screenshots.
- Every Transport tab populated from current scenario.
- Patient Flow canvas nonblank pixel check.
- Patient tokens/trails/occupancy/barriers visible at desktop and mobile.
- No overlays occlude critical controls.
- No horizontal top-nav scroll at target viewports.
- Mobile drawer focus/keyboard/touch behavior.
- Superuser Integrations route visible only to intended role.
- Admin and ops-leader direct navigation returns forbidden.

### Data validation

- Seed/roll-forward twice with stable counts and natural keys.
- No non-synthetic rows deleted or updated.
- Current source timestamps fall within expected cadence.
- 23 inpatient units and 500 beds mapped.
- Staffing slots reconcile to qualified people.
- Transport capacity reconciles to resources.
- All transport lifecycle timestamps are monotonic.
- Occupancy snapshots are nonempty and continue hourly.
- Integration health matches real watermarks and failures.

## 11. Suggested Validation Commands

Run sequentially unless isolated test databases are configured.

```bash
git status --short --branch
php artisan migrate:status
php artisan route:list --path=staffing
php artisan route:list --path=transport
php artisan route:list --path=patient-flow
php artisan route:list --path=integrations
php artisan schedule:list
php artisan queue:failed
php artisan facility:link-operational --dry-run
php artisan facility:export-plates --check
php artisan zephyrus:demo-roll-forward --dry-run
```

Focused tests to create/run:

```bash
php artisan test --filter=Staffing
php artisan test --filter=Transport
php artisan test --filter=PatientFlow
php artisan test --filter=Integration
php artisan test --filter=Navigation
npm run test -- tests/js/staffing tests/js/transport tests/js/config/navigationConfig.test.ts
npm run test:e2e -- tests/e2e/navigation.spec.ts tests/e2e/staffing.spec.ts tests/e2e/transport.spec.ts tests/e2e/patient-flow.spec.ts tests/e2e/integrations.spec.ts
npm run build
```

Production checks must use an authenticated approved session without printing credentials or PHI.

## 12. Deployment and Rollback Strategy

### 12.1 Release slices

Release A - truthful UI, no schema dependency (**shipped via reconciled feature history / PR #14**):

- JSON map contract fixes.
- Error states.
- Freshness envelope and null/unknown semantics.
- Transport analytics corrections.

Release B - existing pending foundations (**shipped; July foundation migrations are recorded and live mappings/snapshots verified**):

- Targeted July migration chain.
- Staff role seed.
- Facility operational link backfill.
- Snapshot backfill.

Release C - canonical Staffing and Transport schemas (**core shipped via PRs #18 and #19; advanced unchecked depth remains**):

- Additive migrations.
- Backfills and compatibility reads.
- Demo generators.
- UI activation after validation.

Release D - navigation shell and Integrations route/RBAC (**shipped via PRs #14 and #17**):

- Navigation replacement.
- Strict capabilities.
- Legacy redirects.
- Control-plane panels.

Release E - real connectors (**operational first slice shipped via PR #17; credentialed Epic polling, production HL7 activation, bulk/remaining families, and outbound remain gated**):

- Queue/scheduler infrastructure.
- FHIR/SMART.
- HL7 gateway.
- Transactional connector families.
- Outbound lifecycle.

### 12.2 Production procedure

1. Confirm clean/current release commit and intended file scope.
2. Back up PostgreSQL and archive current migration/count evidence.
3. Validate release against a production clone.
4. Run `./deploy.sh` for application code.
5. Apply only reviewed explicit migration paths.
6. Run idempotent reference seeds/backfills.
7. Run the synthetic roll-forward only when the demo guard passes.
8. Clear caches and restart/supervise queues as required.
9. Run authenticated API and Playwright smoke.
10. Monitor for at least 30 minutes and archive evidence.

### 12.3 Rollback

- Disable new views/connectors with feature flags first.
- Preserve additive schema and data; do not run destructive down migrations in production.
- Revert application commit through the normal release workflow if required.
- Pause connector schedules/queues without deleting raw messages.
- Keep old routes as temporary redirects until the new navigation is proven.
- Restore database only for demonstrated data corruption, using the pre-release backup and an approved incident procedure.

## 13. Definition of Done

### Staffing Office

- [x] Page never remains indefinitely on loading after a failed request.
- [x] Current shift has a nonzero, explainable requirement denominator.
- [x] Missing/stale data is explicit and never displayed as 100 percent.
- [x] All operational units and three shifts are represented.
- [x] Role, person, verified qualification, availability/conflict state, requirement, governed offer, assignment, and fulfillment are linked for the canonical fulfillment path.
- [x] Synthetic roster is sufficient for 500 inpatient beds plus ED/perioperative operations and is derived from coverage hours.
- [x] Generator is idempotent and synthetic-row-scoped.

### Navigation

- [x] Only section-level controls are rendered in the top utility bar.
- [x] No hidden horizontal navigation scroll at supported viewports.
- [x] Patient Flow has one canonical owner under RTDC.
- [x] Mobile has a complete accessible drawer.
- [x] Every authorized route is reachable in at most three actions or command search.
- [ ] Web route, API, nav, palette, and local tabs share authorization/config truth.

### Integrations

- [x] Deployment label is replaced by Integrations at `/integrations`.
- [x] Only approved superusers can see or access it.
- [x] Facility/readiness and Staffing Alignment are relocated with accurate labels.
- [x] Health is observed, time-aware, and protocol-aware.
- [x] Epic FHIR R4/SMART discovery is real, not fabricated; clinical polling remains activation-gated.
- [x] HL7 ingress uses an exact-ability machine boundary and raw/canonical/projection/provenance lineage.
- [x] Runs, watermarks, dead letters, bounded replay, credential references, and audit controls are operable. Governed outbound transmission remains an explicit Phase 7 gate.
- [x] No secret or PHI leak is possible through normal serialization/logging.

### Patient Flow 4D

- [x] Current or explicitly historical movements are visible.
- [x] Patient markers/trails honor persona scope and redaction.
- [x] Occupancy history persists and renders; production has 1,150 snapshots across 23 mapped spaces at reconciliation.
- [ ] Multiple verified barrier types show reason, owner, age, SLA, source, and lineage.
- [ ] Demo scenario is coherent across all web/mobile/Eddy surfaces.
- [x] Live mode means a current cursor/feed, not finite stale replay.
- [x] Desktop/mobile screenshots and canvas-pixel checks prove marker visibility.

### Transport

- [x] Existing and generated rows parse and display.
- [x] Every board has plausible current work.
- [x] Requests, synchronized resources/capacity, assignments, lifecycle checkpoints, delays, and required handoffs reconcile for the governed core. The explicit pre-transport equipment checklist/vendor-event expansion remains open.
- [x] State transitions and analytics share one event vocabulary.
- [x] Internal teams are not vendors.
- [x] Completed-today uses completion time.
- [x] Active/history APIs are filtered and paginated.
- [x] Synthetic scenarios are clinically and geographically coherent.

### Release

- [ ] Retrospectively archive clone-rehearsal evidence for the older July foundation chain; the later transport migration was rehearsed against a production-shaped disposable database with the real migration ledger and all invariants green.
- [x] The final production ledger contains the reviewed additive `000200`, `000300`, and `000400` migrations. An unrelated concurrent `000500` migration that entered the first transport sync window was immediately backed up, rolled back alone, and removed; it is absent from the final ledger.
- [x] Full test/build suite passed.
- [x] Authenticated production smoke passed for the shipped implementation tranches; public/login/machine boundaries were also rechecked.
- [x] Scheduler, database queues, Apache, and worker logs remained healthy through the release/monitoring window; protocol checks reported Epic healthy and the non-live synthetic HL7 source degraded rather than fabricating health.
- [x] Evidence, current-state plans, and operational runbooks were updated.

## 14. Dependencies and Parallel Work

Critical path:

```text
P0 truthful contracts
  -> P1 migration/mapping readiness
    -> P2 canonical staffing
    -> P3 transport resources/lifecycle
    -> P4 Patient Flow current barriers
    -> P6 connector sources for live data
```

Parallelizable after P0:

- Navigation shell design/build can proceed alongside P1-P4.
- Integrations RBAC and shell can proceed while connector workers are built.
- Workforce and Transport schema design can proceed in parallel if they share facility/source/role conventions.
- Patient Flow visual improvements can proceed after the scene contract and source/freshness contract are frozen.

Do not parallelize against one shared PostgreSQL test schema. Use isolated databases or run migration-heavy suites sequentially.

## 15. First Executable Backlog

The audit originally recommended a narrow truthfulness-only first pull request. The user subsequently approved the broader local implementation tranche recorded in Section 1.1 and in the checked items above.

The next release backlog is now bounded to work that is actually still open:

1. Provide approved Epic non-production client/key references outside Git, complete token exchange plus bounded Encounter/Location polling, verify raw/FHIR/provenance retention, and advance the first clinical watermark.
2. Complete contract/BAA/PHI/sender governance for the first production HL7 v2 ADT source, issue an exact-ability expiring machine token, and prove one test ADT through raw -> canonical -> Patient Flow -> provenance.
3. After real source activation, execute the production failure/dead-letter, staleness, credential-rotation, worker-restart, and rollback drills.
4. Continue only the explicitly unchecked extended-roadmap items: production-clone evidence for the older foundation chain, advanced staffing rule/forecast/FHIR depth, pre-transport equipment/vendor-event depth, FHIR Bulk Data, remaining transactional connector families, and governed outbound transmission.
5. Re-audit open Patient Flow PR #13 and either extract unique work into a bounded current branch or close it as superseded; do not merge its stale branch wholesale.

The production migrations, operational runtimes, and immutable release-source path described in Section 1.2 are shipped. Real clinical connector activation remains deliberately gated on external credentials, privacy/interface approval, and post-activation operational evidence; it must not be inferred from a healthy discovery endpoint alone.
