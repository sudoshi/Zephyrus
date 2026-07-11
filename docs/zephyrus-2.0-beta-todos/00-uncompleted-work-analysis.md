# Uncompleted Work Analysis

Source of truth: `docs/ZEPHYRUS-2.0-BETA-PRD.md`.

Audit date: 2026-07-08.

Audit method:

- Local repo inspection of routes, controllers, services, migrations, frontend pages, mobile shells, tests, deployment script, and configuration.
- Three read-only swarm passes focused on backend/API/domain, frontend/product/mobile, and data/deploy/security.
- Reconciliation against the PRD workstreams, beta roadmap, demo track, validation gates, and open decision register.

No test suite or build was run as part of the swarm audit. Any statement below that says "implemented" means repo evidence exists, not that the current checkout has passed the full beta validation package.

## Highest-Level Finding

The PRD should be converted into a completion plan, not a greenfield implementation plan. The codebase already contains many of the foundational objects the PRD asks for:

- Cockpit snapshot API and web cockpit home.
- Canonical status and metric shape.
- Metric trust tables and cockpit serving tables.
- Integration ledger, canonical event, provenance, dead-letter, replay, and source registry tables.
- Synthetic healthcare connector and integration tests.
- Mobile BFF routes and contract drift tests.
- Patient Flow 4D web/API foundation.
- Eddy draft/propose/approval foundation with scoped agent token constraints.
- Manual deployment script.

The work that remains is concentrated in four categories:

1. Proof gaps: tests, route smoke, screenshots, native builds, scheduler/queue/Reverb runtime proof, PHI review, and release archive.
2. Product gaps: Patient Flow scenario registry/history, Eddy execution adapters, mobile push/offline/security, shell convergence, domain-specific live/mocked behavior.
3. Trust gaps: consistent source/as-of/freshness/synthetic/confidence/lineage/fallback metadata across cockpit, mobile, Eddy, and domain screens.
4. Safety gaps: open API surfaces, demo auto-login posture, PHI display inconsistencies, permissive web-server headers, Reverb origin/rate-limit posture, and no clear strict self-approval rule if required.

## Swarm Reconciliation

| Area | Backend/API Explorer | Frontend/Product Explorer | Data/Deploy/Security Explorer | Final Disposition |
| --- | --- | --- | --- | --- |
| PRD tracking | PRD is untracked. | PRD is untracked. | PRD is untracked. | B0 must decide whether to commit, move, or intentionally park the PRD before treating it as an authority. |
| Cockpit foundation | Snapshot/status API is implemented. | `/dashboard` cockpit page is implemented. | Metric trust and serving tables are implemented. | Do not rebuild; add completeness tests, provenance assertions, runtime proof, and visual evidence. |
| Navigation | Legacy dashboard redirects exist. | `navigationConfig.ts` is current source of truth; `AGENTS.md` is stale. | Memory confirms nav config is the durable current seam, but older notes mention `DashboardContext`. | B0/B3 must update docs and prevent future nav drift. |
| Patient Flow 4D | Strong foundation, but no occupancy history or demo-scenarios route; one scenario only. | Web navigator and occupancy UI exist; PRD-specific endpoints not found. | Integration and provenance foundations exist, not full scenario/live proof. | B4 is a finish-and-prove phase, not a full rebuild. |
| Eddy | Scoped token cannot approve; lifecycle records exist; execution adapters not proven. | Need one real cockpit -> Eddy -> Hummingbird -> ledger -> web loop. | Same-human approval may violate strict no-self-approval interpretation. | B5 must define strict semantics, add tool catalog metadata, implement execution adapters, and prove E2E. |
| Hummingbird | BFF and safety tests exist. | iOS and Android shells exist, but push/security/screenshots are incomplete. | APNs real when configured; FCM/log fallback unresolved. | B6 must close parity and platform evidence, not only backend routes. |
| Integrations | Synthetic connector is the only concrete connector. | Synthetic/demo visibility must be explicit. | Real beta integrations and admin dead-letter/replay visibility are largest gaps. | B1/B7/B8 must distinguish synthetic demo readiness from real integration readiness. |
| Domain completion | 2026-07-09 local implementation corrected the identified periop stale table references; improvement static fallbacks remain. | RTDC and analytics pages still have mock/live mix and PHI display inconsistencies. | API auth and security posture now have local route-family tests, but production evidence is still needed. | B7 owns live-vs-demo cleanup and domain smoke coverage. |
| Deployment | Scheduled jobs defined but runtime proof missing. | Full validation package not run. | `deploy.sh` is canonical and does not run migrations. | B8 must capture migrations, scheduler, queue, Reverb, vhost, screenshots, mobile builds, and rollback. |

## Already Implemented Or Substantially Present

These should be preserved and hardened. Do not spend beta capacity recreating them unless local tests prove a defect.

### Cockpit, Snapshot, And Status

Evidence:

- `routes/api.php` defines `/api/cockpit/snapshot`, `/api/cockpit/scopes`, `/api/cockpit/face`, `/api/cockpit/drill/{domain}`, and `/api/cockpit/kpi-definitions`.
- `app/Http/Controllers/Api/CockpitController.php` serves cockpit payloads.
- `app/Services/Cockpit/SnapshotBuilder.php` builds, persists, caches, refreshes, writes metric history, and emits reload events.
- `app/Services/Cockpit/StatusEngine.php` resolves canonical five-state health.
- `app/Support/Cockpit/MetricValue.php` holds the metric shape.
- `database/migrations/2026_06_26_000010_create_ops_metric_trust_tables.php` and `2026_07_04_000010_create_cockpit_serving_tables.php` establish trust and serving data.

Remaining work:

- Assert every emitted hero metric includes source, as-of, refresh cadence, freshness, status, synthetic/demo flag, confidence, lineage, and fallback state.
- Prove the same snapshot timestamp is visible in web, mobile, and Eddy contexts.
- Prove cache, ETag, stale, refresh, and fallback behavior under current data and in degraded feed mode.
- Prove materialized view refresh, scheduled snapshot refresh, queue worker, and broadcast/SSE/poll fallback at runtime.

### Unified Cockpit Home And Web Route Convergence

Evidence:

- `/dashboard` is routed to `CommandCenterController@index`.
- Legacy dashboard URLs redirect to cockpit drill URLs.
- `resources/js/Pages/Dashboard/CommandCenter.tsx` implements cockpit, wall display, stale-data banner, drill modal, patient lens, action inbox, and realtime/polling wiring.

Remaining work:

- Route smoke every nav leaf.
- Capture desktop, wall, tablet, and mobile screenshots.
- Remove or gate legacy mock defaults for beta surfaces.
- Document intentional route redirects and dead routes.
- Prove no cockpit hero/action surface leaks PHI beyond role policy.

### Navigation And Information Architecture

Evidence:

- `resources/js/config/navigationConfig.ts` defines Cockpit, Workspaces, Study, Deploy, Admin, and the RTDC order `Bed Tracking -> Patient Flow 4D -> Bed Placement -> Ancillary Services -> Global Huddle -> Unit Huddle -> Service Huddle`.
- `resources/js/Contexts/DashboardContext*` is absent in the current checkout, so references that say it is the navigation source are stale.

Remaining work:

- Update `AGENTS.md` and related docs to name `navigationConfig.ts` as the current navigation source.
- Add a nav config test that route definitions and nav leaves remain in sync.
- Decide whether any pages still need contextual nav state and document the pattern.

### Mobile BFF And Hummingbird Foundation

Evidence:

- `routes/api.php` exposes `/api/mobile/v1/*` routes for altitude, For You, activity, patient context, RTDC, flow, transport, EVS, command, OR, ops, staffing, improvement, and Eddy.
- `tests/Feature/MobileBffTest.php`, `MobileRoleCatalogParityTest.php`, `MobileUiVocabularyParityTest.php`, and mobile safety tests exist.
- iOS and Android app shells expose role-aware Home, For You, and Activity structures.

Remaining work:

- Close role-specific workflow gaps.
- Prove each mobile action emits exactly one activity/audit event.
- Finish push behavior or explicitly choose fetch-on-open as the beta fallback.
- Harden Android backup/cleartext settings for non-dev builds.
- Capture persona/platform screenshots and native build evidence.

### Eddy Governed Action Foundation

Evidence:

- `app/Http/Controllers/Api/Eddy/EddyActionController.php` exposes action catalog, propose, and agent token flows.
- Agent tokens include draft/read scopes but not approval scope.
- `app/Services/Eddy/EddyActionService.php` creates recommendations, actions, and approvals.
- `tests/Feature/Eddy/EddyActionTest.php` covers key lifecycle behavior.

Remaining work:

- Define whether "no self-approval" means no agent self-approval only, or also different human requester and approver.
- Add tool catalog metadata for min role, tier, PHI policy, dry-run, rollback, execution adapter, audit fields, and mobile availability.
- Implement backing execution adapters beyond lifecycle records.
- Add one full E2E proof from cockpit signal through Eddy proposal, approval, mobile action, activity ledger, and updated web outcome.

### Integration And Trust Foundation

Evidence:

- Integration migrations create source registry, credentials, raw inbound ledger, canonical events, FHIR mirrors, provenance, projection offsets/errors, dead letters, watermarks, and replay jobs.
- `HealthcareConnector` defines health/backfill/poll/webhook/replay.
- `SyntheticHealthcareConnector` implements the connector and has integration tests.
- Enterprise connector control tables and services exist for FHIR capability, SMART credentials, playbooks, adapters, and writeback drafts.

Remaining work:

- Real beta integration readiness is not proven; only the synthetic connector is implemented.
- Admin Integration Health is count/status oriented, not a complete dead-letter/replay/watermark/provenance control console.
- FHIR discovery currently records local/sandbox/planned metadata rather than proving a real connected endpoint.
- Synthetic/demo values must be visibly labeled across UI, APIs, screenshots, and release notes.

### Deployment

Evidence:

- `deploy.sh` is the manual production deployment path.
- It requires a clean/current tree, builds assets, rsyncs to `/var/www/Zephyrus/`, clears caches as `www-data`, restarts Apache, and verifies the Zephyrus vhost.

Remaining work:

- `deploy.sh` does not run migrations. The release checklist must explicitly run `sudo -u www-data php artisan migrate --force` after deployment when schema changes exist.
- Scheduler, queue worker, Reverb, vhost, storage permissions, cockpit refresh, materialized views, mobile BFF, Eddy, and Patient Flow must be smoke-tested after deployment.
- GitHub Actions must remain CI-only and must not be converted into a production deploy path.

## Partial, Inconsistent, Or Risky Areas

### Legacy Mock And Dev Mode

Evidence:

- `resources/js/Contexts/ModeContext.tsx` defaults to `dev`.
- `resources/js/services/data-service.js` can return bundled mock data for older API paths.
- Analytics, Improvement, and some RTDC pages still have mock/fallback paths.

Risk:

- A beta demo can silently show mock values without source/provenance labels.
- Screenshots can pass visually while failing the trust contract.

Needed work:

- In beta mode, default to live/provenanced data.
- If a surface is intentionally synthetic, label it in API payloads and visible UI.
- Add tests that fail when beta cockpit/mobile/Eddy surfaces consume unlabelled mock data.

### PHI And Redaction Inconsistency

Evidence:

- Patient Flow and mobile contexts have redaction concepts.
- Some RTDC/ED/huddle pages render `patient_ref`, patient names, or MRNs directly.

Risk:

- Role-based patient lensing can be undermined by older pages.

Needed work:

- Create one patient display policy for beta.
- Apply it to RTDC Bed Placement, ED Triage, Service Huddle, Unit Huddle, Patient Flow, mobile patient context, and action inboxes.
- Add role-based screenshot and API response tests.

### Perioperative Legacy Table Names

Evidence:

- Cockpit periop metrics read live `prod.or_cases`.
- Public API methods for `/api/cases/metrics` and `/api/cases/room-status` query `prod.orcase` or `prod.orlog`.
- Migrations create `prod.or_cases` and `prod.or_logs`.

Risk:

- Periop demo pages can break even while cockpit metrics pass.

Needed work:

- Fix table mappings.
- Add route/API tests for the affected endpoints.
- Add route smoke coverage so table-name regressions fail early.

### Patient Flow Scenario And History Gaps

Evidence:

- Patient Flow has occupancy context and one demo overlay, `rtdc_barriers`.
- 2026-07-09 local implementation added `/api/patient-flow/occupancy/history`.
- 2026-07-09 local implementation added `/api/patient-flow/demo-scenarios`.
- Snapshot detail columns exist but current writes leave key detail/count/projection fields empty.

Risk:

- The PRD demo track requires switchable, named operational scenarios and historical context. Current code cannot prove that contract.

Needed work:

- Add a scenario registry with PRD scenarios.
- Add occupancy history API from persisted snapshots.
- Persist detail/count/projection/lineage fields.
- Add retention/pruning tests and visual timeline tests.

### Eddy Execution And Audit Gaps

Evidence:

- Eddy can propose and create lifecycle records.
- Python local loop is gated/stubbed by environment and uses in-memory session state.
- Backing domain execution adapters are not proven.

Risk:

- Eddy may appear to act while only creating recommendations.

Needed work:

- Make every available action either executable, explicitly dry-run only, or explicitly disabled.
- Add tool descriptors for safety, rollback, PHI, authorization, dry-run, and audit.
- Persist agent run and tool-call state durably.
- Add no-SQL, prompt-injection, no-write-tool, self-approval, and PHI-minimization tests.

### Security And Auth Posture

Evidence:

- Demo auto-login as admin is present by design.
- Some API routes are throttled but not session/token gated.
- Admin integration routes are not all protected by admin middleware.
- `public/.htaccess` has permissive CORS/CSP behaviors and disables mod_security for `/login`.
- Reverb allows all origins and no rate limit by default.

Risk:

- A beta demonstration environment can drift into production-like exposure without hard gates.

Needed work:

- Define demo auth vs production auth behavior.
- Gate mutable APIs.
- Gate admin APIs with admin middleware.
- Align Apache headers with Laravel security headers.
- Restrict Reverb origins/rate limits for beta.

## Missing Or Unproven PRD Requirements By Section

## Coverage Status Index

This table is the execution index for the remaining work. It should be updated whenever a phase moves a requirement from `Open` or `Partial` to `Ready for B8`, `Deferred`, or `Complete`. Stable requirement IDs live in `TRACEABILITY-MATRIX.md`.

| PRD Area | Requirement IDs | Current Evidence | Missing Proof | Owning Phase | Blocker Decision | Final B8 Artifact |
| --- | --- | --- | --- | --- | --- | --- |
| Canonical status/snapshot | PRD-Z1-001 through PRD-Z1-004 | `StatusEngine`, `MetricValue`, `SnapshotBuilder`, cockpit APIs and tests exist. | Versioned schema, all trust fields, web/mobile/Eddy `as_of` parity, stale/degraded proof. | B2 | D1, D2, D18 | snapshot API samples, screenshots, scheduler proof. |
| Unified cockpit home | PRD-Z2-001 through PRD-Z2-003 | `/dashboard`, `CommandCenterController`, command center UI, redirect tests exist. | route/nav full smoke, Eddy-disabled proof, wall/tablet/mobile visual evidence. | B3 | D3, D18 | cockpit screenshot matrix and route disposition table. |
| Drill/descent/patient lens | PRD-Z3-001, PRD-Z3-002 | Drill modal, patient lens concepts, role-redaction pieces exist. | complete source/owner/next-move proof, patient display policy across legacy pages. | B3/B7 | D4, D16 | PHI review and drill screenshots/API samples. |
| Navigation convergence | PRD-Z4-001, PRD-Z4-002 | `navigationConfig.ts`, route tests, RTDC order exist. | stale docs cleanup, nav leaf route proof, rollback route table. | B0/B3 | D3, D13 | nav/route drift output and redirect tests. |
| Domain completeness | PRD-Z5-001 through PRD-Z5-006 | Many RTDC, transport, EVS, staffing, cockpit tests exist. | ED/periop/staffing/improvement/storyline proof, stale periop table cleanup, domain API smoke, live/synthetic labels. | B7 | D1, D4, D11, D17 | domain completion matrix and demo screenshots. |
| HOSP1/Summit demo data | PRD-Z6-001 through PRD-Z6-003 | `zephyrus:demo-seed`, Patient Flow synthetic commands, facility/integration foundations exist. | repeatable seed proof, HOSP1 bed-story consistency, visible synthetic/demo labels. | B1 | D1, D2, D6, D7 | seed logs, proof queries, degraded feed evidence. |
| Patient Flow 4D | PRD-PF-001 through PRD-PF-007 | Navigator, occupancy context, barrier taxonomy, one scenario, mobile parity foundation exist. | history endpoint, scenario registry, populated snapshot detail JSON, role redaction proof, canvas evidence. | B4 | D2, D5, D6, D7, D16 | Patient Flow API samples, screenshot/canvas proof. |
| Eddy governed action loop | PRD-EDDY-001 through PRD-EDDY-008 | Eddy action controller/service, scoped tokens, lifecycle tests exist. | strict no-self-approval decision, tool catalog metadata, dry-run/execution adapters, durable local-loop state, full E2E loop. | B5 | D8, D9, D15, D17 | tool catalog, no-self-approval tests, demo loop proof. |
| Hummingbird parity | PRD-HB-001 through PRD-HB-008 | Mobile BFF, role tests, iOS/Android shells, flow/mobile Eddy foundation exist. | push/fetch decision, offline/unsafe-write tests, Android manifest hardening, iOS project generation/build proof, screenshot matrix. | B6 | D4, D9, D10, D12, D16 | native build logs and persona screenshot matrix. |
| Integration/trust/security | PRD-TRUST-001 through PRD-SEC-003 | integration tables/services, synthetic connector, security middleware/config exist. | real-vs-synthetic disclosure, admin health control depth, API auth matrix, CORS/CSP/Reverb hardening proof. | B0/B1/B7/B8 | D1, D14, D17, D18 | auth tests, admin screenshots, security review. |
| Deployment/ops/rollback | PRD-OPS-001 through PRD-OPS-003 | `deploy.sh`, scheduler definitions, deployment memory evidence exist. | preflight branch isolation, migration status proof, scheduler/queue/Reverb/vhost smokes, app/DB rollback artifacts. | B8 | D19 | deploy/post-deploy/rollback logs. |
| Demo scenarios | PRD-DEMO-001 through PRD-DEMO-009 | scenario narrative exists and supporting code is partial. | fresh-seed rehearsal across visibility -> recommendation -> human decision -> action -> outcome. | B8 with B1-B7 inputs | D1-D19 as applicable | `evidence/B8/demo/demo-rehearsal.md`. |
| Validation gates | PRD-GATE-AUTO-001 through PRD-GATE-DEMO-001 | PHP/JS/Playwright/native command surfaces exist; some tests exist. | create API smoke/auth tests, archive all commands, mobile builds, PHI review, screenshots. | B8 | all open decisions | final validation archive. |

### Section 6: Main Zephyrus Web Application

- Z1 canonical status/metric/snapshot foundation is mostly implemented but not fully proven against the trust contract.
- Z2 unified cockpit home is mostly implemented but needs screenshot and stale/fallback proof.
- Z3 drill/descent architecture is partially implemented and needs full route/metric/action traceability.
- Z4 IA/layout convergence needs doc update, nav tests, route smoke, and visual proof.
- Z5 domain completeness remains mixed; key open items include RTDC huddles, periop stale API names, improvement static data, analytics fallbacks, and live/synthetic labeling.
- Z6 HOSP1/Summit demo data needs repeatable seed/import/rebase/runbook proof.

### Section 7: Patient Flow 4D

- Missing `/api/patient-flow/occupancy/history`.
- Missing `/api/patient-flow/demo-scenarios`.
- Missing seven switchable PRD scenarios.
- Missing persisted detail/count/projection/lineage fields in snapshot writes.
- Missing scenario/live flags, lineage counts, retention/pruning proof, and visual evidence across role lenses.

### Section 8: Eddy AI Agent

- Governed lifecycle exists but execution adapters are unproven.
- Tool catalog metadata is incomplete.
- Strict no-self-approval semantics need decision and enforcement.
- Local loop persistence and production operation are incomplete.
- Full cockpit -> Eddy -> Hummingbird -> ledger -> web outcome loop is unproven.

### Section 9: Hummingbird

- Backend BFF exists, but role-specialized mobile workflows need closure and screenshots.
- APNs/FCM/fetch-on-open behavior needs an explicit beta decision.
- Android manifest security needs non-dev hardening.
- Native offline and unsafe-write behavior needs tests and evidence.
- Full iOS/Android build evidence has not been captured.

### Section 10: Integration, Provenance, And Trust

- Foundational tables and synthetic connector exist.
- Real beta integration implementation is not proven.
- Admin Integration Health does not yet look like a full operations console for dead letters, replay, watermarks, provenance, and writeback drafts.
- Every metric and action context needs visible source/freshness/provenance labels.

### Section 11: Deployment And Operations

- Manual deployment script exists.
- Post-deploy migrations are manual and must be captured.
- Scheduler, queue worker, Reverb/fallback, materialized views, cockpit refresh, mobile BFF, Eddy, and Patient Flow smoke evidence is missing.
- Rollback and known-limitations artifacts are missing.

### Section 14: Beta Validation Gates

Unproven until run and archived:

- Full PHP suite.
- Targeted cockpit, Patient Flow, Eddy, mobile, integration, route smoke, and security tests.
- JS tests.
- Production asset build.
- UI canon script.
- OpenAPI/mobile drift checks.
- PHI review.
- Desktop/tablet/mobile/wall screenshots.
- Native iOS and Android builds.
- End-to-end demo rehearsal.

## Decision Register For Remaining Work

These decisions should be made in B0 or early B1 so later implementation does not fork:

- D1: Is `docs/ZEPHYRUS-2.0-BETA-PRD.md` committed as the beta authority, moved, or intentionally kept local?
- D2: What is the beta environment auth posture: demo auto-login allowed only locally, or allowed on the beta host behind other controls?
- D3: Does "no self-approval" mean agent-only, or must human requester and human approver differ?
- D4: Is Android push required for beta, or is fetch-on-open with APNs-only push acceptable?
- D5: Are real external FHIR/HL7/ADT integrations required for beta, or is synthetic/demo integration acceptable if labeled?
- D6: Which PRD scenarios are required for the live demo track, and which can be hidden behind a scenario-disabled flag?
- D7: What is the source of truth for patient display labels across web/mobile/Eddy?
- D8: Which mutable API routes may remain public in demo mode, and which must require session/Sanctum/admin middleware?
- D9: What is the production Reverb origin/rate-limit policy?
- D10: What is the beta rollback trigger and expected maximum rollback time?

## Phase Mapping

- B0 converts decisions and drift risks into hard project guardrails.
- B1 makes data, source labels, and demo reset trustworthy.
- B2 proves the cockpit/status/snapshot foundation.
- B3 converges the web application into the cockpit-era shell.
- B4 finishes Patient Flow 4D and barrier intelligence.
- B5 finishes Eddy as a governed action loop.
- B6 finishes Hummingbird beta parity.
- B7 removes or labels remaining domain gaps.
- B8 packages the beta release and demonstration evidence.
