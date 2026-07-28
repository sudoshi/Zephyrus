# DEVLOG — FLOW-4D Phase D: Governed DRG catalog → conformance bridge (ships dark)

**Date:** 2026-07-27  **Branch:** `feature/flow4d-pathway-bridge`  **PR:** #115  **Branch commit:** `64ca48cf` (squash hash filled in on merge)
**Plan:** [FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN §8 Phase D](./plans/FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN-2026-07-26.md)
**Memo:** [D3 CPG-on-FHIR decision](./plans/FLOW-4D-D3-CPG-ON-FHIR-DECISION-MEMO-2026-07-27.md)

## What shipped

The bridge between the governed 250-DRG care-pathway catalog and the Arena
conformance engine — built end-to-end, **shipped dark**. Nothing per-patient from
the DRG catalog reaches a screen without [SU]/clinical sign-off (plan G-9). The only
visible artifact is a synthetic demo overlay under the already-on demo flag.

- **D1 `ReferenceModelCompiler`** (`app/Services/CarePathways/`) — pure `compile(meta,
  milestones)` → the declarative portion of the sidecar `PATHWAYS` spec (ordered
  activities, timing targets, data-driven deviation labels) + a content-address
  sha256 digest; thin `compileVersion(id)` DB adapter returning `null` when a version
  has no executable layer.
- **D2** `config/care-pathways-ocel-map.php` (near-empty) + `care-pathways:ocel-coverage`
  — the honest unmapped-milestone gap report.
- **D3** decision memo (see above).
- **D4 `PathwayInstanceReadService`** — flag + `Schema::hasTable` guarded, append-only
  reads via the `current_*_statuses` views, grant resolved by `source_encounter_id`.
- **D5 `FlowPathwayDemoService`** — curated HF milestones → the *same* pure compiler →
  LOS-driven synthetic progress, attached to one designated demo patient in the
  journey drawer (`PathwayProgressPanel`), gated by `care_pathways.demo`.
- Flags: new `services.flow4d.pathway_progress` (`FLOW4D_PATHWAY_PROGRESS_ENABLED`,
  off) composed with `care-pathways.assignment_enabled` into the Inertia
  `flow4d.pathway_progress_enabled`.

No migration. 25 new PHP tests + 6 JS tests, all green; tsc, `vite build`, Pint,
`check-ui-canon.sh` clean.

## Discoveries that shaped the design (from live-DB recon)

1. **The executable layer is never persisted.** `care_pathways.versions` = 250 rows,
   `sections` = 7000, but `milestone_definitions`/`activity_definitions` = **0** — the
   catalog is drafted from prose on demand, not stored. Everything is `inactive`
   (0 active releases, 0 approved/active versions). → The compiler is a pure transform
   with a DB adapter that honestly returns `null` for every current version, and the
   demo synthesizes curated milestones through that same pure core rather than reading
   the (empty) catalog. Seed-independent, always visible.
2. **The sidecar reference model's operative part is a Python *callable*, not data.**
   `arena/app/pathways.py::PATHWAYS[*]["evaluate"]` encodes imperative clinical timing
   (SEP-3 3h, AHCAH visit floor) and is not serializable/derivable. PATHWAYS is
   hardcoded at import with **no registration endpoint**. → The compiler emits only
   the declarative spec (and asserts it never emits `evaluate`); D3's memo owns the
   executable-representation question. No sidecar change.
3. **`patient_experience.pathway_*` tables don't exist on dev** (the `2026_07_22`
   migration hasn't run there). → D4 is `Schema::hasTable`-guarded and inert on dev;
   tested under `RefreshDatabase`.
4. **Flow ↔ patient_experience linkage is real and established:**
   `encounter_access_grants.source_encounter_id` = flow `Encounter` PK (used across
   Rounds/Messaging). D4 resolves the active grant through it — no invented join.

## Design decisions

- **Declarative compiler, not an executable one.** Ship the bespoke spec (matches the
  running sidecar); adopt CPG-on-FHIR `PlanDefinition` as the canonical representation
  as a serving-gated follow-up; defer a CQL engine until there's something to execute
  (D3).
- **Demo ties D1→D5.** The investor artifact runs curated milestones through the real
  compiler, then derives status purely from length of stay — honest scaffolding,
  `clinical_use=false`, explicit "not clinical guidance" notice, no DB writes.
- **Two-gate dark serving.** Real progress needs `assignment_enabled` (service) AND the
  composed Inertia flag (orchestrator) — belt-and-suspenders. The journey response
  omits `pathway_progress` entirely when both are off (byte-identical inertness,
  test-pinned).
- **F-9 palette respected.** `PathwayProgressPanel` reuses the sanctioned dark-only
  navigator overlay palette; status is glyph + word (never colour alone). No net-new
  hue introduced. The design-hook findings on the navigator CSS are the pre-existing
  sanctioned exception (CLAUDE.md) — not touched.

## Deploy / enablement note

**Not enabled.** This PR is code only; it changes no prod flags. The serving path
(`FLOW4D_PATHWAY_PROGRESS_ENABLED` + `CARE_PATHWAYS_ASSIGNMENT_ENABLED`) stays off.
The demo overlay becomes visible wherever `CARE_PATHWAYS_DEMO_ENABLED` is on (dev +
investor prod) once the frontend is deployed — a synthetic, clearly-labeled pathway
in the journey drawer for one designated demo patient. Deploy is a normal
`./deploy.sh --frontend` (no migration, no `--db`); do it only on the usual cadence
with [SU] awareness, since it surfaces the demo overlay on the investor env.

## Deferred (with serving; not scheduled)

- The `PlanDefinition` projection output on the compiler (D3).
- Any real per-patient serving from the DRG catalog — [SU]/clinical, G-9.
- Authoring/persisting the executable milestone layer (`milestone_definitions`) for
  governed versions, which the coverage report will then make substantive.
