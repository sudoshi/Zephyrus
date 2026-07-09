# Zephyrus 2.0 Beta Known Limitations

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`

This file is the stakeholder-facing limitation register for the Zephyrus 2.0 beta hardening slice. It prevents demo, security, mobile, and operations gaps from being mistaken for completed production capability.

## Native Mobile

- Android debug unit tests and release build pass on the local Linux host with Java 17. Release config defaults to `https://zephyrus.acumenus.net` and `wss`; emulator cleartext traffic is debug-only.
- iOS build validation is not complete on this host because `swift`, `xcodegen`, and `xcodebuild` are unavailable. Required validation must run on macOS:

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

- Native screenshot matrices for Android and iOS personas are not yet archived.
- Push-notification PHI review is not yet complete. Demo operators should prefer fetch-on-open/manual refresh paths unless an approved PHI-free push smoke is executed.
- iOS client code now includes Patient Flow history/scenario calls and stricter query encoding, but it has not been compiled on this Linux host.

## Visual And PHI Review

- Automated E2E, PHP, JS, build, and Android gates passed locally, but the full screenshot matrix is not archived.
- Manual PHI review is still required for cockpit wall, Patient Flow privileged views, ED/RTDC pages, Hummingbird personas, Activity, logs, and Eddy prompt/output paths.
- Patient Flow aggregate-history and mobile broad-list tests enforce redaction for tested payloads, but screenshots remain the source of truth for visual disclosure review.
- Patient Flow history redaction now strips raw patient/encounter identifiers and emits `ptok_` context references where detail is allowed; this is automated API proof, not a replacement for screenshot review.

## Eddy And AI Governance

- Eddy action approval is draft-only for agent tokens and cannot self-approve in the tested paths.
- The tool catalog now declares role, scope, PHI policy, dry-run, adapter, rollback, audit, and mobile availability metadata.
- Web Eddy proposal/token routes and Hummingbird Eddy approval calls now enforce role eligibility in tested paths.
- Cloud-model routing, BAA/de-identification posture, prompt-injection review, and full production Eddy runtime policy review remain pending before broad live use.
- Some Eddy actions may remain dry-run-only or disabled; operators must treat action catalog metadata as authoritative.

## Integrations And Demo Data

- Synthetic/demo data must be disclosed in UI, API evidence, screenshots, and release notes.
- Synthetic connector proof passed locally, but real integration status must not be overclaimed as live production feed completeness.
- Stale/degraded feed scenario screenshots and Eddy caveat/refusal samples are not yet fully archived.

## Operations

- `deploy.sh` is the canonical production deployment mechanism.
- `deploy.sh` does not run Laravel migrations. Production migration execution should occur only when `migrate:status` shows a migration needed by the deployed slice and the release owner/operator approves it.
- After deployment on 2026-07-09, production was missing Patient Flow snapshot detail columns required by the deployed snapshot/history code. The operator ran only `database/migrations/2026_07_05_000400_extend_patient_flow_occupancy_snapshots_for_disk_details.php` with `--path` and `--force`; the columns then existed and `flow:snapshot` succeeded.
- Production still reports unrelated pending service-line/staffing alignment migrations and legacy base migrations. These were not run during this slice.
- Scheduler, queue, cockpit snapshot refresh, Patient Flow snapshot, route registration, health endpoint, vhost checks, Apache status, and production cron were smoked after the post-review hardening deployment of `fe78ba2` on 2026-07-09.
- Full Reverb/fallback, authenticated mobile BFF, authenticated Eddy catalog, and Integration Health browser checks remain pending.
- Rollback is currently app-artifact/commit based for the post-review hardening code because no new migrations are added. The prior targeted Patient Flow columns should be treated as additive and left in place unless an ops-approved database restore is required.
- Mobile idempotency replay is tested for sequential retries and conflicting-payload rejection. A database-level unique/upsert path for simultaneous duplicate submissions remains a hardening follow-up.
- `db/schemas/init/004-case-tables.sql` still needs reconciliation with the live/migration schema; live production was verified as `prod.or_cases.scheduled_start_time timestamp without time zone`.

## Demo

- The demo script is available under `docs/zephyrus-2.0-beta-todos/evidence/B8/demo/demo-script.md`.
- A fresh-seed manual rehearsal is not yet archived end-to-end.
- If any domain route, native surface, or Eddy action is unavailable during demo, the fallback is to show the corresponding tested API/evidence artifact and explicitly label the limitation.
