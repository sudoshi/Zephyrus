# B8 - Beta Hardening And Demo Package

Goal: assemble the shippable beta package: tests, builds, screenshots, native evidence, deployment runbook, demo script, rollback plan, known limitations, and production smoke checks.

Primary source: PRD section `B8 - Beta Hardening And Demo Package`.

Exit principle: beta is complete only when a fresh operator can deploy, verify, rehearse, demo, explain limitations, and roll back with archived evidence.

## Current Evidence

- `deploy.sh` is the canonical deployment mechanism.
- It does not run Laravel migrations.
- Scheduler jobs are defined; production runtime proof is still required after deployment.
- Queue default and broadcast/Reverb state need production release verification.
- `scripts/check-ui-canon.sh` exists.
- 2026-07-09 local targeted validation is archived in `evidence/B8/commands/LOCAL-VALIDATION-2026-07-09.md`.
- 2026-07-09 full PHP validation passed: 1 skipped, 661 passed, 6810 assertions.
- 2026-07-09 full Vitest validation passed: 61 files, 277 tests.
- 2026-07-09 Playwright validation passed after local harness correction: 2 skipped, 17 passed across auth, navigation, command palette, mobile layout, and RTDC unit huddle.
- 2026-07-09 production Vite build passed with existing large-chunk warnings.
- 2026-07-09 adversarial hardening validation passed for the focused backend safety slice: `MobileBackendSafetyTest|FlowWindowTest|PatientFlowApiTest|EddyActionTest|ApiAuthorizationTest|ApiRouteSmokeTest` produced 70 tests and 1563 assertions.
- Android unit and release build evidence is archived under `evidence/B8/mobile/android/`.
- iOS build remains blocked on this Linux host and is documented under `evidence/B8/mobile/ios/`.
- Deployment completed successfully from commit `2e58cf2a8492bbcd0e13c746725b08c7278a337e`; deploy and post-deploy evidence is archived under `evidence/B8/deploy/DEPLOYMENT-RESULT-2026-07-09.md`.
- A targeted Patient Flow migration was run after deployment because production lacked the new `flow_core.occupancy_snapshots` detail columns required by the deployed snapshot/history code.
- A post-deployment adversarial review found and fixed mobile Patient Flow identity leakage, mobile persona write gaps, Android release cleartext defaults, iOS timestamp query encoding, Eddy role gates, OR write authorization/schema mapping, and login failed-auth error rendering. These fixes require the final clean-branch deploy in this run.

## Deliverables

- [x] Final automated validation archive for local PHP/JS/E2E/build gates.
- [ ] Final manual/visual validation archive.
- [ ] Partial: mobile iOS and Android build archive. Android complete; iOS blocked pending macOS/Xcode.
- [ ] Partial: PHI and security review signoff. Automated/code review notes and adversarial review remediation complete; screenshot, push, runtime headers, and Eddy cloud-policy review pending.
- [x] Demo script with scenario data and fallback path.
- [x] Deployment and post-deployment checklist for this slice.
- [x] Rollback checklist.
- [x] Known limitations release note.
- [ ] Partial: final beta definition-of-done checklist. Automated gates complete; deployment/manual/mobile limitations remain.

## Phase Entry Gate

B8 may start only after:

- [ ] B0-B7 phase files have either passed exit gates or recorded explicit known limitations.
- [ ] `TRACEABILITY-MATRIX.md` has no `Open` requirement IDs without owner and disposition.
- [ ] `DECISION-REGISTER.md` has all D1-D19 decisions resolved, time-boxed, or known-limited.
- [ ] `docs/beta-known-limitations.md` or the agreed limitations register is current.
- [x] The release branch, commit, and deployment environment are approved by user instruction on 2026-07-09: "Commit, push, deploy and proceed with all unfinished items" and "Proceed".
- [ ] The production deploy operator has permission to run `./deploy.sh`, `sudo -u www-data php artisan ...`, Apache status commands, cron checks, and backup commands.
- [ ] The rollback owner and decision owner are named before deploy.

Preflight commands:

```bash
cd /home/smudoshi/Github/Zephyrus
git status --short --branch
git fetch --prune origin
git rev-list --left-right --count @{u}...HEAD
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
composer install
npm ci
php artisan route:list
```

Release branch rule:

- [x] `git status --short` must be clean before `./deploy.sh`.
- [x] Ahead/behind count should be `0 0` unless an approved hotfix release explicitly allows a local-ahead commit.
- [x] Do not deploy from `feat/*` or a mixed worktree unless B8 records explicit approval. Approval for this feature branch was given by the user on 2026-07-09.
- [ ] If the normal checkout is dirty, create a clean release worktree:

```bash
git worktree add ../Zephyrus-release <approved-branch>
cd ../Zephyrus-release
git fetch --prune origin
git status --short --branch
git rev-list --left-right --count @{u}...HEAD
```

## Agent Swarm

| Work Package | Owner Agent | Reviewer | Handoff Recipient | Required Artifact |
| --- | --- | --- | --- | --- |
| Validation archive | QA Agent | Orchestrator | release operator | full command output bundle |
| Visual/mobile archive | Frontend + Mobile Agents | Security Agent | demo operator | screenshot/build matrix |
| PHI/security signoff | Security Agent | Orchestrator | release owner | PHI/security review |
| Demo rehearsal | Orchestrator | QA Agent | demo operator | fresh-seed rehearsal script/output |
| Deploy execution | Ops Agent | Orchestrator | production owner | deploy/post-deploy logs |
| Runtime monitoring | Ops Agent | QA Agent | support owner | 15-30 minute monitoring proof |
| Rollback rehearsal | Ops Agent | Backend/Data Agent | release owner | app/DB rollback artifact and smoke |
| Release notes/limitations | Documentation Agent | Security Agent | user/stakeholder | final release package |

## Agent Execution Contract

Owned write scope:

- B8 evidence package.
- release notes and known limitations.
- deployment/rollback runbook docs.
- tests only if needed to finish final validation gates.

Read-only first:

- `deploy.sh`.
- `bootstrap/app.php`.
- `routes/api.php`.
- `routes/web.php`.
- `composer.json`.
- `package.json`.
- `playwright.config.ts`.
- `hummingbird/androidApp/*`.
- `hummingbird/iosApp/*`.
- all B0-B7 evidence.

Do not touch:

- product behavior during B8 unless a validation failure requires a scoped fix.
- deployment mechanism alternatives; `./deploy.sh` remains canonical.

## Validation Inventory

| Validation | Existing Or To Create | B8 Rule |
| --- | --- | --- |
| `php artisan test` | Existing | required unless an approved blocker is recorded. |
| targeted PHP filters | Existing for most domains | run existing filters; do not claim missing tests passed. |
| `ApiRouteSmokeTest` | Missing unless created earlier | if still missing, B8 must either create it or record a blocking exception. |
| `ApiAuthorizationTest` | Missing unless created earlier | if still missing, B8 must either create it or record a blocking exception. |
| `npm run test` | Existing | required. |
| `npm run test:e2e` | Existing Playwright command | required for final web validation unless documented blocker. |
| `npm run build` | Existing | required before deploy and used by `deploy.sh`. |
| Android build/test | Existing Gradle wrapper | required if Android is in demo scope. |
| iOS generation/build | Requires `xcodegen` + Xcode | required if iOS is in demo scope. |
| Eddy Python tests | Existing under `eddy/tests` | required if local Eddy loop is in beta scope. |
| `hummingbird:test-push <username>` | Existing | required if push is in demo scope. |

## Release Evidence Structure

B8 must assemble the final package using `ACCEPTANCE-EVIDENCE-PACKAGE.md`:

```text
docs/zephyrus-2.0-beta-todos/evidence/B8/
  commands/
  api/
  screenshots/web/
  screenshots/mobile/
  mobile/android/
  mobile/ios/
  deploy/
  rollback/
  reviews/
  demo/
```

Every artifact must record date, branch, commit, environment, operator, reviewer, requirement IDs, and pass/fail/defer status.

## Criticality Double Check

B8 must close every row from `ENGINEERING-CRITICALITIES.md` across the full beta, not only B8-owned release work. Required final statuses:

- [ ] Requirements traceability: every requirement ID is `Complete`, `Ready for B8`, or `Deferred`.
- [ ] Architecture fit: no release-critical feature bypasses established Laravel/Inertia/BFF/native patterns.
- [x] API contract: route/API samples are archived and drift tests are run or known-limited for the hardened route families.
- [x] Authorization: route-family auth matrix is tested or known-limited for mobile writes, Eddy, OR case writes, and route smoke.
- [ ] PHI/privacy: screenshot/API/log/push/Eddy review is signed off.
- [ ] Data trust: every action-driving signal has source/as-of/freshness/synthetic/fallback state.
- [ ] Data integrity: seed/import/rebase/snapshot operations are idempotent or limitations are recorded.
- [ ] Migrations: production migration status and reversibility/forward-fix plan are archived.
- [ ] Frontend quality: web screenshots and Playwright/manual viewport reviews are archived.
- [x] Mobile parity: Android/BFF evidence is archived and iOS/toolchain limitations are explicit.
- [ ] Eddy governance: no-self-approval, tool catalog, dry-run, adapter, and audit evidence are archived.
- [ ] Observability: scheduler, queue, logs, source watermarks, and action ledgers are proven.
- [ ] Performance: cache/poll/realtime/materialized-view behavior is acceptable or limited.
- [ ] Realtime/fallback: Reverb state or poll fallback is proven.
- [ ] Security headers/config: CORS/CSP/Reverb/mobile config is reviewed.
- [ ] Accessibility/usability: visual and interaction review is archived.
- [ ] Testing: full and targeted command archive is complete.
- [ ] Deployment: `./deploy.sh` and post-deploy proof are archived.
- [ ] Rollback: rollback artifact and rehearsal are archived or risk-accepted.
- [ ] Documentation: release notes, known limitations, and demo script are current.

No criticality may remain `Pending` at final beta signoff.

## Evidence Package

B8 owns the final evidence bundle:

- [x] `evidence/B8/README.md`.
- [x] `commands/LOCAL-VALIDATION-2026-07-09.md` covering full PHP, targeted PHP, Vitest, Playwright, UI canon, Android, and build.
- [ ] `screenshots/web/`.
- [ ] `screenshots/mobile/`.
- [x] `mobile/android/`.
- [ ] Partial: `mobile/ios/` with Linux blocker and required macOS commands.
- [x] API route smoke evidence in `commands/LOCAL-VALIDATION-2026-07-09.md`.
- [ ] Partial: `reviews/PHI-SECURITY-NOTES-2026-07-09.md` with adversarial-review remediation notes.
- [ ] `reviews/traceability-final.md`.
- [x] `demo/demo-script.md`.
- [ ] Partial: `demo/demo-rehearsal.md` with local automated proof and manual rehearsal gap.
- [x] `deploy/DEPLOYMENT-RESULT-2026-07-09.md`.
- [x] Post-deploy smoke evidence in `deploy/DEPLOYMENT-RESULT-2026-07-09.md`.
- [x] `rollback/rehearsal.md`.
- [x] final known limitations and release notes.

## Workstream 8.1: Automated Validation

- [x] Run full PHP test suite.
- [ ] Run targeted suites:
  - [ ] Cockpit.
  - [x] Patient Flow.
  - [x] Eddy.
  - [x] Mobile BFF/mobile safety through `MobileBackendSafetyTest` and `FlowWindowTest`.
  - [x] Integration/synthetic connector.
  - [x] Route smoke.
  - [x] API authorization.
  - [x] Auth posture and mutable API authorization where tests exist.
- [x] Run JS tests.
- [x] Run UI canon script.
- [x] Run production build.
- [x] Archive command outputs with date, commit, branch, environment, pass/fail, and known skipped tests/reasons.

Commands:

```bash
git status --short
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
php artisan test
php artisan test --filter=CockpitSnapshotApiTest
php artisan test --filter=PatientFlowApiTest
php artisan test --filter=EddyActionTest
php artisan test --filter=AgentControlPlaneTest
php artisan test --filter=MobileBffTest
php artisan test --filter=MobileBackendSafetyTest
php artisan test --filter=SyntheticHealthcareConnectorTest
php artisan test --filter=RouteSmokeTest
# Only after these files exist:
php artisan test --filter=ApiRouteSmokeTest
php artisan test --filter=ApiAuthorizationTest
npm run test
npm run test:e2e
./scripts/check-ui-canon.sh
npm run build
```

## Workstream 8.2: Manual And Visual Validation

- [ ] Start local app and Vite server or use production build preview according to release mode.
- [ ] Capture web screenshots:
  - [ ] Cockpit desktop.
  - [ ] Cockpit wall.
  - [ ] Cockpit stale/degraded.
  - [ ] Cockpit action inbox.
  - [ ] RTDC Bed Tracking.
  - [ ] Patient Flow 4D default.
  - [ ] Patient Flow 4D scenario.
  - [ ] Patient Flow selected barrier.
  - [ ] ED board/triage.
  - [ ] Periop.
  - [ ] Transport.
  - [ ] EVS.
  - [ ] Staffing.
  - [ ] Improvement.
  - [ ] Study handoff.
  - [ ] Admin Integration Health.
- [ ] Test viewports:
  - [ ] Mobile.
  - [ ] Tablet.
  - [ ] Desktop.
  - [ ] Wall.
- [ ] For each screenshot, verify:
  - [ ] No overlapping elements.
  - [ ] No unlabelled synthetic values.
  - [ ] Source/freshness visible where action-driving.
  - [ ] PHI posture matches role.
  - [ ] Action state is clear.
  - [ ] Empty/degraded state is intelligible.

Suggested local run:

```bash
./start-dev.sh
php artisan route:list
```

Stop when done:

```bash
./stop-dev.sh
```

## Workstream 8.3: Native Mobile Validation

- [ ] Run iOS build.
- [x] Run Android tests/build.
- [ ] Capture iOS screenshot matrix.
- [ ] Capture Android screenshot matrix.
- [x] Validate mobile API target configuration for Android release/debug and iOS query construction.
- [ ] Validate token/device registration.
- [ ] Validate push/fetch-on-open behavior.
- [ ] Validate offline/stale behavior.
- [x] Validate PHI redaction for tested mobile Patient Flow/history payloads.
- [x] Validate action lifecycle and activity feed replay semantics for tested mobile writes.

Suggested commands:

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

```bash
cd hummingbird/androidApp
./gradlew testDebugUnitTest
./gradlew assembleRelease
```

If local toolchains are unavailable, archive the exact blocker and run on the correct machine before beta signoff.

## Workstream 8.4: PHI And Security Review

- [ ] PHI review:
  - [ ] Cockpit wall display.
  - [ ] Patient Flow aggregate role.
  - [ ] Patient Flow privileged role.
  - [ ] ED pages.
  - [ ] RTDC pages.
  - [ ] Huddle pages.
  - [ ] Mobile screenshots.
  - [ ] Eddy context and proposals.
  - [ ] Logs and broadcast payloads.
- [ ] Auth review:
  - [ ] Demo auto-login restriction.
  - [x] Mutable API auth.
  - [ ] Admin route auth.
  - [x] Mobile token auth.
  - [x] Agent scoped-token auth.
- [ ] Headers/realtime review:
  - [ ] Apache `.htaccess` CORS/CSP posture.
  - [ ] Laravel `SecurityHeaders`.
  - [ ] Reverb allowed origins.
  - [ ] Reverb rate limit.
  - [ ] Broadcast payload PHI.
- [ ] Mobile security review:
  - [x] Android backup.
  - [x] Android cleartext.
  - [ ] Exported components.
  - [ ] iOS entitlements.
  - [ ] Credential storage.
- [x] Archive findings and fixes.

Suggested commands:

```bash
rg -n "patientName|patient_ref|MRN|mrn|medical record|Authorization|Bearer|password|secret" app resources routes config public hummingbird
# Run concrete security/authorization tests only after their classes exist.
php artisan test --filter=ApiAuthorizationTest
```

## Workstream 8.5: Demo Script

- [ ] Write the final beta demo script with:
  - [ ] Environment setup.
  - [ ] Login/auth instructions.
  - [ ] Data seed timestamp.
  - [ ] Scenario order.
  - [ ] Expected values.
  - [ ] Expected screenshots.
  - [ ] Failure fallback.
  - [ ] Known limitations.
  - [ ] Operator notes.
- [ ] Include scenario steps:
  - [ ] House executive glance.
  - [ ] ED boarder to inpatient bed.
  - [ ] ICU downgrade unlocks capacity.
  - [ ] OR delay creates bed and staffing pressure.
  - [ ] Discharge barrier resolution.
  - [ ] Staffing gap and safe capacity.
  - [ ] Regional transfer/external demand if in scope.
  - [ ] Improvement/study handoff.
  - [ ] Trust/provenance/degraded feed.
- [ ] For each scenario, list:
  - [ ] Web route.
  - [ ] Mobile role.
  - [ ] Eddy prompt or action.
  - [ ] Expected source/freshness label.
  - [ ] Expected audit/activity row.
  - [ ] Screenshot target.

## Workstream 8.6: Deployment Checklist

- [ ] Pre-deploy:
  - [x] Confirm branch.
  - [ ] Confirm clean worktree.
  - [ ] Confirm branch current with origin.
  - [ ] Confirm ahead/behind count with `git rev-list --left-right --count @{u}...HEAD`.
  - [x] Confirm release is not from a mixed feature branch unless explicitly approved.
  - [ ] Confirm migrations needing production run.
  - [ ] Confirm env vars.
  - [ ] Confirm backup/rollback point.
  - [ ] Capture current app artifact or approved rollback source.
  - [ ] Capture database backup or approved restore point.
  - [ ] Confirm no GitHub Actions production deploy path.
- [ ] Deploy:
  - [x] Run `./deploy.sh` for the prior hardening tranche; rerun required for post-review hardening commit.
  - [x] Record output for the prior hardening tranche.
  - [x] Confirm Apache restart for the prior hardening tranche.
  - [x] Confirm vhost check for the prior hardening tranche.
- [ ] Post-deploy:
  - [x] Run migrations if needed.
  - [x] Clear caches if needed.
  - [ ] Refresh materialized views.
  - [x] Refresh cockpit snapshot.
  - [x] Run route/schema checks from `/var/www/Zephyrus`.
  - [x] Verify HTTP/HTTPS vhost.
  - [ ] Verify storage permissions.
  - [x] Verify scheduler.
  - [x] Verify queue worker.
  - [ ] Verify Reverb or fallback.
  - [ ] Verify cockpit.
  - [x] Verify Patient Flow.
  - [x] Verify mobile BFF route registration.
  - [x] Verify Eddy route registration.
  - [ ] Verify Integration Health.

Commands:

```bash
./deploy.sh
cd /var/www/Zephyrus
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan route:list
sudo -u www-data php artisan route:list --path=api
sudo -u www-data php artisan schedule:list
timeout 120s sudo -u www-data php artisan schedule:run -vvv
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan config:show queue.default
curl -I -H "Host: zephyrus.acumenus.net" http://localhost/
curl -I https://zephyrus.acumenus.net/
```

Only run `migrate --force` when schema state must be reconciled; always archive `migrate:status` before and after. Commands after deploy must run from `/var/www/Zephyrus`.

## Workstream 8.7: Runtime Operations Proof

- [ ] Scheduler:
  - [ ] `schedule:list` includes cockpit refresh, flow snapshot, materialized view refresh, pruning, OCEL/process jobs.
  - [ ] `timeout 120s sudo -u www-data php artisan schedule:run -vvv` succeeds as the production user.
  - [ ] Host cron/systemd timer is installed for the production user.
- [ ] Queue:
  - [ ] Queue worker process is running.
  - [ ] Failed queue is empty or known.
  - [ ] A test job can execute.
- [ ] Reverb/realtime:
  - [ ] Reverb process is running if enabled.
  - [ ] Origins/rate limits match beta policy.
  - [ ] Poll fallback works when realtime is disabled.
- [ ] Cockpit:
  - [ ] Snapshot is fresh.
  - [ ] Stale state can be observed or simulated.
  - [ ] Metric history is being written.
- [ ] Patient Flow:
  - [ ] `flow:snapshot` writes details.
  - [ ] Occupancy history returns data.
  - [ ] Scenario registry returns expected state.
- [ ] Eddy:
  - [ ] Action catalog loads.
  - [ ] Agent token scopes are correct.
  - [ ] Proposal can be created.
  - [ ] Approval policy enforced.
  - [ ] Execution adapter path works for demo action.

Post-deploy monitoring window:

- [ ] Watch production for 15-30 minutes after deploy.
- [ ] Archive Apache, cron, Laravel, queue, and app smoke outputs.
- [ ] Confirm no new fatal errors in Laravel logs.
- [ ] Confirm cockpit snapshot freshness after at least one scheduler interval.
- [ ] Confirm Patient Flow occupancy/history/scenario routes still respond.
- [ ] Confirm mobile BFF role route responds.
- [ ] Confirm Eddy catalog/proposal path responds.
- [ ] Confirm Integration Health/admin route follows admin auth posture.

Suggested commands:

```bash
sudo journalctl -u apache2 --since '-15 min' --no-pager
sudo journalctl -u cron --since '-15 min' --no-pager
sudo tail -n 200 /var/www/Zephyrus/storage/logs/laravel.log
cd /var/www/Zephyrus
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan config:show queue.default
curl -fsS -H "Cookie: <redacted-beta-session>" https://zephyrus.acumenus.net/api/cockpit/snapshot
curl -fsS -H "Cookie: <redacted-beta-session>" "https://zephyrus.acumenus.net/api/patient-flow/occupancy?include=eddy_context"
# Use the approved beta mobile token/session for mobile BFF auth:
curl -fsS -H "Authorization: Bearer <redacted-mobile-token>" https://zephyrus.acumenus.net/api/mobile/v1/altitude
```

If queue mode is not `sync`, verify the actual worker service/process. If Reverb is enabled, verify process/service, origins, rate-limit config, and poll fallback.

## Workstream 8.8: Rollback Plan

- [ ] Document rollback triggers:
  - [ ] Failed deploy.
  - [ ] Failed migration.
  - [ ] Cockpit unavailable.
  - [ ] PHI exposure.
  - [ ] Mobile BFF unavailable.
  - [ ] Eddy unsafe behavior.
  - [ ] Data corruption.
- [ ] Document rollback steps:
  - [ ] Previous release artifact or commit.
  - [ ] Current app artifact captured before deploy.
  - [ ] Database backup or restore point captured before migration.
  - [ ] Database rollback strategy.
  - [ ] Per-migration reversibility table.
  - [ ] Forward-fix owner when rollback is unsafe.
  - [ ] Cache clear.
  - [ ] Apache restart.
  - [ ] Queue restart.
  - [ ] Reverb restart.
  - [ ] Smoke checks.
- [ ] Document non-rollbackable migrations and mitigation.
- [ ] Practice rollback in staging or local deploy-like environment.
- [ ] Archive rollback rehearsal evidence.

Suggested backup/rehearsal commands to adapt with the actual production DB name/path:

```bash
git rev-parse HEAD
sudo tar -C /var/www -czf /var/backups/zephyrus-app-$(date +%Y%m%d%H%M%S).tgz Zephyrus
cd /var/www/Zephyrus
sudo -u www-data php artisan migrate:status
```

Use an Ops-approved PostgreSQL backup command for the actual production database, for example a `pg_dump` path under the established backup location. Do not add credentials to evidence files.

Post-rollback smoke:

```bash
cd /var/www/Zephyrus
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo systemctl restart apache2
curl -sS -o /dev/null -w '%{http_code}\n' -H 'Host: zephyrus.acumenus.net' http://localhost/
curl -fsS -H "Cookie: <redacted-beta-session>" https://zephyrus.acumenus.net/api/cockpit/snapshot
timeout 120s sudo -u www-data php artisan schedule:run -vvv
```

## Workstream 8.9: Final Known Limitations And Release Notes

- [ ] Update known limitations with:
  - [ ] Synthetic data surfaces.
  - [ ] Real integration status.
  - [ ] Mobile push posture.
  - [ ] Disabled scenarios.
  - [ ] Disabled Eddy tools.
  - [ ] Draft-only action paths.
  - [ ] Post-beta domains.
  - [ ] Test skips.
  - [ ] Operational dependencies.
- [ ] Write release notes:
  - [ ] What is included.
  - [ ] What is intentionally excluded.
  - [ ] How to demo.
  - [ ] How to validate.
  - [ ] How to roll back.
- [ ] Ensure release notes do not overclaim live integration where data is synthetic.

## Workstream 8.10: Final Definition Of Done

Mark beta complete only when all are true:

- [ ] B0 exit gate complete.
- [ ] B1 exit gate complete.
- [ ] B2 exit gate complete.
- [ ] B3 exit gate complete.
- [ ] B4 exit gate complete.
- [ ] B5 exit gate complete.
- [ ] B6 exit gate complete.
- [ ] B7 exit gate complete.
- [ ] Full automated validation archived.
- [ ] Visual validation archived.
- [ ] Mobile builds archived.
- [ ] Security/PHI review archived.
- [ ] Demo script archived.
- [ ] Deployment checklist rehearsed.
- [ ] Rollback checklist rehearsed.
- [ ] Known limitations approved.

## Phase Exit Gate

This phase is complete only when:

- [ ] The complete validation package passes or exact approved exceptions are documented.
- [ ] The demo can be rehearsed from a fresh seed without undocumented intervention.
- [ ] Production deployment and post-deploy checks are documented and rehearsed.
- [ ] Rollback is documented and rehearsed.
- [ ] Known limitations are accurate and visible.
- [ ] The final beta evidence bundle is committed or stored in an agreed artifact location.
