# Medgnosis EHR Connectivity — Reusable Assets for Zephyrus

**Date:** 2026-06-20
**Source:** `/home/smudoshi/Github/Medgnosis` (production at Geisinger). Mapped on the founder's instruction to learn from proven EHR connectivity rather than rebuild.
**Bearing on Zephyrus:** primarily informs the **FHIR/SMART/Bulk ingestion path** (future S1/S8). The immediate S2 build runs on the synthetic simulator and does not need this yet — but the north-star ingestion design now assumes we **port Medgnosis**, not green-field it.

---

## The decisive lesson: ingestion is Node/TypeScript, not Python

My initial north-star put the ingestion sidecar in Python. Medgnosis proves a **production FHIR stack in Node/TypeScript** at Geisinger. Porting it beats rebuilding, and Node/TS lets the ingestion layer share `packages/core` types with web + Hummingbird. **Revised decision:** `zephyrus-ingest` = Node/TypeScript for the FHIR/SMART/Bulk path. Python is retained only where its ecosystem is decisive — `zephyrus-predict` (ML) and `zephyrus-optimize` (OR-Tools/Timefold).

**The one genuine gap:** Medgnosis is FHIR-only. It has **no HL7v2 ADT / MLLP listener**. Zephyrus's priority real-time source is the ADT feed (admit/transfer/discharge = the census heartbeat). That MLLP listener + canonical-event mapping is net-new and is the part of the backbone we actually have to build.

---

## Port verbatim / near-verbatim (production-hardened)

| Asset | Medgnosis path | Reuse | Notes |
|-------|----------------|-------|-------|
| **FHIR client** | `apps/api/src/services/ehr/fhirClient.ts` (570 L) | ~100% | Hand-rolled; pagination, retry/backoff, OperationOutcome classification, `FhirRequestAudit`. Inject `FetchLike` + vendor adapter. |
| **Vendor adapters** | `services/ehr/vendorAdapters/*` | ~90% | Epic, Oracle Cerner, HAPI, SMART-generic behind a stable `EhrVendorAdapter` interface. Extend per vendor. |
| **SMART launch** | `services/ehr/smartLaunch.ts` (420 L) | ~95% | PKCE + `state`/`nonce`, token exchange supporting `public_pkce`, `client_secret_*`, **`private_key_jwt`**. **Never stores raw tokens** — only hashes + expiry metadata. |
| **Bulk Data** | `services/ehr/bulkData.ts` (~1200 L) | ~85% | `$export` polling, backoff, manifest + NDJSON parsing; BullMQ `ehr-bulk-import` worker. |
| **EDW hydration** | `services/ehr/edwHydration.ts` (850 L) | ~80% | The integration engine: staging → normalize, **patient-first ordering**, hash idempotency, transaction-scoped, batched draining (500/batch, resumable). Remap targets to Zephyrus schema. |
| **EMPI / identity** | `services/ehr/identity/*` (~900 L) | ~95% | Three-tier match: strong-identifier → demographic floor key (HL7 Identity Matching IG) → probabilistic MPI fallback. **Never auto-merges on demographics alone**; enqueues steward review on conflict. Safety-critical; copy whole. |
| **FHIR mappers** | `services/fhir/mappers.ts` (345 L) | ~70% | EDW↔FHIR R4, US Core + OMB race/ethnicity extensions, gender/encounter crosswalks. Use as template. |
| **Tenant registry + onboarding** | `services/ehr/tenantRegistry.ts`, `onboardingProfile.ts` | ~85% | Multi-tenant EHR ("tenant 2" Epic pattern): `ehr_tenant`, `ehr_client_registration` (secrets stored as refs, never raw). |
| **HTTP routes** | `routes/ehr/{launch,admin,jwks}.ts` | ~80% | SMART launch/callback, admin console, `.well-known/jwks`. |
| **Workers** | `workers/ehr-{bulk-import,patient-context-refresh}.ts`, `mpi-feed.ts` | ~90% | BullMQ async patterns. |
| **Schema** | `packages/db/migrations/060,061,063,089,090` | ~100% | `ehr_tenant`, `ehr_client_registration`, `ehr_resource_crosswalk`, `fhir_ingest_staging`, `ehr_ingest_run`. Adopt as-is with schema renames. |

## The hydration pattern to adopt (this is the integration engine)

1. **Staging** (`resourceStaging.ts`): raw FHIR JSON → append-only `fhir_ingest_staging` (JSONB) with `content_hash = SHA256(canonical_json)` for idempotent dedup; unique on `(org, tenant, resource_type, resource_id, version, last_updated, hash)`; status `staged → normalized → failed/skipped`.
2. **Hydration** (`edwHydration.ts`): drain staged rows **Patient-first** (`CASE resource_type WHEN 'Patient' THEN 0 …`) so person identity exists before child resources; resolve identity via EMPI; upsert into normalized tables; record `ehr_resource_crosswalk` (source FHIR id ↔ local row) for child lookups; skip orphans (patient not yet ingested); all in one transaction.
3. **Crosswalk** is the idempotency + provenance backbone — hash-based change detection skips unchanged resources; soft-delete tracks source deprecation.

## Epic registration specifics (learned, save the rediscovery)

- **Two apps:** Backend Services (`client_credentials` + signed JWT, for Bulk `$export`) **and** SMART Launch (auth-code, for clinician workflow).
- **RS384** signing (Epic requirement, not RS256); client-assertion JWT **exp ≤ 5 min**; one shared public JWKS for both apps; redirect URI matched byte-for-byte.
- Sandbox test patient `erXuFYUfucBZaryVksYEcMg3` (Camila Lopez); group-export test group id available.

## Do NOT copy (Medgnosis-specific)

QDM bridge, CQL/measure-evaluation layers, HADES/eCQM, risk-scoring/surveillance workers. Zephyrus is operations, not eCQM. CDS Hooks support exists in Medgnosis and *is* relevant later (outbound recommendations into EHR workflow) — adopt selectively.

## Testing pattern to adopt

Vitest + `vi.hoisted` mocks for DB/FHIR isolation; `test-fixtures/fhir/*.json` resource fixtures; `__smoke__/` dirs for live-sandbox checks gated behind a tenant flag; `nock` for HTTP mocking. Mirrors how we'll test Zephyrus ingestion without a live EHR.

---

**Net effect on the roadmap:** the FHIR half of Zephyrus's ingestion backbone is mostly a *port*, not a *build*. The build effort concentrates on (a) the HL7v2 ADT/MLLP listener, (b) mapping both ADT and FHIR into Zephyrus's **canonical operational event** + census projection, and (c) the real-time fan-out — none of which Medgnosis needed because it is population-health, not real-time operations.
