# Hummingbird Patient Staff-Message Handoff Runbook

**Status:** draft operating aid; not approval to enable patient messaging
**Scope:** content-free delivery attempts from the patient message outbox to the accountable staff inbox
**Owner required before pilot:** named response-desk lead and named technical on-call owner

## Purpose and hard boundary

This runbook supports the existing default-off patient-message handoff consumer. It
does not approve a pilot, choose a patient cohort, grant staff access, requeue a
message, or authorize a production database change. The consumer and its health
command deliberately return aggregate counts only: no patient identity, encounter,
thread, outbox UUID, worker reference, routing decision, or message content is
printed.

Never correct an event-sourced handoff by updating/deleting a delivery-attempt row,
inserting a new outbox row, or running SQL directly against production. Do not use
an ad hoc SSH command or direct production `git pull` to respond to a handoff issue.
Apply code/configuration changes only through the controlled release workflow.

## Read-only first check

From an approved application runtime, run:

```bash
php artisan hummingbird:patient-message-handoff-health --json
```

This command performs only aggregate reads. It exits nonzero only for a `critical`
state. Preserve the command output with the incident record only if the receiving
system is approved for operational metadata; do not add identifiers or message text
to a ticket.

## Interpreting the report

| Field / state                   | Meaning                                                                                                                                             | Immediate safe action                                                                                                                              |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `disabled`                      | Messaging is not enabled or is not governance-approved.                                                                                             | No delivery action. Confirm the default-off state is intentional; do not turn a flag on to investigate.                                            |
| `healthy`                       | Required schema exists, the consumer is freshly alive, and no retry/terminal condition is reported.                                                 | Continue normal monitoring.                                                                                                                        |
| `warning`                       | A current lease expired, a retry is due/scheduled, or the latest consumer batch reported degraded.                                                  | Check again after the next approved scheduler interval. If it persists, open an operational incident and notify the response-desk owner.           |
| `critical`                      | Required schema is missing, the active consumer heartbeat is absent/stale, a terminal delivery failure exists, or the latest state is unrecognized. | Open a safety/operations incident; keep patient composition fail-closed and escalate to the named technical and clinical-response owners.          |
| `pending_ready`                 | A content-free outbox fact is ready but has no delivery attempt yet.                                                                                | Verify scheduler/consumer health. Do not create a second outbox event.                                                                             |
| `in_flight`                     | A worker has an active immutable claim lease.                                                                                                       | Do not run a competing/manual delivery process. Wait for the lease to resolve or expire.                                                           |
| `expired_claim_lease`           | A worker lease elapsed before a result was appended.                                                                                                | The next governed consumer batch records an `handoff_claim_lease_expired` fact and reclaims it. Observe that sequence; do not alter the old claim. |
| `retry_due` / `retry_scheduled` | A delivery failed safely and is awaiting its governed retry time or is due now.                                                                     | Preserve the facts; investigate the stable error code through the approved support path. Do not force an immediate replay.                         |
| `terminal_failure`              | Automated retry budget is exhausted.                                                                                                                | Treat as an operational/safety incident. The current implementation has no operator requeue because duplicating a patient communication is unsafe. |

## Incident workflow

1. Record the timestamp, aggregate health JSON, application release SHA, active
   feature-flag state, scheduler/service health, and whether the condition is
   `warning` or `critical`. Do not record patient or message content.
2. For a stale heartbeat, verify the approved scheduler/supervisor and the controlled
   runtime health. A restart, configuration change, or release requires the normal
   change/release authority; do not access the server with ad hoc commands.
3. For an expired lease, allow one bounded approved consumer interval to append its
   immutable recovery fact. Escalate if the condition remains after that interval.
4. For a retry or terminal failure, retain the existing facts, notify the named
   response-desk lead, and follow the approved downtime/escalation policy. Patient
   compose must remain unavailable whenever the readiness gate is not healthy.
5. For a terminal failure, do not fabricate staff ownership or manually duplicate the
   handoff. The pilot requires a separately approved remediation decision and a
   tested, auditable superseding workflow before any enablement.
6. Close the incident only after the aggregate report is healthy, the release/config
   state is verified, and the clinical-response owner documents the patient-safe
   outcome through the approved process.

## Pilot prerequisites still open

- Named alert routing, service-level objectives, response times, and escalation owners.
- An approved terminal-failure remediation/supersession workflow; no requeue exists today.
- Projection and push outbox consumers with their own delivery policy and runbooks.
- Approved patient identity/enrollment, source/release, responsibility-pool, and pilot
  governance decisions.
- Integrated rehearsal evidence from a production-like test environment.

The command and this document are operational scaffolding only. They do not enable
messaging or establish clinical/production readiness.
