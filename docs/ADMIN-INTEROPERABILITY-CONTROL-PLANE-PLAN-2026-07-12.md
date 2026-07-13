# Zephyrus Administration and Healthcare Interoperability Control Plane Plan

**Status:** Canonical go-forward implementation plan for `/admin` and `/integrations`

**Implementation status (2026-07-13):** Phase 0 production safety, the Admin cockpit, enterprise/source scope, governed source lifecycle, source onboarding/readiness/scheduled activation, and the credential/network authority foundation are code-complete on `feat/admin-production-safety`. Checked items below mean code-complete with automated evidence on that branch; they do not waive production smoke, institutional provider/bootstrap configuration, raw-PHI storage, partner conformance, or go-live gates.

**Audit date:** 2026-07-12

**Product authority:** `docs/ZEPHYRUS-2.0-BETA-PRD.md` remains the application-wide product authority. This document is the execution authority for Administration, enterprise setup, integration governance, and healthcare transactional interoperability.

**Supersedes:** The remaining Release C-H sequence in `docs/PARTHENON-ADMIN-PORT-PLAN-2026-07-10.md` and the administration/control-plane backlog in `docs/superpowers/plans/2026-06-25-real-time-healthcare-data-acquisition.md`. Those documents remain useful background and standards research.
**Delivery rule:** Production deployment remains manual-only through `./deploy.sh`; GitHub Actions may validate but must not deploy.

---

## 1. Executive Decision

Zephyrus should complete `/admin` as the governed front door for identity, enterprise topology, interoperability, data trust, platform health, and accountability. It should not become a second implementation of the existing `/integrations` console. The durable product split is:

- `/admin` is the administration cockpit: readiness, risk, ownership, policy, access, approvals, and links into governed domain consoles.
- `/integrations` is the deep healthcare integration control plane: sources, connections, protocol capabilities, credentials, mappings, runtime, dead letters, replay, outbound delivery, and conformance evidence.
- Existing operational consoles remain authoritative for staffing, Cockpit thresholds, enterprise deployment topology, Eddy policy, and domain workflows.
- Every configuration action is capability-gated, reasoned, auditable, secret-safe, PHI-safe, and reversible.
- Every live data path is source-labelled, version-pinned, idempotent, replayable, reconciled, and observable from receipt through projection.

"Ready for all modern healthcare transactional systems" must mean an extensible, standards-first platform with explicit conformance profiles and production-tested connector packs. It cannot mean that a generic connector is automatically certified against every proprietary vendor, trading partner, version, network, and contract. A connector is only production-supported when its exact vendor/version/profile combination has passed the go-live evidence gates in this plan.

No real PHI source may be activated until the Phase 0 security and identity gates are complete.

---

## 2. Audit Evidence and Current Baseline

### 2.1 Live `/admin` evidence

The 2026-07-12 production inspection found a visually coherent but incomplete administration shell:

| Surface | Live state | Assessment |
| --- | --- | --- |
| User Management | Available | Basic local CRUD exists; lifecycle, identity source, session/token revocation, protected-account rules, and enterprise role governance are missing. |
| User Audit | Available | Useful server-filtered accountability ledger exists and is append-only; retention, export, incident correlation, and privileged-action reason capture remain. |
| Cockpit Thresholds | Available | Functional but presents a large flat metric list; needs ownership, validation, change preview, versioning, rollback, and scope-specific policy. |
| Enterprise Setup | Available | Page reported zero organizations and instructed operators to run `deployment:import-facilities`; no live IDN registry is configured. |
| Staffing Administration | Shown as unavailable | The implementation exists but the demo `admin` role does not have `manageDeploymentConfig`. The UI conflates "restricted" with "not implemented." |
| Integrations | Shown as unavailable | The deep console and API exist, but only `super_admin`/`superuser` may open them. Again, "restricted" is displayed as "unavailable." |
| Authentication providers | API only | Secret-safe OIDC settings endpoints exist, but there is no `/admin/auth-providers` Inertia page or Admin card. |
| System health | Missing | No unified database, queue, scheduler, cache, broadcast, integration, external dependency, and deployment health surface. |
| Roles and capabilities | Missing | Scalar roles, Spatie roles, Gates, mobile personas, and Sanctum abilities are not administered through one contract. |
| Data governance | Missing | Terminology, identity matching, provenance, retention, consent, and source-quality workflows have storage seams but no complete administrator experience. |

The public response also included user names, email addresses, IP addresses, and accountability events. The content is not anonymous-safe even when it contains no clinical PHI.

### 2.2 Production security boundary

The audit's unauthenticated curl, browser, and Firecrawl requests created successful `auth.login` events from the audit clients' external IPs. This is direct proof of the production auto-login path, not evidence of compromise by those addresses.

Release-blocking observations:

- `SessionAuthMiddleware` creates or mutates the shared `admin` account, assigns the `admin` role, disables password-change enforcement, and logs every unauthenticated web visitor into that account.
- `public/.htaccess` sets `Access-Control-Allow-Origin: *` for the application, handles OPTIONS broadly, unsets CSRF response headers, and installs a CSP that allows any origin plus `unsafe-inline` and `unsafe-eval`.
- `public/direct-login.php` also emits wildcard CORS and must not be a production authentication path.
- The application middleware intends `X-Frame-Options: DENY`, but `.htaccess` overwrites it with `SAMEORIGIN`; header policy has two competing owners.
- Shared demo authentication makes the login metrics and actor identity unsuitable for real accountability.
- Local password bootstrap data and demo identities are visible on the live user screen.

### 2.3 Existing integration implementation

Zephyrus already has a meaningful foundation and should extend it:

- `integration.sources`, capabilities, endpoints, credential references, identity links, terminology maps, configuration audits, watermarks, canonical events, projection offsets/errors, replay jobs, and provenance.
- `raw.ingest_runs`, inbound messages, and dead letters.
- `fhir.resource_versions` and resource links.
- Strict `viewIntegrations` and `manageIntegrations` Gates.
- Secret-safe source/endpoint/credential configuration APIs with outbound URL policy.
- FHIR R4 SMART Backend Services discovery and an Epic-specific Encounter/Location polling client.
- A machine-token HL7 v2 ADT HTTPS boundary with idempotency, raw/canonical/projection lineage, and opaque receipts.
- A connector contract, synthetic connector, normalizer registry, canonical writer, projection dispatcher, and bounded replay.
- Integration queue jobs, scheduler dispatch, a supervised worker, protocol health, and a runtime runbook.
- A deep React console with Overview, Sources, FHIR, HL7, Transactional Apps, Mappings, Runs, Dead Letters, Outbound, Credentials, and Audit tabs.
- Draft-only governed writeback linked to operational approvals.

The current data proves the foundation is running but not connected to a real institution:

| Runtime fact | 2026-07-12 observation |
| --- | ---: |
| Configured sources | 2 |
| Real production sources | 0 |
| Demo source | `synthetic-flow-ehr`, sandbox, active/demo, degraded, PHI disallowed |
| Discovery source | `epic.fhir-r4.sandbox`, testing, healthy discovery, activation not started, PHI disallowed |
| Source endpoints | 3 public Epic sandbox endpoints |
| Credential state | No usable secret reference; one SMART credential metadata row is `activation_required` |
| FHIR client connections | 1 Epic R4 sandbox connection, healthy discovery, `activation_required` |
| Interface engines | Ready production canonical HTTPS ingress plus one sandbox MLLP template |
| Discovered FHIR resource capabilities | 59 resource types from the public sandbox CapabilityStatement |
| Raw ingest runs | 1,312 |
| Raw inbound messages | 3,779 |
| Canonical events | 4,697 |
| Open dead letters / projection errors | 0 / 0 |
| Organizations / facilities | 0 / 0 |

The deployed release matches `origin/main` and has the integration foundation, enterprise control, configuration-audit, and operational-runtime migrations applied. Apache, the Zephyrus queue worker, and the Arena service were active. The unrelated ancillary work visible in the local dirty worktree and its pending migrations are not certified as deployed by this plan.

### 2.4 Validation evidence

- The focused Admin and integration frontend tests passed: 4 files, 11 tests.
- An isolated integration configuration test passed with 35 assertions.
- After the other test process ended, the focused backend Admin, Integrations, Deployment Console, and Staffing Administration set passed: 45 tests, 484 assertions.
- A run attempted while another Ancillary suite owned the shared `zephyrus_test` database produced migration-table/schema races. The clean rerun proves those errors were test isolation failures rather than integration behavior failures. Per-process database isolation is therefore a required Phase 0 gate.
- The 2026-07-13 enterprise-scope closeout passed the complete focused Integration suite (**34 tests / 419 assertions**), the active-scope contract suite (**6 / 33**), and four focused React files (**11 tests**). The final authoritative gates passed **1,000 backend tests / 15,459 assertions** with one intentional fixture-regeneration skip, **97 Vitest files / 412 tests**, TypeScript, Laravel Pint across **1,111** PHP files, `git diff --check`, and a production Vite build. The complete fresh-migration authenticated browser lane passed **30 tests** with three deliberate feature skips, including explicit selection, scoped Admin-to-Integrations deep links, and clearing. Evidence is retained at `artifacts/release-evidence/browser/20260713T083235Z-browser-playwright.json`; both concurrent harnesses removed their disposable PostgreSQL databases.
- The source-lifecycle tranche has focused proof for immutable create/update/proposal/application, optimistic concurrency, lifecycle transitions, production activation, database projection guards, and append-only ledgers: **29 backend tests / 344 assertions** across configuration, activation, operational runtime, and active-scope contracts. Its final authoritative gates passed **1,006 backend tests / 15,539 assertions** with one intentional fixture-regeneration skip, **97 Vitest files / 412 tests**, TypeScript, Laravel Pint across **1,116** PHP files, `git diff --check`, and the production Vite build. The fresh-migration authenticated browser lane passed **30 tests** with three deliberate feature skips and retained `artifacts/release-evidence/browser/20260713T091623Z-browser-playwright.json`; its disposable `zephyrus_test_e2e*` database was removed.
- The onboarding/readiness/scheduled-activation tranche adds immutable onboarding profiles, non-confidential evidence references, exact readiness assessments, truthful support badges, future-dated dual-control activation windows, scheduler leases, drift detection, bounded retries, cancellation, and fail-closed terminal handling. Its focused API proof passed **11 tests / 218 assertions**. The final authoritative gate passed **1,017 backend tests / 15,759 assertions** with one intentional fixture-regeneration skip, **97 Vitest files / 414 tests**, TypeScript, Laravel Pint across **1,123** PHP files, `git diff --check`, and the production Vite build. The fresh-migration authenticated browser lane passed **30 tests** with three deliberate feature skips and retained `artifacts/release-evidence/browser/20260713T100938Z-browser-playwright.json`; its disposable database was removed. Browser validation exposed and drove fixes for the Admin Integrations deep link and the empty-SLO JSON map contract before the successful run and final backend rerun.

---

## 3. Target Administration Information Architecture

The Admin home should report readiness and route the operator to a real surface. A route that exists but is not permitted must say **Restricted** and name the capability required; **Unavailable** is reserved for a feature that is genuinely not shipped.

| Admin domain | Required destination and content | Primary capabilities |
| --- | --- | --- |
| Identity and Access | Users, local/OIDC/SCIM identity, roles, capabilities, sessions, tokens, MFA state, break-glass accounts | `viewIdentity`, `manageIdentity`, `managePrivileges` |
| Authentication Providers | Local password policy, OIDC providers, discovery diagnostics, group mapping, JIT/provisioning policy | `viewIdentity`, `manageAuthProviders` |
| Enterprise Registry | Organizations, markets, facilities, service lines, locations/spaces, capabilities, transfer relationships, readiness | `viewEnterpriseSetup`, `manageEnterpriseSetup` |
| Integrations | Cross-source readiness summary and link to `/integrations`; approval queue and active incidents | `viewIntegrations`, `manageIntegrations`, `operateIntegrations` |
| Data Governance | Terminology maps, identity match/merge review, data classification, consent/segmentation, retention, provenance, quality rules | `viewDataGovernance`, `manageTerminology`, `reviewIdentity`, `manageDataPolicy` |
| Staffing Alignment | Governed workforce source alignment and deployment readiness | `viewStaffingConfig`, `manageStaffingConfig` |
| Cockpit Governance | Threshold policies, freshness rules, metric ownership, preview, versioning, rollback | `viewCockpitPolicy`, `manageCockpitPolicy` |
| System Health | Database, queue, scheduler, cache, broadcast, Arena, Eddy, integration runtime, storage, backups, and external dependencies | `viewSystemHealth`, `runDiagnostics` |
| Eddy Governance | Provider profiles, model/capability policy, fallback order, routing simulation, cost/usage without prompt/PHI content | `viewAiGovernance`, `manageAiGovernance` |
| Audit and Compliance | User, configuration, writeback, export, access-review, incident, and release evidence | `viewAudit`, `exportAudit`, `reviewAccess` |

Admin home metrics should change from mostly user counts to a balanced readiness strip:

- identity risks: inactive-but-tokened accounts, MFA gaps, password-change due, expiring sessions/tokens;
- integration risks: unhealthy/stale sources, expiring credentials/certificates, dead letters, projection backlog, SLO breaches;
- enterprise readiness: organizations/facilities configured, unmapped locations/service lines, deployment blockers;
- platform risks: failed jobs, scheduler delay, backup age, external dependency failures;
- accountability: privileged changes awaiting review, overdue access reviews, open incidents.

---

## 4. Target Architecture

The control plane and data plane must be separate. Configuration writes must never be able to inject clinical payloads, and data-plane machine identities must never receive administrator capabilities.

```text
Administrator / Integration Operator / Data Steward / Auditor
                           |
                    Admin control plane
       identity | policy | source registry | approvals | audit
                           |
                immutable config versions
                           |
EHR | LIS | RIS/PACS | Pharmacy | WFM | Payer | HIE | Devices | ERP
                           |
       protocol and vendor adapters at a governed network boundary
     HL7 v2 | FHIR | DICOMweb | X12 | NCPDP | IHE/Direct | APIs/files
                           |
        encrypted raw ledger + receipt + idempotency + quarantine
                           |
       validation -> identity -> terminology -> canonical events
                           |
              versioned projectors and reconciliation
                           |
       prod / flow_core / fhir / ocel / star read models
                           |
      Zephyrus Web | Hummingbird | Eddy | approved outbound drafts

Cross-cutting: provenance, security labels, consent, SLOs, telemetry,
dead letters, replay, audit, retention, disaster recovery, conformance.
```

### 4.1 Architectural invariants

1. **Protocol first, vendor profiled.** Implement reusable protocol engines and thin vendor conformance profiles; do not fork the canonical pipeline per vendor.
2. **R4 production baseline, negotiated versions.** Persist the exact FHIR version, US Core/IG packages, capabilities, search parameters, and quirks for each connection.
3. **Raw before projection.** A source adapter may not write directly to `prod`, `flow_core`, `ocel`, or `star`.
4. **Immutable evidence.** Raw receipts, canonical event identities, configuration versions, approvals, delivery attempts, acknowledgements, and provenance are append-only.
5. **Idempotency at every boundary.** Receipt, canonical mapping, projection, replay, and outbound delivery each have stable idempotency keys and duplicate-safe side effects.
6. **Identity is never an incidental join.** Patient, encounter, practitioner, organization, location, order, and device identities use explicit namespaces and reviewed crosswalks.
7. **No silent fallback.** Synthetic, seeded, cached, stale, partial, and live data are distinguishable in every downstream payload.
8. **Writeback is advice plus human authorization.** Eddy or any automated agent may draft; only an authorized human workflow may approve dispatch.
9. **Minimum necessary by design.** Admin screens, logs, metrics, alerts, and audit rows contain no raw clinical payloads or secrets.
10. **Facility and tenant scope is enforced in the query.** UI filters are not a security boundary.

---

## 5. Standards and Transactional Coverage Baseline

Version selection must be stored per source. "Current published" and "currently adopted by regulation/vendor" are different facts.

### 5.1 Version policy as of 2026-07-12

| Standard family | Zephyrus policy |
| --- | --- |
| FHIR core | Use FHIR R4 4.0.1 as the default U.S. production exchange baseline. FHIR R5 5.0.0 is the current published core release, but federal guidance and deployed U.S. IGs still center on R4. Add R4B/R5 adapters only where a customer use case requires them; plan an R6 evaluation after publication and vendor adoption. |
| US Core | Support the regulated/certified floor required by the customer and record it explicitly. ASTP identifies US Core 6.1.0 for HTI-1; HL7's current published guide is US Core 9.0.0 on R4. Conformance testing must pin the exact package, not use a floating "latest." |
| SMART | Implement SMART App Launch 2.2.0 features for user launch and Backend Services, while retaining compatibility profiles for the version a vendor/certification program exposes. Use asymmetric client authentication and least-privilege scopes. |
| Bulk Data | Implement the full asynchronous export lifecycle and negotiate vendor support. Bulk Data 3.0.0 is current published; keep 1.x/2.x compatibility profiles where required. |
| FHIR eventing | Support R4 polling first, then R5 Subscriptions Backport 1.1.0/topic-based subscriptions where the server supports it. Never assume `Subscription` availability from a source checkbox. |
| CDS Hooks | Use a stable published HL7 release agreed with the EHR, never the continuous `current` draft. Treat CDS Hooks as workflow integration, not the primary operational data feed. |
| HL7 v2 | Profile by trigger/message and trading partner rather than claiming one generic v2 version. Preserve MSH version and conformance profile. Support ACK/NACK and vendor Z-segments through source-specific profiles. |
| C-CDA and documents | Support CDA R2/C-CDA R2.1 production exchange, C-CDA STU4 compatibility, FHIR `DocumentReference`/Binary, and IHE MHD/XDS/XDR/XDM as customer/network requirements dictate. |
| Imaging | Support DICOM DIMSE at an imaging gateway and DICOMweb PS3.18 QIDO-RS, WADO-RS, STOW-RS, UPS-RS, and capability discovery. Never copy pixel data into the operational application database. |
| Administrative transactions | Support adopted ASC X12N 5010 transactions and CAQH CORE operating rules through a licensed parser/clearinghouse adapter. Preserve the exact implementation guide and companion guide per trading partner. |
| Pharmacy | Support NCPDP D.0 for retail pharmacy claims and the customer's current SCRIPT implementation; build transition compatibility for SCRIPT 2023011 by the 2028 Medicare Part D requirement, F&B v60 and RTPB v13 by 2027. |
| Nationwide/HIE trust | Integrate through the customer's HIE/QHIN/participant path. TEFCA Common Agreement 2.1 is a policy/network dependency, not a direct API that Zephyrus can self-enable. Use applicable QTF, IHE, FHIR, document, purpose-of-use, and consent requirements. |

### 5.2 Required protocol and domain matrix

| System family | Transactions and APIs to support | Canonical Zephyrus outcomes |
| --- | --- | --- |
| EHR/registration/MPI | HL7 ADT A01-A13, A17, A28/A31, A37/A40/A43/A47; FHIR Patient, RelatedPerson, Encounter, Account, EpisodeOfCare, Organization, Location; PIX/PIXm, PDQ/PDQm, PMIR | Patient/encounter identity, admit/register/transfer/discharge/cancel/merge/correct, facility/unit/bed occupancy |
| Bed management/RTDC | ADT; FHIR Encounter, Location, Task; vendor bed-board APIs/webhooks/files | Bed state, assignment, blocked/dirty/ready, census, pending admit/discharge, barriers, reconciliation |
| Emergency/EMS | ADT, ORU, FHIR Encounter/Observation/Condition/ServiceRequest/Task/Communication; NEMSIS/vendor APIs where contracted | Arrival, triage, provider seen, disposition, boarding, departure, LWBS, EMS inbound, transfer |
| Perioperative/anesthesia | HL7 SIU, ORM/OML, ORU, DFT; FHIR Appointment, Schedule, Slot, ServiceRequest, Procedure, Encounter, Observation; vendor APIs | Schedule, room/case status, milestone timestamps, PACU holds, cancellations, turnover, block utilization |
| Laboratory/pathology/blood bank | HL7 OML/ORM, ORU, OUL, ACK; FHIR ServiceRequest, Specimen, Observation, DiagnosticReport, Task; instrument/LIS APIs | Ordered, collected, received, in-process, preliminary, final, corrected, cancelled, add-on, critical result, pathology/frozen-section milestones |
| Imaging/cardiology | HL7 ORM/OML, ORU, SIU; DICOM/DICOMweb; FHIR ServiceRequest, ImagingStudy, DiagnosticReport, Observation, Appointment | Order/schedule, protocol, acquisition, interpretation, final report, critical result, modality/reader/worklist state |
| Pharmacy/eMAR/medication history | HL7 RDE/RDS/RAS/RGV, FHIR MedicationRequest/Dispense/Administration/Statement, NCPDP SCRIPT/D.0/RTPB/F&B, vendor APIs | Medication order, verification, dispense, administration, reconciliation, discharge prescription and authorization barriers |
| Staffing/WFM/credentialing | FHIR Practitioner, PractitionerRole, HealthcareService, Schedule, Slot; HL7 MFN; SCIM for application identity only; vendor APIs/SFTP/CSV | Workforce identity, role/qualification, assignment, availability, coverage, safe minimums, callouts, agency/overtime |
| Transport/EVS/facilities/RTLS | FHIR Task/ServiceRequest/Device/Location; vendor REST/webhooks/MQTT through a managed gateway; files where unavoidable | Request, accept, dispatch, arrive, complete, cancel, SLA risk, bed cleaning/block, equipment/location readiness |
| Payer/clearinghouse/revenue cycle | X12 270/271, 276/277, 278, 837, 835, 834, 820; Da Vinci CRD/DTR/PAS, PDex, Formulary, Plan-Net; CARIN Blue Button | Eligibility, benefits, authorization, claim/remit/status, coverage, avoidable-day and discharge/transfer barriers |
| HIE/TEFCA/documents/referrals | C-CDA, Direct/XDR/XDM, IHE XDS.b/MHD, PIXm/PDQm, FHIR DocumentReference/Binary/Bundle/Communication/Task; QHIN/HIE APIs | External context, transitions, referrals, document provenance, exchange purpose, consent/security labels |
| Public health | eCR/eICR/RR, ELR, VXU/QBP, syndromic ADT, NNDSS, vital records; FHIR and jurisdictional HL7 v2 profiles | Reportability, submission/acknowledgement status, public-health handoff evidence without contaminating operational metrics |
| Medical devices/IoT | IEEE 11073 nomenclature/device profiles, IHE PCD, FHIR Device/DeviceMetric/Observation, vendor gateways | Device identity, normalized observation/event, connectivity/availability, alarm state where contractually permitted |
| Supply chain/ERP | GS1 identifiers, X12 850/855/856/810, FHIR SupplyRequest/SupplyDelivery, ERP/vendor APIs | Shortage, inventory/availability, purchase/order/shipment, supply constraint and procedure readiness |
| Quality/research/analytics | FHIR Measure/MeasureReport, QI-Core/DEQM where required, Bulk Data, OMOP/export contracts | Governed aggregates, measure lineage, cohort/bulk export, source freshness, no operational writeback |

### 5.3 Terminology baseline

Zephyrus must version and govern mappings for LOINC, SNOMED CT, RxNorm, NDC, UCUM, ICD-10-CM/PCS, CPT/HCPCS where licensed, CVX/MVX, NUCC/provider specialties, DICOM controlled terminology, HL7 table values, local service lines, location/bed codes, device identifiers, and GS1 identifiers. A map is not production-active until it has an owner, source/target version, effective interval, review decision, test fixture, and impact preview.

---

## 6. Gap Assessment

| Capability | Present | Missing before real-world readiness |
| --- | --- | --- |
| Admin shell | User metrics, six cards, recent audit | Complete IA, restricted-vs-unavailable semantics, readiness/incident metrics, auth providers, roles, health, data governance, Eddy governance |
| Identity | Local CRUD, OIDC backend, audit | Production SSO/MFA, SCIM lifecycle, canonical role/capability model, protected/break-glass accounts, deactivation, session/token revocation, access reviews |
| Enterprise scope | Mature taxonomy/import services | Imported production organizations/facilities, source-to-organization FKs, administrator workflow, tenant/facility enforcement |
| Source registry | Useful source/capability/endpoint/credential tables | Contract/BAA evidence, owner/steward, data classification, retention, network route, versioned config, approvals, facility scope, SLOs, go-live checklist |
| Secrets | References are validated and masked | Resolver currently supports only `file://` while configuration advertises other schemes; implement providers or stop advertising them; add rotation workflow and lease/health evidence |
| FHIR | Discovery, R4 validation, Epic SMART client, Encounter/Location polling | Vendor-neutral client, complete capability/search/profile capture, US Core profiles, resource mappers, Bulk Data, subscriptions, reconciliation, write operations, conformance suite |
| HL7 v2 | ADT HTTPS ingress, many movement triggers, idempotency | Real interface-engine/MLLP boundary, ACK/NACK ledger, profiles/Z-segments, correction/unmerge coverage, source sequence enforcement, domain message families, reconciliation |
| Ancillary | Local worktree contains meaningful normalizers/projectors | Independent merge/deploy validation, production migrations, source activation, end-to-end live conformance; pharmacy/pathology/blood-bank depth remains incomplete |
| DICOM | Endpoint type/template concepts | DICOMweb client/gateway, conformance statement capture, study/series/instance metadata policy, imaging status reconciliation |
| X12/NCPDP | Coverage is named in templates | Licensed parser/validator or clearinghouse adapter, companion guides, acknowledgements, operating rules, trading-partner certification, no production connector |
| HIE/public health | Planned in acquisition document | No production adapter, network participation, purpose-of-use/consent flow, acknowledgement/reconciliation |
| Identity/terminology | Tables exist | Deterministic/probabilistic match policy, review queues, merge/unmerge workflow, mapping version/promotion UI, downstream impact analysis |
| Runtime | Current health, runs, watermarks, DLQ, replay, queue | Health history, SLOs, alert routing, per-source backpressure, quarantine workflow, repair notes, metrics/traces, DR drills |
| Raw storage | JSONB payload/storage pointer fields | Current Patient Flow path stores raw HL7 in database JSONB; require encrypted payload storage, key policy, retention, legal hold, partitioning, purge evidence |
| Outbound | Approval-linked draft records | Dispatch adapters, delivery attempts, ACK/status correlation, retries/compensation, endpoint policy, dual control, full reconciliation |
| Testing | Strong focused tests | Isolated per-process databases, UI interaction coverage for all tabs/forms, protocol conformance harnesses, vendor sandboxes, load/soak/chaos, PHI leakage gates |

---

## 7. Implementation Workstreams and Detailed TODO

### Phase 0 - Production Safety Gate

**Objective:** Make it impossible for an anonymous or demo user to reach production administration or clinical data. No real source activation is allowed before all `P0` items are proven in production.

#### P0-AUTH - Remove the demo authentication boundary

- [x] Add an explicit `DEMO_AUTO_LOGIN_ENABLED` configuration defaulting to `false` in production and testing the environment guard itself.
- [x] Make `SessionAuthMiddleware` refuse auto-login when `APP_ENV=production`; it must never create or mutate a user in request middleware.
- [x] Remove the production dependency on the shared `admin/password` account and rotate or deactivate all known demo credentials.
  - Branch status: the one-way production-safety migration deactivates every exact legacy bootstrap username/email pair, replaces its password with an unknown random value, removes scalar and Spatie privilege, clears remember/browser/Sanctum access, and writes hash-only external-identity unlink evidence. `UserSeeder` is production-disabled and creates no non-production account without explicit demo mode plus a unique username, email, and 16+ character password; any explicitly provisioned demo identity is unprivileged and must change its password.
- [x] Remove or production-deny `public/direct-login.php` and any direct-login route, artifact, or proxy rule.
- [x] Require a real login for every web route; unauthenticated `/admin`, `/users`, `/integrations`, enterprise setup, staffing administration, and their APIs must return redirect/401 rather than content.
- [x] Complete `/admin/auth-providers` over the existing provider API, including discovery, bounded connection diagnostics, allowed/admin group mapping, and safe enable/disable.
  - Branch status: the page now enforces an environment-owned host/port/redirect allowlist, public-address DNS policy, no-follow HTTP, response/time bounds, issuer-origin validation, audited discovery/JWKS diagnostics, secret-safe readiness, and an authoritative stored emergency-disable state.
- [ ] Enable institutional OIDC SSO with PKCE for interactive users, MFA policy at the identity provider, issuer/audience/nonce/state validation, and group-removal enforcement for existing users.
  - Branch status: PKCE and issuer/audience/nonce/state validation are implemented; every linked-user login now re-evaluates allowed/admin group membership, and administrative step-up accepts only recent IdP `auth_time` plus deployment-approved MFA `amr`/`acr` evidence. Institution-owned provider enablement and upstream MFA policy evidence remain.
- [ ] Define two sealed break-glass accounts with hardware-backed MFA, monitored use, short sessions, documented rotation, and no routine use.
- [x] Add deactivation, session invalidation, remember-token invalidation, Sanctum token revocation, and OIDC identity unlink/relink workflows.
  - Branch status: deactivation and password/identity/role changes advance a session generation, invalidate file/database-backed browser sessions on their next request, clear remember tokens, delete database-session rows, and revoke Sanctum tokens. Identity administrators can unlink or relink only a previously validated provider subject after step-up and a 10-500 character reason; self-change, protected/purged accounts, mismatched user/identity routes, inactive-account relink, and silent OIDC reconciliation of an administratively unlinked subject fail closed. The append-only ledger stores provider-subject/email hashes rather than raw identifiers and includes an idempotent baseline for links that predate the ledger.
- [x] Add protected-account and last-privileged-administrator rules; irreversible identity purge becomes an exceptional, separately approved action while physical hard deletion remains denied.
  - Branch status: protected-account mutation, self-deactivation, self-demotion, last-active-administrator removal, and routine hard deletion fail closed and are audited. A deactivated, non-protected account can enter the retention-aware purge only through an exact-payload, unexpired dual-control request: a `manageIdentity` author and different `managePrivileges` approver are required, with step-up at request, decision, and execution. Execution preserves the numeric user key and referential/audit history while irreversibly pseudonymizing direct identifiers, replacing credentials, removing roles, permissions, scopes, units, devices, sessions, tokens, and external subjects, and binding the tombstone to the immutable change request.

Branch verification evidence for the completed code-addressable P0-AUTH lifecycle: focused lifecycle/schema/seeder tests passed **14/121** and legacy credential tests passed **5/39**; the named Admin lane passed **143/1,070** and Migration lane **35/138**. The authoritative full backend gate passed **981** tests and **15,206** assertions with one intentional skip. Vitest passed **93** files and **403** tests, TypeScript passed, the Vite production build passed, and the complete browser lane passed **28** tests with three deliberate feature skips, including real navigation to the external-identity/purge UI without exposing the raw provider subject. Laravel Pint passed all **1,091** PHP files, `git diff --check` passed, and no disposable test database remained. Institutional provider enablement/MFA evidence and the two hardware-backed break-glass accounts remain deployment-owned P0-AUTH blockers; production was not changed.

**Acceptance:** Anonymous requests expose no admin/user/audit content; demo middleware is unreachable in production; SSO and break-glass tests pass; session/token revocation is immediate and audited.

#### P0-WEB - Repair the browser/API boundary

- [x] Replace wildcard CORS with same-origin default and explicit per-machine/per-partner API origin policy; do not combine credentialed browser sessions with wildcard origins.
- [x] Remove the `.htaccess` rule that broadly unsets CSRF headers and document the actual CSRF contract for Inertia and machine APIs.
- [x] Replace the permissive CSP with a tested nonce/hash policy; remove `default-src *`, `unsafe-eval`, wildcard frames, and wildcard connect sources.
- [x] Establish one header owner between Laravel and Apache and add response-header regression tests.
- [x] Restrict OPTIONS handling to known API routes and declared methods/headers.
- [x] Apply route-specific rate limits, payload limits, content types, request IDs, and machine authentication to every ingress.
- [ ] Add WAF/reverse-proxy rules for admin paths and machine endpoints, with allowlists/mTLS where a trading partner supports them.
- [x] Verify cookies are Secure, HttpOnly where applicable, SameSite-appropriate, rotated at login, and scoped to the correct domain.
- [x] Run secret, dependency, static, and dynamic scans; resolve all critical/high findings before PHI activation.

Implemented browser CSRF contract: Inertia and other session-authenticated web mutations remain in Laravel's `web` middleware and must present Laravel's CSRF/XSRF token. Machine APIs use explicit bearer/machine authentication and are not made safe by CORS. CORS is limited to declared `api/*` origins, methods, and headers and does not replace authentication or CSRF protection.

Branch implementation evidence for the P0-WEB scanning and edge-contract tranche: the framework was moved from vulnerable Laravel 11 dependencies to Laravel **12.63.0**, after which Composer, npm, Arena pip, and Eddy pip audits all reported no known vulnerabilities. Gitleaks **8.28.0** scanned **754 commits** plus the current working tree with full redaction and no unreviewed findings; every exception is an exact path/line/rule or commit/path/line/rule fingerprint. Semgrep **1.169.0** ran seven repository-owned blocking rules across **1,463** parsed PHP, JavaScript/TypeScript, and Python files and reported zero findings. Digest-pinned ZAP baseline DAST completed against a disposable Laravel/database boundary with zero failing findings and ten retained warnings documented in `docs/TESTING-AND-RELEASE-EVIDENCE.md`. The CI workflow now owns separate security and DAST jobs with 30-day evidence artifacts. Post-upgrade regression passed **984** backend tests and **15,220** assertions with one intentional skip, **93** Vitest files and **403** tests, TypeScript, the Vite production build, all **1,093** Pint targets, and the guarded Playwright lane with **28** passes and three deliberate skips; no disposable test database remained.

The repository-side edge contract is code-complete in `deploy/security/edge-policy.json` and `deploy/apache/`: blocking ModSecurity/OWASP CRS policy, request/method/path bounds, partner-ingress allowlist template, exact include verification, live TLS/header/path/TRACE probes, and fail-closed deployment preflight. The WAF checkbox remains open because current host inspection on 2026-07-13 found no active `security2_module` and the live TLS vhost does not yet include the policy. Production remains untouched. The one-time host preparation, partner-specific CIDR or mTLS decision, Apache validation, and live deployment probe must all pass before this item can close or PHI can be enabled.

Implemented ingress contract: only `/api/health` and the rate-limited mobile credential exchange are deliberately anonymous; a route-inventory regression test fails if any other `api/*` route lacks authentication or if any API route lacks a throttle. Formerly public case, block, provider, service, room, analytics, and process-map data now require a real browser session, and the anonymous block-schedule write now additionally requires the canonical OR-write capability. Every API mutation is globally size-bounded and accepts only JSON or `+json`, except the machine HL7 endpoint's explicit `application/hl7-v2`, `application/edi-hl7`, and `text/plain` contract. Trusted UUID request IDs are normalized before audit/integration processing and returned on responses. Both HL7 ingestion and the Eddy agent callback require persisted, active, exact-scope, non-wildcard machine tokens and token-keyed named limits. Production boot now fails closed unless sessions use database/Redis storage, encryption, Secure and HttpOnly cookies, Lax/Strict SameSite, `/` path, and a host-only/exact-host domain; feature tests verify cookie attributes and login-time session rotation.

**Acceptance:** Security headers are consistent at the public boundary; CSRF tests cover browser mutations; CORS does not grant arbitrary origins; no unsafe CSP fallback is needed by the production build.

#### P0-RBAC - Establish one authorization contract

- [x] Define canonical capabilities independent of scalar role names.
- [x] Reconcile `prod.users.role`, Spatie roles, Laravel Gates, Hummingbird personas, workforce roles, and Sanctum abilities through one normalization service.
- [x] Separate identity administration, privilege administration, integration configuration, integration operation, data stewardship, audit, and facility administration.
- [x] Require step-up authentication, reason, and append-only audit for privilege or source-go-live changes.
- [x] Add segregation-of-duties rules: the same actor may not author and approve production source activation, credential rotation, destructive replay, or outbound dispatch policy.
- [x] Add facility/organization scope to authorization queries and tests.
- [x] Add quarterly access-review workflow and evidence export.

Branch implementation evidence: `Capability`, `RoleCapabilityService`, and `AuthorizationScope` are the canonical contract; legacy Gates, Inertia permission props, Hummingbird personas, workforce assignments, and Sanctum abilities are adapters. Effective-dated `prod.user_access_scopes` rows establish organization/facility boundaries, while workforce membership may scope operational capabilities only. Recent local-password or verified OIDC-MFA step-up is mandatory for privilege, identity-provider, and governed integration changes. The append-only `governance.change_requests`, `change_decisions`, and `change_executions` ledger enforces independent approval in both service logic and a PostgreSQL trigger. Direct source activation and credential rotation are blocked; production activation, credential rotation, and destructive replay execute only when the subject and SHA-256 payload contract exactly match an unexpired approval. The outbound-policy action is present in the same dual-control contract for the later dispatcher implementation.

Quarterly certification is implemented at `/admin/access-reviews`. Opening one campaign per active calendar quarter freezes every active privileged user's scalar/Spatie roles, direct permissions, reconciled roles and capabilities, explicit scope grantor/reason/effective dates, workforce-assignment provenance, external identity providers, active token count, authentication recency, and risk flags. A primary reviewer certifies the population while an independent alternate reviews the primary reviewer's own access. The service and PostgreSQL trigger both deny self-certification or the wrong reviewer; every decision requires recent step-up and is immutable. A revoke decision atomically removes reviewed scalar/Spatie/direct access, revokes explicit scopes, browser sessions, remember tokens, and Sanctum tokens, and records append-only remediation evidence; protected and last-administrator accounts fail closed. Campaign completion is impossible with pending decisions or missing remediation. Deterministic JSON and formula-safe CSV evidence carry SHA-256 `ETag`, `Digest`, and `Content-Digest` values, are `no-store`, and create append-only export/audit records. Step-up-protected immutable cancellation releases a stranded quarter for a replacement campaign without erasing the original evidence.

Branch verification evidence after the safety, test-foundation, identity-lifecycle, Admin-cockpit, enterprise-scope, source-lifecycle, and onboarding/scheduled-activation tranches: each of two earlier simultaneous `php artisan test` processes passed **962** tests with **15,047** assertions and one intentionally skipped fixture-regeneration test. The current full gate passed **1,017** tests with **15,759** assertions and the same intentional skip; Vitest passed **97** files and **414** tests, and `npx tsc --noEmit`, Laravel Pint across all **1,123** PHP files, `git diff --check`, and the Vite production build passed. Route inventory now proves **290** `api/*` routes with zero missing rate limits and zero missing authentication outside the two reviewed public routes; the exact matrix pins all **31** Admin integration mutations to facility, source, and/or governed-change middleware. The build retains the existing advisory for a chunk larger than 500 kB and the existing stale Browserslist database warning. No disposable suffixed `zephyrus_test_*` database remained after the backend/browser proof. Production deployment and production browser smoke were not performed because this work is isolated on `feat/admin-production-safety` and no deployment was requested; the complete guarded Playwright lane passed **30** tests with three deliberate feature skips and retained `artifacts/release-evidence/browser/20260713T100938Z-browser-playwright.json`.

**Acceptance:** Capability tests cover allow and deny cases for scalar and Spatie inputs, tenant/facility scope, self-demotion, last-admin protection, and author/approver separation.

#### P0-TEST - Make validation trustworthy

- [x] Allocate a unique test database or schema set per PHPUnit/CI process; eliminate shared `zephyrus_test` migration races.
- [x] Make multi-schema reset deterministic and fail loudly when another process owns the target database.
- [x] Split unit, contract, integration, browser, migration, and conformance suites with documented isolation.
- [x] Add a CI guard proving tests cannot resolve the production database, secret directory, or production network endpoints.
- [x] Establish clean baseline commands and evidence retention for every release.

**Acceptance:** Two full backend suites can run concurrently without schema collisions; a clean focused Admin/integration suite is green and repeatable.

Branch implementation evidence: `tests/bootstrap.php` now runs a pre-Laravel `TestEnvironmentGuard` that requires the deterministic test key, loopback PostgreSQL, a `zephyrus_test*` database, isolated database provisioning, a test-only secret root, and only loopback or reserved `.test` application/OIDC/FHIR/Eddy/Arena endpoints and allowlists. Laravel's HTTP client rejects stray requests by default. `IsolatedTestDatabase` creates and removes a random database for every PHPUnit process. The browser lane uses a separately guarded `zephyrus_test_e2e*` lifecycle, a test-only seeded actor, real login, a directly owned loopback server process, and cleanup whose failure fails the lane. The CI workflow has one authoritative backend/frontend/Arena/browser pipeline, captures logs plus SHA-256 manifests, and retains its release evidence for 30 days. Commands, lane ownership, safety constraints, concurrency proof, and minimum release evidence are documented in `docs/TESTING-AND-RELEASE-EVIDENCE.md`.

Branch acceptance evidence: two complete backend suites ran simultaneously and each passed **962** tests, **15,047** assertions, and one intentional skip; both wrote independent evidence manifests and the final disposable-database inventory was empty. The isolated named lanes passed Unit **139/6,503**, Contract **58/1,618**, Integration **55/561** twice, Admin **156/1,239**, Migration **35/138**, and Conformance PHP **25/762** plus Arena pytest **34**. The current release proof passed Backend **1,017/15,759** with one intentional skip and Browser **30 passed / 3 skipped**; both removed their independently named databases. P0-TEST acceptance is met on the branch; CI must reproduce these gates before merge, and manual deployment remains a separate authorization.

### Phase 1 - Complete the Administration Cockpit

#### ADM-IA - Finish Admin navigation and readiness

- [x] Replace hard-coded section availability with `implemented`, `restricted`, `degraded`, `blocked`, and `ready` states plus capability and remediation metadata.
- [x] Add cards for Authentication Providers, System Health, Roles and Capabilities, Data Governance, Eddy Governance, and Audit/Compliance.
- [x] Add the balanced readiness strip defined in Section 3.
- [x] Add source/facility/tenant scope indicators and require scope selection before a scoped mutation.
- [x] Add an operator action queue for credential expiry, dead letters, mapping reviews, activation approvals, access reviews, and deployment blockers.
- [x] Add deep links preserving active scope and filter state.
- [x] Add empty-state actions that are safe and capability-aware; "run a CLI command" is not the primary production UX.
- [ ] Meet WCAG 2.2 AA for keyboard navigation, focus, status announcements, contrast, responsive tables, and non-color status encoding.

Branch implementation evidence: `AdminDashboardController` and `AdminReadinessService` publish a capability-filtered, read-only cockpit contract with 13 section cards, explicit five-state readiness, remediation metadata, balanced identity/health/access/integration/mapping/approval metrics, principal and organization/facility/source scope indicators, and a severity-sorted operator action queue. Restricted principals receive `null` identity/audit aggregates, no recent-event payload, and no health counts rather than learning a protected domain's posture from the landing page. Authentication Providers, System Health, Roles/Capabilities, Audit/Compliance, Data Governance, and the separately governed integration console have real targets; Eddy Governance is truthfully `blocked` rather than presented as operational. Action links preserve health status, integration tab filters, and the selected enterprise boundary. The integration audit tab exposes a sanitized governed-change ledger plus step-up- and segregation-of-duties-enforced approve/reject controls for authorized independent approvers.

The active-scope contract is explicit and fail closed: no role, including a global administrator, receives an automatic organization, facility, or source. The session payload is bound to the authenticated user and re-resolved on every request against the current active facility hierarchy, source membership, and effective-dated access grant. Revocation, account switching, stale hierarchy, malformed identifiers, and source mismatch clear or reject the boundary. Every Admin integration mutation declares `facility`, `source`, and/or `governed_change` middleware, and a route-inventory regression fails if that exact matrix changes silently. The React selector filters each level to a coherent hierarchy; source, endpoint, credential, FHIR, health, replay, governed-decision, and writeback controls cannot mutate a different source. Read summaries remain capability-wide and say so explicitly. The remaining ADM-IA item is a formal WCAG 2.2 AA audit/remediation pass.

#### ADM-IAM - Modernize user lifecycle

- [ ] Display identity source, external subject, group reconciliation state, MFA assurance if provided, last login, last meaningful activity, active sessions, and active tokens.
- [ ] Replace normal hard delete with deactivate, revoke, transfer ownership, and retention-aware purge.
- [ ] Add invite/JIT/SCIM provisioning states and prevent local password creation where SSO-only policy applies.
- [ ] Add role/capability and facility/unit assignment editors backed by canonical policy services.
- [ ] Add bulk deactivation and access-review decisions with preview and rollback-safe transactions.
- [ ] Redact email/IP data according to auditor capability and retention policy.

#### ADM-HEALTH - Build system health

- [x] Add `/admin/system-health` and `/admin/system-health/{key}`.
- [x] Observe database, replicas, queue depth/age, failed jobs, scheduler heartbeat, cache, session store, broadcast/Reverb, integration worker, Arena, Eddy, object storage, disk, backups, certificates, and configured dependencies.
- [x] Store health observations separately from configuration and source freshness.
- [ ] Show last observation, duration, freshness, status, error code, owner, runbook, and last incident without secrets or raw upstream responses. Observation evidence is shipped; incident association/history remains open.
- [x] Add bounded on-demand diagnostics with authorization, rate limit, correlation ID, and audit.
- [ ] Add alert routing and acknowledgement to the on-call system selected by deployment.

Branch implementation evidence: `SystemHealthService`, the `admin:observe-system-health` scheduled command, and append-only `governance.system_health_observations` provide a 14-component, PHI-free platform evidence model. The scheduler records a heartbeat synchronously every minute without relying on the queue; manual diagnostics cannot manufacture scheduler health, reuse the trusted request UUID as batch/correlation evidence, are separately capability-gated and rate-limited, and write an immutable audit event in the same transaction. Expired observations become `unknown` without rewriting history. Probes return only allowlisted counts, booleans, stable error codes, freshness, duration, owner, and runbook references: they do not call an EHR, advance a cursor, read backup contents, expose filesystem paths/private keys/secrets, or return raw upstream responses. The Admin pages provide status/freshness filters, non-color status labels, component drill-down, and bounded remediation guidance. Incident linkage/history and deployment-selected alert delivery/acknowledgement remain intentionally open.

#### ADM-POLICY - Govern Cockpit and Eddy policy

- [ ] Version Cockpit threshold policies with owner, scope, unit, direction, validation constraints, effective date, reason, preview, approval, and rollback.
- [ ] Detect duplicate/ambiguous metric keys and add filtering by domain/scope/status.
- [ ] Build `/admin/ai-providers` using Zephyrus/Eddy naming and the existing policy service.
- [ ] Govern model/provider capability, fallback order, cost limits, PHI eligibility, region, and surface routing.
- [ ] Add dry-run route simulation that stores no prompt or patient content.

### Phase 2 - Productionize Enterprise and Integration Governance

#### ENT-REG - Make enterprise topology authoritative

- [ ] Import and review the production organization, market, facility, service-line, location/space, capability, and transfer registry before source onboarding.
- [x] Add database FKs from integration sources to `hosp_org.organizations` and `hosp_org.facilities`; retain tenant/facility keys only as database-canonicalized compatibility projections during migration.
- [ ] Remove tenant/facility compatibility key columns after every downstream consumer uses canonical identifiers.
- [ ] Add effective dating, source-of-truth, external identifiers, ownership, and change history to enterprise entities.
- [ ] Add UI import preview, conflict review, commit, and readiness scoring.
- [x] Block source activation when its canonical organization/facility is missing, mismatched, or inactive.
- [ ] Block source activation when required locations/service lines are missing or unresolved after the onboarding contract declares them.

Branch implementation evidence: `integration.sources.organization_id` and `facility_id` now reference the authoritative enterprise tables. Migration backfill resolves exact existing keys and fails rather than silently carrying an unresolved active/live source. A PostgreSQL trigger rejects organization/facility mismatches, canonicalizes compatibility keys, and requires a canonical organization plus active facility for active/live states. Application creation derives IDs and keys from the selected facility; updates cannot move a source between enterprise boundaries. CLI and synthetic provisioning remain non-live unless an explicit canonical facility is resolved. Production enterprise import/review, richer entity history, and removal of compatibility keys remain open.

#### INT-LIFECYCLE - Add a governed source lifecycle

- [x] Implement source states: `draft -> discovery -> configured -> validating -> approved -> scheduled -> live -> degraded/suspended -> retired`.
- [ ] Separate lifecycle state, protocol health, data freshness, conformance status, contract status, and incident status.
- [x] Add source onboarding wizard: organization/facility, system/vendor/version, protocol/profile, owner/steward, network, data class, purpose, contract/BAA/DUA, PHI permission, retention, SLO, credentials, test evidence, cutover, rollback.
- [x] Add append-only configuration versions and diff/preview; never mutate the effective production configuration without a new version.
- [x] Bind configuration application and production activation to exact immutable versions with step-up and author/approver separation.
- [x] Add future-dated scheduled activation windows and a scheduler-owned execution lease.
- [x] Add evidence pointers for contracts, BAAs, conformance reports, vendor approvals, change tickets, and rollback artifacts without storing confidential documents in audit JSON.
- [x] Add maintenance windows, contact/escalation roster, support entitlement, and vendor incident identifiers.
- [x] Add truthful support badges: `template`, `implemented`, `conformance-tested`, `vendor-sandbox-tested`, `customer-UAT`, `production-certified`, `live`.

Branch implementation evidence: `integration.source_configuration_versions` is the immutable authority for non-secret source configuration; `integration.sources` is now a guarded read projection with an optimistic-concurrency version pointer. `integration.source_lifecycle_events` records every state transition against the exact effective version. Both ledgers reject update/delete at the database layer, while projection triggers reject direct mutation or status/lifecycle drift. Backfill creates version 1 and an initial lifecycle event for every existing source. Pre-approval edits create and apply a new version, invalidate prior validation, and require a reason plus expected version. Protected sources create non-effective proposals; applying one requires step-up, an independently authored decision, exact hash matching, and a second activation cycle.

`integration.source_onboarding_versions` adds immutable, optimistic-concurrency-controlled system/profile, ownership, network, classification, purpose, PHI basis, retention, credential strategy, conformance, support, maintenance, contact, and SLO authority. Append-only `source_evidence_records` store sanitized references and hashes rather than confidential documents. `source_readiness_assessments` evaluate the exact future activation time across enterprise, effective configuration, endpoint and credential authority, onboarding, legal evidence, conformance, operations, and SLO requirements. The input hash binds exact configuration/onboarding versions, current evidence, endpoint-address hashes, credential-reference hashes, and expiry state; support badges are derived from passed checks instead of operator claims.

Future activation uses a separately governed `schedule_production_source_activation` contract that binds organization/facility, immutable configuration and onboarding hashes, the readiness assessment/input hash, UTC cutover bounds, requested timezone, and desired state. Independent approval moves the source to `scheduled`; a minute-level, single-server, non-overlapping scheduler claims due rows with database leases and re-evaluates readiness before `live`. Drift, expiry, cancellation, stale or exhausted leases, scope mismatch, or a changed endpoint/credential fails closed and releases the lifecycle where safe. Ordinary child endpoint and credential mutation is blocked for protected lifecycle states; the separately approved credential-rotation path remains exact and invalidates stale readiness. The UI captures the three required contact roles and one recurring maintenance window while the API model accepts bounded multi-item rosters/windows. Protocol health and freshness remain separate projections, but conformance and incident lifecycles are not yet implemented, so that broader separation item remains open.

#### INT-SECRET - Complete credential and network governance

- [x] Introduce a `SecretProvider` contract and implementations for the providers Zephyrus actually supports.
- [x] Implement version-aware reference resolution for Vault KV, AWS Secrets Manager, GCP Secret Manager, and Azure Key Vault; expose provider bootstrap readiness without exposing bootstrap credentials.
- [x] Preserve file-reference support for sealed single-host deployments with root, ownership, mode, group, size, traversal, and symlink controls.
- [x] Add immutable credential versions, provider version/lease/expiry, bounded rotation overlap, validation evidence, last use, and revocation state without exposing the secret.
- [x] Add certificate chain, subject/SAN, issuer, fingerprint, validity, key usage, extended key usage, and client certificate/private-key match metadata.
- [ ] Add explicit mTLS server-peer trust/pinning policy metadata where a partner contract requires controls beyond normal CA and server-name verification.
- [x] Add network route objects for exact endpoint, DNS policy, IPv4/IPv6 CIDR allowlist, port, HTTPS proxy, VPN/private-link/direct-connect classification, client mTLS credential, egress policy, and environment.
- [x] Run DNS-rebinding-safe URL checks at configuration and connection time, pin the selected target and proxy addresses for the request, reject redirects, and persist only address fingerprints.
- [x] Compute visible credential rotation states at the configurable 90/60/30/14/7-day thresholds.
- [ ] Deliver threshold alerts through the INT-OBS acknowledgement, suppression, escalation, and incident lifecycle rather than treating a console badge as an alert.

Branch implementation evidence: `SecretProviderRegistry` is the only runtime resolver authority for `file://`, `vault://`, `aws-secretsmanager://`, `gcp-secretmanager://`, and `azure-keyvault://` references. Provider-specific allowlists and HTTPS/no-redirect behavior constrain remote resolution; AWS requests use SigV4, GCP responses verify CRC32C when supplied, and provider responses must yield an immutable version before production readiness passes. File and GCP bootstrap files remain beneath the configured secret root and pass ownership, mode, group, size, and symlink checks. Bootstrap support currently covers a sealed local file, Vault token, AWS access/session credentials, GCP access token or guarded service-account file, and Azure access token or service principal; cloud workload identity/managed-identity adapters remain an optional hardening increment and are not claimed.

`integration.source_credential_versions` and `credential_validation_observations` are append-only authorities. The source credential row is a trigger-guarded current projection; linked SMART runtime metadata must exactly match it. Runtime resolution permits the previous version only during an explicitly bounded overlap and records last use. Validation binds source, authority hash, provider version/lease/expiry, reference fingerprints, certificate metadata, key-pair match, evaluation time, and requirement results without persisting resolved values. Rotation request, independent decision, and execution use the existing step-up/two-person governed-change ledger and fail closed unless the operator re-enters the exact approved payload.

`integration.source_network_routes` binds one non-retired route to an exact source endpoint, source environment, host, port, transport classification, DNS/CIDR policy, proxy, egress key, server name, and optional same-source mTLS authority. Every configuration validation and every guarded connection re-resolves the target and proxy, enforces public/private/allowlist policy, disables redirects, and passes deterministic cURL resolution plus in-memory certificate/key blobs to the HTTP client. Append-only observations store counts and SHA-256 address/policy fingerprints, never addresses, PEM data, tokens, or secret values. Production connections require a validated governed route; the legacy public-host policy remains only as a non-production fallback. The operator procedure and failure model are in `docs/operations/ADMIN-CREDENTIAL-NETWORK-GOVERNANCE-RUNBOOK.md`.

Verification evidence for this branch state: the focused credential/network API suite passes **5 tests / 95 assertions**, including provider secrecy, exact public CIDR policy, catch-all/proxy-path/server-name rejection, DNS rebinding, immutable authority, bounded overlap, and in-memory mTLS. The authoritative backend gate passes **1,032 tests / 15,905 assertions** with one intentional skip; Vitest passes **98 files / 419 tests**; TypeScript, Vite production build, `git diff --check`, and Pint across **1,148 PHP files** pass. The authenticated Chromium lane passes **31 tests** with three deliberate feature skips, including the real credential/network Admin page. Arena passes **34 tests**. Composer/npm/Python dependency audits, full-history and working-tree secret scans, Semgrep, and the edge-security contract pass with no findings. Production was not changed.

#### INT-STORAGE - Harden raw and canonical storage

- [ ] Move PHI-bearing raw bodies out of ordinary JSONB into encrypted object/blob storage or application-encrypted columns with per-environment keys; store only pointer, hash, classification, and receipt metadata in the ledger.
- [ ] Partition high-volume raw, canonical, provenance, audit, and health-history tables by time and/or tenant as appropriate.
- [ ] Define retention, purge, legal hold, and replay eligibility per source/data class.
- [x] Add cryptographic integrity verification and periodic sample restore.
- [x] Add quarantine distinct from dead letter for malware, policy, consent, or unsafe-content failures.
- [x] Ensure error messages, job payloads, failed-job rows, logs, traces, and alerts cannot contain raw PHI or tokens.

**Branch implementation state:** INT-STORAGE now has a concrete, fail-closed payload authority rather than a design-only placeholder. `ClinicalPayloadStore` encrypts each JSON body with a random per-object DEK, XChaCha20-Poly1305 authenticated encryption, source/kind/UUID-bound AAD, optional gzip, and a version-pinned external KEK resolved through the supported secret providers. `raw.payload_objects` records opaque scope, classification, storage, integrity, immutable provider-version, retention, legal-hold, and tombstone metadata; append-only object/quarantine/backfill event ledgers own lifecycle projection. Database triggers enforce exact source authority and prevent protected raw, normalized, FHIR, canonical, or outbound writeback bodies from remaining in ordinary JSONB.

New Patient Flow HL7, synthetic connector/import, Epic SMART FHIR, canonical-event, and outbound writeback draft paths persist and verify the encrypted object before committing a pointer. Models and replay hydrate through the decrypting authority and retain bounded legacy fallback during migration. The resumable source/time-bounded backfill inventories all five authorities, leases per item, detects drift, verifies decrypt/hash before clearing the legacy body, and records only stable codes, counts, and hashes. Retention preview/execution honors legal holds, replay/dead-letter/backfill dependencies, and unresolved writeback drafts; successful deletion leaves a tombstone, while provider failure emits durable `deletion_failed` evidence. Daily bounded integrity verification and hourly retention execution are scheduler-configured.

`/admin/data-protection` provides minimum-necessary, scope-aware provider readiness, five-target coverage, backfill, retention, legal-hold, quarantine, integrity, and partition readiness without content, object paths, hashes, wrapped keys, or key references. Organization, facility, and capability-wide views remain aggregate and read-only. Selecting one exact source reveals at most 50 opaque, non-deleted object authorities, 50 open quarantine authorities, stable deletion blockers, and 50 relevant governed-change records. The UI never fetches or renders a clinical body.

Legal-hold apply/release, exceptional object purge, quarantine release/terminal purge, and post-restore integrity recovery now use explicit `GovernedAction` contracts. Request, decision, and execution each require recent step-up; the author cannot approve; source and subject are rebound from immutable authority; and execution hashes the current classification, status, hold, retention, dependency, operation, and derived cryptographic-authority contract against the unexpired approval. Every lifecycle event records the governing change UUID. PostgreSQL transition triggers independently require the approved, unexpired, exact action/subject for governed events and reject ungoverned hold/recovery/release/purge events plus invalid direct deletion transitions. Object purge cannot bypass a legal hold, quarantine authority, pending/failed projection, open dead letter, unresolved backfill, or non-terminal writeback. A storage deletion failure commits `deletion_pending`, append-only `deletion_failed`, and a failed governed execution, preserving the same exact approval for bounded retry. Integrity recovery accepts no Admin upload: an operator must restore the exact immutable object to its existing provider key, after which the approved action verifies ciphertext, key version, authenticated decryption, plaintext hash, and JSON structure before returning the object to `ready`.

Quarantine is separate from dead letter and blocks decryption. Its bounded Admin record contains only opaque IDs, category/code, detector, timestamps, legal-hold state, and stable dependency codes. Release and terminal purge are distinct exact contracts; terminal purge deletes the encrypted object and retains object/quarantine tombstones. Local fail-closed and S3-compatible object disks are implemented; production local use is blocked by default, S3 defaults to private `aws:kms` server-side encryption, and a random non-PHI write/read/delete probe proves current provider reachability.

The negative-output boundary is now implemented as defense in depth rather than a logging convention. `ClinicalContentGuard` recognizes reserved test canaries plus HL7 v2, FHIR JSON/XML, X12, CDA/C-CDA, NCPDP SCRIPT, DICOM/DICOMweb, vendor JSON/CSV clinical envelopes, bearer/basic/JWT/cookie/API credentials, and private keys. It is a tripwire, not a de-identification service. Global JSON failure middleware replaces every validation message with a fixed non-content sentence and suppresses tainted error/exception/trace/debug fields without breaking authorized optimistic-concurrency recovery projections. All queue payloads pass the tripwire before dispatch; the `integrations` queue additionally accepts only declared `ClinicalPayloadSafeQueueJob` arguments and encrypted jobs. Integration failure middleware discards the original exception chain and failed-job serialization receives only a stable code.

PostgreSQL independently rejects clinical content in dead letters, projection errors, configuration/user audit, governed-change and access-review evidence, system-health observations, every queued-job row, and every failed-job row. Existing rows are scanned before the migration installs triggers. Every configured Monolog lane and Laravel's emergency fallback redact messages, context, exception arguments, and trace arguments while retaining bounded file/line/class/function frames. Teams/APNs/log push alerts, Hummingbird alert fan-out, governance reasons/metadata, quarantine details, access-review evidence, and incident-control-plane projections are guarded or allowlisted. Release-evidence capture scans the complete command/output stream before persistence, preventing multi-line FHIR/XML or trailing key material from escaping a line-oriented filter. No application trace exporter is currently configured; enabling one remains part of INT-OBS and must consume this same safe-attribute contract before activation.

The migration, partitioning, and institution-owned retention/policy checklist items intentionally remain open. Production data has not been inventoried or backfilled; legacy fallback has not completed its source-by-source soak/cutover; source/data-class policy ownership is not institutionally signed; Azure Blob/GCS object adapters, cloud-KMS data-key APIs, KEK rewrap, WORM policy, and recovery topology are not claimed; scanner/provider evidence and production operator exercises for the new quarantine/lifecycle controls have not been retained; partition migrations have not been benchmarked/executed; and production backup/restore evidence is still required. The exact operator and release gate is `docs/operations/ADMIN-CLINICAL-PAYLOAD-PROTECTION-RUNBOOK.md`.

Verification evidence for this branch state: the focused content-taint matrix passes **10 tests / 473 assertions** across HTTP errors and validation, all queue names, encrypted integration serialization, stable failed-job exceptions, PostgreSQL tripwires, configured and emergency logs/traces, alerts, user/integration audit, incident projections, and multi-line release evidence. The authoritative backend gate passes **1,057 tests / 16,654 assertions** with one intentional fixture-regeneration skip and **97/97** GET-route smoke. Vitest passes **100 files / 423 tests**; TypeScript, Vite production build, `git diff --check`, and Pint across **1,184 PHP files** pass. The isolated authenticated Chromium lane applies the full migration chain and passes **32 tests** with three deliberate feature skips, including aggregate-to-exact-source Data Protection behavior and no clinical-content exposure. Composer, npm, Arena Python, and Eddy Python dependency audits; the **754-commit** history and working-tree secret scans; Semgrep across **1,536** targets; and the edge-security contract pass with no findings. Digest-pinned ZAP DAST has **zero failing findings** and the same ten documented local-edge warnings. Production was not changed, and no production inventory, backfill, retention, purge, restore, or deployment action was performed.

**Remaining dependency-ordered exit sequence:**

1. Rehearse the migration and provider bootstrap against a production-volume clone, then run source-bounded inventory/backfill with zero unexplained mismatch and retain a real isolated backup/restore sample.
2. Benchmark and execute online tenant/time partition migration for raw, canonical, provenance, audit, and health-history authorities, including indexes, retention detach/drop, replication, backup, query-plan, and rollback evidence.
3. Implement the deployment-selected object/KMS identity and recovery adapters, KEK rewrap/version migration, immutability/replication controls, and provider-outage behavior required by the signed architecture.
4. Exercise the implemented hold, purge, quarantine release/purge, failed-delete retry, and exact-object recovery paths with deployment-owned provider/scanner evidence; retain deletion proof without clinical content.
5. Complete source-by-source mixed-authority soak, disable legacy reads only after zero legacy bodies, execute the authenticated browser/full release/security gates, and retain the approved forward-repair/rollback decision before production cutover.

INT-STORAGE exits only when new PHI-bearing receipts cannot persist a plaintext body in ordinary JSONB; existing rows are reconciled to the new authority; replay/projection remain idempotent; retention, hold, quarantine, restore, and key-rotation drills pass; and logs/failed jobs/evidence exports remain PHI-free under forced failures.

#### INT-OBS - Add operational history and SLOs

- [x] Persist health observations, not only the latest status.
- [x] Add per-source SLO definitions for availability, freshness, completeness, latency, error rate, acknowledgement, and reconciliation variance.
- [ ] Add queue age/backpressure, retry budget, circuit breaker, rate-limit state, and vendor maintenance awareness.
- [ ] Add OpenTelemetry-compatible metrics/traces with PHI-safe attributes and correlation from receipt to projection/outbound ACK.
- [ ] Add alerts, acknowledgement, suppression/maintenance, escalation, incident link, and post-incident review.
- [ ] Add source health to every downstream Cockpit/Hummingbird/Eddy signal contract.

**Branch implementation state:** `integration.health_observations` is an append-only per-source history ledger (UPDATE/DELETE rejected by trigger) recording window bounds, observation/freshness timestamps, status, stable error codes, duration, runtime state, and origin; `SourceObservabilityService::observe()` writes history and projects a monotonic current status, and `POST /api/admin/integrations/sources/{source}/observations` is capability- and scope-gated (`operateIntegrations` + `admin.scope:source`). `integration.source_slo_definitions` normalizes all seven SLO metrics one-to-one against the governing onboarding version, versioned via `previous_definition_id`, append-only, with backfill from existing onboarding versions. `integration.slo_breaches`/`slo_breach_events` record opened/continued/suppressed/resumed/acknowledged/recovered/escalated/incident_linked/reviewed transitions, and maintenance windows suppress notification without erasing the breach (`SourceObservabilityControlPlaneTest`). The unchecked items remain genuinely open: queue backpressure/retry-budget/circuit-breaker/rate-limit analysis is not computed; no OpenTelemetry exporter is configured (it must consume the INT-STORAGE safe-attribute contract before activation); breach records have no delivery/escalation/incident-workflow behind them beyond the ledger states; and downstream Cockpit/Hummingbird/Eddy signal contracts do not yet carry source health.

### Phase 3 - Complete the Core Clinical Transaction Engines

#### FHIR-CORE - Generalize the FHIR implementation

- [ ] Refactor `EpicSmartFhirClient` into a vendor-neutral SMART Backend FHIR client plus an Epic conformance profile; preserve Epic-specific behavior outside the protocol core.
- [ ] Capture full CapabilityStatement details: FHIR version, formats, profiles, interactions, operations, compartments, search parameters, includes/revincludes, batch/transaction, subscriptions, and Bulk Data endpoints.
- [ ] Capture `.well-known/smart-configuration`, authorization/token endpoints, capabilities, algorithms, scopes, and issuer validation.
- [ ] Support paging, `_since`, `_lastUpdated`, `_count`, conditional requests/ETags, 429/Retry-After, bounded retries, OperationOutcome, deletion/tombstones, history, partial bundles, and safe next-link origin validation.
- [ ] Replace the hard-coded Encounter/Location poll allowlist with a governed resource profile registry.
- [ ] Implement R4 mappers and versioned crosswalks for Patient, RelatedPerson, Encounter, Location, Organization, Practitioner, PractitionerRole, HealthcareService, Coverage, Account, ServiceRequest, Specimen, Observation, DiagnosticReport, ImagingStudy, Procedure, Appointment, Schedule, Slot, MedicationRequest, MedicationDispense, MedicationAdministration, Task, Communication, DocumentReference, Device, and Provenance in dependency order.
- [ ] Implement Bulk Data kickoff, status polling, manifest validation, secure NDJSON download, checksum, import, error file handling, cancellation/delete, and retention.
- [ ] Implement R4 subscription/backport support where available, with signed notification verification and recovery polling.
- [ ] Add daily count/hash/sample reconciliation against source search/Bulk exports.
- [ ] Add a package/version registry and HL7 validator execution for every supported profile.

#### HL7-CORE - Complete the v2 boundary

- [ ] Keep Laravel behind a real interface-engine/gateway boundary; terminate MLLP/TLS/VPN and trading-partner routing outside the web application.
- [ ] Add interface-engine route registry, sender/receiver/facility validation, message profile/version, Z-segment policy, and size/encoding rules.
- [ ] Persist technical and application ACK/NACK, control IDs, sequence, retry, duplicate, and final disposition.
- [ ] Add conformance profiles and fixtures for ADT A01-A13, A17, A28/A31, A37/A40/A43/A47 and customer-used correction/cancel variants.
- [ ] Safely implement patient merge, unmerge, identifier change, encounter correction, cancel admit/transfer/discharge, and out-of-order movement handling.
- [ ] Add message families for orders/results, scheduling, medication, documents, billing signals, master files, immunization, and public health as prioritized in Phase 4.
- [ ] Add per-source field mapping and Z-segment plug-ins without contaminating the shared parser.
- [ ] Add source-vs-Zephyrus reconciliation and replay from the interface engine using stable original identifiers.

#### ID-TERM - Build identity and terminology services

- [ ] Define namespace-qualified identifiers for patient, encounter, practitioner, location, order, accession, study, device, coverage, claim, and external task.
- [ ] Add deterministic match first; any probabilistic matching must be explainable, thresholded, reviewed, and prohibited from automatic destructive merge.
- [ ] Add match-candidate, conflict, merge, unmerge, alias, and survivorship records with downstream re-projection.
- [ ] Add patient/facility/provider query adapters for PIXm/PDQm/PMIR where required.
- [ ] Add terminology package/version registry, import, validate, map, review, promote, retire, and impact-preview workflows.
- [ ] Add unit normalization with UCUM and explicit rejection/quarantine for unsafe conversions.

#### PROJ-CORE - Complete canonical projection and reconciliation

- [ ] Expand canonical event vocabulary with versioning and ownership; prevent two projectors from claiming one event type.
- [ ] Route Patient Flow ingress through the shared projection dispatcher while preserving the existing endpoint/receipt contract.
- [ ] Use per-projector offsets and idempotent upserts for all domains.
- [ ] Add projection version to provenance and support bounded re-projection after mapper upgrades.
- [ ] Add domain invariants: one active bed per encounter, one active encounter per bed, valid movement sequence, source counts within tolerance, no result before order/specimen unless profiled, no impossible timestamps.
- [ ] Build reconciliation dashboards and operator workflows for mismatches, not only dead letters.

### Phase 4 - Deliver Production Connector Packs

Each pack requires source inventory, protocol profile, fixtures, normalizer, canonical mapping, projector, provenance, reconciliation, health/SLOs, DLQ/replay, conformance report, vendor sandbox/UAT, runbook, cutover, rollback, and Admin status. Template rows alone do not count.

#### Pack 1 - EHR census and identity

- [ ] First EHR HL7 ADT production profile and interface-engine route.
- [ ] FHIR R4 Patient/Encounter/Location/Organization/Practitioner baseline.
- [ ] Bed/census reconciliation and source freshness in Cockpit/Hummingbird/Eddy.
- [ ] Merge/unmerge and correction safety drill.

#### Pack 2 - ED, perioperative, scheduling, and transport

- [ ] ED milestone mapper and reconciliation.
- [ ] SIU/Appointment/Schedule/Slot perioperative schedule ingestion.
- [ ] Procedure/case status and anesthesia/PACU milestone mapping.
- [ ] External transport/EMS vendor adapter selected from the customer's actual systems.
- [ ] End-to-end operational timeline across admit, procedure, transport, and bed impact.

#### Pack 3 - Lab, pathology, blood bank, radiology, cardiology

- [ ] Merge and deploy the separately validated ancillary spine before source activation.
- [ ] LIS order/specimen/result/correction/cancel/critical-result profiles.
- [ ] Pathology case, specimen/block/slide, preliminary/final/amended, and frozen-section profiles.
- [ ] Blood-bank order/product/status interfaces with strict clinical-safety boundaries; Zephyrus remains operational visibility, not the transfusion system of record.
- [ ] RIS order/status/report profiles and DICOMweb metadata/worklist adapter.
- [ ] Cardiology diagnostic/procedure status profiles where selected.

#### Pack 4 - Pharmacy and medication operations

- [ ] EHR/pharmacy order, verification, dispense, administer, cancel, and reconciliation mapping.
- [ ] NCPDP SCRIPT current/trading-partner profile and 2023011 transition plan.
- [ ] RTPB/Formulary support or vendor adapter for discharge medication barriers.
- [ ] RxNorm/NDC/local drug mapping workflow.
- [ ] Keep prescribing, dispensing, and medication-administration authority in the source systems.

#### Pack 5 - Workforce, EVS, facilities, devices, and supply chain

- [ ] First WFM source for roster, qualification, assignment, schedule, availability, callout, overtime, and agency status.
- [ ] SCIM only for application identity provisioning; never treat it as the clinical workforce assignment source without explicit policy.
- [ ] EVS/bed-cleaning and facilities work-order connectors.
- [ ] RTLS/device gateway with explicit asset identity and location quality.
- [ ] IEEE 11073/IHE PCD device observations only through an approved gateway and use-case allowlist.
- [ ] ERP/supply adapter for operational shortages and procedure readiness, using GS1/X12/vendor APIs as available.

#### Pack 6 - Payer, HIE, TEFCA, documents, public health

- [ ] X12/clearinghouse adapter for eligibility, authorization, claim/remit/status signals selected by business need.
- [ ] Da Vinci CRD/DTR/PAS readiness and CMS-0057 API alignment; account for CMS enforcement discretion around all-FHIR prior authorization.
- [ ] PDex/CARIN/Formulary/Plan-Net support where payer exchange is in scope.
- [ ] C-CDA/DocumentReference ingestion, document metadata indexing, security labels, and retention.
- [ ] HIE/QHIN onboarding through the customer's participant/subparticipant relationship, purpose-of-use, consent, query, retrieve, and audit requirements.
- [ ] eCR, ELR, immunization, syndromic, or other public-health connectors only for jurisdictions/customers that require them.

### Phase 5 - Governed Outbound and Transaction Completion

- [ ] Define outbound state machine: `draft -> reviewed -> approved -> queued -> sent -> transport_acknowledged -> application_accepted/rejected -> reconciled/failed/compensated`.
- [ ] Add immutable delivery attempts, request hashes, endpoint/config versions, actor/approver, idempotency, technical ACK, application ACK, and source response correlation.
- [ ] Add vendor-neutral dispatch contracts and adapters for FHIR Task/ServiceRequest/Communication, HL7 v2, vendor REST/webhooks, Direct/IHE documents, and administrative transactions where authorized.
- [ ] Validate target capability and effective credential immediately before send.
- [ ] Add dual control for production activation, high-impact writeback, bulk replay, and any identity merge.
- [ ] Make retry policies transaction-aware; never blindly retry a clinically or financially state-changing request without idempotency evidence.
- [ ] Add compensation/manual-repair workflow and prevent "sent" from being treated as "accepted."
- [ ] Ensure Eddy can create drafts and explain evidence but can never approve or dispatch.

### Phase 6 - Operations, Scale, and Resilience

- [ ] Separate integration queues by protocol/source/priority and set per-source concurrency, backpressure, timeout, and retry budget.
- [ ] Run workers under least-privilege identities with supervised restart, readiness, graceful drain, and deploy compatibility checks.
- [ ] Add HA/singleton rules for scheduled pollers and replay coordinators.
- [ ] Add PostgreSQL/object-store capacity planning, indexes, partition maintenance, vacuum/analyze, retention, encryption, and restore tests.
- [ ] Define customer-approved SLOs. Suggested initial targets: 99.9% accepted-message availability, ADT receipt-to-projection p95 <= 30 seconds, alert within 5 minutes of a hard stale threshold, zero unaccounted message loss, and reconciliation variance within the signed source contract.
- [ ] Define RPO/RTO per data class and test backup restore plus replay recovery quarterly.
- [ ] Add blue/green or canary activation by source configuration, not alternate deployment scripts.
- [ ] Add feature flags and rollback for every new protocol/resource/projector.
- [ ] Add 24x7 on-call ownership before a feed is declared production-live.

---

## 8. Proposed Code and Schema Seams

Extend the current architecture rather than replacing it.

### 8.1 Existing seams to keep

- `resources/js/Pages/Admin/Dashboard.tsx`
- `app/Http/Controllers/Admin/AdminDashboardController.php`
- `resources/js/Pages/Integrations/Index.tsx`
- `resources/js/features/integrations/*`
- `app/Integrations/Healthcare/Contracts/*`
- `app/Integrations/Healthcare/Services/CanonicalEventWriter.php`
- `app/Integrations/Healthcare/Services/ProjectionDispatcher.php`
- `app/Integrations/Healthcare/Services/IntegrationControlPlaneService.php`
- `app/Integrations/Healthcare/Services/IntegrationConfigurationService.php`
- `app/Security/Network/IntegrationUrlPolicy.php`
- `integration`, `raw`, `fhir`, `flow_core`, `prod`, `ocel`, and `star` schema roles

### 8.2 Refactors and additions

| Seam | Change |
| --- | --- |
| Authentication | Replace production `SessionAuthMiddleware`; add production auth policy middleware, session/token service, protected-account service, and Admin provider page. |
| Authorization | Add `RoleCapabilityService` and policies with organization/facility scope; Gates become adapters to the canonical service. |
| Source governance | Add `SourceLifecycleService`, `ConfigurationVersionService`, `IntegrationApprovalService`, and go-live checklist evaluator. |
| Secret providers | Add `SecretProvider` contract and provider registry; make `IntegrationSecretReferenceResolver` dispatch only to installed providers. |
| FHIR | Rename/generalize the protocol client; add vendor profiles, profile/package registry, mapper registry, Bulk Data service, subscription service, and reconciliation jobs. |
| HL7 | Add conformance profile registry, ACK ledger/service, interface-engine route model, message-family registries, and source-specific mapping plug-ins. |
| Other protocols | Add protocol modules for DICOMweb, X12/clearinghouse, NCPDP, IHE/documents, and vendor REST/webhook/file adapters only when a real pack is scheduled. |
| Identity/terminology | Add explicit services and review queues; do not place matching or terminology logic inside controllers/projectors. |
| Observability | Add health history, SLO, breach, alert, incident, and conformance-result models/services. |
| Outbound | Add dispatcher registry, delivery attempt/ack models, reconciliation, and compensation service behind approval. |

### 8.3 Additive schema plan

Prefer compatibility migrations and backfills. Do not weaken current constraints to accommodate old test or demo data.

- `integration.source_configuration_versions`
- `integration.source_lifecycle_events`
- `integration.source_approvals`
- `integration.source_evidence`
- `integration.source_slo_definitions`
- `integration.health_observations`
- `integration.slo_breaches`
- `integration.network_routes`
- `integration.credential_versions`
- `integration.certificate_observations`
- `integration.conformance_profiles`
- `integration.conformance_runs`
- `integration.bulk_export_jobs`
- `integration.bulk_export_files`
- `integration.subscription_channels`
- `integration.acknowledgements`
- `integration.delivery_attempts`
- `integration.reconciliation_runs`
- `integration.reconciliation_variances`
- `integration.identity_match_candidates`
- `integration.terminology_map_versions`
- `integration.terminology_map_approvals`
- `raw.quarantine_items`
- organization/facility FKs on integration source/config/runtime records
- partition, retention, and encrypted-payload metadata on raw/canonical/provenance/audit tables

---

## 9. First 90-Day Delivery Sequence

### Days 0-14 - Safety and truth

1. Complete P0-AUTH and P0-WEB.
2. Deactivate/rotate demo credentials and prove anonymous denial on production.
3. Make tests process-isolated and establish a green Admin/integration baseline.
4. Define canonical capabilities and protected-account rules.
5. Import a non-PHI organization/facility registry and correct Admin `restricted` status semantics.

**Exit gate:** No anonymous admin content, no wildcard browser trust, no production demo auto-login, repeatable tests, organization/facility scope exists.

### Days 15-30 - Complete the Admin cockpit

1. Ship Authentication Providers, System Health, and Roles/Capabilities read views.
2. Add Admin readiness metrics and action queue.
3. Link the existing integration console for authorized roles.
4. Add user deactivation/session-token revocation and access-review evidence.
5. Add Cockpit policy version/preview/rollback foundation.

**Exit gate:** Every Admin card is functional, restricted, or intentionally deferred with an owner; no misleading unavailable state.

Branch progress on 2026-07-13: Days 15-30 items 1-4 are implemented at branch level. The new System Health and Roles/Capabilities read views, readiness/action queue, governed integration approval target, identity deactivation/session-token revocation, and immutable access-review evidence are covered by backend, frontend, and authenticated browser tests. Item 5 remains open, as does the dedicated Eddy governance surface; therefore the Days 15-30 exit gate is not yet declared complete.

### Days 31-60 - Govern the integration lifecycle

1. Add source configuration versions, approvals, evidence, organization/facility FKs, SLOs, and health history.
2. Implement the onboarding wizard and two-person production activation.
3. Complete secret-provider truthfulness and rotation visibility.
4. Encrypt/externalize raw PHI payload storage and add retention/quarantine.
5. Add source-specific telemetry, alerting, and downstream freshness contracts.

Branch progress on 2026-07-13: canonical source organization/facility FKs, explicit active-scope selection, exact mutation enforcement, append-only configuration/onboarding/credential versions and lifecycle events, guarded projections, exact readiness/evidence binding, truthful support and provider-bootstrap badges, version-bound dual-control source/configuration/credential/replay boundaries, future-dated lease-protected activation, bounded credential overlap, certificate validation, connection-time network authority, and scoped lifecycle/onboarding/credential/network UI are complete at branch level. This does not complete Days 31-60: raw-PHI storage hardening, explicit partner server-peer pinning when required, persisted SLO/health enforcement history, alert delivery/acknowledgement, and downstream freshness contracts remain open.

**Exit gate:** A source can move from draft through approved sandbox validation without CLI-only steps or unaudited configuration.

### Days 61-90 - First institutional integration candidate

1. Generalize the FHIR client and pin the selected EHR sandbox profile.
2. Complete the real HL7 interface-engine boundary and ACK ledger.
3. Validate ADT + Patient/Encounter/Location against the selected source.
4. Complete bed/census reconciliation and freshness propagation.
5. Run performance, failure, replay, merge/correction, cutover, rollback, and incident drills.

**Exit gate:** A named vendor/version/source/facility has a signed conformance matrix, zero unresolved critical security findings, successful UAT, operational ownership, and a go/no-go decision. This does not automatically authorize PHI for any other source.

---

## 10. Validation and Conformance Program

### 10.1 Automated gates

- PHP unit and feature tests with isolated databases.
- React interaction, accessibility, responsive, and error-state tests for every Admin/integration tab and form.
- Route and capability matrix tests for anonymous, frontline, facility admin, data steward, integration operator, integration admin, identity admin, auditor, and superuser.
- Migration up/down/compatibility and production-data backfill tests.
- Secret/PHI leakage tests covering responses, audit, logs, jobs, failed jobs, traces, alerts, and exports.
- Idempotency, duplicate, ordering, retry, circuit-breaker, DLQ, replay, reconciliation, and compensation tests.
- Property/fuzz tests for HL7 parsing, FHIR bundles, DICOM metadata, and file adapters.
- Load/soak tests at agreed peak plus recovery after upstream throttling/outage.
- Backup/restore, key rotation, credential expiry, certificate expiry, and disaster-replay drills.

### 10.2 Standards and partner conformance

- HL7 FHIR validator against the exact core/US Core/Da Vinci/IHE packages.
- Inferno or equivalent certified/API test kits where applicable.
- HL7 v2 conformance profiles, negative ACK tests, vendor Z-segment fixtures, and trading-partner certification.
- IHE Gazelle/profile testing where an IHE exchange is claimed.
- DICOM conformance statement review plus QIDO/WADO/STOW/UPS test cases where supported.
- Licensed X12/NCPDP validation and clearinghouse/trading-partner companion-guide testing.
- Public-health jurisdiction onboarding and acknowledgement testing.
- Vendor sandbox, customer UAT, parallel run, and signed reconciliation.

### 10.3 Production smoke and evidence

Every release affecting visible Admin or integration pages requires a real browser smoke using `domcontentloaded` plus explicit selectors/waits, not `networkidle`. Every schema-bearing release requires explicit migration status verification. Evidence must include:

- deployed commit and migration versions;
- service/worker/scheduler health;
- public authentication and authorization boundary;
- affected page screenshots without PHI;
- source/config version and conformance result;
- queue, freshness, DLQ, replay, and reconciliation evidence;
- rollback result or rehearsal;
- known limitations and owner.

---

## 11. Go-Live Gates

### Gate G0 - Platform safety

- [ ] Anonymous admin/API denial proven.
- [ ] SSO/MFA/break-glass and canonical RBAC proven.
- [ ] CORS/CSRF/CSP/header boundary hardened.
- [ ] Secrets external, rotated, and non-exportable.
- [ ] Raw PHI encryption/retention/quarantine proven.
- [ ] Test and production environment isolation proven.
- [ ] Security risk review has no unresolved critical/high release blocker.

### Gate G1 - Enterprise and legal readiness

- [ ] Organization/facility/source ownership is authoritative.
- [ ] Contract, BAA/DUA, purpose, data classification, retention, and PHI permission are approved.
- [ ] Network/trading partner/vendor enablement is complete.
- [ ] Source and escalation owners accept SLOs and runbooks.

### Gate G2 - Technical conformance

- [ ] Exact protocol/vendor/version/IG profile is pinned.
- [ ] Positive, negative, duplicate, out-of-order, correction, replay, and outage tests pass.
- [ ] Identity and terminology mappings are approved.
- [ ] Raw-to-canonical-to-projection provenance is complete.
- [ ] Reconciliation meets the signed tolerance.

### Gate G3 - Operational readiness

- [ ] Health history, alerts, on-call, incident, DLQ, repair, replay, and rollback are rehearsed.
- [ ] Capacity/load/soak and dependency throttling tests pass.
- [ ] Backup/restore and disaster replay pass.
- [ ] Cockpit, Hummingbird, and Eddy visibly degrade stale/partial sources.

### Gate G4 - Source activation

- [ ] Two authorized people approve the effective configuration version.
- [ ] Change window and rollback checkpoint are recorded.
- [ ] Parallel run and UAT are signed.
- [ ] Production activation emits an immutable event and begins enhanced monitoring.
- [ ] Post-go-live reconciliation is reviewed at 1 hour, 24 hours, 7 days, and 30 days.

"Integration complete" is declared per source, facility, domain, and transaction profile only after G0-G4. Platform-wide completion requires every in-scope source in the signed inventory to reach that state or carry an explicitly accepted exception.

---

## 12. Risks and Non-Negotiable Constraints

| Risk | Required control |
| --- | --- |
| Proprietary/vendor variation | Capability negotiation, vendor profiles, companion guides, sandbox/UAT; no universal-connector claims. |
| Contract/network dependency | Track as source evidence and activation blocker; TEFCA/HIE/QHIN and payer exchange require external participation. |
| Licensed standards | Budget and procure X12, NCPDP, CPT/HCPCS, and vendor implementation material as required. |
| Identity collision | Namespace identifiers, deterministic first, reviewed probabilistic matches, reversible merges. |
| Silent stale/demo data | Mandatory source/freshness/mode metadata and degraded downstream behavior. |
| PHI in operational tooling | Minimum-necessary Admin payloads, encrypted raw storage, safe errors/logs/traces/jobs, retention and purge proof. |
| Duplicate or out-of-order transactions | Stable idempotency, sequence policy, ACK ledger, canonical ordering, reconciliation. |
| Unsafe automated writeback | Human approval, step-up, dual control, target capability check, immutable delivery/ACK/reconciliation. |
| Schema and release drift | Compatibility migrations, explicit production migration verification, source config versions, manual deploy path. |
| Test false confidence | Per-process DB isolation, real protocol conformance, vendor sandbox/UAT, production smoke. |

---

## 13. Primary Standards and Regulatory Sources

The version decisions in this plan were checked against primary sources on 2026-07-12:

- [2026 Interoperability Standards Advisory](https://isp.healthit.gov/sites/default/files/2026-03/ISA-Reference-Mar-2026.html)
- [HL7 FHIR current published core](https://hl7.org/fhir/)
- [HL7 US Core current published guide](https://hl7.org/fhir/us/core/)
- [HL7 SMART App Launch](https://hl7.org/fhir/smart-app-launch/)
- [HL7 FHIR Bulk Data Access](https://hl7.org/fhir/uv/bulkdata/)
- [HL7 FHIR Subscriptions R5 Backport](https://hl7.org/fhir/uv/subscriptions-backport/)
- [HL7 CDS Hooks stable publications](https://cds-hooks.hl7.org/)
- [DICOMweb services and PS3.18 references](https://www.dicomstandard.org/using/dicomweb)
- [CMS adopted HIPAA transaction standards and operating rules](https://www.cms.gov/priorities/key-initiatives/burden-reduction/administrative-simplification/hipaa/adopted-standards-operating-rules)
- [CMS e-prescribing standards and NCPDP transition dates](https://www.cms.gov/medicare/regulations-guidance/electronic-prescribing/adopted-standard-and-transactions)
- [CMS Interoperability and Prior Authorization Final Rule CMS-0057-F](https://www.cms.gov/initiatives/burden-reduction/overview/interoperability/policies-regulations/cms-interoperability-prior-authorization-final-rule-cms-0057-f)
- [TEFCA RCE overview and Common Agreement 2.1](https://rce.sequoiaproject.org/tefca/)
- [IHE published implementation guides](https://profiles.ihe.net/)
- [CDC data exchange and interoperability guidance](https://www.cdc.gov/csels/dmi-support/guidance-portal/data-exchange-dsi.html)
- [NIST SP 800-66 Rev. 2 HIPAA Security Rule implementation guidance](https://csrc.nist.gov/pubs/sp/800/66/r2/final)
- [OAuth 2.0 Security Best Current Practice, RFC 9700](https://www.rfc-editor.org/rfc/rfc9700)

---

## 14. Definition of Done for the Administration Section

The Administration section is complete when:

1. An anonymous visitor cannot see or mutate any protected surface, and production has no demo auto-login path.
2. Institutional identity, MFA, roles/capabilities, facility scope, lifecycle, sessions/tokens, and break-glass access are governed and auditable.
3. Every Admin card is functional or explicitly restricted; no shipped feature is mislabelled unavailable and no placeholder is presented as operational.
4. Enterprise organizations/facilities/service lines/locations are populated and are authoritative foreign-key scopes for sources and users.
5. An authorized operator can onboard, validate, approve, activate, monitor, suspend, rotate, replay, reconcile, and retire a source without exposing secrets or raw PHI.
6. The integration console truthfully distinguishes templates, implementations, conformance results, customer UAT, production certification, and live state.
7. FHIR R4/US Core, SMART, Bulk Data, FHIR eventing, HL7 v2, DICOMweb, X12/NCPDP, documents/HIE, public health, device, and vendor API/file adapters fit one contract and evidence model.
8. Every clinical or operational signal carries source, mode, as-of, freshness, confidence where applicable, and provenance; stale or partial feeds visibly degrade Web, Hummingbird, and Eddy.
9. Every outbound transaction requires policy and human authorization, records delivery/ACK/reconciliation, and is safe to retry or repair.
10. Automated, conformance, load, security, resilience, browser, migration, deployment, and rollback gates pass with retained evidence for each activated source.

Until these conditions are met, Zephyrus may accurately describe the current integration layer as an operational foundation and demo control plane, but not as production-ready for institution-wide healthcare transactional interoperability.
