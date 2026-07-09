# Validation Inventory

Purpose: distinguish commands and tests that exist now from validation assets that a phase must create before claiming completion.

This inventory is deliberately strict. A command listed as "to create" cannot be used as green evidence until the named file or command exists in the repo and has itself been reviewed.

## Existing Repo Commands

| Validation Need | Existing Command | Notes |
| --- | --- | --- |
| Full PHP tests | `php artisan test` | Existing Laravel test runner. |
| Route list | `php artisan route:list` | Use targeted `--path=api`, `--path=patient-flow`, `--path=mobile`, or `--path=eddy` when narrowing output. |
| Artisan command inventory | `php artisan list --raw` | Use to verify commands before documenting them. |
| JS tests | `npm run test` | Vitest. |
| Browser E2E tests | `npm run test:e2e` | Playwright. Requires app/server setup per Playwright config. |
| Production frontend build | `npm run build` | Used by `deploy.sh`. |
| UI canon | `./scripts/check-ui-canon.sh` | Existing script. |
| Demo seed | `php artisan zephyrus:demo-seed` | Preferred high-level demo provisioning path. |
| Patient Flow synthetic import | `php artisan patient-flow:import-synthetic <path>` | Requires a path argument. Known candidate: `patient-flow-4d-navigator/data/hl7_messages.ndjson` if present and approved. |
| Patient Flow synthetic rebase | `php artisan patient-flow:rebase-synthetic` | Anchor defaults to now; record chosen anchor. |
| Patient Flow snapshot | `php artisan flow:snapshot` | Writes hourly per-unit census and per-space occupancy checkpoints. |
| RTDC demo reset | `php artisan rtdc:demo-reset` | Existing command. |
| RTDC simulation | `php artisan rtdc:simulate` | Existing command. |
| Hummingbird push smoke | `php artisan hummingbird:test-push <username>` | Username is required; use PHI-free title/body. |
| Scheduler list | `php artisan schedule:list` | Source is `bootstrap/app.php`. |
| Scheduler run | `timeout 120s php artisan schedule:run -vvv` | Writing smoke check; use after deploy or in an approved local/test environment. |
| Queue failed jobs | `php artisan queue:failed` | Pair with `php artisan config:show queue.default`. |
| Reverb local server | `php artisan reverb:start` | Only proof if Reverb is enabled for the environment. |
| Config inspection | `php artisan config:show <key>` | Useful keys: `queue.default`, `broadcasting.default`, `reverb`. |

## Existing Test Classes And Targets

| Area | Existing Test/Command | Use |
| --- | --- | --- |
| Cockpit snapshot | `php artisan test --filter=CockpitSnapshotApiTest` | Snapshot API behavior. |
| Cockpit dashboard | `php artisan test --filter=CockpitDashboardPageTest` | `/dashboard` page behavior. |
| Cockpit status parity | `php artisan test --filter=StatusParityTest` | Status vocabulary. |
| Cockpit trust/source | `php artisan test --filter=FlowLiveSourcesTest` and `php artisan test --filter=StaffingLiveSourcesTest` | Existing live-source coverage. |
| Patient Flow API | `php artisan test --filter=PatientFlowApiTest` | Current Patient Flow API foundation. |
| Mobile BFF | `php artisan test --filter=MobileBffTest` | Broad mobile BFF. |
| Mobile safety | `php artisan test --filter=MobileBackendSafetyTest` | Role/redaction safety. |
| Mobile flow | `php artisan test --filter=FlowWindowTest` | Mobile Flow Window. |
| Mobile Eddy | `php artisan test --filter=EddyMobileBffTest` | Mobile Eddy BFF. |
| Mobile contract drift | `php artisan test --filter=MobileRoleCatalogParityTest` and `php artisan test --filter=MobileUiVocabularyParityTest` | Mobile parity fixtures/vocabulary. |
| Eddy action lifecycle | `php artisan test --filter=EddyActionTest` | Existing lifecycle tests. |
| Eddy control plane | `php artisan test --filter=AgentControlPlaneTest` | Existing ops/agent control plane tests. |
| Synthetic connector | `php artisan test --filter=SyntheticHealthcareConnectorTest` | Synthetic integration proof. |
| Route smoke | `php artisan test --filter=RouteSmokeTest` | Existing route smoke. Important: current test skips `api/` routes, so it is not API coverage. |
| RTDC huddles | `php artisan test --filter=HuddleApiTest` | Huddle API coverage. |
| Bed placement | `php artisan test --filter=BedPlacementFlowTest` | Bed placement workflow. |
| Periop cockpit drill | `php artisan test --filter=PeriopDrillTest` | Cockpit periop drill. |
| Staffing | `php artisan test --filter=StaffingApiTest` and `php artisan test --filter=StaffingAuthInvariantsTest` | Staffing API/auth. |
| Transport | `php artisan test --filter=TransportRequestApiTest` and `php artisan test --filter=RegionalTransferApiTest` | Transport/transfer APIs. |
| EVS | `php artisan test --filter=EvsRequestApiTest` | EVS request API. |
| JS navigation | `npm run test -- tests/js/config/navigationConfig.test.ts` | Navigation config tests. |
| JS cockpit | `npm run test -- tests/js/cockpit` | Cockpit component/hooks tests. |
| JS command center | `npm run test -- tests/js/commandCenter` | Command center component tests. |
| Playwright navigation | `npm run test:e2e -- tests/e2e/navigation.spec.ts` | E2E navigation. |

## Current E2E Harness Notes

- `playwright.config.ts` runs with `fullyParallel: false` and `workers: 1`.
- Reason: the Laravel development server, shared browser session state, and long-lived SSE cockpit stream routes are not safe under high-concurrency Playwright execution.
- Specs that visit cockpit/RTDC routes should stub or abort long-lived `/api/cockpit/stream` traffic when the assertion is about navigation, huddle flow, or page shell behavior rather than streaming itself.
- E2E login assumptions must match the current demo/development auth posture: demo routes auto-auth where intended; seeded login tests should be skipped unless `TEST_USERNAME` and `TEST_PASSWORD` are supplied.

## Validation Assets To Create Or Maintain

| Needed Asset | Owner Phase | Expected File/Command | Required Assertions |
| --- | --- | --- | --- |
| API route smoke | B0/B7/B8 | Created: `tests/Feature/ApiRouteSmokeTest.php` | API route families return expected `200/401/403/404/405` and do not throw. |
| API authorization matrix | B0/B7/B8 | Created: `tests/Feature/ApiAuthorizationTest.php` | Public/read/session/admin/Sanctum/agent route behavior is explicit. |
| Nav leaf route smoke if not already enough | B0/B3 | Extend `tests/js/config/navigationConfig.test.ts` or PHP route smoke | Every nav leaf resolves or is disabled/external. |
| Beta mock-mode guard | B3 | JS/PHP tests chosen by implementation | Beta surfaces cannot silently use unlabeled mock data. |
| Shared PHI display policy tests | B3/B6/B7 | `tests/Feature/Privacy/PatientDisplayPolicyTest.php` or equivalent | Patient name/MRN/ref behavior by role and surface. |
| Patient Flow history tests | B4 | Extended: `tests/Feature/PatientFlow/PatientFlowApiTest.php` | `/api/patient-flow/occupancy/history` bounded/paginated and redacted. |
| Patient Flow scenario tests | B4 | Extended: `tests/Feature/PatientFlow/PatientFlowApiTest.php` | `/api/patient-flow/demo-scenarios` enumerates selected scenario set and labels demo/live. |
| Patient Flow detail persistence tests | B4 | Extended: `tests/Feature/Mobile/FlowWindowTest.php` | Snapshot detail/count/projection/lineage JSON fields are populated and retained. |
| Eddy tool catalog schema tests | B5 | Extended: `tests/Feature/Eddy/EddyActionTest.php` | Every tool declares role/scope/PHI/dry-run/adapter/rollback/audit/mobile availability. |
| Eddy strict no-self-approval tests | B5 | Extended: `tests/Feature/Eddy/EddyActionTest.php` | Agent cannot approve; human self-approval rule matches D15. |
| Eddy local Python tests in release gate | B5/B8 | `cd eddy && python -m pytest` | Local agent loop, sanitizer, proposal, stream, and router pass with dependencies installed. |
| Hummingbird push/fetch tests | B6 | PHP/native tests and `hummingbird:test-push <username>` smoke | PHI-free payload, correct device behavior, fetch fallback if push not required. |
| Android release security tests/checks | B6/B8 | Implemented code review plus `./gradlew test` and `./gradlew assembleDebug` | Non-dev backup/cleartext/exported component posture. |
| iOS project generation/build proof | B6/B8 | `cd hummingbird/iosApp && xcodegen generate && xcodebuild ...` | Project generated and demo-scope build succeeds. |
| Domain API smoke matrix | B7 | Extended: `tests/Feature/ApiRouteSmokeTest.php` | ED/RTDC/periop/transport/EVS/staffing/improvement/study route families smoke. |
| Operational-event-to-study contract | B7 | Feature test around Study/A3 route/API | Event payload is aggregate, redacted, source-linked, and actionable. |
| Deployment rollback rehearsal | B8 | `evidence/B8/rollback/rehearsal.md` | App artifact, DB backup, migration reversibility, forward-fix owner, smoke commands. |

## Production Validation Command Block

Use from the production code directory after `./deploy.sh`:

```bash
cd /var/www/Zephyrus
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan route:list --path=api
sudo -u www-data php artisan schedule:list
timeout 120s sudo -u www-data php artisan schedule:run -vvv
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan config:show queue.default
```

Runtime checks:

```bash
sudo systemctl is-active apache2
sudo systemctl is-active cron
sudo crontab -u www-data -l
curl -sS -o /dev/null -w '%{http_code}\n' -H 'Host: zephyrus.acumenus.net' http://localhost/
curl -sSI https://zephyrus.acumenus.net/
curl -fsS -H "Cookie: <redacted-beta-session>" https://zephyrus.acumenus.net/api/cockpit/snapshot
```

Only run production migrations when a release plan says schema state must be reconciled. Always capture `migrate:status` before and after.
