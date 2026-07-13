# Testing and Release Evidence

## Safety contract

Every PHPUnit process executes `tests/bootstrap.php` before Laravel boots. The bootstrap refuses to run unless all of these conditions are true:

- `APP_ENV=testing` and the deterministic test-only application key is active;
- PostgreSQL resolves through loopback and `DB_DATABASE` is in the `zephyrus_test*` namespace;
- per-process database isolation and the outbound-network guard are enabled;
- the integration secret root is below `storage/framework/testing/secrets`;
- application, OIDC, FHIR, Eddy, Arena, and allowlisted integration hosts are loopback or reserved `.test` names.

The bootstrap creates a random `zephyrus_test_<12 hex>` database, points Laravel at it, and removes it at process shutdown. `Tests\TestCase` also calls `Http::preventStrayRequests()`, so Laravel HTTP traffic fails before network I/O unless the individual test installs an explicit fake.

Never weaken these guards to make a test pass. Supply a local fake, fixture, or isolated service instead.

## Test lanes

The full PHPUnit run remains the authoritative backend regression. The named lanes are smaller, independently runnable release gates with explicit ownership:

| Lane | Command | Boundary |
|---|---|---|
| Unit | `composer test:unit` | Pure and service-level PHP behavior |
| Contract | `composer test:contract` | API authorization, ingress, OpenAPI/mobile DTO, and route middleware contracts |
| Integration | `composer test:integration` | Governed sources, FHIR/HL7 runtime, audit/replay, and patient-flow ingress |
| Admin | `composer test:admin` | Identity, authorization, governance, integrations, and production web boundaries |
| Migration | `composer test:migration` | Every `*SchemaTest.php` plus the production boundary on an isolated migrated database |
| Conformance | `composer test:conformance` | Healthcare/API conformance fixtures plus the Arena pytest/evaluation harness |
| Browser | `composer test:browser` | Chromium interaction against a deliberately provisioned test server/database |
| Security | `composer test:security` | Locked dependency audits, full-history/working-tree secret scans, local SAST policy, and offline edge contract |
| DAST | `composer test:dast` | Pinned OWASP ZAP passive scan against a disposable Laravel server/database |
| Full | `composer test:full` | All PHPUnit Unit and Feature tests |

List the lanes with `bash scripts/test-suite.sh list`. `scripts/run-browser-suite.sh` creates a random `zephyrus_test_e2e*` database through the loopback-only `scripts/manage-test-database.php` guard, seeds only the guarded browser actor and non-PHI RTDC fixtures, starts a directly owned loopback PHP server process, runs Chromium, then terminates the server and drops the database. Cleanup failure makes the lane fail. It must never target production.

The conformance lane expects the Arena dependencies and pytest to be installed. Set `ARENA_PYTHON` to the supported Arena virtual-environment interpreter when it is not the default `python`, for example `ARENA_PYTHON=arena/.venv/bin/python composer test:conformance`. CI installs `arena/requirements.txt` and pytest before running this gate.

The security lane installs exact top-level versions of Gitleaks, pip-audit, and Semgrep under `~/.cache/zephyrus-security-tools`; the Gitleaks Linux artifact is SHA-256 pinned. It audits `composer.lock`, `package-lock.json`, `arena/requirements.txt`, and `eddy/requirements.txt`, scans both complete Git history and the current tree with redaction, applies the repository-owned rules in `security/semgrep.yml`, and validates `deploy/security/edge-policy.json`. `.gitleaksignore` contains only exact reviewed fingerprints; path-wide exceptions for source or environment files are forbidden.

The DAST lane uses a digest-pinned ZAP stable image and a random `zephyrus_test_e2e*` database through the same loopback/drop guard as Playwright. `security/zap/baseline.conf` fails controls owned by the application, including missing CSP and anti-clickjacking protection. Its documented warnings cover the script-readable XSRF cookie, static-file headers enforced by the production Apache edge, `style-src 'unsafe-inline'` compatibility debt, and passive informational observations. The scan is unauthenticated; protected-route interaction remains owned by the required Playwright lane. Neither lane may target production.

## Concurrency proof

Run two complete backend suites at the same time:

```bash
composer test:concurrent
```

For a fast CI preflight, use `bash scripts/verify-test-isolation.sh --focused`. Both modes capture separate logs, require both processes to pass, query PostgreSQL for leftover `zephyrus_test_*` databases, and fail unless cleanup is complete.

## Release evidence

Wrap each release gate with the evidence recorder:

```bash
bash scripts/capture-release-evidence.sh backend-phpunit php artisan test --compact
bash scripts/capture-release-evidence.sh frontend-vitest npm test -- --run
bash scripts/capture-release-evidence.sh frontend-build npm run build
bash scripts/capture-release-evidence.sh security-suite composer test:security
bash scripts/capture-release-evidence.sh dast-zap composer test:dast
```

Each invocation writes a log, SHA-256 checksum, and `zephyrus.release-evidence.v1` JSON manifest containing the commit, timestamps, command, exit status, and log digest under `artifacts/release-evidence/`. CI uploads these artifacts even when a gate fails. Do not pass credentials on captured command lines and do not emit secrets or PHI to test output.

The recorder sends the complete command display and complete combined stdout/stderr stream through `scripts/redact-clinical-output.php` before persistence. The filter deliberately buffers a lane so transaction envelopes split across lines, pretty-printed FHIR/XML, and trailing private-key material are evaluated as one unit. If a recognizable clinical/credential signature is present, the persisted lane output is replaced with `[clinical-content-redacted]`; this is containment, not a passing test or de-identification proof. Keep gate output bounded and use the dedicated negative-output suite to prove producers never emit the content in the first place:

```bash
php artisan test --compact \
  tests/Unit/Security/ClinicalContentGuardTest.php \
  tests/Feature/Security/ClinicalContentFailureBoundaryTest.php
```

Minimum release evidence is:

1. immutable snapshot regression;
2. test-environment guard and focused concurrency proof;
3. Pint, the focused clinical-content negative-output matrix, full PHPUnit, TypeScript, Vitest, and Vite production build;
4. Arena conformance/evaluation tests when the sidecar changes;
5. Playwright for affected user-visible routes;
6. dependency, full-history/working-tree secret, SAST, and disposable-environment DAST gates;
7. `git diff --check`, zero disposable databases, and the deployment checklist for a manual production release;
8. production edge verification from `deploy.sh`, which requires active ModSecurity/OWASP CRS, the exact root-owned edge include, public security headers, sensitive-path denial, and TRACE rejection before PHI activation.
