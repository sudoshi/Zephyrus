# Zephyrus 2.0 Beta Hardening Release Notes

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`

## Included

- API authorization and route smoke coverage for protected mutable/admin/API route families.
- Perioperative API schema hardening for current `prod.or_cases` / `prod.or_logs` data shape.
- Patient Flow 4D scenario registry, occupancy history endpoint, redacted history behavior, and richer live snapshot details.
- Eddy action lifecycle hardening: token callers remain draft-only, self-approval stays blocked, and tool catalog metadata is explicit.
- Mobile BFF idempotency replay protection for operational activity writes.
- Android demo security hardening: backup/device-transfer disabled, cleartext traffic disabled, and data extraction rules added.
- iOS client idempotency preparation for macOS validation.
- Playwright harness stabilization for current Inertia/Vite app behavior.
- B8 evidence package, known limitations, demo script, rollback plan, and deployment preflight.

## Validated Locally

- `php artisan test`: 1 skipped, 661 passed, 6810 assertions.
- `npm run test`: 61 files, 277 tests.
- `npm run test:e2e`: 2 skipped, 16 passed.
- `npm run build`: passed with existing large-chunk warnings.
- Android `./gradlew test` and `./gradlew assembleDebug`: passed with Java 17.

## Limitations

- iOS build validation requires macOS/Xcode/xcodegen.
- Manual screenshot PHI review and native screenshot matrices are not complete.
- Push-notification PHI review is not complete.
- Production runtime smoke evidence must be added after `./deploy.sh`.
- Some demo scenarios remain proof-by-API/test rather than fully rehearsed visual walkthrough.

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
