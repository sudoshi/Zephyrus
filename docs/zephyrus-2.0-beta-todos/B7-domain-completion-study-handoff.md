# B7 - Domain Completion And Study Handoff

Goal: finish or explicitly label all beta-visible operational domains so the cockpit, workspaces, study surfaces, Hummingbird, and Eddy are coherent.

Primary source: PRD section `B7 - Domain Completion And Study Handoff`.

Exit principle: every domain may be live, seeded synthetic, or post-beta, but it cannot be ambiguous.

## Current Evidence

- ED NEDOCS/live-visit foundation exists.
- Periop cockpit metrics use live `prod.or_cases`; 2026-07-09 local implementation corrected public case API stale table references.
- RTDC has live components but also demo/local state paths.
- Improvement/process pages retain fixed arrays and mock-data fallbacks.
- Analytics pages include design fallback behavior.
- Integration foundation exists, but only synthetic connector is implemented.
- 2026-07-09 local implementation adds API route smoke and route-family auth posture tests.
- Several API surfaces still need final B8 release evidence and visual/domain screenshots.

## Deliverables

- [ ] Domain-by-domain live/synthetic/post-beta matrix.
- [ ] RTDC huddle and bed-flow completion.
- [ ] ED operational board and strain proof.
- [x] Periop API correctness and smoke coverage for the corrected case API table references.
- [ ] Transport/EVS live or synthetic-labeled metrics.
- [ ] Staffing capacity and mitigation parity.
- [ ] Improvement/process intelligence live or labeled synthetic.
- [ ] Study handoff path from operational event to improvement artifact.
- [x] API auth and route smoke hardening for the implemented route-family matrix.

## Phase Entry Gate

B7 may start only after:

- [ ] B0 has created or assigned API route/auth validation assets.
- [ ] B1 has handed off live/synthetic/source-label rules.
- [ ] B3 has handed off web shell, mock-mode, and patient display policy.
- [ ] B5/B6 have handed off action/activity expectations for domains with mobile/Eddy actions.
- [ ] D1, D4, D11, and D17 are resolved or time-boxed.
- [ ] Requirement IDs `PRD-Z5-001` through `PRD-Z5-006` and demo IDs `PRD-DEMO-004`, `PRD-DEMO-006`, `PRD-DEMO-007`, and `PRD-DEMO-008` are assigned.

Preflight commands:

```bash
git status --short --branch
php artisan route:list
php artisan route:list --path=api
php artisan test --filter=RouteSmokeTest
php artisan test --filter=Rtdc
php artisan test --filter=Staffing
php artisan test --filter=Transport
php artisan test --filter=Evs
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Domain completion matrix | Orchestrator | QA Agent | B8 owner | live/synthetic/post-beta matrix |
| API auth hardening | Security Agent | Backend Agent | B8 owner | route-family auth matrix and tests |
| RTDC/bed flow | Backend + Frontend Agents | Security Agent | B8 owner | route/API tests, screenshots |
| ED and periop | Backend Agent | QA Agent | B8 owner | table/API correctness tests |
| Transport/EVS/staffing | Backend + Mobile Agents | Security Agent | B6/B8 owners | action/activity/mobile proof |
| Improvement/Study handoff | Backend + Frontend Agents | Data Agent | B8 owner | operational-event-to-study payload |
| Visual/domain smoke | QA Agent | Frontend Agent | B8 owner | screenshots and smoke output |

## Agent Execution Contract

Owned write scope:

- domain controllers/services/routes/tests needed for beta-visible domain completion.
- route/auth tests created from B0 inventory.
- UI labels/screens/study handoff code needed to remove ambiguity.
- domain docs/evidence.

Read-only first:

- `routes/api.php`.
- `routes/web.php`.
- `app/Http/Controllers/Api/ORCaseController.php`.
- `resources/js/Pages/RTDC/ServiceHuddle.jsx`.
- `resources/js/Pages/ED/Operations/Triage.jsx`.
- domain tests under `tests/Feature/Rtdc`, `tests/Feature/Staffing`, `tests/Feature/Transport`, `tests/Feature/Evs`, `tests/Feature/Improvement`.
- B1/B3/B5/B6 handoff evidence.

Do not touch:

- Patient Flow core scenario/history implementation owned by B4 unless connecting a domain handoff.
- mobile native screens beyond domain parity needed for B6 handoff.
- production deployment runbook owned by B8.

## Route-Family Auth And Error Contract

B7 must complete or implement the B0 route-family auth matrix for beta-visible APIs:

| Route Family | File/Area To Inspect | Target Outcome |
| --- | --- | --- |
| Admin integrations | `routes/api.php` admin integration routes | admin-only or explicitly disabled; `401/403/200` tests |
| Mutable OR cases | `routes/api.php`, `ORCaseController.php` | session/domain/admin middleware; no raw exception `trace` or unsafe `message` leakage |
| Block/reference/legacy analytics | `routes/api.php` | public/session posture decided; read-only endpoints labeled |
| Improvement fallbacks | `routes/api.php`, Improvement pages/services | live/provenanced or demo-labeled; not silent fake live |
| RTDC mutable routes | RTDC controllers/routes | role/session auth, unsafe-placement guard, audit events |
| Transport/EVS/staffing writes | domain controllers/routes | authenticated role writes with activity/audit rows |
| Study handoff | improvement/study routes | aggregate/redacted payload and authorization |

Required tests:

- [ ] `ApiRouteSmokeTest` exists or is created before B7 exits if API route smoke is claimed.
- [ ] `ApiAuthorizationTest` exists or is created before B7 exits if auth hardening is claimed.
- [ ] domain-specific feature tests assert unauthenticated, unauthorized, and success behavior.
- [ ] error responses avoid raw traces and internal SQL details.

## Operational-Event-To-Study Contract

B7 must define an exact payload for Study/A3 handoff:

| Field | Requirement |
| --- | --- |
| `event_ref` | durable operational event/action/activity identifier |
| `domain` | source domain such as flow, staffing, transport, EVS, periop |
| `scope` | house/unit/service/patient-lens scope with PHI policy applied |
| `pattern` | aggregate repeated barrier/delay/strain pattern |
| `source` | source registry/table/event lineage |
| `as_of` | timestamp of operational evidence |
| `measures` | impact and balancing measures or explicit future scope |
| `recommended_next_step` | PI/PDSA/A3 next move |
| `patient_identifiers` | absent unless privileged and explicitly justified; default none |
| `action_ref` | optional link to governed action lifecycle |

The handoff cannot be a static card. It must be backed by operational event/action evidence or explicitly marked post-beta.

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| RTDC tests | Existing | huddle/bed placement/census/prediction/unsafe-placement outputs |
| ED/NEDOCS tests | Existing cockpit/unit tests | ED strain/API screenshots and PHI review |
| Periop route/API tests | Partial | `/api/cases/metrics` and `/api/cases/room-status` tests after table fix |
| Transport/EVS/staffing tests | Existing | write/auth/activity proof |
| `ApiRouteSmokeTest` | To create if not done in B0 | route-family smoke output |
| `ApiAuthorizationTest` | To create if not done in B0 | route-family auth matrix |
| Study handoff test | To create | operational-event-to-study contract proof |
| domain screenshot matrix | To capture | normal/strain/redacted/source-labeled states |

## Criticality Double Check

B7 must pass or defer:

- Requirements traceability.
- API contract.
- Authorization.
- PHI/privacy.
- Data trust.
- Data integrity.
- Frontend quality.
- Mobile parity where affected.
- Eddy governance where affected.
- Observability.
- Testing.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B7/README.md`.
- [ ] `reviews/domain-completion-matrix.md`.
- [ ] `reviews/api-auth-route-family-matrix.md`.
- [ ] `api/domain-smoke-samples/`.
- [ ] `commands/domain-tests.txt`.
- [ ] `commands/api-route-smoke.txt` if test exists.
- [ ] `commands/api-authorization.txt` if test exists.
- [ ] `api/study-handoff-sample.json`.
- [ ] `screenshots/web/domain-matrix/`.
- [ ] `reviews/phi-domain-review.md`.

## Workstream 7.1: Domain Completion Matrix

- [ ] Create a matrix with rows for:
  - [ ] RTDC/Bed Flow.
  - [ ] Emergency Department.
  - [ ] Perioperative.
  - [ ] Transport.
  - [ ] EVS.
  - [ ] Staffing.
  - [ ] Improvement/Quality/Process Intelligence.
  - [ ] Analytics/Study.
  - [ ] Service Lines.
  - [ ] Financial.
  - [ ] Quality Scorecards.
  - [ ] Regional Transfer if beta-visible.
- [ ] For each row, capture:
  - [ ] Web route.
  - [ ] API route.
  - [ ] Mobile surface.
  - [ ] Eddy actions.
  - [ ] Source mode.
  - [ ] Freshness contract.
  - [ ] PHI posture.
  - [ ] Tests.
  - [ ] Screenshot.
  - [ ] Owner.
  - [ ] Known limitation.
- [ ] Use the matrix to decide which domain gaps must be closed before B8.

## Workstream 7.2: RTDC And Bed Flow

- [ ] Bed Tracking:
  - [ ] Replace representative demo values with live/seeded source-aware data or label them synthetic.
  - [ ] Add source/freshness labels.
  - [ ] Add route/API tests.
- [ ] Bed Placement:
  - [ ] Apply patient display policy.
  - [ ] Ensure unsafe placement guard tests still pass.
  - [ ] Ensure decisions create action/audit/activity records.
  - [ ] Add mobile parity where required.
- [ ] Unit Huddle:
  - [ ] Replace default `unitId = 1` with route/query/scope selector.
  - [ ] Persist selected unit where appropriate.
  - [ ] Show stale/source state.
  - [ ] Add deep link tests.
- [ ] Service Huddle:
  - [ ] Replace mock/local-only updates with persisted backend state or label as draft-only.
  - [ ] Remove raw MRN display unless policy permits.
  - [ ] Add huddle agenda draft path if Eddy supports it.
- [ ] Global Huddle:
  - [ ] Verify it uses cockpit/RTDC snapshot data.
  - [ ] Add action inbox integration if relevant.
- [ ] Ancillary Services:
  - [ ] Align with transport/EVS source state.

Verification:

```bash
php artisan test --filter=Rtdc
php artisan test --filter=UnsafePlacementGuardTest
npm run test
```

## Workstream 7.3: Emergency Department

- [ ] Confirm ED live data path through `prod.ed_visits` and NEDOCS.
- [ ] Add or update tests for ED strain metrics.
- [ ] Apply patient display policy to ED Triage.
- [ ] Add source/freshness labels to ED pages.
- [ ] Ensure empty states are credible when live data is absent.
- [ ] Ensure ED boarder scenario connects to Patient Flow and Eddy action path.
- [ ] Capture ED screenshots for normal, strain, and redacted states.

Verification:

```bash
php artisan test --filter=Nedocs
php artisan test --filter=Ed
rg -n "patientName|patientRef|patient_ref|MRN|mrn" resources/js/Pages/ED app tests
```

## Workstream 7.4: Perioperative

- [ ] Fix stale table references:
  - [ ] `prod.orcase` -> current table.
  - [ ] `prod.orlog` -> current table.
  - [ ] `ORLog` model mapping.
- [ ] Add tests for:
  - [ ] `/api/cases/metrics`.
  - [ ] `/api/cases/room-status`.
  - [ ] Periop cockpit metrics.
  - [ ] OR delay scenario.
- [ ] Remove or label case-management mock data.
- [ ] Verify periop pages show source/freshness.
- [ ] Verify OR delay creates visible downstream bed/staffing pressure in demo scenario if required.

Suggested files:

- `app/Http/Controllers/Api/ORCaseController.php`.
- `app/Models/ORLog.php`.
- `app/Data/CaseManagementMockData.php`.
- `tests/Feature/*Periop*`.

Verification:

```bash
php artisan test --filter=ORCase
php artisan test --filter=Periop
php artisan route:list | rg "cases|or|periop"
```

## Workstream 7.5: Transport And EVS

- [ ] Inventory transport and EVS routes, services, and mobile cards.
- [ ] Ensure waits, queue length, turn-around time, and barrier states have source/freshness labels.
- [ ] Ensure Patient Flow barriers can link to transport/EVS contexts.
- [ ] Ensure Eddy can draft or route transport/EVS escalation only through governed actions.
- [ ] Add tests for action/audit/activity semantics.
- [ ] Add screenshots for transport and EVS beta scenarios.

Verification:

```bash
php artisan route:list | rg "transport|evs"
php artisan test --filter=Transport
php artisan test --filter=Evs
```

## Workstream 7.6: Staffing

- [ ] Confirm staffing data source for beta:
  - [ ] Live.
  - [ ] Seeded synthetic.
  - [ ] Explicitly post-beta.
- [ ] Ensure staffing cards show:
  - [ ] Safe capacity.
  - [ ] Gap.
  - [ ] Role/skill mix.
  - [ ] Unit.
  - [ ] Source/freshness.
  - [ ] Suggested mitigation where in scope.
- [ ] Ensure staffing gap scenario feeds cockpit, Hummingbird, and Eddy consistently.
- [ ] Add tests for safe capacity, stale staffing source, and unauthorized role.
- [ ] Add mobile screenshots for staffing coordinator role.

Verification:

```bash
php artisan route:list | rg "staff"
php artisan test --filter=Staff
```

## Workstream 7.7: Improvement, Quality, And Process Intelligence

- [ ] Replace or label fixed arrays and sample-file fallbacks in:
  - [ ] `DashboardService`.
  - [ ] `ProcessAnalysisService`.
  - [ ] Improvement routes.
  - [ ] Improvement React pages.
- [ ] Define live or seeded source for:
  - [ ] Bottlenecks.
  - [ ] Root causes.
  - [ ] PDSA cycles.
  - [ ] Process maps.
  - [ ] Quality opportunities.
  - [ ] Study handoff candidates.
- [ ] Add source/freshness labels.
- [ ] Add "promote to study" flow from operational event or Eddy recommendation if required by PRD.
- [ ] Add tests for improvement API routes.
- [ ] Add screenshots for active PDSA and process analysis states.

Verification:

```bash
rg -n "mock|fallback|sample|hardcoded|PDSA" app resources/js/Pages/Improvement routes tests
php artisan test --filter=Improvement
```

## Workstream 7.8: Analytics And Study Handoff

- [ ] Decide which analytics routes are beta-visible.
- [ ] Remove design fallback from beta-visible analytics pages or label it.
- [ ] Ensure Study routes connect to trusted metric definitions and source state.
- [ ] Ensure cockpit drill can hand off to Study with:
  - [ ] Metric key.
  - [ ] Scope.
  - [ ] Snapshot id/as-of.
  - [ ] Source lineage.
  - [ ] Suggested question.
- [ ] Add tests for study handoff route and payload.
- [ ] Add screenshot showing operational signal -> study context.

## Workstream 7.9: Service Line, Financial, And Quality Scorecards

- [ ] Confirm materialized views and facts behind these scorecards are populated by seed/import path.
- [ ] Add source/freshness labels to scorecard cards.
- [ ] Show confidence/fallback state for derived values.
- [ ] Hide or label any scorecard that cannot be trusted for beta.
- [ ] Add tests for metric availability and stale/fallback behavior.
- [ ] Add executive dashboard screenshot.

## Workstream 7.10: API Smoke And Auth Hardening

- [ ] Add broad API route smoke tests for domain endpoints.
- [ ] Add auth tests for mutable endpoints.
- [ ] Add admin middleware tests for admin endpoints.
- [ ] Add mobile token tests for mobile-only endpoints.
- [ ] Add agent scoped-token tests for Eddy agent endpoints.
- [ ] Add tests for degraded/stale responses instead of raw failures.

Route families:

- [ ] `/api/cockpit/*`.
- [ ] `/api/patient-flow/*`.
- [ ] `/api/cases/*`.
- [ ] `/api/rtdc/*`.
- [ ] `/api/transport/*`.
- [ ] `/api/evs/*`.
- [ ] `/api/staffing/*`.
- [ ] `/api/improvement/*`.
- [ ] `/api/eddy/*`.
- [ ] `/api/ops/*`.
- [ ] `/api/admin/*`.
- [ ] `/api/mobile/v1/*`.

Verification:

```bash
# Only after these files exist:
php artisan test --filter=ApiRouteSmokeTest
php artisan test --filter=ApiAuthorizationTest
php artisan test
```

## Phase Exit Gate

This phase is complete only when:

- [ ] Every beta-visible domain is live, labeled synthetic, or explicitly post-beta.
- [ ] Periop stale table references are fixed and tested.
- [ ] RTDC huddle/local state gaps are closed or labeled.
- [ ] ED, transport, EVS, staffing, improvement, analytics, and scorecards have source/freshness labels.
- [ ] Study handoff path is implemented or explicitly scoped out.
- [ ] API route smoke and authorization tests cover beta route families.
- [ ] Domain screenshots are archived.
