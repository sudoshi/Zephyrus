# Governed Transport Lifecycle Runbook

**Owner:** Transport Operations and Platform Operations

**Applies to:** Zephyrus web Transport Command Center and Hummingbird transport persona
**Production deploy path:** merged `main` through `./deploy.sh` only

## Runtime contract

All request creation, assignment, claim, status, cancellation, and handoff writes pass through `TransportLifecycleService`. The service locks the request and selected resource, checks the actor, applies one explicit transition, records an append-only event, and commits an append-only command receipt in the same database transaction.

The normal progression is:

`requested -> accepted/queued -> assigned -> dispatched -> arrived_pickup -> patient_ready/picked_up -> en_route -> arrived_destination -> handoff_complete -> completed`

`patient_not_ready`, `escalated`, `canceled`, and `failed` are governed exception paths. A structured reason is mandatory for those targets. Assignment must use the assignment endpoint or the Hummingbird claim action. `handoff_complete` must use the structured handoff endpoint.

Dispatch roles can request, assign, and manage exceptions. A transporter can claim unassigned work only when its mode is in `TRANSPORT_MOBILE_CAPABILITIES`, and can progress only work assigned to their canonical user resource. Hummingbird queue reads expose only unassigned work plus the authenticated transporter's active assignments; incapable work is visible as awaiting dispatch rather than claimable.

## Idempotency

Every lifecycle write requires an `Idempotency-Key` header containing 1-200 letters, numbers, dots, underscores, colons, or hyphens. A byte-equivalent logical command replay returns its original response snapshot and creates no duplicate event or assignment. Reusing the key for a different actor, request, command, or payload returns `409 Conflict`.

Clients should generate one key when the operator starts a command, retain it for network retries, and discard it after a definitive response. Do not reuse a key for a later intentional command.

## Resource and handoff rules

`prod.transport_resources` is the capacity catalog. `prod.transport_assignments` reserves one or more capacity units and permits only one active assignment per request. Terminal transitions release the reservation. A configured capacity reduction never makes a live resource mathematically over capacity; it converges after reserved work releases.

The request types in `TRANSPORT_HANDOFF_REQUIRED_TYPES` cannot complete until `prod.transport_handoff_evidence` contains receiver identity, receiver role, acceptance status, and acceptance time. Optional document references and outstanding risks are structured arrays. Event, command, and handoff ledgers reject direct update/delete operations and ordinary parent cascades. The deterministic scenario service selects explicit ownership markers and enables its cascade only through a transaction-local database setting.

## First deployment

1. Start from a clean, current `main` worktree and capture a verified PostgreSQL logical backup plus the migration ledger.
2. Review the `TRANSPORT_*` values in the production environment. Keep secrets out of Git; these settings contain no credentials.
3. Preview the configured catalog:

   ```bash
   php artisan transport:sync-resources --dry-run
   ```

4. Deploy the merged commit and explicit additive migration:

   ```bash
   DEPLOY_RUN_MIGRATIONS=1 ./deploy.sh
   ```

5. Materialize the configured catalog:

   ```bash
   php artisan transport:sync-resources
   ```

6. Clear cached configuration if any environment value changed:

   ```bash
   php artisan config:clear
   ```

## Post-deploy verification

Run these read-only checks against production:

```sql
-- No request has multiple active assignments.
SELECT transport_request_id, count(*)
FROM prod.transport_assignments
WHERE status = 'active' AND released_at IS NULL
GROUP BY transport_request_id
HAVING count(*) > 1;

-- No resource is over capacity.
SELECT r.resource_key, r.capacity,
       COALESCE(sum(a.capacity_units), 0) AS busy
FROM prod.transport_resources r
LEFT JOIN prod.transport_assignments a
  ON a.transport_resource_id = r.transport_resource_id
 AND a.status = 'active' AND a.released_at IS NULL
GROUP BY r.transport_resource_id, r.resource_key, r.capacity
HAVING COALESCE(sum(a.capacity_units), 0) > r.capacity;

-- Required completed handoffs have receiver evidence.
SELECT tr.transport_request_id, tr.request_type, tr.status
FROM prod.transport_requests tr
LEFT JOIN prod.transport_handoff_evidence he
  ON he.transport_request_id = tr.transport_request_id
WHERE tr.handoff_required = true
  AND tr.status = 'completed'
  AND he.transport_handoff_evidence_id IS NULL;

-- Command keys and active assignments remain unique.
SELECT idempotency_key, count(*)
FROM prod.transport_commands
GROUP BY idempotency_key
HAVING count(*) > 1;
```

All four queries must return zero rows. Also verify:

- guest API calls return `401`;
- a non-transport frontline account cannot create, assign, or progress work;
- a dispatcher can create and assign with an idempotency key;
- a transporter sees only unassigned work and their own assignments;
- retrying the same key returns the same status/version without another event;
- web and mobile cursor pages do not overlap;
- Apache and the Zephyrus forced-host check remain healthy.

## Monitoring and incident response

- Capacity conflicts return `409`; inspect active assignments before increasing configured capacity.
- Illegal or stale transitions return `409`; refresh the request and use the server-provided `allowed_transitions`.
- Missing/invalid idempotency keys and incomplete handoffs return `422`.
- Actor or ownership failures return `403`; do not work around these by changing the request directly.
- Compare `prod.transport_commands`, `prod.transport_events`, and request `lifecycle_version` when investigating a disputed action.
- Never update/delete an event, command receipt, or handoff evidence row. Correct operational state with a new governed transition or an explicitly approved data-remediation migration.

## Rollback

Prefer forward repair. The migration deliberately refuses `down()` outside local/testing because rollback would destroy command receipts, assignments, handoff evidence, resources, and lifecycle columns. An application rollback should keep the schema in place, restore the prior merged `main` commit through the normal release process, and verify that no older code attempts to mutate append-only ledgers. Any production schema reversal requires a separately reviewed export/remediation migration and incident plan.
