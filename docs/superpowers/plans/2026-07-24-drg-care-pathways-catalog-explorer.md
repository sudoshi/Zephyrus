# DRG Care Pathways Catalog Explorer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a comprehensive, on-canon "Catalog Explorer" surface that lets authorized staff examine all 250 governed DRG care pathways — an Overview dashboard, a searchable/filterable 250-pathway index, and a full per-pathway detail view — reading exclusively from the existing (already-complete) governance read API, and rebuild the off-canon Heart-Failure Demo onto the design system.

**Architecture:** The Laravel `CatalogGovernanceReadService` already returns every shape we need (`summary()`, `pathways()`, `version()`, `claims()`), fail-closed on `governance_enabled`, with the "not clinically approved / inactive" framing baked into every envelope. We add (1) two thin Inertia page routes/controllers that server-render initial data and delegate live filtering/pagination to the JSON API, (2) a Zod-validated typed API client + TanStack Query hooks, (3) three React pages built on the `Surface`/`Panel`/`MetricCard` canon primitives and `healthcare-*` tokens, and (4) a canon rebuild of `Demo.tsx`. No new backend business logic — the catalog is the single source of truth.

**Tech Stack:** Laravel 11 / PHP 8.4 (Inertia render + existing governance services), React 19 + TypeScript + Vite, TanStack Query v5, Zod v4, TailwindCSS v4 (`healthcare-*` tokens), lucide-react icons, PHPUnit (isolated `zephyrus_test_*` on host PG17).

---

## Non-Negotiable Constraints (read before any task)

- **Governance framing is mandatory.** This is a *reference/examination* surface, never a clinical-serving one. Every page must surface: `state = inactive`, `clinical_signoff_count = 0`, the envelope's `clinical_approval_warning` ("Automated evidence verification is not institutional clinical approval."), and `patient_serving:false / eddy_serving:false`. Never imply a pathway is clinically approved or active.
- **No serving flags are touched.** Only `CARE_PATHWAYS_GOVERNANCE_ENABLED` (a read gate) changes, and only on **dev** in this plan. Prod stays off until [SU] explicitly approves.
- **Token canon (CLAUDE.md).** `healthcare-*` tokens with `dark:` pairs only; **no raw Tailwind palette** (`slate/gray/cyan/emerald/rose/amber/white-*`); surfaces via `Surface`/`Panel`/`Card`/`MetricCard` only (never `bg-white`/hand-rolled); weights 400/500/600 only (**no `font-bold`**); Tailwind size scale only (**no `text-[Npx]`**); metrics/IDs `tabular-nums` (**never `font-mono`**); status by icon+label, never color alone; earned-urgency color rationing (`healthcare-critical/warning/success/info`); gold `:focus-visible`. `scripts/check-ui-canon.sh` must stay green and the raw-palette ratchet must **go down** (fixing Demo.tsx).
- **DB safety.** Host PG17 via `~/.pgpass`, TCP (`-h localhost -U claude_dev`), never Docker PG, never peer socket. All Phase 0 ops are additive to `zephyrus_dev` only and reversible via `DROP SCHEMA care_pathways CASCADE` on dev.
- **Named exports only** (except Inertia page default exports, which the framework requires). **No `any`** — every API boundary is Zod-validated.

## File Structure

**Backend (new):**
- `app/Http/Controllers/CarePathwayCatalogPageController.php` — `index()` (Overview+Index shell, server-renders `summary()` + page-1 `pathways()`) and `show($versionUuid)` (Detail shell, server-renders `version()`).
- `tests/Feature/CarePathways/CarePathwayCatalogPageTest.php` — gating + render assertions.

**Backend (modify):**
- `routes/web.php` — register the two Inertia routes (after the demo route, ~line 94).
- `app/Http/Middleware/HandleInertiaRequests.php` — add `care_pathways_catalog` to the `features` share (~line 98).
- `resources/js/config/navigationConfig.ts` — add `care_pathways_catalog` to `NavigationFeatures` (~line 82) and a "Catalog" group to the `CARE_PATHWAYS` nav domain (~line 193).

**Frontend data layer (new):**
- `resources/js/lib/carePathways/catalogSchemas.ts` — Zod schemas mirroring the PHP envelopes.
- `resources/js/lib/carePathways/catalogApi.ts` — typed axios client returning parsed data.
- `resources/js/hooks/carePathways/useCatalog.ts` — TanStack Query hooks.

**Frontend pages (new):**
- `resources/js/Pages/CarePathways/Catalog/Index.tsx` — Overview + Pathway Index.
- `resources/js/Pages/CarePathways/Catalog/Show.tsx` — Pathway Detail.

**Frontend components (new, under `resources/js/Components/CarePathways/`):**
- `GovernanceBanner.tsx` — the mandatory inactive/not-approved framing (reused on every page).
- `CatalogOverview.tsx` — release header + metric tiles + evidence/disposition partitions + distributions.
- `ActivationReadiness.tsx` — activation blockers list from `release_readiness` + counts.
- `PathwayFilters.tsx` — search + faceted filter controls.
- `PathwayTable.tsx` — virtualized/paginated 250-row table.
- `EvidenceBadge.tsx`, `GovernanceStatus.tsx`, `DrgChips.tsx` — small shared display atoms (icon+label, never color alone).
- `PathwaySections.tsx` — the 28 clinical prose sections (accordion, source vs approved).
- `PathwayAuthoring.tsx` — milestones / goals / activities / education.
- `EvidenceClaims.tsx` — paginated claims → sources.
- `GovernanceLedger.tsx` — reviews / approvals / change log / provenance digests.

**Frontend (modify):**
- `resources/js/Pages/CarePathways/Demo.tsx` — canon rebuild + "Back to Catalog" cross-link.

---

## Phase 0 — Provision dev data + enable read gate (dev only, additive, reversible)

> Ops, not TDD. The governance API and every page below return empty/404 until `zephyrus_dev` holds the adopted catalog. `zephyrus_dev` currently has **no `care_pathways` schema**; the fully-adopted release lives in the separate `zephyrus` DB, and the raw source tables (`raw.drg_cp_*`) live there too. We reproduce the canonical migrate → load raw → adopt pipeline on `zephyrus_dev`.

### Task 0.1: Run the care_pathways migrations on zephyrus_dev

**Files:** none (DB migration).

- [ ] **Step 1: Confirm starting state (schema absent)**

Run:
```bash
export PGPASSFILE=~/.pgpass
psql -h localhost -U claude_dev -d zephyrus_dev -tAc "SELECT to_regclass('care_pathways.catalog_releases');"
```
Expected: empty line (schema absent).

- [ ] **Step 2: Run ONLY the 8 catalog migrations (skip the patient-instance one)**

The 9th migration (`2026_07_22_001400_create_patient_pathway_instance_history`) builds `patient_experience.*` tables that depend on `encounter_access_grants` and are **out of scope** for a read-only catalog explorer. Run the 8 catalog migrations by path:

```bash
cd /home/smudoshi/Github/Zephyrus
for m in 2026_07_21_000900_create_care_pathway_catalog \
         2026_07_21_001000_extend_care_pathway_provenance \
         2026_07_21_001100_create_care_pathway_current_provenance_views \
         2026_07_21_001200_create_care_pathway_source_status_ledger \
         2026_07_21_001300_repair_care_pathway_source_retraction_negation \
         2026_07_21_001400_create_care_pathway_release_source_membership \
         2026_07_21_001500_protect_care_pathway_section_sources \
         2026_07_21_001600_enforce_care_pathway_source_membership; do
  php artisan migrate --path="database/migrations/${m}.php" --database=pgsql
done
```
Expected: each prints `DONE`. (These target `DB_DATABASE=zephyrus_dev` per `.env`.)

- [ ] **Step 3: Verify schema + triggers exist**

Run:
```bash
psql -h localhost -U claude_dev -d zephyrus_dev -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='care_pathways' AND table_type='BASE TABLE';"
```
Expected: `25`.

### Task 0.2: Copy the raw verification tables from zephyrus → zephyrus_dev

**Files:** none (DB data copy).

- [ ] **Step 1: Dump the raw drg_cp_* tables + manifest from zephyrus**

```bash
export PGPASSFILE=~/.pgpass
pg_dump -h localhost -U claude_dev -d zephyrus \
  --table='raw.drg_care_pathway_verification_imports' \
  --table='raw.drg_cp_*' \
  --no-owner --no-privileges -f /tmp/drg_cp_raw.sql
```
Expected: file written, no error.

- [ ] **Step 2: Load into zephyrus_dev (raw schema already exists there)**

```bash
psql -h localhost -U claude_dev -d zephyrus_dev -v ON_ERROR_STOP=1 -f /tmp/drg_cp_raw.sql
```
Expected: `COPY` lines, no error. If `raw` schema is missing, precede with `psql -h localhost -U claude_dev -d zephyrus_dev -c "CREATE SCHEMA IF NOT EXISTS raw;"`.

- [ ] **Step 3: Verify raw manifest row id=1 exists**

```bash
psql -h localhost -U claude_dev -d zephyrus_dev -tAc "SELECT count(*) FROM raw.drg_cp_verified_pathways_v43_1_20260721;"
```
Expected: `250`.

### Task 0.3: Adopt the release inactive via the canonical pipeline

**Files:** none (artisan command).

- [ ] **Step 1: Dry-run to validate all controls before writing**

```bash
cd /home/smudoshi/Github/Zephyrus
php artisan care-pathways:adopt-raw-release 1 --actor="dev-bootstrap-2026-07-24" --dry-run
```
Expected: `Care-pathway release controls passed (dry run).` with `Pathways: 250`, `MS-DRG codebook entries: 770`, `Pathway-to-DRG associations: 802`, `Evidence claims: 10123`. If any control fails, STOP — the raw copy is incomplete; re-run Task 0.2.

- [ ] **Step 2: Adopt for real (writes the inactive catalog)**

```bash
php artisan care-pathways:adopt-raw-release 1 --actor="dev-bootstrap-2026-07-24"
```
Expected: `Care-pathway release adopted inactive.` `State: inactive` `Pathways: 250`.

- [ ] **Step 3: Verify population matches zephyrus**

```bash
psql -h localhost -U claude_dev -d zephyrus_dev -tAc "SELECT (SELECT count(*) FROM care_pathways.definitions) defs, (SELECT count(*) FROM care_pathways.versions) versions, (SELECT count(*) FROM care_pathways.sections) sections, (SELECT count(*) FROM care_pathways.drg_codebook_entries) drgs, (SELECT count(*) FROM care_pathways.evidence_claims) claims, (SELECT clinical_signoff_count FROM care_pathways.catalog_releases) signoffs;"
```
Expected: `250|250|7000|770|10123|0`.

### Task 0.4: Enable the governance read gate on dev + verify end-to-end

**Files:** Modify `.env` (dev only — not committed).

- [ ] **Step 1: Add the dev read flag**

Append to `/home/smudoshi/Github/Zephyrus/.env`:
```
CARE_PATHWAYS_GOVERNANCE_ENABLED=true
```

- [ ] **Step 2: Clear config cache**

```bash
php artisan config:clear
```
Expected: `Configuration cache cleared successfully.`

- [ ] **Step 3: Confirm the admin user holds `viewCarePathwayCatalog`**

```bash
php artisan tinker --execute="\$u = App\Models\User::where('email','admin@acumenus.net')->first(); dump(app(App\Services\Authorization\RoleCapabilityService::class)->allows(\$u, App\Authorization\Capability::ViewCarePathwayCatalog));"
```
Expected: `true`. If `false`, note in the devlog — the page will 403 for that user and a capability grant is a separate [SU] decision (do NOT self-grant).

- [ ] **Step 4: Smoke the JSON API returns the 250 catalog (authenticated)**

Start the dev server if not running, then confirm the summary endpoint returns data (not 404). Document the exact curl (with session cookie) in the devlog. Expected `meta.dataset_key = "drg-care-pathways-verification-package-v43.1-20260721"`, `data.release.state = "inactive"`, `data.release.clinical_signoff_count = 0`.

---

## Phase 1 — Inertia page routes, controllers, share, nav (backend, TDD)

### Task 1.1: CarePathwayCatalogPageController + routes (test-first)

**Files:**
- Create: `app/Http/Controllers/CarePathwayCatalogPageController.php`
- Create: `tests/Feature/CarePathways/CarePathwayCatalogPageTest.php`
- Modify: `routes/web.php` (after the `/care-pathways/demo` route, ~line 94)

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\CarePathways;

use App\Authorization\Capability;
use App\Models\User;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

final class CarePathwayCatalogPageTest extends TestCase
{
    use CarePathwayRawFixture;

    public function test_catalog_index_returns_404_when_governance_disabled(): void
    {
        config(['care-pathways.governance_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/care-pathways/catalog')->assertNotFound();
    }

    public function test_catalog_index_renders_inertia_page_with_summary_for_authorized_user(): void
    {
        $this->seedAdoptedCarePathwayRelease(); // fixture trait: migrates + adopts release into the test DB
        config(['care-pathways.governance_enabled' => true]);
        $user = $this->userWithCapability(Capability::ViewCarePathwayCatalog);

        $this->actingAs($user)
            ->get('/care-pathways/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CarePathways/Catalog/Index')
                ->where('initialSummary.data.release.state', 'inactive')
                ->where('initialSummary.data.release.clinical_signoff_count', 0)
                ->has('initialPathways.data'));
    }

    public function test_catalog_show_404s_for_unknown_version(): void
    {
        $this->seedAdoptedCarePathwayRelease();
        config(['care-pathways.governance_enabled' => true]);
        $user = $this->userWithCapability(Capability::ViewCarePathwayCatalog);

        $this->actingAs($user)
            ->get('/care-pathways/catalog/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
```

Note: reuse/extend `Tests\Support\CarePathwayRawFixture`. If it lacks `seedAdoptedCarePathwayRelease()` / `userWithCapability()` helpers, add them mirroring `CarePathwayGovernanceApiTest` (which already boots an adopted release + authorized user). Read that test first and copy its exact bootstrapping.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./scripts/test-suite.sh contract` filtered to the new test, or:
`php artisan test --filter=CarePathwayCatalogPageTest`
Expected: FAIL — route `/care-pathways/catalog` not defined (404 on the render test / route-not-found).

- [ ] **Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Services\CarePathways\CatalogGovernanceReadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CarePathwayCatalogPageController extends Controller
{
    public function __construct(
        private readonly CatalogGovernanceReadService $catalog,
    ) {}

    public function index(Request $request): Response
    {
        $summary = $this->catalog->summary();
        if ($summary === null) {
            throw new NotFoundHttpException();
        }

        $pathways = $this->catalog->pathways(['page' => 1, 'per_page' => 25]);

        return Inertia::render('CarePathways/Catalog/Index', [
            'initialSummary' => $summary,
            'initialPathways' => $pathways,
        ]);
    }

    public function show(Request $request, string $versionUuid): Response
    {
        $version = $this->catalog->version($versionUuid);
        if ($version === null) {
            throw new NotFoundHttpException();
        }

        return Inertia::render('CarePathways/Catalog/Show', [
            'initialVersion' => $version,
        ]);
    }
}
```

- [ ] **Step 4: Register the routes** in `routes/web.php` immediately after the `/care-pathways/demo` route (~line 94), inside the same authenticated group:

```php
        Route::middleware([
            \App\Http\Middleware\EnsureCarePathwayGovernanceEnabled::class,
            'can:viewCarePathwayCatalog',
        ])->group(function (): void {
            Route::get('/care-pathways/catalog', [\App\Http\Controllers\CarePathwayCatalogPageController::class, 'index'])
                ->name('care-pathways.catalog');
            Route::get('/care-pathways/catalog/{versionUuid}', [\App\Http\Controllers\CarePathwayCatalogPageController::class, 'show'])
                ->whereUuid('versionUuid')
                ->name('care-pathways.catalog.show');
        });
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=CarePathwayCatalogPageTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Run Pint**

Run: `docker compose exec -T php sh -c "cd /var/www/html && vendor/bin/pint"` (or the host Pint if Docker isn't up: `vendor/bin/pint app/Http/Controllers/CarePathwayCatalogPageController.php`).
Expected: no style errors.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CarePathwayCatalogPageController.php tests/Feature/CarePathways/CarePathwayCatalogPageTest.php routes/web.php
git commit -m "feat: add care pathways catalog explorer page routes (gated, read-only)"
```

### Task 1.2: Share the `care_pathways_catalog` feature flag

**Files:** Modify `app/Http/Middleware/HandleInertiaRequests.php` (features block, ~line 98).

- [ ] **Step 1: Add the flag** inside the `'features' => [ ... ]` array, next to `care_pathways_demo`:

```php
                // Read-only governance examination surface for the inactive
                // catalog; gated identically to the governance JSON API so nav
                // and route never disagree. Never a clinical-serving gate.
                'care_pathways_catalog' => (bool) config('care-pathways.governance_enabled'),
```

- [ ] **Step 2: Verify it serializes** — start dev server, load any authenticated page, confirm `features.care_pathways_catalog === true` in the Inertia `page.props` (browser devtools or `window`). Expected: `true` on dev.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat: share care_pathways_catalog feature flag to frontend nav"
```

### Task 1.3: Add the Catalog nav entry

**Files:** Modify `resources/js/config/navigationConfig.ts` (type ~line 82; `CARE_PATHWAYS` domain ~line 193).

- [ ] **Step 1: Extend `NavigationFeatures`** — add after `care_pathways_demo`:

```ts
  readonly care_pathways_catalog?: boolean;
```

- [ ] **Step 2: Add a "Catalog" group** to the `CARE_PATHWAYS` domain's `groups` array (before or after the existing "Simulation" group):

```ts
    {
      title: 'Catalog',
      items: [
        {
          label: 'Catalog Explorer',
          href: '/care-pathways/catalog',
          icon: BookOpen, // import from lucide-react at top of file
          requiredFeature: 'care_pathways_catalog',
        },
      ],
    },
```

Ensure `BookOpen` is added to the existing `lucide-react` import. Do NOT change `dashboardHref`/`requiredFeature` of the domain (it stays `care_pathways_demo`), so the domain shows whenever *either* the demo or the catalog is enabled and each leaf self-gates.

- [ ] **Step 3: Type-check**

Run: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/config/navigationConfig.ts
git commit -m "feat: add Catalog Explorer to Care Pathways nav (feature-gated)"
```

---

## Phase 2 — Frontend data layer: Zod schemas, API client, hooks

### Task 2.1: Zod schemas mirroring the governance envelopes

**Files:** Create `resources/js/lib/carePathways/catalogSchemas.ts`.

These MUST match `CatalogGovernanceReadService` exactly. Nullable fields use `.nullable()`; unknown-but-present JSON uses `z.unknown()`.

- [ ] **Step 1: Write the schemas**

```ts
import { z } from "zod";

const metaSchema = z.object({
  schema: z.string(),
  catalog_release_uuid: z.string(),
  dataset_key: z.string(),
  grouper_version: z.string(),
  source_cutoff_date: z.string().nullable(),
  as_of: z.string(),
  clinical_approval_warning: z.string(),
  patient_serving: z.boolean(),
  hummingbird_serving: z.boolean(),
  eddy_serving: z.boolean(),
  pagination: z
    .object({ page: z.number(), per_page: z.number(), total: z.number(), last_page: z.number() })
    .optional(),
});

const releaseSchema = z.object({
  catalog_release_uuid: z.string(),
  dataset_key: z.string(),
  grouper_version: z.string(),
  grouper_effective_period: z.object({ start: z.string().nullable(), end: z.string().nullable() }),
  state: z.string(),
  clinical_signoff_complete: z.boolean(),
  clinical_signoff_count: z.number(),
  pathway_count: z.number(),
  adopted_at: z.string().nullable(),
  activated_at: z.string().nullable(),
  withdrawn_at: z.string().nullable(),
});

export const summaryEnvelopeSchema = z.object({
  data: z.object({
    release: releaseSchema,
    catalog: z.object({
      definitions: z.number(),
      versions: z.number(),
      institutionally_approved_versions: z.number(),
      active_versions: z.number(),
      sections: z.number(),
      approved_sections: z.number(),
      patient_or_caregiver_sections: z.number(),
      drg_codebook_entries: z.number(),
      drg_mappings: z.number(),
      evidence_claims: z.number(),
      sources: z.number(),
      current_sources: z.number(),
      noncurrent_sources: z.number(),
      changes: z.number(),
    }),
    review_queues: z.object({
      evidence_verified: z.number(),
      evidence_limitations: z.number(),
      institutional_signoff: z.number(),
      specialist_review: z.number(),
      redesign: z.number(),
      recorded_reviews: z.number(),
      recorded_approvals: z.number(),
    }),
    controls: z.object({
      by_status: z.record(z.string(), z.number()),
      failed: z.number(),
      residual_unknowns: z.number(),
      service_line_mappings: z.record(z.string(), z.number()),
    }),
    serving_flags: z.record(z.string(), z.boolean()),
    release_readiness: z.object({
      clinical_signoff_complete: z.boolean(),
      may_serve_approved_catalog: z.boolean(),
      patient_projection_released: z.boolean(),
      eddy_retrieval_released: z.boolean(),
    }),
    authorization: z.record(z.string(), z.boolean()).optional(),
  }),
  meta: metaSchema,
});

const drgCandidateSchema = z.object({
  ms_drg: z.string(),
  title: z.string(),
  mdc: z.string().nullable(),
  type_code: z.string().nullable(),
  type_label: z.string().nullable(),
  mapping_role: z.string(),
  ambiguity_note: z.string().nullable(),
  requires_clinician_confirmation: z.boolean(),
});

const pathwayIdentitySchema = z.object({
  uuid: z.string(),
  key: z.string(),
  name: z.string(),
  mdc: z.string().nullable(),
  care_type: z.string().nullable(),
  source_service_line: z.string().nullable(),
  service_line_code: z.string().nullable(),
  lifecycle_state: z.string(),
});

const versionIdentitySchema = z.object({
  version_uuid: z.string(),
  semantic_version: z.string(),
  source_rank: z.number().nullable(),
  content_digest: z.string(),
  effective_period: z.object({ start: z.string().nullable(), end: z.string().nullable() }),
  source_cutoff_date: z.string().nullable(),
  exact_version: z.boolean(),
});

const evidenceSchema = z.object({
  status: z.string(),
  confidence: z.string().nullable(),
  source_specificity: z.string().nullable(),
  release_disposition: z.string(),
  claim_count: z.number(),
  source_currency: z.string(),
});

const pathwayRowGovernanceSchema = z.object({
  clinical_signoff_status: z.string(),
  institutional_approval_status: z.string(),
  activation_status: z.string(),
  section_count: z.number(),
  approved_section_count: z.number(),
  review_count: z.number(),
  approval_count: z.number(),
});

export const pathwayRowSchema = z.object({
  pathway: pathwayIdentitySchema,
  version: versionIdentitySchema,
  drg_candidates: z.array(drgCandidateSchema),
  evidence: evidenceSchema,
  governance: pathwayRowGovernanceSchema,
});

export const pathwaysEnvelopeSchema = z.object({
  data: z.array(pathwayRowSchema),
  meta: metaSchema,
});

const reviewSchema = z.object({
  review_uuid: z.string(),
  reviewer_role: z.string(),
  review_scope: z.string(),
  decision: z.string(),
  reason: z.string(),
  issues: z.unknown(),
  reviewed_at: z.string().nullable(),
});

const approvalSchema = z.object({
  approval_uuid: z.string(),
  approval_type: z.string(),
  decision: z.string(),
  conditions: z.string().nullable(),
  effective_period: z.object({ start: z.string().nullable(), end: z.string().nullable() }),
  decided_at: z.string().nullable(),
});

const sectionSchema = z.object({
  section_uuid: z.string(),
  section_code: z.string(),
  audience: z.string(),
  language_code: z.string(),
  source_text: z.string(),
  approved_text: z.string().nullable(),
  content_mode: z.string(),
  review_state: z.string(),
  source_digest: z.string(),
  approved_digest: z.string().nullable(),
});

const milestoneSchema = z.object({
  milestone_uuid: z.string(),
  stable_key: z.string(),
  title: z.string(),
  phase: z.string().nullable(),
  sequence: z.number().nullable(),
  predecessor_keys: z.unknown(),
  expected_range: z.unknown(),
  applicability_ref: z.string().nullable(),
  completion_evidence_ref: z.string().nullable(),
  review_state: z.string(),
});

const goalSchema = z.object({
  goal_uuid: z.string(),
  stable_key: z.string(),
  goal_code: z.string().nullable(),
  goal_text: z.string(),
  author_type: z.string(),
  target: z.unknown(),
  patient_visible_explanation: z.string().nullable(),
  review_state: z.string(),
});

const activitySchema = z.object({
  activity_uuid: z.string(),
  stable_key: z.string(),
  activity_type: z.string(),
  title: z.string(),
  performer_role: z.string().nullable(),
  timing: z.unknown(),
  preconditions: z.unknown(),
  executable: z.boolean(),
  fhir_canonical_ref: z.string().nullable(),
  review_state: z.string(),
});

const educationSchema = z.object({
  education_uuid: z.string(),
  stable_key: z.string(),
  audience: z.string(),
  language_code: z.string(),
  reading_level: z.string().nullable(),
  title: z.string(),
  approved_content: z.string().nullable(),
  teach_back_prompt: z.string().nullable(),
  required_reviewer_role: z.string().nullable(),
  content_digest: z.string().nullable(),
  review_state: z.string(),
});

export const versionEnvelopeSchema = z.object({
  data: z.object({
    pathway: pathwayIdentitySchema,
    version: versionIdentitySchema.extend({
      source_digest: z.string(),
      unresolved_flags: z.unknown(),
    }),
    drg_candidates: z.array(drgCandidateSchema),
    evidence: evidenceSchema,
    governance: z.object({
      clinical_signoff_status: z.string(),
      institutional_approval_status: z.string(),
      activation_status: z.string(),
      reviews: z.array(reviewSchema),
      approvals: z.array(approvalSchema),
    }),
    sections: z.array(sectionSchema),
    authoring: z.object({
      milestones: z.array(milestoneSchema),
      activities: z.array(activitySchema),
      goals: z.array(goalSchema),
      education: z.array(educationSchema),
    }),
    changes: z.array(z.record(z.string(), z.unknown())),
  }),
  meta: metaSchema,
});

const claimSchema = z.object({
  claim_uuid: z.string(),
  source_rank: z.number(),
  source_field: z.string(),
  claim_type: z.string().nullable(),
  claim_excerpt: z.string(),
  automated_review: z.object({ pass_1: z.unknown(), pass_2: z.unknown() }),
  clinical_adjudication: z.unknown(),
  verification_date: z.string().nullable(),
  claim_digest: z.string(),
  sources: z.array(
    z.object({
      source_uuid: z.string(),
      pmid: z.string().nullable(),
      title: z.string().nullable(),
      current_status: z.string(),
      content_digest: z.string(),
      evidence_grade: z.string().nullable(),
      applicability_note: z.string().nullable(),
    }),
  ),
});

export const claimsEnvelopeSchema = z.object({
  data: z.array(claimSchema),
  meta: metaSchema,
});

export type SummaryEnvelope = z.infer<typeof summaryEnvelopeSchema>;
export type PathwaysEnvelope = z.infer<typeof pathwaysEnvelopeSchema>;
export type PathwayRow = z.infer<typeof pathwayRowSchema>;
export type VersionEnvelope = z.infer<typeof versionEnvelopeSchema>;
export type ClaimsEnvelope = z.infer<typeof claimsEnvelopeSchema>;
```

- [ ] **Step 2: Type-check**

Run: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/lib/carePathways/catalogSchemas.ts
git commit -m "feat: add Zod schemas for care pathways governance envelopes"
```

### Task 2.2: Typed API client + query filter type

**Files:** Create `resources/js/lib/carePathways/catalogApi.ts`.

- [ ] **Step 1: Write the client**

```ts
import axios from "axios";
import {
  claimsEnvelopeSchema,
  pathwaysEnvelopeSchema,
  summaryEnvelopeSchema,
  versionEnvelopeSchema,
  type ClaimsEnvelope,
  type PathwaysEnvelope,
  type SummaryEnvelope,
  type VersionEnvelope,
} from "./catalogSchemas";

const BASE = "/api/care-pathways/v1";

export interface PathwayQuery {
  q?: string;
  drg?: string;
  mdc?: string;
  service_line?: string;
  evidence_state?: "verified" | "limitations";
  disposition?: "signoff" | "specialist_review" | "redesign";
  institutional_approval_status?: "not_reviewed" | "in_review" | "approved" | "rejected" | "withdrawn";
  activation_status?: "inactive" | "active" | "withdrawn";
  page?: number;
  per_page?: number;
}

export async function fetchSummary(): Promise<SummaryEnvelope> {
  const { data } = await axios.get<unknown>(`${BASE}/summary`);
  return summaryEnvelopeSchema.parse(data);
}

export async function fetchPathways(query: PathwayQuery): Promise<PathwaysEnvelope> {
  const params = Object.fromEntries(
    Object.entries(query).filter(([, v]) => v !== undefined && v !== ""),
  );
  const { data } = await axios.get<unknown>(`${BASE}/pathways`, { params });
  return pathwaysEnvelopeSchema.parse(data);
}

export async function fetchVersion(versionUuid: string): Promise<VersionEnvelope> {
  const { data } = await axios.get<unknown>(`${BASE}/versions/${versionUuid}`);
  return versionEnvelopeSchema.parse(data);
}

export async function fetchClaims(versionUuid: string, page = 1, perPage = 50): Promise<ClaimsEnvelope> {
  const { data } = await axios.get<unknown>(`${BASE}/versions/${versionUuid}/claims`, {
    params: { page, per_page: perPage },
  });
  return claimsEnvelopeSchema.parse(data);
}
```

- [ ] **Step 2: Type-check + commit**

Run: `npx tsc --noEmit` (Expected: no errors), then:
```bash
git add resources/js/lib/carePathways/catalogApi.ts
git commit -m "feat: add typed care pathways catalog API client"
```

### Task 2.3: TanStack Query hooks

**Files:** Create `resources/js/hooks/carePathways/useCatalog.ts`.

- [ ] **Step 1: Write the hooks** (TanStack Query is already provided app-wide via `Providers/HeroUIProvider`):

```ts
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import {
  fetchClaims,
  fetchPathways,
  fetchSummary,
  fetchVersion,
  type PathwayQuery,
} from "@/lib/carePathways/catalogApi";
import type {
  ClaimsEnvelope,
  PathwaysEnvelope,
  SummaryEnvelope,
  VersionEnvelope,
} from "@/lib/carePathways/catalogSchemas";

export function useCatalogSummary(initialData?: SummaryEnvelope) {
  return useQuery({
    queryKey: ["care-pathways", "summary"],
    queryFn: fetchSummary,
    initialData,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCatalogPathways(query: PathwayQuery, initialData?: PathwaysEnvelope) {
  return useQuery({
    queryKey: ["care-pathways", "pathways", query],
    queryFn: () => fetchPathways(query),
    initialData:
      initialData && query.page === 1 && Object.keys(query).length <= 2 ? initialData : undefined,
    placeholderData: keepPreviousData,
  });
}

export function useCatalogVersion(versionUuid: string, initialData?: VersionEnvelope) {
  return useQuery({
    queryKey: ["care-pathways", "version", versionUuid],
    queryFn: () => fetchVersion(versionUuid),
    initialData,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCatalogClaims(versionUuid: string, page: number) {
  return useQuery<ClaimsEnvelope>({
    queryKey: ["care-pathways", "claims", versionUuid, page],
    queryFn: () => fetchClaims(versionUuid, page),
    placeholderData: keepPreviousData,
  });
}
```

- [ ] **Step 2: Type-check + commit**

Run: `npx tsc --noEmit` (Expected: no errors), then:
```bash
git add resources/js/hooks/carePathways/useCatalog.ts
git commit -m "feat: add TanStack Query hooks for care pathways catalog"
```

---

## Phase 3 — Overview + Pathway Index page

> Visual polish for Phases 3–4 goes through `/impeccable craft` (reads PRODUCT.md + DESIGN.md). All components below use ONLY `healthcare-*` tokens + `Surface`/`Panel`/`MetricCard`. Status atoms pair an icon + label (never color alone). Reserve `healthcare-critical` (coral) strictly for real breaches — here, the only "critical" framing allowed is the inactive/blocked governance state, shown as `healthcare-warning` (amber), not coral.

### Task 3.1: GovernanceBanner (mandatory framing, reused everywhere)

**Files:** Create `resources/js/Components/CarePathways/GovernanceBanner.tsx`.

- [ ] **Step 1: Implement** — a `Panel`-based banner. Props: `{ warning: string; state: string; signoffCount: number }`. Renders a `ShieldAlert` (lucide) icon + heading "Reference catalog — not clinically approved", the `warning` text, and three inline facts (`tabular-nums`): `State: {state}`, `Clinical sign-offs: {signoffCount}`, `Patient/Eddy serving: Off`. Tokens: `bg-healthcare-warning/10` equivalent via `healthcare-*` (use `text-healthcare-warning dark:text-healthcare-warning-dark` for the icon; body text `text-healthcare-text-primary`). Named export `GovernanceBanner`.

- [ ] **Step 2: Type-check.** Run `npx tsc --noEmit`. Expected: no errors.

### Task 3.2: Overview + partition + distribution components

**Files:** Create `resources/js/Components/CarePathways/CatalogOverview.tsx`, `resources/js/Components/CarePathways/ActivationReadiness.tsx`.

- [ ] **Step 1: `CatalogOverview`** — prop `{ summary: SummaryEnvelope["data"]; meta: SummaryEnvelope["meta"] }`. Layout:
  - Release header (`Panel`): grouper version, effective period, dataset key (`tabular-nums`), `as_of`.
  - Metric tiles row (`MetricCard`): Pathways (`catalog.definitions`), DRG codes (`catalog.drg_codebook_entries`), DRG associations (`catalog.drg_mappings`), Evidence claims (`catalog.evidence_claims`), Sources (`catalog.sources`), Clinical sign-offs (`release.clinical_signoff_count`, always 0 → render with `healthcare-warning` label + `AlertTriangle` icon, NOT success).
  - Evidence partition (`Panel`): a labelled bar — `review_queues.evidence_verified` (96) vs `evidence_limitations` (154) of `pathway_count` (250). Use `healthcare-success` for verified, `healthcare-warning` for limitations, each with an icon + numeric label.
  - Disposition partition (`Panel`): `institutional_signoff` (96) / `specialist_review` (148) / `redesign` (6), same treatment (`info` / `warning` / `warning`).
  - Controls (`Panel`): `controls.by_status` as labelled counts; `controls.failed` (expect 0) and `controls.residual_unknowns` (expect 0) shown with `CheckCircle` when 0.

- [ ] **Step 2: `ActivationReadiness`** — prop `{ readiness: SummaryEnvelope["data"]["release_readiness"]; release: SummaryEnvelope["data"]["release"]; catalog: SummaryEnvelope["data"]["catalog"] }`. Derives and lists the activation blockers as an explicit checklist (icon + label), e.g.:
  - `Clinical sign-off complete` → `readiness.clinical_signoff_complete` (false → `XCircle` amber "Not complete — 0/250 institutional sign-offs")
  - `All versions institutionally approved` → `catalog.institutionally_approved_versions === release.pathway_count`
  - `All versions active` → `catalog.active_versions === release.pathway_count`
  - `May serve approved catalog` → `readiness.may_serve_approved_catalog` (false)
  Each false item uses `healthcare-warning` + an icon; the panel title is "Why this catalog stays inactive". This is the honest "defensible" framing [SU] expects.

- [ ] **Step 3: Type-check.** Run `npx tsc --noEmit`. Expected: no errors.

### Task 3.3: Filters + table + display atoms

**Files:** Create `EvidenceBadge.tsx`, `GovernanceStatus.tsx`, `DrgChips.tsx`, `PathwayFilters.tsx`, `PathwayTable.tsx` under `resources/js/Components/CarePathways/`.

- [ ] **Step 1: Display atoms**
  - `EvidenceBadge` — prop `{ status: string }`. Maps the long status label to a short chip with icon: contains "verified" → `ShieldCheck` + `healthcare-success` "Verified"; contains "limitations" → `AlertTriangle` + `healthcare-warning` "Limitations". Icon + text, never color-only.
  - `GovernanceStatus` — prop `{ approval: string; activation: string }`. Renders two chips: institutional approval (`not_reviewed`→`Circle` neutral; `approved`→`CheckCircle` success; `rejected`→`XCircle` warning) and activation (`inactive`→`PauseCircle` neutral). Always icon + label.
  - `DrgChips` — prop `{ drgs: DrgCandidate[]; max?: number }`. Renders up to `max` (default 6) MS-DRG codes as monospaced-look chips using `tabular-nums` (NOT `font-mono`), each `role`-tinted via `healthcare-*`; overflow "+N more". Tooltip/title = DRG `title`.

- [ ] **Step 2: `PathwayFilters`** — prop `{ value: PathwayQuery; onChange: (next: PathwayQuery) => void }`. Controls: debounced search input (`q`), DRG code input (`drg`, 3-digit), MDC text (`mdc`), service line text (`service_line`), and selects for `evidence_state` / `disposition` / `institutional_approval_status` / `activation_status`. All inputs use canon form styling (`bg-healthcare-surface`, `border-healthcare-border`, gold `:focus-visible`). A "Clear" button resets to `{ page: 1, per_page: 25 }`.

- [ ] **Step 3: `PathwayTable`** — prop `{ rows: PathwayRow[]; pagination: {...}; onPage: (p: number) => void; isLoading: boolean }`. A `Panel`-wrapped table. Columns: Rank (`version.source_rank`, `tabular-nums`), Pathway (`pathway.name` → link to `/care-pathways/catalog/{version.version_uuid}` via Inertia `Link`), MDC (`pathway.mdc`), Service line (`pathway.source_service_line`), DRGs (`<DrgChips>`), Evidence (`<EvidenceBadge>`), Status (`<GovernanceStatus>`), Sections (`governance.approved_section_count`/`section_count`, `tabular-nums`). Footer: pagination (`page`/`last_page`/`total`) with prev/next; disable while `isLoading`. Header row uses `text-healthcare-text-secondary`, `text-xs` uppercase. Zebra/hover via `healthcare-surface`/`healthcare-hover` tokens. 250 rows are paginated (25/pg), so no virtualization library needed; keep DOM light.

- [ ] **Step 4: Type-check.** Run `npx tsc --noEmit`. Expected: no errors.

### Task 3.4: Compose the Index page

**Files:** Create `resources/js/Pages/CarePathways/Catalog/Index.tsx`.

- [ ] **Step 1: Implement** the page (default export required by Inertia):

Props: `{ initialSummary: SummaryEnvelope; initialPathways: PathwaysEnvelope }`. Behavior:
- Wrap in `AuthenticatedLayout` + `<Head title="Care Pathways Catalog" />`.
- `const [query, setQuery] = useState<PathwayQuery>({ page: 1, per_page: 25 })`.
- `const summary = useCatalogSummary(initialSummary)`.
- `const pathways = useCatalogPathways(query, initialPathways)`.
- Render order: `<GovernanceBanner>` (from `summary.data.meta.clinical_approval_warning` + `release.state` + `clinical_signoff_count`) → `<CatalogOverview>` → `<ActivationReadiness>` → `<PathwayFilters value={query} onChange={setQuery}>` → `<PathwayTable rows={pathways.data.data} pagination={pathways.data.meta.pagination} onPage={(p)=>setQuery(q=>({...q,page:p}))} isLoading={pathways.isFetching}>`.
- Gutter owned by `PageContentLayout` (`p-4`) — do NOT add an outer padded wrapper (avoid double gutter). Content max-width comes from the layout.
- Include a header link to the Journey Demo (`/care-pathways/demo`) only when that route exists; render as a secondary action.

- [ ] **Step 2: Type-check + build (build is stricter).**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed, no `UNRESOLVED_IMPORT`.

- [ ] **Step 3: Browser verification** — load `/care-pathways/catalog` on dev as `admin@acumenus.net`. Confirm: banner shows inactive + 0 sign-offs; overview tiles show 250/770/802/10123; table lists rank-ordered pathways; a search for "sepsis" filters; a DRG filter `870` narrows; clicking a row navigates to detail. Screenshot for the devlog.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/CarePathways/ resources/js/Pages/CarePathways/Catalog/Index.tsx
git commit -m "feat: care pathways catalog overview + 250-pathway index"
```

---

## Phase 4 — Pathway Detail page

### Task 4.1: Detail sections, authoring, evidence, ledger components

**Files:** Create `PathwaySections.tsx`, `PathwayAuthoring.tsx`, `EvidenceClaims.tsx`, `GovernanceLedger.tsx` under `resources/js/Components/CarePathways/`.

- [ ] **Step 1: `PathwaySections`** — prop `{ sections: Section[] }`. Groups sections by `audience` (`staff_reference` / `staff_workflow` / `patient` / `caregiver`). Renders each as a collapsible `Panel` row keyed by `section_code` (humanize: `admission_criteria` → "Admission Criteria"). Shows `source_text`; if `approved_text` present, show it under an "Approved" sub-label with a `content_mode`/`review_state` chip. A small `Fingerprint` icon reveals the `source_digest` (truncated, `tabular-nums`) on hover/title for provenance. Long text wraps with readable line length.

- [ ] **Step 2: `PathwayAuthoring`** — prop `{ authoring: VersionEnvelope["data"]["authoring"] }`. Four `Panel`s:
  - Milestones: ordered by `sequence`; each shows `title`, `phase` chip, `expected_range` (render `.display` if present), `review_state` chip.
  - Goals: `goal_text`, `author_type` chip, `target` summary, `patient_visible_explanation` if present.
  - Activities: `title`, `activity_type` chip, `performer_role`, `executable` flag (icon), `fhir_canonical_ref` if present.
  - Education: `title`, `audience` chip, `reading_level`, `teach_back_prompt` if present.
  Empty arrays render a quiet "None defined in this version" (`text-healthcare-text-secondary`) — do not hide silently.

- [ ] **Step 3: `EvidenceClaims`** — prop `{ versionUuid: string; claimCount: number }`. Uses `useCatalogClaims(versionUuid, page)` with local `page` state. Renders a `Panel` titled "Evidence claims ({claimCount})". Each claim: `source_field` chip, `claim_type`, `claim_excerpt`, and its `sources[]` as a list (PMID link when present → `https://pubmed.ncbi.nlm.nih.gov/{pmid}/`, `evidence_grade` chip, `current_status` chip with `AlertTriangle` if not `current`). Paginate via `meta.pagination`.

- [ ] **Step 4: `GovernanceLedger`** — prop `{ governance: VersionEnvelope["data"]["governance"]; version: ...; changes: unknown[] }`. Three `Panel`s: Reviews (`reviewer_role`, `decision` chip, `reason`, `reviewed_at`), Approvals (`approval_type`, `decision`, `effective_period`, `decided_at`), and Provenance (`content_digest`, `source_digest`, `effective_period`, `source_cutoff_date`, `unresolved_flags` count). Include the change log (`changes[]`: `source_field`, `old_value`→`new_value`, `reason`, `changed_on`) as a compact table. All digests `tabular-nums`, truncated with full value in `title`.

- [ ] **Step 5: Type-check.** Run `npx tsc --noEmit`. Expected: no errors.

### Task 4.2: Compose the Detail page

**Files:** Create `resources/js/Pages/CarePathways/Catalog/Show.tsx`.

- [ ] **Step 1: Implement** (default export). Props: `{ initialVersion: VersionEnvelope }`. Behavior:
- `AuthenticatedLayout` + `<Head title={`${pathway.name} — Care Pathway`} />`.
- `const version = useCatalogVersion(initialVersion.data.version.version_uuid, initialVersion)`.
- Header `Panel`: back link (`Link` to `/care-pathways/catalog`, `ArrowLeft`), `pathway.name`, `pathway.mdc` + `care_type` + `source_service_line` chips, `semantic_version` + `source_rank` (`tabular-nums`), `<GovernanceStatus>`.
- `<GovernanceBanner>` (from `version.data.meta`) — reused, so the not-approved framing is present on detail too.
- `<DrgChips drgs={data.drg_candidates} max={99}>` in a "DRG candidates" `Panel`, each row showing `ms_drg`, `title`, `mdc`, `type_label`, `mapping_role`, and the `requires_clinician_confirmation` note.
- `<PathwaySections sections={data.sections}>`.
- `<PathwayAuthoring authoring={data.authoring}>`.
- `<EvidenceClaims versionUuid={...} claimCount={data.evidence.claim_count}>`.
- `<GovernanceLedger governance={data.governance} version={data.version} changes={data.changes}>`.
- Respect single-gutter rule (layout owns `p-4`).

- [ ] **Step 2: Type-check + build.**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed.

- [ ] **Step 3: Browser verification** — open a detail page (e.g. click "Sepsis" from the index). Confirm all 28 section fields render, milestones/goals show, DRG candidates list with clinician-confirmation note, evidence claims paginate and link to PubMed, provenance digests visible, banner present. Screenshot for devlog.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/CarePathways/ resources/js/Pages/CarePathways/Catalog/Show.tsx
git commit -m "feat: care pathways per-DRG detail view (sections, evidence, provenance)"
```

---

## Phase 5 — Demo.tsx canon rebuild + cross-link

> `Demo.tsx` currently violates canon heavily (raw `slate/cyan/emerald/amber/rose` palette, `font-mono`, hand-rolled `bg-white` surfaces, cyan gradient, `tracking-[0.18em]`, `any` props). Rebuild onto canon WITHOUT changing behavior (the 6-step journey + 6 surface projections stay identical). This LOWERS the `check-ui-canon.sh` raw-palette ratchet.

### Task 5.1: Migrate Demo.tsx to the design system

**Files:** Modify `resources/js/Pages/CarePathways/Demo.tsx`.

- [ ] **Step 1: Replace surfaces** — swap every hand-rolled `rounded-2xl border border-slate-200 bg-white shadow-sm dark:...` and the local `Panel` function for the canon `Panel`/`Surface` primitives (`@/Components/ui/Panel`). Remove the local `Panel` definition.

- [ ] **Step 2: Replace palette** — map raw colors to tokens: `slate-*` surfaces/text → `healthcare-surface`/`healthcare-text-*`; `cyan-*` accents → `healthcare-primary` (NOT cyan — cyan is banned); `emerald-*` → `healthcare-success`; `amber-*` → `healthcare-warning`; `rose-*` → `healthcare-critical`. Replace the cyan header gradient with a canon `Surface` header (no gradient, or a subtle `healthcare-primary` accent border). Remove `tracking-[0.18em]` (use default tracking or `tracking-wide`).

- [ ] **Step 3: Replace `font-mono`** on the timeline `<time>` (line ~963) with `tabular-nums`.

- [ ] **Step 4: Type the props** — replace every `any` (`care_team: any`, `virtual_rounds: any`, etc.) with explicit interfaces (or reuse the existing `scenarioFromApiEnvelope` typing). At minimum, define narrow interfaces for each surface's shape so `tsc` passes without `any`.

- [ ] **Step 5: Add the cross-link** — in the header, add a secondary `Link` (Inertia) to `/care-pathways/catalog` labelled "Browse the 250-pathway catalog", shown only if `care_pathways_catalog` feature is on (read from `usePage().props.features`).

- [ ] **Step 6: Verify canon + behavior.**

Run: `bash scripts/check-ui-canon.sh` (Expected: green; raw-palette ratchet count DECREASED), then `npx tsc --noEmit && npx vite build` (Expected: pass).
Browser: re-walk all 6 steps + all 6 surfaces on dev; confirm identical behavior, now on canon (dark + light). Screenshot.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/CarePathways/Demo.tsx scripts/check-ui-canon.sh
git commit -m "refactor: migrate Care Pathways Demo onto design canon + link to catalog"
```

---

## Phase 6 — Full verification + devlog

### Task 6.1: Backend test lane

- [ ] **Step 1: Run the care-pathway suite** on beastmode (isolated `zephyrus_test_*`):

Run: `./scripts/test-suite.sh contract` (or `php artisan test --testsuite=Feature --filter=CarePathway`)
Expected: new `CarePathwayCatalogPageTest` green; no regressions in existing `CarePathway*` tests.

### Task 6.2: Frontend gates

- [ ] **Step 1:** `npx tsc --noEmit` — Expected: clean.
- [ ] **Step 2:** `npx vite build` — Expected: clean (stricter; catches unresolved imports).
- [ ] **Step 3:** `bash scripts/check-ui-canon.sh` — Expected: green; confirm raw-palette baseline went DOWN vs pre-change (Demo fix). Record the delta.

### Task 6.3: Devlog + memory

- [ ] **Step 1: Write** `docs/DEVLOG-care-pathways-catalog-explorer-2026-07-24.md` summarizing: what shipped (3 views + Demo rebuild), the dev-only governance-enable (prod still off), the migrate+adopt provisioning done on `zephyrus_dev`, test/build/canon evidence, screenshots, and the explicit deferred items (prod governance flag = [SU] decision; capability grants unchanged).
- [ ] **Step 2: Commit**

```bash
git add docs/DEVLOG-care-pathways-catalog-explorer-2026-07-24.md
git commit -m "docs: devlog for care pathways catalog explorer"
```

- [ ] **Step 3:** Update the `project_zephyrus_care_pathways` memory with: the new Catalog Explorer surface (routes `care-pathways.catalog[.show]`), the `CARE_PATHWAYS_GOVERNANCE_ENABLED` dev-on/prod-off state, and the `zephyrus_dev` adoption. (Memory update happens outside the repo.)

---

## Deployment note (NOT in this plan's scope)

`deploy.sh` is an immutable-commit snapshot that **skips migrations** and does not touch prod `.env`. Shipping this to prod later requires, as separate explicit [SU]-approved steps: (1) run the 8 care_pathways migrations + adopt on the prod DB (if not already — the prod `zephyrus` DB already has the adopted release), and (2) set `CARE_PATHWAYS_GOVERNANCE_ENABLED=true` in the prod `.env` and confirm the intended staff hold `viewCarePathwayCatalog`. This plan does none of that — it stops at a fully-working, verified dev surface.

---

## Self-Review

- **Spec coverage:** Overview ✔ (Phase 3), 250 Index ✔ (Phase 3), per-DRG Detail elucidating all 49 fields ✔ (Phase 4: sections=28 prose fields, authoring=milestones/goals/activities/education, drg_candidates, evidence, provenance). Governance-API-as-SSOT ✔ (Phase 2, no CSV). Demo rebuild ✔ (Phase 5). Dev data prerequisite ✔ (Phase 0).
- **Governance framing:** `GovernanceBanner` + `ActivationReadiness` enforce the inactive/not-approved narrative on every page; no serving flag touched; prod deferred.
- **Canon:** every component specifies `healthcare-*` tokens + `Surface`/`Panel`/`MetricCard`, `tabular-nums`, icon+label status, and Phase 5 lowers the ratchet. `check-ui-canon.sh` gate in Phase 6.
- **Type consistency:** Zod-inferred types (`SummaryEnvelope`, `PathwaysEnvelope`, `PathwayRow`, `VersionEnvelope`, `ClaimsEnvelope`) flow from `catalogSchemas.ts` → `catalogApi.ts` → `useCatalog.ts` → pages/components unchanged. `PathwayQuery` shape matches the controller's validation rules exactly (`evidence_state`, `disposition`, `institutional_approval_status`, `activation_status`, `q`, `drg`, `mdc`, `service_line`, `page`, `per_page`).
- **Placeholder scan:** data-layer, controllers, routes, shares, nav, Phase 0 commands are fully coded; presentational components carry exact prop contracts + token rules (visual polish via `/impeccable craft`) — acceptable for a skilled dev per the writing-plans skill.
