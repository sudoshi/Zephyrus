# Zephyrus 2.0 Beta Hardening Release Notes

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`

## Included

- API authorization and route smoke coverage for protected mutable/admin/API route families.
- Perioperative API schema hardening for current `prod.or_cases` / `prod.or_logs` data shape.
- Patient Flow 4D scenario registry, occupancy history endpoint, redacted history behavior, and richer live snapshot details.
- Eddy action lifecycle hardening: token callers remain draft-only, self-approval stays blocked, and tool catalog metadata is explicit.
- Mobile BFF idempotency replay protection for operational activity writes, plus persona-specific write gates for RTDC, transport, EVS, staffing, and Eddy approvals.
- Mobile Patient Flow demo-scenario and occupancy-history parity endpoints in the BFF plus Android/iOS client calls.
- Android demo security hardening: backup/device-transfer disabled, release cleartext traffic disabled, production HTTPS/WSS defaults, and debug-only emulator overrides.
- iOS client idempotency, Patient Flow parity calls, and query encoding preparation for macOS validation.
- OR case API authorization/schema mapping hardening for the current `prod.or_cases` plus `prod.or_logs` shape.
- Login failed-credential error rendering restored for the current Inertia auth page.
- Playwright harness stabilization for current Inertia/Vite app behavior.
- B8 evidence package, known limitations, demo script, rollback plan, and deployment preflight.

## Validated Locally

- `php artisan test`: 1 skipped, 661 passed, 6810 assertions.
- `npm run test`: 61 files, 277 tests.
- `npm run test:e2e`: 2 skipped, 16 passed before post-review auth UI fix.
- Targeted post-review Playwright: 17 passed, 2 skipped.
- Targeted post-review backend safety run: 70 tests, 1563 assertions.
- `npm run build`: passed with existing large-chunk warnings.
- Android `./gradlew testDebugUnitTest` and `./gradlew assembleRelease`: passed with Java 17.

## Limitations

- iOS build validation requires macOS/Xcode/xcodegen.
- Manual screenshot PHI review and native screenshot matrices are not complete.
- Push-notification PHI review is not complete.
- Prior production runtime smoke evidence exists for the first hardening deploy; final post-review hardening still requires a clean-branch `./deploy.sh` run and smoke checks.
- Some demo scenarios remain proof-by-API/test rather than fully rehearsed visual walkthrough.
- Concurrent duplicate idempotency submissions should still be hardened with a database-level unique/upsert path; sequential replay and conflicting payload handling are tested.
- `db/schemas/init/004-case-tables.sql` remains stale relative to the verified live `prod.or_cases.scheduled_start_time` type and should be reconciled.

## Deploy

Use only:

```bash
cd /home/smudoshi/Github/Zephyrus
./deploy.sh
```

Run production migrations only if `migrate:status` shows pending migrations and the release owner approves execution.

## 2026-07-09 Deployment

- Commit `2e58cf2a8492bbcd0e13c746725b08c7278a337e` was deployed successfully with `./deploy.sh`.
- Production required one targeted Patient Flow migration for `flow_core.occupancy_snapshots` detail columns.
- Health, vhost, scheduler, queue, route registration, cockpit snapshot refresh, and Patient Flow snapshot smoke checks passed.
- Authenticated mobile/Eddy/Integration Health browser smoke remains pending.
