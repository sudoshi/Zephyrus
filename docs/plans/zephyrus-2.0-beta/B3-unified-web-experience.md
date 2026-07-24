# B3 - Unified Web Experience

Goal: finish the cockpit-era web application experience: one home, one navigation model, consistent layout, explicit route disposition, no unlabelled mocks, and role-safe patient display.

Primary source: PRD section `B3 - Unified Web Experience`.

Exit principle: the beta web app should feel like one coherent command system, not a collection of old dashboards plus a new cockpit.

## Current Evidence

- `/dashboard` is the cockpit home.
- Legacy dashboard URLs redirect into `/dashboard?drill=...`.
- `navigationConfig.ts` is current navigation source.
- Several domain pages still use old mock/fallback patterns.
- Some pages display patient names, MRNs, or raw patient refs outside the newer patient-lens/redaction model.

## Deliverables

- [ ] One web shell and navigation policy.
- [ ] Route disposition table for every primary web route.
- [ ] Route smoke for every nav leaf.
- [ ] Mock mode gated or removed from beta surfaces.
- [ ] PHI display policy applied across web routes.
- [ ] Visual screenshot matrix for cockpit and major workspace routes.
- [ ] Accessibility and layout checks for compact, wall, desktop, tablet, and mobile web.

## Phase Entry Gate

B3 may start only after:

- [ ] B0 has resolved or time-boxed D3, D4, D14, D16, D17, and D18.
- [ ] B2 has published the snapshot/trust field contract or B3 records the interim schema it targets.
- [ ] `TRACEABILITY-MATRIX.md` rows `PRD-Z2-*`, `PRD-Z3-*`, and `PRD-Z4-*` are assigned.
- [ ] Existing nav, route, and UI tests are inventoried.
- [ ] The visual evidence directory for B3 exists.

Preflight commands:

```bash
git status --short --branch
php artisan route:list
php artisan test --filter=RouteSmokeTest
npm run test -- tests/js/config/navigationConfig.test.ts
npm run test -- tests/js/components
npm run test:e2e -- tests/e2e/navigation.spec.ts
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Route disposition | Backend Agent | QA Agent | B8 owner | route table and redirect tests |
| Navigation source of truth | Frontend Agent | QA Agent | all frontend owners | nav leaf route proof |
| Shell/layout convergence | Frontend Agent | Security Agent | B7/B8 owners | screenshot matrix and layout review |
| Mock-mode enforcement | Frontend Agent | Data Agent | B1/B8 owners | beta mock guard tests and label proof |
| Patient display policy | Security Agent | Backend Agent | B4/B6/B7 owners | shared helper/policy and role tests |
| Visual/accessibility evidence | QA Agent | Frontend Agent | B8 owner | Playwright/manual viewport archive |

## Agent Execution Contract

Owned write scope:

- `routes/web.php` only for route disposition/redirects.
- `resources/js/config/navigationConfig.ts`.
- web layout/components/pages needed for beta shell convergence.
- shared patient display helper/policy chosen by implementation.
- frontend and PHP route/nav/PHI/mock tests.

Read-only first:

- `resources/js/Contexts/ModeContext.tsx`.
- `resources/js/services/data-service.js`.
- `resources/js/Pages/RTDC/ServiceHuddle.jsx`.
- `resources/js/Pages/ED/Operations/Triage.jsx`.
- `resources/js/Pages/Dashboard/CommandCenter.tsx`.
- `tests/Feature/RouteSmokeTest.php`.
- `tests/e2e/navigation.spec.ts`.

Do not touch:

- Patient Flow backend API contracts owned by B4.
- Eddy action semantics owned by B5.
- Hummingbird native code owned by B6, except to document matching PHI policy expectations.

## Beta Mock-Mode Rule

For beta-visible web routes:

- [ ] Default data source must be live/provenanced or visibly synthetic/demo.
- [ ] `ModeContext`/`DataService` dev mock paths may remain for local development only if beta routes cannot silently enter mock mode.
- [ ] Any intentional fallback must set source mode/fallback state in API payloads and visible UI.
- [ ] Screenshots must show source/freshness where values drive action.
- [ ] Tests must fail when a beta-visible route renders unlabeled mock data.

Implementation targets to inspect:

- `resources/js/Contexts/ModeContext.tsx`.
- `resources/js/services/data-service.js`.
- beta-visible pages under `resources/js/Pages`.
- API responses supplying cockpit/domain values.

## Patient Display Policy Contract

B3 must either create or designate one shared policy/helper and test it. The policy must define:

| Surface | Aggregate Role | Privileged Role | Broad Screenshot Rule |
| --- | --- | --- | --- |
| Cockpit wall | no patient identifiers | no patient identifiers unless explicitly in detail modal | no MRN/name |
| RTDC/bed placement | masked/context ref by default | patient name only if role authorized | no MRN |
| ED triage/board | masked/context ref by default | patient name only if role authorized | no MRN |
| huddles | no unnecessary patient identifiers | patient detail only in authorized context | no MRN/name unless justified |
| Patient Flow | aggregate redaction by default | patient/encounter lens only when authorized | masked refs |
| Eddy prompts/context | aggregate/de-identified unless policy permits | minimal necessary patient context | no raw PHI in logs/screenshots |

Required code review targets:

- `app/Http/Middleware/SessionAuthMiddleware.php`.
- PHI-bearing API serializers/resources.
- `resources/js/Pages/RTDC/ServiceHuddle.jsx`.
- `resources/js/Pages/ED/Operations/Triage.jsx`.
- Patient Flow web components.
- cockpit action inbox/patient lens components.

## Validation Inventory

| Validation | Existing Or To Create | Required Evidence |
| --- | --- | --- |
| `RouteSmokeTest` | Existing, web-oriented | pass/fail output and noted API limitation |
| nav config test | Existing JS test | extended route reachability proof |
| `ApiRouteSmokeTest` | To create in B0/B7/B8 | not required for B3 exit unless web changes add API routes |
| beta mock guard | To create | unlabeled mock data fails beta route test |
| patient display policy tests | To create | role matrix for aggregate/privileged/broad screenshot surfaces |
| Playwright navigation | Existing command | `npm run test:e2e -- tests/e2e/navigation.spec.ts` |
| screenshot matrix | To capture | desktop/tablet/mobile/wall, light/dark where relevant |

## Criticality Double Check

B3 must pass or defer:

- Requirements traceability.
- Frontend quality.
- Authorization.
- PHI/privacy.
- Data trust.
- Accessibility/usability.
- Realtime/fallback when cockpit stream UI is touched.
- Testing.
- Documentation.

## Evidence Package

Create:

- [ ] `evidence/B3/README.md`.
- [ ] `commands/route-smoke.txt`.
- [ ] `commands/nav-tests.txt`.
- [ ] `commands/playwright-navigation.txt`.
- [ ] `screenshots/web/<viewport>-<role>-<surface>.png`.
- [ ] `reviews/route-disposition.md`.
- [ ] `reviews/mock-mode-review.md`.
- [ ] `reviews/patient-display-policy-review.md`.

## Workstream 3.1: Web Route Disposition

- [ ] Export or inspect all web routes.
- [ ] Classify every primary route as:
  - [ ] Canonical beta route.
  - [ ] Redirect to cockpit drill.
  - [ ] Admin/deploy route.
  - [ ] Study/analytics route.
  - [ ] Legacy route retained temporarily.
  - [ ] Hidden route.
  - [ ] Removed route.
- [ ] For every redirect, document:
  - [ ] Source route.
  - [ ] Target route.
  - [ ] Query parameters.
  - [ ] Reason.
  - [ ] Removal date or permanence.
- [ ] Add route smoke tests for canonical routes and redirects.
- [ ] Add a failing test for nav leaf routes that return 404/500.

Verification:

```bash
php artisan route:list
php artisan test --filter=RouteSmokeTest
```

## Workstream 3.2: Navigation Source Of Truth

- [ ] Treat `resources/js/config/navigationConfig.ts` as the beta nav source.
- [ ] Remove stale docs that instruct updates to `DashboardContext`.
- [ ] Add a test or static check that:
  - [ ] Every nav path has a matching route or explicit external flag.
  - [ ] Hidden nav items are intentionally flagged.
  - [ ] RTDC order matches the product decision.
  - [ ] Icons used in nav are importable.
  - [ ] Role-limited items are not visible to unauthorized roles.
- [ ] Add a developer note for adding future routes:
  - [ ] Add Inertia page.
  - [ ] Add route.
  - [ ] Add nav entry only if beta-visible.
  - [ ] Add smoke/screenshot coverage.

Verification:

```bash
rg -n "DashboardContext|navigationConfig|RTDC" AGENTS.md docs resources/js
npm run test
```

## Workstream 3.3: Shell And Layout Convergence

- [ ] Audit all beta-visible pages for layout wrappers:
  - [ ] Cockpit.
  - [ ] RTDC pages.
  - [ ] ED pages.
  - [ ] Periop pages.
  - [ ] Transport/EVS pages.
  - [ ] Staffing pages.
  - [ ] Improvement pages.
  - [ ] Analytics/Study pages.
  - [ ] Deploy/Admin pages.
- [ ] Ensure each page uses the intended authenticated shell.
- [ ] Ensure top navigation, breadcrumbs, page title, scope selector, source/freshness labels, and action affordances are consistent.
- [ ] Remove old dashboard chrome where it conflicts with cockpit-era IA.
- [ ] Verify wall mode removes unnecessary navigation while preserving source/freshness state.
- [ ] Verify compact pages do not use hero-sized typography inside dense panels.
- [ ] Verify no cards are nested inside cards unless they are modals or repeated items.

Visual checks:

- [ ] No overlapping text.
- [ ] No offscreen buttons.
- [ ] No table controls hidden on mobile.
- [ ] No source/freshness label truncation.
- [ ] No hidden PHI due to overflow/tooltip leakage.

## Workstream 3.4: Mock Mode And Fallback Cleanup

- [ ] Audit all usages of:
  - [ ] `ModeContext`.
  - [ ] `DataService`.
  - [ ] `mock-data`.
  - [ ] "fallback".
  - [ ] "representative demo".
  - [ ] hardcoded arrays in services/controllers/pages.
- [ ] For each beta-visible mock/fallback:
  - [ ] Replace with live data.
  - [ ] Replace with seeded synthetic data and label it.
  - [ ] Hide behind a demo flag.
  - [ ] Move to post-beta.
- [ ] Change beta default mode away from silent `dev` behavior where applicable.
- [ ] Add a test that beta cockpit/domain routes do not consume unlabelled mock payloads.
- [ ] Add visible empty states for truly absent data rather than falling back to hardcoded examples.

Known surfaces to review:

- `resources/js/Contexts/ModeContext.tsx`.
- `resources/js/services/data-service.js`.
- `resources/js/Pages/RTDC/BedTracking.tsx`.
- `resources/js/Pages/RTDC/ServiceHuddle.jsx`.
- `resources/js/Pages/Improvement/Process.jsx`.
- `resources/js/Pages/Improvement/Active.jsx`.
- `resources/js/Pages/Analytics.jsx`.
- `app/Data/CaseManagementMockData.php`.

Verification:

```bash
rg -n "mock|fallback|representative demo|ModeContext|DataService|mock-data" app resources routes tests
php artisan test
npm run test
```

## Workstream 3.5: PHI Display Policy

- [ ] Define one patient display helper/policy for web surfaces:
  - [ ] Privileged patient-care role.
  - [ ] Operational aggregate role.
  - [ ] Executive/wall display.
  - [ ] Mobile redacted parity.
  - [ ] Eddy context parity.
- [ ] Replace direct rendering of patient names, MRNs, and raw patient refs where policy requires masking.
- [ ] Review at least:
  - [ ] RTDC Bed Placement.
  - [ ] ED Triage.
  - [ ] Service Huddle.
  - [ ] Unit Huddle.
  - [ ] Patient Flow inspector.
  - [ ] Cockpit patient lens.
  - [ ] Eddy proposal context.
  - [ ] Action inbox.
- [ ] Add tests for role-specific payloads.
- [ ] Add screenshots for privileged and redacted roles.
- [ ] Add a PHI screenshot review checklist to B8.

Verification:

```bash
rg -n "patientName|patient_name|patient_ref|MRN|mrn|medical record" app resources tests
php artisan test --filter=MobileBackendSafetyTest
# Create or extend a focused patient-display/redaction test before using it as a gate.
```

## Workstream 3.6: Page-Level Source And Action Pattern

- [ ] Every beta page should show:
  - [ ] Scope.
  - [ ] As-of timestamp.
  - [ ] Source mode.
  - [ ] Freshness state.
  - [ ] Action status if actions are available.
  - [ ] Empty/degraded state if data is unavailable.
- [ ] Reuse cockpit source/freshness rendering where possible.
- [ ] Ensure Eddy-triggered suggestions are visually distinct from deterministic recommendations.
- [ ] Ensure user actions have:
  - [ ] Confirmation where high-risk.
  - [ ] Dry-run preview where required.
  - [ ] Audit event.
  - [ ] Mobile parity if relevant.

## Workstream 3.7: Visual And Accessibility Matrix

- [ ] Create screenshot script or manual checklist for:
  - [ ] `/dashboard`.
  - [ ] `/dashboard?display=wall`.
  - [ ] Primary RTDC pages.
  - [ ] Patient Flow 4D.
  - [ ] ED operations.
  - [ ] Periop operations.
  - [ ] Transport/EVS.
  - [ ] Staffing.
  - [ ] Improvement.
  - [ ] Study/Analytics.
  - [ ] Admin/Deploy health pages.
- [ ] Test viewports:
  - [ ] 390x844 mobile.
  - [ ] 768x1024 tablet.
  - [ ] 1440x900 desktop.
  - [ ] 1920x1080 wall.
  - [ ] 3840x2160 wall if available.
- [ ] Verify keyboard focus order for top nav and primary actions.
- [ ] Verify color contrast for status chips and freshness labels.
- [ ] Verify no hover-only action is required on touch devices.

Verification:

```bash
npm run build
npm run test
./scripts/check-ui-canon.sh
```

## Phase Exit Gate

This phase is complete only when:

- [ ] Every beta-visible web route has a disposition and smoke coverage.
- [ ] Navigation source and documentation are aligned.
- [ ] Legacy mock paths are removed, gated, or visibly labeled.
- [ ] PHI display policy is applied and tested.
- [ ] Major pages share cockpit-era shell semantics.
- [ ] Screenshot matrix is archived.
- [ ] UI canon, JS tests, PHP route tests, and build pass or exact blockers are captured.
