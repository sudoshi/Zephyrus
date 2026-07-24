# B8 Evidence Index

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Environment: local validation and production deploy evidence through post-review hardening commit `fe78ba2`

## Automated Evidence

- `commands/LOCAL-VALIDATION-2026-07-09.md` records full PHP, targeted PHP, Vitest, Playwright, UI canon, Android, and production build results, including the post-adversarial 70-test focused safety run and 17-pass browser smoke.
- `mobile/android/README.md` records Android unit/release-build and security posture evidence.
- `mobile/ios/README.md` records the Linux-host blocker and exact macOS commands required for iOS proof.

## Review Evidence

- `reviews/PHI-SECURITY-NOTES-2026-07-09.md` records automated/code-review security evidence and outstanding manual/runtime checks.
- `reviews/ADVERSARIAL-REVIEW-2026-07-09.md` records the mobile/native and backend/security review findings, fixes, and remaining hardening items.

## Demo Evidence

- `demo/demo-script.md` is the operator-facing beta walkthrough.
- `demo/demo-rehearsal.md` records what has been proven automatically and what still requires a manual rehearsal.

## Deployment Evidence

- `deploy/DEPLOYMENT-BLOCKERS-2026-07-09.md` was converted into the current deployment preflight and deferred-evidence register after user approval to deploy.
- `deploy/DEPLOYMENT-RESULT-2026-07-09.md` records the deployed commit, deploy result, targeted migration, and post-deploy smoke results.

## Rollback Evidence

- `rollback/rehearsal.md` records rollback triggers, available rollback route, schema position, and required smoke checks.

## Known Limitations

- `../../../beta-known-limitations.md` is the canonical stakeholder-facing limitation register for this beta slice.
