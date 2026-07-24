# Zephyrus Documentation

This tree is organized **by kind of document first** (product / architecture / plan /
devlog / audit / guide / runbook / evidence), with a small number of **per-surface
folders** for modules that own their own contracts (`hummingbird/`, `home-hospital/`).

Canon that governs the whole repo lives at the **repo root**, and only that:
[README.md](../README.md) (what Zephyrus is) ·
[PRODUCT.md](../PRODUCT.md) (strategy, users, design principles) ·
[DESIGN.md](../DESIGN.md) (visual system) ·
[AGENTS.md](../AGENTS.md) (build/deploy/engineering conventions) ·
[CLAUDE.md](../CLAUDE.md) (token canon + non-negotiables) ·
[AUTHENTICATION.md](../AUTHENTICATION.md) (current auth flow).
Everything else — including deployment runbooks and the business plan — files below.

## Map

| Directory | What belongs here | Start here |
| --- | --- | --- |
| [product/](./product/) | PRDs, master plans, roadmaps, scope, demo narrative | [ZEPHYRUS-2.0-PLAN.md](./product/ZEPHYRUS-2.0-PLAN.md), [ZEPHYRUS-2.0-BETA-PRD.md](./product/ZEPHYRUS-2.0-BETA-PRD.md) |
| [architecture/](./architecture/) | Durable system architecture and taxonomies — outlives any one initiative | [OCEL-DRG care-pathway measurement](./architecture/OCEL-DRG-CARE-PATHWAY-MEASUREMENT-ROOT-CAUSE-ARCHITECTURE-2026-07-21.md), [service-line/location taxonomy](./architecture/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md) |
| [plans/](./plans/) | Dated initiative and implementation plans, plus their companion evidence | [plans/zephyrus-2.0-beta/](./plans/zephyrus-2.0-beta/) (B0–B8 delivery program) |
| [devlog/](./devlog/) | Dated execution logs — what was actually built, verified, and merged | Newest first: [governed DRG care pathways](./devlog/DEVLOG-governed-drg-care-pathways-2026-07-22.md) |
| [audits/](./audits/) | Audits, reviews, and the instruments used to run them | [Comprehensive UX/UI + HFE audit](./audits/ZEPHYRUS_COMPREHENSIVE_UX_UI_HFE_AUDIT_2026-07-17.md) |
| [guides/](./guides/) | Engineering reference and how-to for contributors | [coding-standards.md](./guides/coding-standards.md) |
| [operations/](./operations/) | Runbooks for running, deploying, and releasing the system | [DEPLOY_NOW.md](./operations/DEPLOY_NOW.md) (manual deploy is the only supported path), [DEPLOYMENT_CHECKLIST.md](./operations/DEPLOYMENT_CHECKLIST.md), [DEVELOPMENT-AND-PRODUCTION-RELEASE-RUNBOOK.md](./operations/DEVELOPMENT-AND-PRODUCTION-RELEASE-RUNBOOK.md) |
| [evidence/](./evidence/) | Acceptance/verification artifacts (screenshots, query output, import logs) | [evidence/ancillary/](./evidence/ancillary/) |
| [hummingbird/](./hummingbird/) | Mobile companion app: ADRs, API contracts, design tokens, personas | [hummingbird/README.md](./hummingbird/README.md) |
| [home-hospital/](./home-hospital/) | Hospital-at-Home / RPM virtual-ward module | [HOME-HOSPITAL-BUILD-PROMPT.md](./home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md) |
| [superpowers/](./superpowers/) | Skill-authored implementation plans + design specs (**stable path**, see below) | [superpowers/plans/](./superpowers/plans/) |
| [business/](./business/) | Business plan, investor and GTM material, deck build tooling | [BUSINESS_PLAN.md](./business/BUSINESS_PLAN.md), [Zephyrus_Investor_Deck_Reconciled.pdf](./business/Zephyrus_Investor_Deck_Reconciled.pdf) |
| [reference/](./reference/) | External prototypes and third-party inputs we read but do not own | [hospital-operations-cockpit/](./reference/hospital-operations-cockpit/) |
| [screenshots/](./screenshots/) | App screenshots used by the repo README | — |
| [archive/](./archive/) | Superseded documents, kept for provenance | [archive/README.md](./archive/README.md) |

## Filing rules

1. **Kind first.** A new Flow-4D plan goes in `plans/`, not a `flow-4d/` folder. Only a
   module that ships its own contracts or app code earns a per-surface folder
   (`hummingbird/`, `home-hospital/`).
2. **Plans and devlogs pair up.** An initiative plan in `plans/` gets its execution log in
   `devlog/` under the same slug; cross-link them at the top of both files.
3. **Date anything time-bound.** `-YYYY-MM-DD` suffix for plans, audits, and devlogs; no
   date for durable architecture, guides, and runbooks.
4. **Companions travel with their parent.** Raw teardown/intel that only exists to support a
   plan sits beside that plan (e.g. `EDDY-ABBY-TEARDOWN-EVIDENCE.md`); reusable verification
   artifacts go in `evidence/`.
5. **Archive, don't delete.** When a doc is superseded, move it to `archive/` and record what
   replaced it in [archive/README.md](./archive/README.md).

## Stable paths — do not move without a sweep

- `docs/hummingbird/api-contract/**`, `capability-*.yaml`, `role-catalog.v1.json`,
  `capability-registry.lock` — machine-consumed by codegen, contract tests, and CI.
- `docs/superpowers/plans/**` and `docs/superpowers/specs/**` — cited by provenance
  docblocks in ~75 PHP source files. These are the skill-authored implementation plans;
  `plans/` holds the hand-authored initiative plans.
- `docs/screenshots/**` — referenced by the repo [README.md](../README.md).
