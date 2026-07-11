# Canonical Staffing Fulfillment Runbook

## Purpose

This runbook operates the qualification, availability, shift-assignment, and request-fulfillment runtime introduced by migration `2026_07_10_000300_create_canonical_staffing_fulfillment_tables.php`.

The runtime fails closed: a person cannot be offered, accepted, or filled unless the request resolves to an active canonical assignment, all effective requirements have verified qualifications, an explicit availability window covers the whole shift, and no blocking window or overlapping active shift exists.

## Runtime invariants

- `hosp_org.staff_members` plus `hosp_org.staff_assignments` remain the workforce identity and role/unit/service-line authority.
- `hosp_ref.staff_qualifications` and `hosp_ref.staff_role_qualification_requirements` define the effective requirement vocabulary.
- `hosp_org.staff_member_qualifications` stores effective verification state and its canonical assignment lineage.
- `prod.staff_availability_windows` stores timezone-labelled availability, on-call, leave, unavailable, preference, and conflict intervals in UTC.
- `prod.staff_shift_assignments` is the active capacity/conflict boundary.
- `prod.staffing_request_fulfillments` follows `offered -> accepted -> filled -> released` or the allowed cancellation branches.
- `prod.staffing_fulfillment_events` and `prod.staffing_fulfillment_commands` are append-only. Never repair them with direct updates or deletes.
- Every web and Hummingbird write requires an idempotency key and passes through the same locked service path.

## Configuration

```dotenv
STAFFING_DEFAULT_FACILITY_KEY=SUMMIT_REGIONAL
STAFFING_DEFAULT_TIMEZONE=America/New_York
STAFFING_MATERIALIZATION_DAYS=28
STAFFING_MATERIALIZATION_SCHEDULE_ENABLED=true
STAFFING_MATERIALIZATION_SCHEDULE_TIME=04:10
```

Use an IANA timezone. The materializer selects only active assignments for the configured facility and reconciles only rows it owns. Manual or integrated qualification and availability rows are not deleted.

## Deployment and first activation

1. Capture the database backup and migration ledger required by the normal production change procedure.
2. Deploy the merged `main` tree only through `DEPLOY_RUN_MIGRATIONS=1 ./deploy.sh`.
3. Confirm the migration is recorded:

   ```bash
   php artisan migrate:status | rg '2026_07_10_000300'
   ```

4. Execute the complete projection inside a rollback-only transaction:

   ```bash
   php artisan staffing:materialize-canonical --facility=SUMMIT_REGIONAL --days=28 --dry-run
   ```

5. Review the projected qualification and window counts. Unexpected zero counts are a stop condition.
6. Commit the same projection:

   ```bash
   php artisan staffing:materialize-canonical --facility=SUMMIT_REGIONAL --days=28
   ```

7. Re-run it and confirm stable counts. This is the idempotency check.
8. Confirm the daily schedule is registered:

   ```bash
   php artisan schedule:list | rg 'staffing:materialize-canonical'
   ```

## Verification queries

Run these read-only checks through the approved PostgreSQL console:

```sql
SELECT status, count(*)
FROM hosp_org.staff_member_qualifications
GROUP BY status
ORDER BY status;

SELECT window_type, count(*)
FROM prod.staff_availability_windows
WHERE source = 'canonical-materializer'
  AND ends_at > now()
GROUP BY window_type
ORDER BY window_type;

SELECT status, count(*)
FROM prod.staffing_request_fulfillments
GROUP BY status
ORDER BY status;

SELECT count(*) AS overfilled_requests
FROM (
    SELECT f.staffing_request_id
    FROM prod.staffing_request_fulfillments f
    JOIN prod.staffing_requests r USING (staffing_request_id)
    WHERE f.status = 'filled'
    GROUP BY f.staffing_request_id, r.headcount_needed
    HAVING count(*) > r.headcount_needed
) violations;

SELECT count(*) AS overlapping_active_assignments
FROM prod.staff_shift_assignments a
JOIN prod.staff_shift_assignments b
  ON a.staff_member_id = b.staff_member_id
 AND a.staff_shift_assignment_id < b.staff_shift_assignment_id
 AND a.starts_at < b.ends_at
 AND a.ends_at > b.starts_at
WHERE a.status IN ('offered', 'accepted', 'filled')
  AND b.status IN ('offered', 'accepted', 'filled');
```

Both violation counts must be zero.

## Operational response

- `qualification_requirements_unconfigured`: run the dry-run materializer and inspect the role vocabulary before committing it.
- `missing_required_qualification`: verify the source assignment review state or record an approved credential; do not bypass eligibility.
- `availability_unverified`: load an explicit covering availability/on-call window. Metadata alone is not sufficient during fulfillment.
- `unavailable_or_on_leave`: resolve the blocking source record; a positive window does not override leave or conflict.
- `overlapping_shift_assignment`: release/cancel the existing governed assignment or choose another person.
- headcount rejection: another accepted or filled command won the request lock. Refresh and cancel the now-unneeded offer.

## Rollback

Disable future projection first with `STAFFING_MATERIALIZATION_SCHEDULE_ENABLED=false`, clear configuration cache, and redeploy the prior merged `main` tree through `./deploy.sh`.

Do not roll back the migration after fulfillment events exist: its ledgers are intentionally append-only and reference operational requests. Keep the additive tables, disable the write surface, and restore application behavior through a forward fix. Database restoration is reserved for the approved full-release rollback procedure.
