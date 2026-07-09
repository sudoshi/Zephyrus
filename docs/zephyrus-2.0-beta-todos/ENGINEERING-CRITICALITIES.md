# Engineering Criticalities Checklist

Purpose: define the non-negotiable review dimensions for every Zephyrus 2.0 beta phase. A phase can defer a criticality only by recording a known limitation with owner, risk, and beta impact.

## Criticality Matrix

| Criticality | What Must Be Checked | Minimum Evidence | Common Failure To Catch |
| --- | --- | --- | --- |
| Requirements traceability | Every PRD acceptance point maps to a phase checkbox, code path, and validation artifact. | Traceability row with PRD section, file path, test, screenshot or command. | Work feels complete but does not satisfy the PRD wording. |
| Architecture fit | New work uses existing Laravel, Inertia, React, mobile BFF, and Hummingbird patterns. | Code review notes and file paths. | A parallel abstraction bypasses existing services or route conventions. |
| API contract | Routes, request validation, response shape, status codes, and errors are stable. | Route list, feature tests, sample JSON. | Frontend/mobile relies on fields not guaranteed by backend tests. |
| Authorization | Session, Sanctum, admin, agent scoped-token, and role policies are explicit. | `401`, `403`, and success tests by route family. | Mutable or admin API is throttle-only or public in beta. |
| PHI/privacy | Patient names, MRNs, refs, logs, broadcasts, caches, and screenshots follow role policy. | API assertions, screenshot review, `rg` scan notes. | A legacy RTDC/ED page leaks patient identifiers while cockpit is clean. |
| Data trust | Source, as-of, freshness, synthetic/demo, confidence, lineage, and fallback state are visible. | JSON samples and screenshots. | Synthetic values look live or stale values look current. |
| Data integrity | Seeds/imports/rebase/jobs are idempotent, transactional where needed, and retention-safe. | Repeat-run logs and tests. | Demo reset duplicates rows or history pruning deletes needed evidence. |
| Migrations | Schema changes are compatible with deployed environments and production rollout. | Migration status, rollback note, compatibility reasoning. | Code deploys before schema or migration assumes empty database. |
| Frontend quality | UI works across viewport sizes, empty/stale/error states, and dark/light modes if relevant. | Vitest/Playwright/screenshots. | Hero or dashboard looks fine at desktop but breaks tablet/mobile/wall. |
| Mobile parity | Hummingbird behavior matches web/backend contracts for shared capabilities. | BFF tests, iOS/Android screenshots/builds. | Mobile implements a shortcut that web/Eddy cannot observe. |
| Eddy governance | Agent tools have scopes, dry-run, approval, execution, audit, rollback, and PHI policies. | Eddy tests and tool catalog artifact. | Eddy creates recommendations but cannot actually execute or audit. |
| Observability | Logs, audit rows, ledgers, dead letters, watermarks, and action/activity rows prove state. | DB/API samples and log review. | Failure disappears into UI toast without durable trace. |
| Performance | Query count, payload size, caching, ETag/stale behavior, polling/realtime cadence are sane. | Targeted test, explain/log note, browser/network sample. | Wall display or mobile view hammers expensive endpoints. |
| Realtime/fallback | Reverb/SSE/poll fallback works under enabled and disabled realtime conditions. | Runtime check or automated test. | UI silently freezes when Reverb is down. |
| Security headers/config | CORS, CSP, Reverb origins, rate limits, mobile backup/cleartext, secrets are reviewed. | Config diff, command output, test or manual note. | Beta host inherits permissive local/demo settings. |
| Accessibility/usability | Keyboard, focus, labels, contrast, loading states, and action affordances are usable. | Screenshot/Playwright/manual review notes. | Operational dashboard is visually impressive but hard to operate. |
| Testing | Unit, feature, JS, E2E, native, smoke, and security tests match blast radius. | Command output archive. | Only happy path PHP tests run after UI/mobile changes. |
| Deployment | `./deploy.sh`, migrations, caches, Apache, vhost, scheduler, queue, Reverb, storage are proven. | Deploy/post-deploy command log. | Deploy succeeds but schema or scheduled refresh is stale. |
| Rollback | Rollback path is known before deploy and accounts for schema/data changes. | Rollback note or rehearsal output. | A failed migration leaves no approved recovery path. |
| Documentation | Docs, known limitations, demo script, release notes, and phase ledger are current. | Doc diff and ledger row. | Implementation ships but demo operator cannot explain limits. |

## Phase Review Form

Copy this table into the evidence README for each phase.

```markdown
| Criticality | Owner | Reviewer | Evidence | Status |
| --- | --- | --- | --- | --- |
| Requirements traceability |  |  |  | Pending |
| Architecture fit |  |  |  | Pending |
| API contract |  |  |  | Pending |
| Authorization |  |  |  | Pending |
| PHI/privacy |  |  |  | Pending |
| Data trust |  |  |  | Pending |
| Data integrity |  |  |  | Pending |
| Migrations |  |  |  | Pending |
| Frontend quality |  |  |  | Pending |
| Mobile parity |  |  |  | Pending |
| Eddy governance |  |  |  | Pending |
| Observability |  |  |  | Pending |
| Performance |  |  |  | Pending |
| Realtime/fallback |  |  |  | Pending |
| Security headers/config |  |  |  | Pending |
| Accessibility/usability |  |  |  | Pending |
| Testing |  |  |  | Pending |
| Deployment |  |  |  | Pending |
| Rollback |  |  |  | Pending |
| Documentation |  |  |  | Pending |
```

## Pass, Defer, Fail Rules

Use only these statuses:

- `Pass`: evidence exists and reviewer agrees.
- `Deferred`: documented in known limitations with owner, reason, user impact, and beta risk.
- `Fail`: blocks phase exit.
- `Not applicable`: reviewer states why the criticality does not apply to this phase.

No criticality may stay `Pending` at phase exit.

## Evidence Naming

Use predictable names so later agents can find proof:

- `commands/<yyyymmdd>-<phase>-<command-slug>.txt`
- `api/<route-slug>-<role>-<scenario>.json`
- `screenshots/<viewport>-<role>-<surface>-<scenario>.png`
- `mobile/<platform>-<role>-<surface>-<scenario>.png`
- `deploy/<yyyymmdd>-deploy-log.txt`
- `review/<role>-criticality-review.md`

Sensitive values must be redacted before committing artifacts. If artifacts cannot be committed because they include sensitive data, record the secure storage location and redaction owner.

## Required Cross-Phase Commands

These commands are valid in this repo as of the audit and should remain in the validation package unless B0 records a replacement:

```bash
php artisan route:list
php artisan list --raw
php artisan test
npm run test
npm run test:e2e
npm run build
./scripts/check-ui-canon.sh
```

Known artisan commands relevant to beta operations:

```bash
php artisan zephyrus:demo-seed
php artisan patient-flow:import-synthetic patient-flow-4d-navigator/data/hl7_messages.ndjson --source-key=synthetic-flow-ehr --facility-code=ZEPHYRUS-500
php artisan patient-flow:rebase-synthetic
php artisan flow:snapshot
php artisan rtdc:demo-reset
php artisan rtdc:simulate
php artisan schedule:list
php artisan schedule:run -vvv
php artisan queue:failed
php artisan reverb:start
```

Do not invent command names in phase evidence. If a desired command does not exist, the phase must either create it with tests or document the real substitute.
