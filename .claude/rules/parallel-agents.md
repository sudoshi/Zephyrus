# Parallel Agent Work — Isolation, Decomposition, Integration

How work is planned, split, executed, and merged in this repo — whether one agent
is working or five. **Isolation and coordination are different problems.** Git
worktrees solve isolation. They do nothing for coordination; Layers 2 and 3 are
where the failures actually happen.

This rule is binding on any change bigger than a single-file edit. Read it
alongside [AGENTS.md](../../AGENTS.md) (build/deploy) and
[CLAUDE.md](../../CLAUDE.md) (token canon). Protected surfaces in
[auth-system.md](./auth-system.md) are never in scope for a worker agent.

---

## Layer 1 — Isolation: worktrees

One task → one worktree → one branch. The repo already uses this layout; keep it:

```bash
git worktree add ../Zephyrus-<slug> -b codex/<slug> main
```

Sibling directory `../Zephyrus-<slug>`, branch `codex/<slug>` (or `feat/<slug>`).
`git worktree list` is the live inventory — check it before adding another.

Per-worktree setup, in order. `.env`, `/vendor`, and `/node_modules` are
gitignored, so a fresh worktree has **none of them**:

```bash
cd ../Zephyrus-<slug> && cp ../Zephyrus/.env . && composer install && npm install
```

### Shared state worktrees do NOT isolate

Assign each concurrent worktree a **slot number N** (1, 2, 3…) and offset every
port by `+10N`. The orchestrator worktree is slot 0 and keeps the defaults.

| Service | Slot 0 | Slot N | How to set |
| --- | --- | --- | --- |
| `php artisan serve` | 8001 | `8001+10N` | `php artisan serve --port=8011` |
| Vite dev | 5176 | `5176+10N` | `npm run dev -- --port 5186` |
| compose nginx / pg / redis | 8084 / 5484 / 6384 | `+10N` | `NGINX_PORT` / `POSTGRES_PORT` / `REDIS_PORT` in that worktree's `.env` |
| mailhog | 8029 / 1029 | `+10N` | `MAILHOG_UI_PORT` / `MAILHOG_SMTP_PORT` |
| eddy / arena | 8090 / 8110 | `+10N` | `EDDY_PORT` / `ARENA_PORT` |

- `./start-dev.sh` hardcodes 8001/5176 and **cannot** be used outside slot 0 —
  run `php artisan serve --port` and `npm run dev -- --port` directly. Each
  worktree writes its own `public/hot`, so Laravel finds its own Vite.
- **Docker Compose:** always `docker compose -p zephyrus-<slug>`. The `eddy` and
  `arena` services pin `container_name:` (`zephyrus-eddy`, `zephyrus-arena`),
  which collides regardless of project name — **only slot 0 runs the `eddy` or
  `arena` profiles.**
- **The canonical DB is shared and live.** `.env` points at
  `pgsql.acumenus.net/zephyrus`, which every worktree, the deployed app, and the
  scheduled `zephyrus:demo-refresh` all hit. `migrate`, `migrate:fresh`, `db:seed`,
  and demo-refresh against it are **orchestrator-only and serialized** — never a
  worker action, never concurrent. A worker that needs schema change writes the
  migration and stops.
- **PHPUnit is already isolated — do not "fix" it.** `tests/bootstrap.php` →
  `IsolatedTestDatabase::provision()` creates `zephyrus_test_<12hex>` per process
  (keyed on cwd + pid + random) and drops it at shutdown; browser tests use the
  `zephyrus_test_e2e<12hex>` namespace. Concurrent `php artisan test` across
  worktrees is safe. Killed runs leave orphans — sweep with
  `php scripts/manage-test-database.php list-orphans`.

---

## Layer 2 — Decomposition: where the leverage is

Isolation prevents file collisions. It does not make two tasks independent. Two
agents both told to "improve the command center" will conflict no matter how many
worktrees they have.

**Split by domain boundary, never by file list.** The real seams here:
ED · RTDC · Perioperative · Improvement/Process · Patient Flow 4D Navigator ·
cockpit · Arena sidecar · Eddy · Hummingbird staff · Nightingale patient ·
Home Hospital. Agents discover adjacent files mid-task; file-level partitioning
breaks the moment they do.

### Single-writer files — the orchestrator owns these

Highest-churn files in the last 90 days, which is exactly why they collide. A
worker **proposes** a diff for these in its PR description; the orchestrator
applies it. A worker never edits one directly:

- `routes/api.php`, `routes/web.php`
- `resources/js/config/navigationConfig.ts` (single source for nav — see AGENTS.md)
- `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`,
  `app/Providers/AuthServiceProvider.php`, `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/types/index.ts`, `resources/js/types/cockpit.ts`
- `.env.example`, `.github/workflows/ci.yml`, `tailwind.config.js`
- `composer.json` / `composer.lock`, `package.json` / `package-lock.json`
- `database/seeders/CommandCenterDemoSeeder.php`
- `docs/hummingbird/api-contract/**`, `capability-registry.lock`, `role-catalog.v1.json`
- `RAW_PALETTE_BASELINE` in `scripts/check-ui-canon.sh` — **at most one branch in
  flight may lower it.** Two branches that both lower it merge into a baseline
  that neither tree was verified against, and main goes red.

**No new dependencies without orchestrator approval.** Lockfiles are the single
worst merge surface in this repo.

### Freeze contracts before fanning out

The expensive failures are two individually-correct branches with incompatible
assumptions. Before any worker starts, the shared surface is written down and
frozen: Inertia page-prop shapes, `/api/**` request+response shapes, the
Hummingbird BFF OpenAPI, shared TS types, DB schema (`db/schemas/` +
`database/migrations/`), and any new `navigationConfig` entry. Changing a frozen
contract mid-flight is an orchestrator decision that re-briefs every affected
worker — not a unilateral edit.

### Coordination contract

1. One task, one branch, one worktree. No worker touches another worker's domain.
2. Draft PR opened **at start**, not at finish — work in flight must be visible.
3. Keep changes small. Conflict rate rises monotonically with diff size.
4. Design work obeys the CLAUDE.md token canon; `/impeccable` for judgment calls.
5. An initiative plan in `docs/plans/` pairs with its `docs/devlog/` execution log
   (docs/README.md filing rules). The orchestrator owns the plan; workers append
   to the devlog.

---

## Layer 3 — Integration: sequential, small, gated

**Merge one branch at a time.** Rebase onto current `main`, re-run the gate, merge,
then start the next. Never batch-merge.

Squash-merge from a PR — the CI verdict-reuse gatekeeper in `ci.yml` depends on
the squash commit reproducing the PR head's tree byte-for-byte, and `deploy.sh`
requires green CI on the *exact* commit.

### "Green" in this repo — a worker leaves its branch here

```bash
./vendor/bin/pint && npx tsc --noEmit && npm run test && bash scripts/check-ui-canon.sh && php artisan test
```

Plus, when the branch touches that surface: `npm run icons:check` (icons),
`scripts/verify-hummingbird-*.php` (mobile contracts), `npm run test:e2e`
(browser), `scripts/verify-test-isolation.sh` (anything touching test setup).
Note `.husky/pre-commit` calls `npm run build:check`, which is **not defined** in
`package.json` — don't rely on the pre-commit hook as your gate.

### Conflict resolution

**Never let an agent auto-resolve a conflict between two other agents' branches.**
This is the failure that doesn't announce itself: the resolver picks a winner
without knowing what the loser was building, a feature silently disappears, and
because no human wrote either side, no human notices. Conflicts between agent
branches go to a human or to a verifier holding the full spec.

### Verification is a separate agent

The agent that wrote a branch does not sign off on it. A verifier reviews the
branch against the frozen spec — did it build what was specified, does it respect
the token canon, did it touch a single-writer file, are the tests real.

### Semantic conflict is unsolved

No VCS removes it. Type checking and a full post-merge suite are the only
detectors, which makes **test-suite quality load-bearing for parallelism** in a
way it never is for solo work. If a domain's tests are thin, don't fan out into it.

---

## Practical ceiling

**2–4 concurrent writers.** Coordination cost scales worse than linearly;
parallelism gains scale sub-linearly. Go past 4 only when the work is genuinely
disjoint — independent modules, or a mechanical migration where every edit is the
same transformation. When in doubt, run fewer agents and merge faster.

---

## Prompt templates

### Orchestrator kickoff

> Act as orchestrator under `.claude/rules/parallel-agents.md`. Do not write
> feature code yourself.
>
> **Goal:** <what we're building>
>
> 1. Read PRODUCT.md, DESIGN.md, CLAUDE.md, AGENTS.md, and the relevant
>    `docs/plans/` entry. Report what already exists before proposing anything.
> 2. Produce a spec: task list split by **domain boundary**, the frozen shared
>    contracts (API shapes, Inertia props, TS types, DB schema), and the
>    single-writer files you will own.
> 3. Recommend a worker count (default 2–3, hard ceiling 4) and justify it. Name
>    the domains whose test coverage is too thin to fan out into.
> 4. Stop and show me the spec before creating any worktree.
>
> Then, on my approval: create worktrees per Layer 1 (slot numbers, port offsets,
> `.env` copy, installs), brief each worker with the template below, and merge
> sequentially per Layer 3. You own all single-writer files and every DB
> operation against the canonical database.

### Worker brief

> You own **<domain>** only, in worktree `../Zephyrus-<slug>` on branch
> `codex/<slug>`, slot N (ports +10N).
>
> **Scope:** <task>. **Frozen contracts you must not change:** <list>.
> **Out of scope:** every file in the single-writer list; anything in
> `.claude/rules/auth-system.md`; any other domain. If you need a change to a
> single-writer file, write the exact diff in your PR description and stop.
>
> Never run migrations, seeders, or `zephyrus:demo-refresh` against the canonical
> database. Open a draft PR before you start work. Leave the branch green (Layer 3
> command list) and record what you built in `docs/devlog/`.

### Verifier

> Review branch `codex/<slug>` against the spec at <path>. You did not write it.
> Check: does it implement what was specified (and nothing more); did it touch a
> single-writer or protected file; does it hold the CLAUDE.md token canon; is
> `scripts/check-ui-canon.sh` green; are the tests real assertions or theater;
> would it conflict semantically with <other in-flight branches>. Report
> findings — do not fix them.
