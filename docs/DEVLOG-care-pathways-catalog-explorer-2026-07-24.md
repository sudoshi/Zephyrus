# DEVLOG — Care Pathways Catalog Explorer (2026-07-24)

Plan: `docs/superpowers/plans/2026-07-24-drg-care-pathways-catalog-explorer.md`
Branch: `main` (direct, per [SU] instruction). Commits `67cb7512..cbed94f9`.

## What shipped

A comprehensive **Catalog Explorer** that elucidates all 250 governed DRG care
pathways — the first UI consumer of the (previously orphaned) governance read
API — plus a design-canon rebuild of the Heart-Failure journey demo.

### 1. `/care-pathways/catalog` — Overview + Index (`Pages/CarePathways/Catalog/Index.tsx`)
- **GovernanceBanner** (on every catalog surface): "Reference catalog — not
  clinically approved", the envelope's `clinical_approval_warning`, release
  state `inactive`, clinical sign-offs `0`, patient/Eddy serving `Off`.
- **CatalogOverview**: release header (grouper v43.1, window 2026-04-01 →
  2026-09-30, dataset key, source cutoff), metric wall via the system
  `MetricGrid`/`metric()` (250 pathways · 770 MS-DRG codes · 802 associations ·
  10,123 claims · 811 sources · 0 sign-offs), evidence partition (96 verified /
  154 limitations), disposition partition (96 sign-off queue / 148 specialist /
  6 redesign), release controls with reconciliation state.
- **ActivationReadiness**: the six activation gates as an explicit icon+label
  checklist derived from `release_readiness` — the honest "why this stays
  inactive" narrative.
- **PathwayFilters + PathwayTable**: debounced search, DRG-code / evidence /
  disposition / approval filters (mirroring the API's validation exactly),
  paginated 250-row table (rank, pathway link, MDC, service line, DRG chips,
  evidence badge, governance chips).

### 2. `/care-pathways/catalog/{versionUuid}` — Detail (`Pages/CarePathways/Catalog/Show.tsx`)
Per-DRG elucidation: all **28 clinical prose sections** in canonical clinical
reading order (admission criteria → data quality notes; immutable source text
with digest provenance, approved-text slot for the editorial pipeline), DRG
candidates with grouper metadata + the clinician-confirmation framing,
paginated **evidence claims with PubMed-linked graded sources**, structured
authoring state (empty in release 2 — stated, not hidden), and the append-only
governance ledger (reviews, approvals, source changes, digests, unresolved
flags).

### 3. Data layer (`lib/carePathways/`, `hooks/carePathways/`)
Zod schemas mirror `CatalogGovernanceReadService` envelopes exactly and were
**validated against real captured envelopes** (fixtures committed at
`tests/js/carePathways/__fixtures__/`, parsed in `catalogSchemas.test.ts`).
Typed axios client + TanStack Query hooks; server-rendered Inertia props pass
through the same Zod gate as live fetches. No `any` anywhere.

### 4. Backend (`CarePathwayCatalogPageController`, routes, share, nav)
Two thin Inertia routes gated **identically to the governance JSON API**
(`EnsureCarePathwayGovernanceEnabled` + `can:viewCarePathwayCatalog`), a
`care_pathways_catalog` feature share, `view_care_pathway_catalog` in the
`can` share, and a "Catalog Explorer" nav leaf gated on **both** the feature
and the capability. TDD: `CarePathwayCatalogPageTest` (5 tests — fail-closed
404, capability 403, Inertia render assertions, unknown-version 404).

### 5. Demo rebuild (`Pages/CarePathways/Demo.tsx`)
Same six-step journey and six surface projections, now on canon:
healthcare-* tokens (in-file raw palette 104 → 0), `Surface` primitive,
`tabular-nums` (no `font-mono`), fully typed projections, icon+label status
pills, `DashboardLayout`+`PageContentLayout` (double gutter removed), and a
feature-gated "Browse the 250-pathway catalog" cross-link.
**Raw-palette ratchet baseline lowered 180 → 76** in `check-ui-canon.sh`.

## Dev provisioning (Phase 0 — dev-only, additive, reversible)

`zephyrus_dev` had **no `care_pathways` schema**. Provisioned via the canonical
pipeline:
1. Ran the 8 catalog migrations individually with `--path=` (the 9th,
   `2026_07_22_001400`, was intentionally skipped: it needs
   `patient_experience.encounter_access_grants` which dev lacks, and its
   catalog-side `stage_definitions` table has 0 rows even in the fully adopted
   `zephyrus` DB and is untouched by import and read services).
2. Copied `raw.drg_cp_*` + manifest + `raw.drg_care_pathway_imports` from the
   `zephyrus` DB via pg_dump (11 tables + 2 views), restored the manifest FK.
3. `php artisan care-pathways:adopt-raw-release 1 --actor="dev-bootstrap-2026-07-24"`
   — dry-run controls passed, then adopted **inactive**. Counts identical to
   prod: 250/250/7000/770/10123, 0 sign-offs.
4. Dev `.env`: `CARE_PATHWAYS_GOVERNANCE_ENABLED=true` and
   `CARE_PATHWAYS_DEMO_ENABLED=true` (verification). **Prod untouched.**

Rollback: `DROP SCHEMA care_pathways CASCADE` on `zephyrus_dev` + remove the
two env lines.

## Verification evidence

- PHPUnit: full CarePathway lane **53 passed (428 assertions)** including the
  new page test; isolated `zephyrus_test_*` DB.
- vitest: **39 passed** (envelope schemas vs real fixtures, demo envelope
  guard, navigation config).
- `npx tsc --noEmit` clean; `npx vite build` clean (twice, incl. final state).
- `check-ui-canon.sh` green at the NEW lower baseline (76).
- Browser (dev, `superadmin` session via curl): catalog Index 200 with real
  250-pathway payload; filters verified through the live API (`q=sepsis`→1,
  `drg=870`→Sepsis, `evidence_state=limitations`→154, `disposition=redesign`→6
  — all matching release controls); Sepsis detail 200 (28 sections, DRGs
  870/871/872, 57 claims, PMID links incl. Sepsis-3); demo 200 with step-3
  release boundaries correct (rounds visible, patient locked).

## Gotchas recorded

- **`.env.local` trap**: a stale 2-line `.env.local` (session-cookie overrides,
  Feb 27) shadows `.env` whenever `APP_ENV=local` is in the actual process
  environment — `php artisan serve` passes it through, so serve dies with
  MissingAppKey. Workaround: `php -S 127.0.0.1:8084` from `public/` with the
  framework router (no APP_ENV passthrough). Left `.env.local` untouched.
- Dev login is `superadmin` / admin@acumenus.net (the `admin` username belongs
  to an inactive seed user).
- Nav: the CARE_PATHWAYS domain stays gated on `care_pathways_demo`; the
  Catalog leaf self-gates on `care_pathways_catalog` + capability. The
  theoretical demo-off+catalog-on combo would hide the domain (acceptable —
  no such environment exists; revisit if that combination ever ships).

## Deferred / [SU] decisions

- **Prod exposure**: setting `CARE_PATHWAYS_GOVERNANCE_ENABLED=true` on prod is
  an explicit [SU] decision (GET-only and fail-closed by design, but it exposes
  the governed catalog to capability-holding prod staff). Prod's `zephyrus` DB
  already holds the adopted release; no migration or adoption is needed there —
  only the env flag + `php8.5-fpm` reload.
- Everything clinical-serving (catalog/assignment/rounds/patient/Eddy/writeback
  flags, approvals, activation) remains **off / deferred on institutional and
  clinical authority** — unchanged by this work.
