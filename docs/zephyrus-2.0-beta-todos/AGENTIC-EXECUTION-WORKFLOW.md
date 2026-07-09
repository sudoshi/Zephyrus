# Agentic Execution Workflow

Purpose: convert the Zephyrus 2.0 beta todo set into an executable, auditable, multi-agent implementation system that can be carried from discovery through code, tests, deployment, post-deploy verification, and rollback.

This file is the operating manual for the phase files. The phase files define the beta backlog. This workflow defines how agents are assigned, how they prove work, how their outputs are double-checked, and how implementation reaches production without losing safety, parity, or evidence.

## Authority Stack

Use this order when sources disagree:

1. Current repo code and runtime behavior.
2. `docs/ZEPHYRUS-2.0-BETA-PRD.md`, once B0 decides its committed authority path.
3. This todo folder.
4. Recently updated domain docs, especially Hummingbird and deployment docs.
5. Historical plans and AGENTS.md notes, only after B0 verifies they still match the current code.

No phase may complete by citing an older plan if current code, tests, routes, migrations, or production behavior contradict it.

## Agent Roles

Each implementation phase runs with a named orchestrator and a small swarm. A single person or model can fill multiple roles only when the phase is small; the role checks still remain mandatory.

| Role | Primary Responsibility | Must Produce | Cannot Approve Own Output |
| --- | --- | --- | --- |
| Orchestrator | Owns scope, branch hygiene, sequencing, conflict resolution, final evidence, and user-facing status. | Phase brief, implementation order, merged evidence ledger, final go/no-go. | Yes |
| Repo Cartographer | Re-checks routes, services, migrations, tests, frontend pages, mobile code, and docs touched by the phase. | Current-state map with file paths and known hazards. | Yes |
| Backend Agent | Implements Laravel controllers, services, models, jobs, commands, migrations, policies, and PHP tests. | Code diff, migration notes, API contract, feature tests. | Yes |
| Frontend Agent | Implements React/Inertia UI, route wiring, state handling, source labels, visual states, and JS tests. | Code diff, screenshots or Playwright evidence, JS tests. | Yes |
| Mobile Agent | Implements or validates Hummingbird iOS/Android/BFF parity for the phase. | Native/BFF diff, screenshots, native build/test evidence. | Yes |
| Data/Integration Agent | Owns seed/import/rebase, provenance, connector, data lineage, retention, and replay/dead-letter paths. | Data contract, idempotency proof, source/trust evidence. | Yes |
| Security/Privacy Agent | Reviews auth, PHI, scopes, headers, tokens, audit, logging, and realtime exposure. | Risk register entries, tests, screenshot/API PHI review. | Yes |
| QA Agent | Builds route/API/unit/component/E2E/mobile validation matrix and runs or verifies commands. | Test log, pass/fail table, reproduction steps for failures. | Yes |
| Ops Agent | Owns deploy, migration, scheduler, queue, Reverb, vhost, rollback, and monitoring proof. | Deploy checklist, post-deploy output, rollback rehearsal. | Yes |
| Documentation Agent | Updates PRD traceability, known limitations, runbooks, and release notes. | Doc diff, traceability entries, limitation decisions. | Yes |

The orchestrator may merge results, but cannot mark a phase complete until at least one non-author agent has checked each criticality listed in `ENGINEERING-CRITICALITIES.md`.

## Phase Execution Loop

Every phase uses the same nine-step loop.

### Step 1: Preflight And Branch Hygiene

- [ ] Record date, branch, commit, working tree state, and upstream status.
- [ ] Identify unrelated dirty files. Do not revert them.
- [ ] Decide whether to isolate the phase in one branch or split it into multiple PR-sized branches.
- [ ] Confirm the phase has no unresolved B0 decisions that would fork implementation.
- [ ] Create or update the phase evidence directory:
  - `docs/zephyrus-2.0-beta-todos/evidence/<phase>/README.md`
  - `docs/zephyrus-2.0-beta-todos/evidence/<phase>/commands/`
  - `docs/zephyrus-2.0-beta-todos/evidence/<phase>/screenshots/`
  - `docs/zephyrus-2.0-beta-todos/evidence/<phase>/api/`
  - `docs/zephyrus-2.0-beta-todos/evidence/<phase>/mobile/`
  - `docs/zephyrus-2.0-beta-todos/evidence/<phase>/deploy/`

Commands:

```bash
git status --short
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git fetch origin
git status --branch --short
```

### Step 2: Current-State Discovery

The repo cartographer reruns a focused discovery pass for the phase. Discovery must use current files, not assumptions from prior notes.

Minimum discovery commands:

```bash
rg -n "<phase keyword>|<route keyword>|<service keyword>" routes app resources tests docs config database
php artisan route:list
php artisan list --raw
find tests -type f | sort
npm run test
```

If a command is too broad or fails because of tool syntax, record the narrower command that was actually useful. Do not treat discovery as complete until the phase file lists exact files, endpoints, commands, and test names that will be changed or validated.

### Step 3: Work Package Split

The orchestrator converts the phase into work packages with disjoint write scopes where possible.

Each work package must include:

- [ ] Objective in one sentence.
- [ ] Files or modules owned by the agent.
- [ ] Files the agent may read but not edit.
- [ ] Inputs from upstream phases.
- [ ] API/UI/data contract affected.
- [ ] Required tests.
- [ ] Required screenshots or command logs.
- [ ] Security/PHI concern.
- [ ] Rollback or feature-flag behavior.

Do not split one fragile migration or shared contract across multiple editing agents. Assign one owner and independent reviewers instead.

### Step 4: Implementation

Implementation agents work in small, reviewable slices. Every slice must keep the app runnable.

Required standards:

- [ ] Prefer existing Laravel service/controller/test patterns.
- [ ] Prefer existing React/Inertia/component patterns and `resources/js/config/navigationConfig.ts` for navigation.
- [ ] Keep Zephyrus web and Hummingbird parity for shared capabilities.
- [ ] Add migrations only when schema changes are needed; make them compatible with already-deployed environments.
- [ ] Add tests in the same slice as behavior.
- [ ] Update source/provenance/freshness labels whenever data changes are action-driving.
- [ ] Update known limitations immediately when a PRD requirement is intentionally deferred.

### Step 5: Local Verification

Each implementation agent runs the narrowest reliable tests for its slice and records output.

Baseline commands:

```bash
php artisan test --filter=<RelevantTestClass>
npm run test -- <relevant test file or pattern>
npm run build
```

Mobile commands when the phase touches Hummingbird:

```bash
cd hummingbird/androidApp
./gradlew testDebugUnitTest
./gradlew assembleRelease
```

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

If local iOS tooling is unavailable, the phase cannot claim iOS completion. It can only record a blocker and assign the validation to a machine with Xcode.

### Step 6: Cross-Agent Double Check

Every work package requires a second-pass review by a different role. The reviewer must inspect code or artifacts, not just read the author's summary.

Double-check dimensions:

- [ ] Requirement traceability: PRD bullet and phase checkbox are satisfied.
- [ ] Contract stability: API payloads, DTOs, OpenAPI/mobile fixtures, routes, and nav links still match.
- [ ] Auth and permissions: correct `401`, `403`, success, and role-redaction behavior.
- [ ] PHI: screenshots, JSON, logs, broadcasts, and mobile caches do not exceed role policy.
- [ ] Data trust: source, as-of, freshness, synthetic/demo, confidence, lineage, fallback state.
- [ ] Failure modes: empty, stale, degraded, connector failure, queue failure, realtime fallback.
- [ ] Tests: new behavior is covered and old critical paths still pass.
- [ ] Performance: polling, query shape, materialized views, cache/ETag, mobile payload size.
- [ ] Operability: logs, metrics, scheduler/queue/Reverb, replay/dead-letter, rollback.
- [ ] Deployment: migrations, cache behavior, `deploy.sh`, production user, vhost checks.

### Step 7: Evidence Ledger

Evidence must be precise enough for a fresh operator to reproduce.

For each phase, record:

- [ ] Commit hash and branch.
- [ ] Files changed.
- [ ] Routes added/changed.
- [ ] Migrations added/changed.
- [ ] Commands run, with pass/fail.
- [ ] Screenshots and viewport/device.
- [ ] API sample responses with sensitive values redacted.
- [ ] Native build outputs where applicable.
- [ ] Known limitations added or removed.
- [ ] Reviewer signoff by criticality.
- [ ] Deployment status: not deployed, staging deployed, production deployed.

Use this format in the phase evidence README:

```markdown
| Item | Evidence | Owner | Reviewer | Status |
| --- | --- | --- | --- | --- |
| Requirement | PRD section / phase checkbox | Orchestrator | QA | Pending |
| Code | Commit hash / diff path | Backend | Security | Pending |
| Tests | Command + output path | QA | Orchestrator | Pending |
| Screenshots | Path + viewport | Frontend | Security | Pending |
| Deploy | Deploy output path | Ops | Orchestrator | Pending |
```

### Step 8: Phase Exit Review

The orchestrator runs the phase exit gate and updates:

- [ ] Phase file checkboxes.
- [ ] README completion ledger.
- [ ] `00-uncompleted-work-analysis.md` if the baseline changed materially.
- [ ] Known limitations.
- [ ] Decision register if a decision was closed or changed.
- [ ] Release notes draft if beta-visible behavior changed.

No phase can exit with "tests not run" unless the exact blocker, owner, and deadline are recorded and accepted as a known limitation.

### Step 9: Deploy Or Handoff

Most phases should end with a deployability handoff even if they are not deployed immediately. B8 owns the production beta release, but earlier phases must keep deployment safe.

Handoff must state:

- [ ] Whether schema changes exist.
- [ ] Whether `php artisan migrate --force` is required after `./deploy.sh`.
- [ ] Whether queues, scheduler, Reverb, or materialized views need restart/refresh.
- [ ] Whether existing demo seed data must be regenerated.
- [ ] Whether rollback requires database mitigation.
- [ ] Which route/API/mobile smoke checks prove the phase after deploy.

## Production Deployment Protocol

GitHub Actions must remain CI-only. Do not deploy production through Actions, direct production `git pull`, ad hoc SSH command blocks, or alternate deploy scripts.

Pre-deploy:

```bash
git status --short
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git fetch origin
git status --branch --short
git rev-list --left-right --count @{u}...HEAD
php artisan test
npm run test
npm run build
```

Require a clean worktree and approved release branch before production deployment. If the normal checkout is dirty with unrelated work, create a clean release worktree instead of deploying from the mixed tree:

```bash
git worktree add ../Zephyrus-release <approved-branch>
cd ../Zephyrus-release
git fetch --prune origin
git status --short --branch
git rev-list --left-right --count @{u}...HEAD
```

Deploy:

```bash
./deploy.sh
```

Post-deploy from `/var/www/Zephyrus`:

```bash
cd /var/www/Zephyrus
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan route:list
sudo -u www-data php artisan schedule:list
timeout 120s sudo -u www-data php artisan schedule:run -vvv
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan config:show queue.default
curl -I -H "Host: zephyrus.acumenus.net" http://localhost/
curl -I https://zephyrus.acumenus.net/
```

Run `migrate --force` only when there are pending schema changes or when the release checklist explicitly says schema state must be reconciled. Still run `migrate:status` before and after for proof.

Runtime proof:

- [ ] Apache is active after deploy.
- [ ] Laravel logs show no fresh fatal errors.
- [ ] Scheduler trigger exists for `www-data` through cron or systemd.
- [ ] `schedule:run -vvv` executes due jobs or reports none due without error.
- [ ] Queue mode is documented; worker state is proven if async queues are used.
- [ ] Reverb is either running with beta-safe origins/rate limits or poll fallback is proven.
- [ ] Cockpit snapshot is fresh.
- [ ] Patient Flow snapshot/history/scenario endpoints respond.
- [ ] Eddy catalog/propose/approve policy responds.
- [ ] Mobile BFF role endpoints respond.
- [ ] Admin Integration Health responds for admin role only.

## Rollback Protocol

Rollback must be documented before deployment, not after failure.

Minimum rollback plan:

- [ ] Previous commit or release artifact.
- [ ] Current production app artifact before deploy, for example a tarball of `/var/www/Zephyrus` stored outside the app tree.
- [ ] Database backup or restore point before migrations, for example a `pg_dump` path approved by Ops.
- [ ] Whether DB rollback is safe.
- [ ] Per-migration reversibility table, including `SafeMigration` behavior and whether rollback is local-only or production-safe.
- [ ] Whether compatibility migrations remove the need for rollback.
- [ ] Commands to resync prior code.
- [ ] Commands to clear caches and restart Apache.
- [ ] Queue/Reverb restart steps.
- [ ] Smoke checks that prove rollback restored service.
- [ ] Human decision owner for PHI/security rollback triggers.

Never run destructive database rollback commands in production unless the release-specific rollback plan says the migration is reversible and the data-loss impact is approved.

## Agent Prompt Packet Template

Use this template when assigning a phase work package to a coding or review agent:

```markdown
You are the <role> for Zephyrus beta phase <B#>.

Objective:
- <one sentence>

Authority:
- Current repo behavior first.
- docs/ZEPHYRUS-2.0-BETA-PRD.md.
- docs/zephyrus-2.0-beta-todos/<phase file>.
- AGENTIC-EXECUTION-WORKFLOW.md and ENGINEERING-CRITICALITIES.md.

Owned write scope:
- <files/modules>

Read-only context:
- <files/modules>

Required outputs:
- Code/doc changes or review findings.
- Tests run and exact results.
- Evidence artifacts.
- Risks and unresolved blockers.

Constraints:
- Do not revert unrelated changes.
- Preserve web/mobile parity where applicable.
- Label synthetic/demo data.
- Enforce auth, redaction, provenance, audit, and rollback semantics.
- Do not mark complete without executable evidence.
```

## Final Beta Completion Rule

The beta is not complete when all checkboxes are visually ticked. It is complete only when:

- [ ] Every phase exit gate is satisfied.
- [ ] Every criticality in `ENGINEERING-CRITICALITIES.md` has pass/defer evidence.
- [ ] Every defer is in known limitations with owner and risk.
- [ ] The demo can be seeded, rehearsed, deployed, validated, and rolled back from the written runbooks.
- [ ] Production deployment uses `./deploy.sh` plus explicit migrations/post-deploy checks.
- [ ] Web, Hummingbird, Eddy, data, security, and operations evidence agree.
