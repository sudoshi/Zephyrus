# B0 - Documentation And Contract Freeze

Goal: make the PRD executable and prevent drift before implementation continues.

Primary source: PRD section `B0 - Documentation And Contract Freeze`.

Exit principle: nobody should have to infer beta scope from scattered historical docs or stale code comments after this phase.

## Current Evidence

- `docs/product/ZEPHYRUS-2.0-BETA-PRD.md` exists but is currently untracked in the worktree.
- `AGENTS.md` describes `DashboardContext` as workflow/navigation source, but the current frontend navigation source is `resources/js/config/navigationConfig.ts`.
- Mobile BFF route and OpenAPI drift tests exist.
- `RouteSmokeTest` currently skips API routes, so route breakage can hide outside mobile-specific tests.
- Several older docs remain valuable lineage, but the PRD now resolves beta authority and scope boundaries.

## Deliverables

- [ ] Tracked and reviewed PRD authority decision.
- [ ] Updated in-repo documentation that reflects current code seams.
- [ ] PRD traceability matrix transformed into automated or manually checked gates.
- [ ] Route drift coverage for web, API, mobile BFF, Eddy, Patient Flow, and admin routes.
- [ ] Decision register resolved or explicitly time-boxed.
- [ ] Beta-known limitations document started, not deferred to the release week.

## Phase Entry Gate

Do not start implementation beyond B0 until these are true:

- [ ] `git status --short --branch` has been captured.
- [ ] The current branch and commit are recorded in `evidence/B0/README.md`.
- [ ] Untracked authority docs are intentionally handled: committed, moved, or explicitly excluded.
- [ ] The orchestrator has read `AGENTIC-EXECUTION-WORKFLOW.md`, `ENGINEERING-CRITICALITIES.md`, `TRACEABILITY-MATRIX.md`, `DECISION-REGISTER.md`, `ACCEPTANCE-EVIDENCE-PACKAGE.md`, and `VALIDATION-INVENTORY.md`.
- [ ] Every open decision in `DECISION-REGISTER.md` has an owner agent and reviewer.
- [ ] Existing versus missing validation assets are understood; `ApiRouteSmokeTest` and `ApiAuthorizationTest` are not treated as existing tests until created.

Preflight commands:

```bash
git status --short --branch
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
php artisan route:list
php artisan list --raw
find tests -type f | sort
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| PRD authority and docs index | Documentation Agent | Orchestrator | all phase owners | authority decision row, docs index diff |
| Decision register | Orchestrator | QA Agent | all phase owners | `DECISION-REGISTER.md` D1-D19 status update |
| Requirement traceability | QA Agent | Documentation Agent | B1-B8 owners | `TRACEABILITY-MATRIX.md` requirement status update |
| Route/nav drift guardrails | Frontend Agent | QA Agent | B3 owner | nav route test plan and command output |
| API auth inventory | Security Agent | Backend Agent | B7/B8 owners | route-family auth matrix |
| Known limitations register | Documentation Agent | Security Agent | B8 owner | limitations file with initial entries |

## Agent Execution Contract

Owned write scope:

- `docs/product/ZEPHYRUS-2.0-BETA-PRD.md` or the chosen committed authority path.
- `docs/plans/zephyrus-2.0-beta/*`.
- `AGENTS.md` only for stale/current repo guidance corrections.
- New or updated tests needed for route/auth/nav guardrails.

Read-only first:

- `routes/web.php`.
- `routes/api.php`.
- `resources/js/config/navigationConfig.ts`.
- `tests/Feature/RouteSmokeTest.php`.
- `tests/js/config/navigationConfig.test.ts`.
- `app/Http/Middleware/SessionAuthMiddleware.php`.
- `public/.htaccess`.
- `config/reverb.php`.

Do not touch:

- Product implementation outside docs/tests unless required to make B0 guardrails pass.
- Deployment script behavior unless the user explicitly expands scope from planning/docs to implementation.

## Validation Inventory

| Validation | Current State | B0 Action |
| --- | --- | --- |
| `RouteSmokeTest` | Exists, but API routes are skipped. | Keep as web smoke and document limitation. |
| `ApiRouteSmokeTest` | Missing. | Create in B0 or assign to B7/B8 with explicit owner; do not list as passing before it exists. |
| `ApiAuthorizationTest` | Missing. | Create route-family matrix first; then implement tests in B0/B7. |
| `navigationConfig.test.ts` | Exists. | Extend or pair with PHP route smoke so every nav leaf resolves or is disabled/external. |
| Mobile route drift tests | Exist. | Keep as required gate and update if route inventory changes. |
| Security/header/Reverb tests | Not complete as named classes. | Add review checklist and create tests only where stable behavior is decided. |

Suggested B0 validation commands:

```bash
php artisan route:list
php artisan route:list --path=api
php artisan test --filter=RouteSmokeTest
npm run test -- tests/js/config/navigationConfig.test.ts
```

New tests to create or explicitly defer:

- [ ] `tests/Feature/ApiRouteSmokeTest.php`.
- [ ] `tests/Feature/ApiAuthorizationTest.php`.
- [ ] Nav leaf route contract test if the existing JS test does not assert route reachability.

## API Authorization Inventory Contract

For each route family, create a row with current and target beta posture:

| Route Family | Current Middleware | Target Beta Middleware | Unauthenticated Result | Unauthorized Role Result | Mutable Role | Test Class | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Cockpit read APIs | To inspect | Session/read or approved public demo read | To define | To define | None | `ApiAuthorizationTest` | Must preserve source/freshness labels. |
| Patient Flow APIs | To inspect | Session/read plus role redaction | To define | To define | scoped role only for mutable scenario controls | `PatientFlowApiTest` + `ApiAuthorizationTest` | Include history/scenarios once B4 lands. |
| Mobile BFF | Sanctum/mobile scopes expected | Sanctum/mobile scopes | `401` | `403` or redacted | `mobile:act` | existing mobile tests | Native clients must not scrape web. |
| Eddy agent routes | Scoped token expected | scoped token, no approval scope | `401` | `403` | never approval by agent | `EddyActionTest` | D15 controls human self-approval. |
| Admin integrations | To inspect | admin-only | `401` | `403` | admin | `ApiAuthorizationTest` | Review routes near admin integration health. |
| Mutable OR cases/blocks | To inspect | session/admin or domain role | `401` | `403` | domain/admin | `ApiAuthorizationTest` | Remove raw exception `trace`/`message` leakage. |
| Reference data | To inspect | decide public read or session read | To define | To define | None | `ReferenceEndpointsTest` + auth test | D17 decides beta posture. |
| Improvement fallback APIs | To inspect | session/read or demo-only with labels | To define | To define | domain/admin | `ApiAuthorizationTest` | Must not silently serve fake live data. |

Specific implementation hazards:

- [ ] Review `app/Http/Controllers/Api/ORCaseController.php` error handling and remove raw exception `trace` or unredacted `message` leakage from beta API responses.
- [ ] Review `public/.htaccess` CORS/CSP behavior and document the beta target.
- [ ] Review `config/reverb.php` origins/rate limits and document D18.
- [ ] Review `SessionAuthMiddleware` and demo auto-login behavior under D14.

## Criticality Double Check

Before B0 exits, copy the review form from `ENGINEERING-CRITICALITIES.md` into `evidence/B0/README.md` and close every applicable row. B0 must at minimum pass or defer:

- Requirements traceability.
- API contract.
- Authorization.
- PHI/privacy.
- Security headers/config.
- Testing.
- Deployment.
- Rollback.
- Documentation.

## Evidence Package

Create or update:

- [ ] `docs/plans/zephyrus-2.0-beta/evidence/B0/README.md`.
- [ ] route list output.
- [ ] API route list output.
- [ ] nav test output.
- [ ] route smoke output.
- [ ] decision register status export.
- [ ] route-family auth inventory.
- [ ] known limitations initial file.
- [ ] docs/index or authority-path diff.

## Workstream 0.1: PRD Authority And File Hygiene

- [ ] Decide how to handle `docs/product/ZEPHYRUS-2.0-BETA-PRD.md`:
  - [ ] Commit it as the beta product authority.
  - [ ] Move it to a different canonical path and update links.
  - [ ] Keep it local only and create a committed derivative authority.
- [ ] Add a short header note in the chosen authority file that states:
  - [ ] Date adopted.
  - [ ] Scope of authority.
  - [ ] Whether it supersedes older phase plans.
  - [ ] Whether these todo files are implementation companions.
- [ ] Add a link from the repo docs index or beta planning index to this todo folder.
- [ ] Add a release hygiene note that untracked authority documents must not drive commits without being intentionally tracked or intentionally excluded.

Verification:

```bash
git status --short
git ls-files docs/product/ZEPHYRUS-2.0-BETA-PRD.md
git ls-files docs/plans/zephyrus-2.0-beta
```

## Workstream 0.2: Documentation Correction For Current Seams

- [ ] Update `AGENTS.md` to replace stale `DashboardContext` navigation guidance with the current rule:
  - [ ] `resources/js/config/navigationConfig.ts` is the current top navigation and workflow navigation source.
  - [ ] `routes/web.php` is the source for Inertia page reachability and redirect behavior.
  - [ ] If a context is reintroduced later, document its relationship to `navigationConfig.ts`.
- [ ] Update any beta planning docs that say `/home` or workflow dashboards are the primary home if `/dashboard` is now canonical.
- [ ] Update docs that imply `DashboardContext` must be edited for RTDC ordering.
- [ ] Document the current RTDC order:
  - [ ] Bed Tracking.
  - [ ] Patient Flow 4D.
  - [ ] Bed Placement.
  - [ ] Ancillary Services.
  - [ ] Global Huddle.
  - [ ] Unit Huddle.
  - [ ] Service Huddle.
- [ ] Document that `deploy.sh` is the application deployment path and that migrations are an explicit post-deploy step.
- [ ] Document the difference between:
  - [ ] Local demo auto-login.
  - [ ] Beta demonstration environment auth.
  - [ ] Production auth expectations.

Verification:

```bash
rg -n "DashboardContext|workflowNavigationConfig|/home|deploy.sh|migrate --force" AGENTS.md docs
php artisan route:list | rg "dashboard|home|patient-flow|mobile|eddy"
```

## Workstream 0.3: Decision Register Closure

Resolve the stable `D1-D19` register in `DECISION-REGISTER.md` before implementing later phase work. The PRD uses `D1-D12`; local implementation decisions begin at `D13` so decision IDs no longer conflict.

Minimum B0 decisions to close or time-box:

- [ ] D1: Which domains may remain synthetic?
- [ ] D2: CDU2 / 500 versus 516 bed story.
- [ ] D3: Old dashboard rollback strategy.
- [ ] D4: Service-scope patient access for hospitalist/intensivist.
- [ ] D5: Barrier taxonomy storage.
- [ ] D6: Demo scenarios storage.
- [ ] D7: Production demo replacement mode.
- [ ] D8: Eddy local runtime.
- [ ] D9: Cloud model use.
- [ ] D10: APNs/FCM credentials.
- [ ] D11: Part X Arena in beta.
- [ ] D12: Native 4D viewer in beta.
- [ ] D13: PRD authority path.
- [ ] D14: Beta auth posture and whether demo auto-login is allowed outside local development.
- [ ] D15: Strict no-self-approval semantics for Eddy:
  - [ ] Agent can never approve.
  - [ ] Decide whether human requester and human approver must differ.
  - [ ] Decide whether role-specific override is allowed and how it is audited.
- [ ] D16: Patient display policy:
  - [ ] Patient names allowed for privileged roles only.
  - [ ] MRN never visible in broad beta screenshots.
  - [ ] Context refs and masked identifiers by default.
- [ ] D17: Mutable API exposure posture.
- [ ] D18: Reverb origins and rate-limit posture.
- [ ] D19: Rollback trigger and expected rollback window.

Deliverable:

- [ ] Create or update a committed decision register section with date, owner, decision, rationale, and affected phase.

## Workstream 0.4: PRD Traceability Guardrails

- [ ] Create a traceability fixture or markdown checklist that maps every PRD beta acceptance bullet to:
  - [ ] Code owner area.
  - [ ] Test file.
  - [ ] Route or UI surface.
  - [ ] Screenshot artifact where applicable.
  - [ ] Operational smoke check where applicable.
- [ ] Add route coverage for the route families the PRD depends on:
  - [ ] Cockpit.
  - [ ] Patient Flow.
  - [ ] RTDC.
  - [ ] ED.
  - [ ] Periop cases and room status.
  - [ ] Transport.
  - [ ] EVS.
  - [ ] Staffing.
  - [ ] Improvement.
  - [ ] Eddy.
  - [ ] Ops actions.
  - [ ] Integrations/admin.
  - [ ] Mobile BFF.
- [ ] Extend route smoke beyond web-only pages so API routes do not remain explicitly skipped.
- [ ] Keep mobile route/OpenAPI drift tests as a required gate.
- [ ] Add a test or script that fails if a `navigationConfig.ts` leaf points to a missing route, unless the leaf is explicitly external or disabled.

Suggested test files:

- `tests/Feature/RouteSmokeTest.php`.
- `tests/Feature/ApiRouteSmokeTest.php`.
- `tests/Feature/MobileBffTest.php`.
- `tests/Feature/MobileRoleCatalogParityTest.php`.
- `tests/Feature/MobileUiVocabularyParityTest.php`.

Verification:

```bash
php artisan route:list
php artisan test --filter=RouteSmokeTest
# Only after this file exists:
php artisan test --filter=ApiRouteSmokeTest
php artisan test --filter=MobileBffTest
php artisan test --filter=MobileRoleCatalogParityTest
php artisan test --filter=MobileUiVocabularyParityTest
```

## Workstream 0.5: API Auth And Exposure Inventory

- [ ] Inventory all routes in `routes/api.php` and classify each as:
  - [ ] Public read-only demo.
  - [ ] Session-auth read.
  - [ ] Session-auth mutable.
  - [ ] Sanctum/mobile token.
  - [ ] Admin-only.
  - [ ] Agent scoped-token.
  - [ ] Internal webhook.
- [ ] Add explicit middleware for routes that currently rely on throttle-only behavior but mutate state.
- [ ] Gate admin integration routes with admin middleware where beta requires admin-only access.
- [ ] Decide whether reference data endpoints can remain unauthenticated.
- [ ] Decide whether improvement fallback routes should be removed, gated, or labeled as demo-only.
- [ ] Add tests for expected `401`, `403`, and success behavior by route family.

Specific surfaces to review:

- `routes/api.php` mutable OR case and block routes.
- `routes/api.php` reference data routes.
- `routes/api.php` improvement fallback data routes.
- `routes/api.php` admin integration routes.
- `routes/api.php` Eddy agent scoped-token routes.

Verification:

```bash
php artisan route:list --path=api
# Only after this file exists:
php artisan test --filter=ApiAuthorizationTest
php artisan test --filter=EddyActionTest
php artisan test --filter=MobileBackendSafetyTest
```

## Workstream 0.6: Known Limitations Register

- [ ] Start `docs/product/beta-known-limitations.md` or an equivalent release note file.
- [ ] Add initial entries for:
  - [ ] Synthetic connector vs real integration status.
  - [ ] Android push decision.
  - [ ] Demo auto-login restrictions.
  - [ ] Any pages intentionally using seeded synthetic values.
  - [ ] Any hidden or disabled PRD scenarios.
  - [ ] Any post-beta vendor integration work.
- [ ] Add a "must not demo as live" section for intentionally synthetic data.
- [ ] Add a rule that B8 cannot pass with undocumented limitations.

## Phase Exit Gate

This phase is complete only when:

- [ ] PRD authority and tracking are settled.
- [ ] `AGENTS.md` no longer points contributors at stale navigation context.
- [ ] Decisions D1-D19 are resolved or explicitly time-boxed with an owner.
- [ ] Route drift coverage exists beyond mobile BFF.
- [ ] API auth inventory is committed and high-risk mutable/admin routes have tests or follow-up issues tied to B7/B8.
- [ ] Known limitations register exists.
- [ ] Validation commands for B0 pass or failures are documented with exact next fixes.
