# ocelescope → Zephyrus OCPM Integration Roadmap

> **For agentic workers:** This is the master roadmap. It records the investigation,
> the licensing decision, the clean-room provenance discipline, and sequences three
> independently-shippable sub-plans. Each sub-plan is a separate document under
> `docs/superpowers/plans/` and uses `superpowers:subagent-driven-development` or
> `superpowers:executing-plans` to implement task-by-task.

**Status:** Planned — investigation complete, decisions locked, sub-plans authored
**Date:** 2026-07-10
**Source:** [`promi4s/ocelescope`](https://github.com/promi4s/ocelescope) (RWTH Aachen PADS), commit inspected 2026-07-10
**Scope:** Adopt three ocelescope capabilities into the Zephyrus Patient-Flow Arena as **clean-room, Apache-2.0 reimplementations** — a composable OCEL filter engine, object-centric Petri-net discovery + replay-based conformance, and a QEL (quantity event log) capacity layer.
**Authority:** This roadmap specializes [`docs/product/ZEPHYRUS-2.0-PART-X.md`](../../product/ZEPHYRUS-2.0-PART-X.md). It does not change Part X's object-type/activity catalog or PHI discipline; it extends the emission and analysis layers.

---

## 1. Executive decision

Zephyrus adopts three ocelescope **capabilities** by **clean-room reimplementation under Apache-2.0**. ocelescope is used **only as a design reference** — its source is read for the *shape of the idea*, never copied, vendored, or installed.

Two paths were rejected:

- **Import the `ocelescope` PyPI library** — rejected on license (see §2).
- **Run `ocelescope-backend` as an arms-length HTTP sidecar** — legally viable (network API call is not a derivative work), but rejected because the upstream is self-described *"not production-ready,"* carries its own React UI we cannot adopt into the canon, and would fork our Arena's operational surface. We already own a production Arena sidecar; we extend it.

The three capabilities become three phases, sequenced by value-to-effort:

| Phase | Capability | Sub-plan | Ships |
|---|---|---|---|
| **XO.1** | Composable OCEL filter engine | [`2026-07-10-arena-ocel-filter-engine.md`](./2026-07-10-arena-ocel-filter-engine.md) | Interactive slice of every Arena view by object-type / time / attribute / frequency |
| **XO.2** | OC Petri-net discovery + replay fitness | [`2026-07-10-arena-oc-petrinet-alignment.md`](./2026-07-10-arena-oc-petrinet-alignment.md) | Proper control-flow model; hardened copilot trust-gate |
| **XO.3** | QEL quantity / capacity layer | [`2026-07-10-arena-qel-capacity.md`](./2026-07-10-arena-qel-capacity.md) | Bed/staff capacity-over-time mined from the log itself |

## 2. What ocelescope is, and the license gate

ocelescope is a research OCPM platform from the van der Aalst group (the authors of the OCEL 2.0 standard). Three tiers:

1. **`ocelescope` PyPI library (`v0.3.1`)** — a pandas-backed `OCEL` 2.0 class with managers (events/objects/e2o/o2o/object_changes/**quantities**), an 11-filter composable engine, discovery algorithms (inductive miner → OC Petri net; OC-DFG), typed self-visualizing Resources, and a declarative Visualization layer.
2. **`ocelescope-backend` (FastAPI)** — a plugin host: `@plugin_method` introspects a Python function's type hints and auto-generates the UI form + run + result view. Registry, SSE task streaming, hot-loadable plugins/modules.
3. **Frontend (React, pnpm workspace)** — auto-renders plugin forms from JSON schema.

**License finding (the gate):** the repository root `LICENSE` is **AGPL-3.0**, and there is **no separate license** on the `src/ocelescope` library — the root AGPL governs it. The PyPI package declares **no license at all** (`license: None`, zero classifiers), which grants no rights on its own. Safe reading: **the entire stack, library included, is AGPL-3.0.**

Because Zephyrus is a **network-deployed product** with protected proprietary components (see `.claude/rules/auth-system.md`), importing or linking AGPL code triggers AGPL §13's network-use clause — it would require offering Zephyrus's source under AGPL. Incompatible with the project's Apache-2.0 posture. Hence: **clean-room only.**

## 3. Clean-room provenance discipline (MANDATORY)

Every sub-plan MUST honor these rules. A reviewer rejects any change that violates them.

1. **No dependency on ocelescope.** `ocelescope` MUST NOT appear in `arena/requirements.txt`, any `pyproject.toml`, `composer.json`, or `package.json`. CI greps for it (see task in Phase XO.1).
2. **No copied code.** Do not paste ocelescope source. Reimplement the *pattern* from the public API shape and standard OCPM literature (Berti & van der Aalst OC-DFG; OPerA; token-based replay). Our identifiers, file layout, and contracts are our own (flat `nodes/edges` JSON in the Zephyrus canon, not ocelescope's pydantic `Resource` classes).
3. **Apache-2.0 headers.** New files carry no AGPL notice. They inherit the repository's license.
4. **Reference, not artifact.** The cloned repo at `/tmp/ocelescope` is a scratch reference for the author only. It is never committed, never a submodule, never a build input.
5. **Attribution in prose only.** Where a technique originates in the OCEL literature, cite the paper in a docstring — never imply code lineage from ocelescope.

A short `arena/CLEAN-ROOM.md` note (created in Phase XO.1, Task 1) records this discipline in-tree so future contributors don't `pip install ocelescope`.

## 4. Capability map — current vs. target

Current Arena: `arena/app/{discovery,conformance,performance,copilot}.py` over raw `pm4py`, orchestrated by `app/Domain/Arena/ArenaService.php`, fed by `app/Domain/Ocel/OcelJsonExporter.php` from the `ocel.*` schema projected by `app/Domain/Ocel/OcelProjector.php`.

| Capability | Today | After XO.* |
|---|---|---|
| OC-DFG discovery | ✅ flat nodes/edges | ✅ + filterable (XO.1) |
| Interactive filtering | ❌ | ✅ object-type / event-type / time / attribute / frequency (XO.1) |
| OC Petri net | ❌ | ✅ places/transitions/variable-arcs (XO.2) |
| Copilot trust-gate | DFG edge-set fitness | + per-object-type token-replay fitness (XO.2) |
| Capacity-over-time from log | ❌ | ✅ QEL quantity ops → per-unit series (XO.3) |
| Uses `ocel.object_changes` / `o2o` we already project | partially | more fully (XO.1 filters, XO.3 quantities) |

## 5. Shared conventions (all sub-plans)

- **Sidecar (Python):** `arena/app/`, FastAPI, `pm4py>=2.7,<3`, Python 3.12. Settings in `arena/app/config.py` (`Settings` + `get_settings()`). Tests: `pytest` in `arena/tests/`. Every endpoint keeps the existing discipline — `_require_engine()` guard, degrade to 503/422, never a 500 stack trace, never PHI.
- **Orchestrator (PHP):** `app/Domain/Arena/*`, `app/Http/Controllers/Api/ArenaController.php`. Tests: PHPUnit feature tests in `tests/Feature/Arena/` run via `php artisan test` (Pest is uninstallable on this project — use PHPUnit). Run Pint after PHP edits.
- **Frontend:** `resources/js/Pages/Analytics/Arena.tsx` + `resources/js/Components/arena/*` + `resources/js/features/arena/{hooks,schema}.ts`. Zod at the boundary, canon tokens only (`healthcare-*`, `tabular-nums`, no raw palette, no `font-bold`). Verify with `npx tsc --noEmit` **and** `npx vite build`.
- **Flags:** everything ships behind the existing `ARENA_ENABLED` gate (`config/services.php` → `services.arena`). New AI-adjacent behavior stays behind `ARENA_AI_ENABLED`.
- **PHI:** the sidecar stays PHI-free by construction. Quantities in XO.3 are **unit-level counts**, never patient-identifying.
- **Additive + reversible:** new `ocel.*` tables and new endpoints only. No `prod.*` mutation. No change to the Part X catalog's meaning.

## 6. Sequencing & effort

Execute in order; each phase is independently shippable and independently valuable.

1. **XO.1 Filter engine** — ~1–2 days. Highest felt-value, lowest risk. No schema change. Unblocks richer XO.2/XO.3 views (filter then discover / filter then measure capacity).
2. **XO.2 Petri net + replay fitness** — ~2–3 days. Strengthens the governed copilot. Depends on nothing in XO.1 but composes with it (filter → discover Petri net).
3. **XO.3 QEL capacity** — ~3–4 days. Deepest strategic fit (RTDC/NEDOCS). Adds two `ocel.*` tables + projector emission + a capacity endpoint + a chart. Largest surface; do last so it can reuse the XO.1 filter plumbing.

## 7. Success metrics

- **XO.1:** an operator can restrict any Arena view (map / performance / conformance) to a chosen object-type set, time window, and event-attribute value, and the result recomputes server-side and caches per filter signature.
- **XO.2:** the Arena renders an OC Petri net for the current log; the copilot gate reports a token-replay fitness per object type alongside the existing DFG fitness, and withholds below the floor on either.
- **XO.3:** the Arena shows available-bed capacity over time per unit, computed purely from `ocel.quantity_operations`, with the daily nadir and time-below-threshold surfaced — reconciling to the same reality the cockpit shows.
- **All:** `scripts/check-ui-canon.sh` passes; `grep -R "ocelescope" arena/ app/ resources/ composer.json package.json` returns nothing outside prose docs; sidecar `pytest` and `php artisan test` green.

---

## Execution handoff

Implement the sub-plans in order (XO.1 → XO.2 → XO.3). For each, use
`superpowers:subagent-driven-development` (fresh subagent per task, review between
tasks) or `superpowers:executing-plans` (inline, batched with checkpoints).
