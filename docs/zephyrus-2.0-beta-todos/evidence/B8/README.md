# B8 Evidence Index

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Environment: local validation plus pending production deploy evidence

## Automated Evidence

- `commands/LOCAL-VALIDATION-2026-07-09.md` records full PHP, targeted PHP, Vitest, Playwright, UI canon, Android, and production build results.
- `mobile/android/README.md` records Android unit/build and security posture evidence.
- `mobile/ios/README.md` records the Linux-host blocker and exact macOS commands required for iOS proof.

## Review Evidence

- `reviews/PHI-SECURITY-NOTES-2026-07-09.md` records automated/code-review security evidence and outstanding manual/runtime checks.

## Demo Evidence

- `demo/demo-script.md` is the operator-facing beta walkthrough.
- `demo/demo-rehearsal.md` records what has been proven automatically and what still requires a manual rehearsal.

## Deployment Evidence

- `deploy/DEPLOYMENT-BLOCKERS-2026-07-09.md` was converted into the current deployment preflight and deferred-evidence register after user approval to deploy.
- Final `deploy.sh` and post-deploy smoke output must be added after the clean deploy completes.

## Rollback Evidence

- `rollback/rehearsal.md` records rollback triggers, available rollback route, schema position, and required smoke checks.

## Known Limitations

- `../../../beta-known-limitations.md` is the canonical stakeholder-facing limitation register for this beta slice.
