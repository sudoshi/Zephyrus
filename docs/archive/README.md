# Archive

Superseded documents, kept for provenance. Nothing here describes current behavior — read
the replacement listed below instead.

| Archived document | Superseded by |
| --- | --- |
| [PLATFORM-TECHNICAL-REFERENCE-2026-02-28.md](./PLATFORM-TECHNICAL-REFERENCE-2026-02-28.md) — the former root `DEVLOG.md`: a Feb-2026 platform snapshot (architecture, feature catalog, code statistics). Predates Zephyrus 2.0 entirely — no mention of the Cockpit, Arena, Eddy, Hummingbird, or Patient Flow 4D | [README.md](../../README.md) + [AGENTS.md](../../AGENTS.md) for current architecture; [product/ZEPHYRUS-2.0-PLAN.md](../product/ZEPHYRUS-2.0-PLAN.md) for where it went. Renamed on archiving so it is not mistaken for the [devlog/](../devlog/) series |
| [PARTHENON-ADMIN-PORT-PLAN-2026-07-10.md](./PARTHENON-ADMIN-PORT-PLAN-2026-07-10.md) — historical Parthenon → Zephyrus admin port inventory | [plans/ADMIN-INTEROPERABILITY-CONTROL-PLANE-PLAN-2026-07-12.md](../plans/ADMIN-INTEROPERABILITY-CONTROL-PLANE-PLAN-2026-07-12.md) (the doc says so itself) |
| [auth-legacy/no-login-solution.md](./auth-legacy/no-login-solution.md) — auto-login as superuser, no authentication | [AUTHENTICATION.md](../../AUTHENTICATION.md) — the auto-login bypass was removed; `SessionAuthMiddleware` now only permits a *configured, feature-gated demo account* |
| [auth-legacy/session-auth.md](./auth-legacy/session-auth.md) — session auth as a CSRF replacement | [AUTHENTICATION.md](../../AUTHENTICATION.md) + `.claude/rules/auth-system.md` (the enforced temp-password / forced-change flow) |
| [auth-legacy/csrf-solution.md](./auth-legacy/csrf-solution.md) — CSRF removal rationale | [AUTHENTICATION.md](../../AUTHENTICATION.md); the migration checklist is [auth-legacy/csrf-removal-checklist.md](./auth-legacy/csrf-removal-checklist.md) |

Archiving is a move, not a delete — `git log --follow` still reaches the full history.
