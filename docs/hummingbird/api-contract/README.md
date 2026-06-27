# Hummingbird BFF — API Contract

[`hummingbird-bff.v1.yaml`](hummingbird-bff.v1.yaml) is the **OpenAPI 3.1 contract** for the
mobile Backend-for-Frontend (`/api/mobile/v1/*`) — the *only* surface the native apps talk
to. It is a **v1 draft** covering the highest-value endpoints (auth, me, home, For-You, RTDC
beds/barriers, Ops approvals, OR board, Command Center, Transport, EVS, realtime config). It
expands per the [feature-parity matrix](../01-feature-parity-matrix.md) and
[backend requirements](../04-backend-requirements.md).

## Why a contract-first BFF

The audit found the existing web `/api` is **chatty**, has **no uniform envelope**, leaves
several operational endpoints **without `auth`**, and interleaves **PHI** with operational
data. Pointing two native clients at that directly would bake those problems into mobile. The
BFF fixes all four at one seam:

- **One screen → one call** (aggregate the chatty reads).
- **Uniform envelope** `{ data, meta:{as_of,stale,version}, links:{web} }`.
- **`auth:sanctum` on everything**, role-scoped, with token abilities.
- **PHI-minimized** payloads; full PHI only on explicit authorized detail calls, **never** in
  lists or notifications.

Crucially, **mutations delegate** to the existing lifecycle services
(`OperationalActionLifecycleService`, the RTDC engine, the Transport/EVS/Staffing
`*OperationsService`s) — the BFF reshapes, it does not re-implement. One source of truth.

## How the contract is used

```
hummingbird-bff.v1.yaml
   ├── generates → shared/data Ktor client (KMP)  [openapi-generator / Ktor]
   ├── generates → Laravel route/Resource stubs + request validation
   ├── drives    → the conformance test spec (status rules, envelope, error/409 handling)
   └── documents → the deep-link `links.web` targets for every WEB-deferred surface
```

If the org rejects the shared-KMP approach, **this contract + the conformance spec becomes
the binding agreement** that keeps two fully-separate native apps behaving identically. Either
way, it is the anti-drift mechanism for behavior, exactly as the
[design tokens](../design-tokens/) are for appearance.

## Conventions baked in

- **Auth:** `bearerAuth` (Sanctum PAT) on all but `/auth/*`; `/auth/token` honors
  `must_change_password` by returning a **scoped change challenge** (the locked web flow is
  preserved, never bypassed).
- **Optimistic concurrency:** mutable resources carry `meta.version`; mutations may send it;
  the server returns **409** on a stale/illegal transition, and the client surfaces "changed
  since you loaded" rather than overwriting.
- **Delta sync:** list reads accept `updated_since` + `cursor`.
- **Deep links:** every envelope can carry `links.web` so a mobile surface can hand off to the
  full web experience in one tap.
- **Realtime:** `/realtime/config` returns the **PHI-free** Reverb channels to subscribe to,
  with the explicit reminder that Reverb does not replay — **re-snapshot on every reconnect**.

## Validate / preview

```bash
# lint
npx @redocly/cli lint hummingbird-bff.v1.yaml
# preview docs
npx @redocly/cli preview-docs hummingbird-bff.v1.yaml
# generate the KMP client (example)
openapi-generator-cli generate -i hummingbird-bff.v1.yaml -g kotlin -o ../../../../hummingbird/shared/data/generated
```

## Status / TODO

- [ ] Add OR safety-notes / milestones / case-transport request bodies (P2).
- [ ] Add Staffing + ED-signals + PI/PDSA paths (P3/P4) as their backends land.
- [ ] Add the `links.web` target map per WEB-deferred surface.
- [ ] Pin error codes enum + retry semantics; finalize pagination cursors.
