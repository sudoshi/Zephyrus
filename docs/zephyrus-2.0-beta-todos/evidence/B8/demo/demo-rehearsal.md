# Demo Rehearsal Evidence

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Status: partially rehearsed through automated local validation; manual fresh-seed rehearsal pending

## Automated Coverage Completed

- Full Laravel suite passed: 1 skipped, 661 passed, 6810 assertions.
- Vitest suite passed: 61 files, 277 tests.
- Playwright suite passed: 2 skipped, 16 passed before post-review auth UI fix.
- Post-review targeted Playwright suite passed: 17 passed, 2 skipped.
- Targeted Chromium navigation/RTDC huddle suite passed: 11/11.
- Patient Flow scenario/history/redaction tests passed.
- Post-review backend safety suite passed: 70 tests, 1563 assertions across mobile safety, Flow Window, Patient Flow, Eddy, authorization, and route smoke.
- Eddy no-self-approval and catalog metadata tests passed.
- Synthetic connector/admin authorization tests passed.
- Android debug unit tests and release build passed with Java 17.
- Production Vite build passed.

## Manual Rehearsal Still Required

- Fresh demo seed from a clean known timestamp.
- Browser screenshot matrix for cockpit, RTDC, Patient Flow, ED, periop, staffing, transport, EVS, improvement, study handoff, and Integration Health.
- Native Android/iOS persona screenshot matrix.
- End-to-end operator rehearsal from visibility to recommendation to human decision to action to outcome.
- Screenshot PHI review and push-notification PHI review.
- Production runtime smoke after the final post-review `./deploy.sh`.

## Rehearsal Decision

The beta can be deployed for continued hardening because automated gates passed and the user explicitly approved deployment. It should not be represented as final beta signoff until the manual rehearsal and runtime evidence above are archived.
