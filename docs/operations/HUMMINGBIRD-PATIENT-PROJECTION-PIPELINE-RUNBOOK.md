# Hummingbird Patient Pathway Projection Pipeline Runbook

**Status:** operational diagnostic only; it does not approve, release, or activate patient content.

## Purpose

The `patient_projection_pipeline` system-health component provides bounded, PHI-free
evidence for the draft-only Hummingbird Patient pathway projection producer. It reads
only aggregate timestamps, freshness classes, and failure categories from the patient
projection ledgers. It never reads projection content, calls a source system, advances a
cursor, identifies a patient, grant, encounter, or source event, or changes a feature
flag.

The probe is healthy while every patient-pathway draft gate is disabled by governance.
Once all four gates are deliberately enabled—patient product, patient pathway,
pathway-history drafts, and care-pathway patient support—it becomes a required
system-health signal.

## Signals

The component records the following non-content aggregates:

- number of effective pathway instances, observed instances, and instances with no
  status observation;
- age of the oldest and newest current observation;
- number of observed active grants expected to have a draft, drafts missing
  entirely, drafts behind newer source history, and freshness class of the
  latest draft projection;
- recent retryable, manual-review, and terminal failure counts.

The configured warning/critical lag, failure window, and critical-failure thresholds
live under `admin-health.patient_projection`. A missing observation, missing or
source-behind draft, stale latest draft, critical freshness age, or critical recent
failure count makes the component critical and triggers the existing deduplicated
system-health alert path.

## Safe triage

1. Open the System Health view and select **Patient pathway projections**, or run
   `php artisan admin:observe-system-health` from the approved deployed runtime.
2. Record the component status, stable error code, aggregate counts, release SHA, and
   observation time in the incident record. Do not copy projection content, source
   payloads, identifiers, enrollment material, or credentials into logs, tickets, or chat.
3. If governance gates are partially enabled, resolve the configuration/release decision
   before enabling a worker. Do not use a monitor observation to authorize a source,
   release a draft, or activate a patient feature.
4. For a missing, source-behind, or stale draft, inspect the approved source connector
   and its content-free reconciliation/dead-letter evidence using the source owner's
   runbook. Preserve the patient product in its approved degraded/withheld state until
   the source and release owner approve recovery.
5. For recent failures, determine whether the category is retryable or requires manual
   review. Re-run only the bounded, approved worker after the source and clinical owners
   have verified the input. Do not edit append-only projection, cursor, or failure rows.
6. If a patient-visible release could be incorrect, use the governed correction/retraction
   process and the patient-product kill switch. The monitor itself cannot perform either
   action.

## Boundaries

- This control is not evidence that a clinical source, disclosure policy, independent
  review/release, identity proofing, message ownership, or patient pilot is approved.
- No direct production SQL, ad hoc source replay, blanket migration, or bypass of the
  canonical deployment path is permitted.
- The monitor can be deployed with all patient gates off; gate activation remains a
  separately approved release decision.
