# Zephyrus 2.0 Beta Demo Script

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Audience: internal beta review, hospital operations workflow review, engineering release review

## Operator Setup

1. Confirm the deployed commit and branch.
2. Confirm the app was deployed with `./deploy.sh`.
3. Confirm `migrate:status` has no unexpected pending migrations.
4. Confirm the browser session is using the approved beta/demo role.
5. Keep `docs/beta-known-limitations.md` open and disclose limitations before showing affected areas.

## Scenario 1 - House Executive Glance

- Web route: `/dashboard`.
- Goal: show calm command-center entry, status vocabulary, source/freshness posture, and route to drill.
- Expected proof: page loads from production, no unlabeled synthetic claim, no overlapping hero/action labels.
- Fallback: show `npm run test:e2e` and route smoke evidence from `commands/LOCAL-VALIDATION-2026-07-09.md`.

## Scenario 2 - ED Boarder To Inpatient Bed

- Web route: RTDC/Patient Flow routes surfaced from dashboard navigation.
- Mobile role: RTDC, EVS, transport, or ops role homes through `/api/mobile/v1/*`.
- Eddy path: use draft/recommendation action only; do not claim autonomous approval.
- Expected proof: flow bottleneck, owner/next move, handoff queue, and activity ledger behavior agree.
- Fallback: show `PatientFlowApiTest`, `FlowWindowTest`, `MobileBackendSafetyTest`, and Eddy draft-only test evidence.

## Scenario 3 - ICU Downgrade Unlocks Capacity

- Web route: Patient Flow 4D scenario view.
- Expected proof: scenario registry exposes repeatable demo scenario metadata and occupancy history remains bounded/redacted.
- API proof: `/api/patient-flow/demo-scenarios` and `/api/patient-flow/occupancy/history`.
- Fallback: use route list and `PatientFlowApiTest` evidence.

## Scenario 4 - OR Delay And PACU Hold

- Web route: perioperative analytics/drill route.
- Expected proof: periop APIs read `prod.or_cases` and `prod.or_logs` style schema without stale table names.
- Fallback: show `ApiRouteSmokeTest` and `/api/cases/*` smoke evidence.

## Scenario 5 - Discharge Barrier Resolution

- Web route: Patient Flow barrier inspector and EVS/transport queues.
- Mobile role: EVS or transport.
- Expected proof: action creates exactly one operational event on retry, with deterministic idempotency key behavior.
- Fallback: show `MobileBackendSafetyTest` idempotency evidence and Android/iOS client idempotency implementation notes.

## Scenario 6 - Staffing Gap And Safe Capacity

- Web route: staffing wizard/admin route.
- Expected proof: read access and write access are separated; demo guest cannot mutate protected staffing configuration.
- Fallback: show `StaffingWizardPageTest`, `StaffingWriteApiTest`, and `ApiAuthorizationTest` evidence.

## Scenario 7 - Regional Transfer / External Demand

- Web route: regional transfer route if included in the demo environment.
- Eddy path: draft-only recommendation.
- Expected proof: transfer decision path remains audited and AI does not self-approve.
- Fallback: show `RegionalTransferApiTest` and Eddy draft-only catalog evidence.

## Scenario 8 - Improvement / Study Handoff

- Web route: improvement/study route if enabled for beta.
- Expected proof: aggregate operational event evidence is shown without exposing an EHR chart.
- Fallback: disclose as an incomplete demo proof item and show tested operational activity ledger behavior.

## Scenario 9 - Trust / Provenance / Degraded Feed

- Web route: Integration Health/admin surfaces.
- Expected proof: source, synthetic/live state, freshness, and degraded-state posture are explicit.
- Fallback: show synthetic connector test evidence and known limitations.

## Closeout

1. Restate what was live-deployed versus what was locally validated.
2. Identify iOS, screenshot PHI review, push review, and runtime smoke limitations if still pending.
3. Show rollback trigger and rollback evidence file.
4. Confirm no autonomous Eddy approval was demonstrated or implied.
