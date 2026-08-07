# Nightingale Five-Patient Investor-Demo Cohort Implementation Plan

**Date:** 2026-07-27

**Status:** production cohort provisioned, isolated, kill-switch rehearsed, restored, and
verified; public-WAN smoke recheck remains an outage-recovery observation, not a cohort
correctness gate

**Execution log:**
[DEVLOG-nightingale-investor-demo-patient-cohort-2026-08-06.md](../devlog/DEVLOG-nightingale-investor-demo-patient-cohort-2026-08-06.md)

**Scope:** five synthetic Nightingale demo accounts, five isolated inpatient scenarios,
five exact MS-DRG bindings, safe in-place adoption of the existing reference sample, and
the canonical iOS and Android investor-demo journeys

**Clinical-use status:** **DEMO — NOT FOR CLINICAL USE**

**Canonical patient apps:**

- `hummingbird/iosPatientApp` (renamed in place to Nightingale and already distributed
  through TestFlight); and
- `hummingbird/androidPatientApp` (the matching Nightingale Android client).

**Superseded app paths:** `nightingale/iosApp` and `nightingale/androidApp` are not
implementation targets and must not be restored.

**Merged upstream foundation:** PR #111 is now on protected `main` and owns the salvaged
activation/disclosure gates, governance documents, privacy manifest, foundation config
keys, and app-identity uniqueness guard. This cohort commit is rebased directly on that
merge and adds no duplicate copy of those artifacts.

## 1. Outcome and fixed decisions

The investor-demo cohort must provide five repeatable Nightingale sign-ins:

| Login handle | Synthetic patient label    | Exact pathway key                                                    | MS-DRG | Authoritative codebook title                                                        | Draft stages | Draft milestones |
| ------------ | -------------------------- | -------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------- | -----------: | ---------------: |
| `demo1`      | Nightingale Demo Patient 1 | `drgcp-heart-failure-671d63b4d61b`                                   | `293`  | Heart Failure and Shock without CC/MCC                                              |            5 |               41 |
| `demo2`      | Nightingale Demo Patient 2 | `drgcp-simple-pneumonia-pleurisy-337d0f29a350`                       | `195`  | Simple Pneumonia and Pleurisy without CC/MCC                                        |            5 |               44 |
| `demo3`      | Nightingale Demo Patient 3 | `drgcp-major-joint-replacement-hipknee-lower-extremity-a7fc97e65adc` | `470`  | Major Hip and Knee Joint Replacement or Reattachment of Lower Extremity without MCC |            5 |               49 |
| `demo4`      | Nightingale Demo Patient 4 | `drgcp-appendectomy-5b2df7e00bf7`                                    | `399`  | Appendix Procedures without CC/MCC                                                  |            5 |               36 |
| `demo5`      | Nightingale Demo Patient 5 | `drgcp-vaginal-delivery-2fd506169d41`                                | `807`  | Vaginal Delivery without Sterilization or D&C without CC/MCC                        |            5 |               44 |

The shared password was supplied directly by the user. It is intentionally absent from
this document and every repository artifact. Provisioning accepts it only through a
non-echoing runtime prompt, hashes it through Laravel's configured password hasher, and
never accepts it as a command option or echoes, serializes, audits, or logs it.

The five scenarios provide medical, surgical, orthopedic, and obstetric breadth. Each
login owns exactly one synthetic principal, identity link, encounter grant, operational
encounter, and pathway binding. No demo account may enumerate, read, mutate, message on,
or obtain an existence signal for another account's resources.

## 2. Source and clinical-governance disposition

Read-only production reconciliation found one 250-pathway catalog release:

- release state: `inactive`;
- institutional approval: `not_reviewed`;
- clinical signoff: incomplete;
- 1,250 stage definitions, all `draft`;
- 9,601 milestone definitions, all `draft`;
- 7,000 sections, all `source_candidate` and `staff_reference`;
- 96 versions marked ready for institutional clinician signoff;
- 148 versions requiring specialist review with documented limitations; and
- 6 versions requiring pathway redesign.

The five selected versions are in the first group and have complete stage/milestone
inventories. That makes them useful reconciliation inputs, not approved patient content.
The demo implementation therefore has two deliberately separate layers:

1. **Catalog binding evidence** resolves the one release with the exact dataset key, three
   immutable SHA-256 evidence fingerprints, grouper version, and aggregate controls, then
   records that environment's release UUID together with the exact pathway key, version
   UUID, MS-DRG code/title, stage count, and milestone count. The UUID is provenance, not
   a portable content identity: production and generated verification fixtures assign
   different UUIDs to the same evidence-defined release.
2. **Patient-safe synthetic demo projections** contain purpose-written, allowlisted,
   conspicuously labeled demonstration copy.

Raw section prose, unapproved stage labels/explanations, milestone detail, evidence claims,
source excerpts, review notes, staff-reference fields, or inferred care recommendations
must not cross into a patient response. The canonical catalog is never activated or
modified by demo provisioning.

## 3. Product and realm boundary

The cohort is a Nightingale demonstration facility, not a pilot and not a real-patient
realm. Its product-owned metadata must include at least:

- `product = nightingale`;
- `environment_class = synthetic_investor_demo`;
- `owner = nightingale-demo-cohort-provisioner-v1`;
- `cohort_version = nightingale-investor-demo-cohort-v1`;
- the non-secret login handle;
- the exact code-owned scenario key;
- `synthetic = true`;
- `clinical_use_permitted = false`; and
- `automatic_enrollment = false`.

Demo accounts may reuse the already-hardened patient identity, grant, session, audit, and
projection kernel only through an explicit Nightingale demo adapter. They must never enter
`prod.users`, staff roles, `/api/mobile/v1`, Hummingbird Staff tokens, or staff endpoints.
Ordinary patient email authentication remains unchanged. Only principals with the exact
owner/product/cohort metadata may authenticate through the short demo handle.

### 3.1 Existing reference-sample adoption

`demo1` is not a newly invented sixth sample. It adopts, in place, the existing
production Nightingale reference patient that was derived from the former Hummingbird
Patient template. The adoption contract is fail-closed:

- require exactly one active, non-discharged, non-deleted encounter with patient reference
  `demo-nightingale-reference-inpatient`, the exact reference owner, and the explicitly
  selected unit;
- require exactly one reference principal with the exact synthetic Nightingale product,
  reference owner, source mode, and Hummingbird source-template lineage;
- require the principal to remain pending/inactive with no email, phone, password,
  verification, authentication, lock, or closure state;
- require zero identity links, encounter grants, enrollment challenges, sessions, audit
  events, notification devices, notification outbox rows, or access tokens;
- preserve the principal primary key, principal UUID, encounter primary key, encounter
  `created_by`, and source-template lineage during adoption;
- record explicit, code-owned adoption lineage inside `demo1` provisioning metadata;
- reject a missing, duplicate, moved, discharged, previously linked, credentialed, or
  otherwise changed reference sample before any cohort write; and
- on replay, require the exact recorded adoption lineage rather than silently treating
  any `demo1` principal as equivalent.

`demo2` through `demo5` remain deterministic cohort-owned creations. No production apply
may create a replacement `demo1` when the governed reference sample is absent or changed.

## 4. Provisioning command contract

Implement one product-owned command family:

```text
nightingale:demo-cohort preview
nightingale:demo-cohort apply
nightingale:demo-cohort verify
nightingale:demo-cohort suspend
```

The exact Artisan signature may use separate commands or a required action argument, but
the following behavior is mandatory.

### 4.1 Preview

- Default to read-only preview.
- Require PostgreSQL and every expected schema/table.
- Resolve exactly one eligible operational unit under a code-owned or explicit reviewed
  unit identifier.
- Reconcile all five catalog definitions by immutable pathway key plus exact MS-DRG code
  and title.
- Require exactly one catalog release and reject catalog, codebook, mapping, count, or
  approval-state drift.
- Report only actions, counts, opaque UUIDs where necessary, and non-sensitive states.
- Never request, read, hash, or validate the password.
- Make zero writes, sequences included.

### 4.2 Apply

- Require an explicit `--commit` or equivalent confirmation phrase.
- Require a non-echoing runtime prompt or equivalently protected secret provider; reject
  command-line password options and repository-backed secret files.
- Reject empty, whitespace, control-character, newline, oversize, known-placeholder, or
  policy-invalid password material without printing it.
- Require a deployed-runtime safety flag and exact environment/host checks.
- Acquire one cohort advisory transaction lock and use serializable or equivalent
  retry-safe transactions.
- Resolve all five catalog rows before any write and lock every existing command-owned
  cohort row needed for deterministic reconciliation.
- Create missing cohort rows or converge only exact command-owned rows.
- Stop on foreign ownership, ambiguous cardinality, cross-linkage, unexpected sessions,
  unexpected projections, wrong DRG binding, altered product metadata, or partial cohort
  state.
- Hash the password once per principal through Laravel's configured hasher; never persist
  plaintext or a reversible credential.
- Commit all five accounts as one atomic cohort change. A failure for any member leaves
  all five unchanged.
- Return only a redacted verification summary.

### 4.3 Verify

Verification must prove:

- exactly five owned principals and login aliases;
- aliases are exactly `demo1` through `demo5`;
- all principals are active, synthetic, and Nightingale-owned;
- five non-null password hashes pass structural/rehash checks without disclosing hashes;
- no plaintext or supplied-secret fragment is present in JSON metadata, encrypted source
  fields, audit metadata, command output, logs, projections, or catalog tables;
- exactly five identity links, grants, operational encounters, and catalog bindings;
- one and only one principal per grant and one grant per synthetic encounter;
- no shared encounter UUID, identity digest, source digest, token family, session, or
  projection across accounts;
- exact DRG code/title/pathway-key reconciliation and expected definition counts;
- the canonical catalog remains inactive and unchanged;
- each account sees exactly its six patient-safe projection kinds;
- every projection carries the demo/non-clinical-use notice and code-owned provenance;
- zero raw staff-reference section values appear in projection content;
- token, refresh, session-list, logout, revocation, and cross-account denial work;
- audit events contain no clinical prose, credential, source identifier, or other
  account's handle; and
- rerunning verify is read-only.

### 4.4 Suspend

- Default to preview.
- Require a separate exact confirmation and the same runtime/ownership checks as apply.
- Revoke all demo sessions and tokens before disabling principals and grants.
- Disable only rows owned by the exact cohort version; never delete or alter foreign rows.
- Preserve append-only projection, audit, and catalog lineage.
- Verify token denial and zero effective grants after revocation.
- Support an independently tested re-apply path that rotates password hashes and creates
  no duplicate patient, encounter, link, grant, policy, projection, or binding.

## 5. Authentication contract

The token request must accept one neutral login field in the Nightingale-owned contract.
During compatibility:

- RFC email input continues to use the existing case-normalized email lookup.
- A value matching `^demo[1-5]$` may use the demo-alias lookup.
- Demo-alias lookup must additionally require exact Nightingale product, owner, cohort,
  synthetic, active, and non-clinical-use metadata.
- No broader username lookup is allowed.
- Unknown, disabled, foreign-owned, duplicated, malformed, or wrong-password cases return
  the same generic credential denial.
- Timing protection uses the same dummy hash path for unknown aliases.
- The response never reveals whether the alias, principal, DRG, encounter, or cohort
  exists.
- Authentication audit records a safe method class such as
  `nightingale_demo_password`, never the submitted alias or password.

The compatibility API must not be relabeled as a production-ready Nightingale contract.
A dedicated `/api/nightingale/v1` route remains subject to its existing contract,
authorization, activation, and migration gates.

## 6. Patient-safe projection set

Each demo account requires these six released, synthetic projection kinds:

1. `today`;
2. `pathway`;
3. `pathway_events`;
4. `discharge_readiness`;
5. `rounds_summary`; and
6. `care_team`.

Every screen and payload begins with or persistently exposes:

> DEMO — NOT FOR CLINICAL USE. This is synthetic information for a product
> demonstration. It is not a medical record, care instruction, diagnosis, order, or
> promise. For urgent help, use the bedside call button or speak with staff.

The pathway projection should be scenario-distinct while remaining synthetic. It must:

- display the exact MS-DRG code and codebook title only in a clearly labeled
  `Synthetic demo scenario` context;
- explain that the catalog pathway is draft and institutionally unapproved;
- provide a complete, navigable synthetic stage/milestone journey;
- mark timing as estimated or unknown and explicitly changeable;
- avoid medication doses, procedure instructions, diagnostic assertions, risk scores,
  predicted outcomes, discharge promises, or personalized recommendations;
- preserve accessible non-color state cues;
- supply patient-safe clarification prompts; and
- avoid claiming comprehension, consent, education completion, or clinical approval.

“Full pathway” for this cohort means every patient-facing demo stage and milestone in the
released synthetic projection is present and navigable, and its catalog binding proves the
complete underlying definition counts. It does not mean raw draft/staff content is copied.

## 7. Canonical native implementations and investor journey

The existing, feature-complete Nightingale iOS target at
`hummingbird/iosPatientApp` and its Android counterpart at
`hummingbird/androidPatientApp` are the only native implementation targets for this
cohort. No parallel app, target, bundle/application identifier, signing identity, or store
record may be created. In particular, this stream must not build or restore either
superseded `nightingale/*App` scaffold.

Both clients must use the existing patient token route and preserve normal email
authentication while allowing only exact, normalized `demo1` through `demo5` handles.
The bounded demo journey is:

1. welcome and `DEMO` environment identity;
2. login-handle and password fields with password-manager-compatible semantics;
3. generic progress/failure states and no account-existence disclosure;
4. protected storage of session material only, never password persistence;
5. Today landing page with persistent demo/urgent-help framing;
6. My Path overview, stage list, milestone detail, uncertainty, and synthetic DRG context;
7. Care Team roles and safe contact routes;
8. non-urgent message entry only if the governed demo handoff is explicitly implemented;
9. session inventory and logout/revocation; and
10. privacy cover plus local removal.

The credential must not be compiled into UI tests. Simulator and emulator tests use
synthetic test-only credentials, while live TestFlight/device verification receives the
real demo credential only at runtime. Screenshots, videos, accessibility hierarchies,
XCTest/Android test results, and logs must be scanned for the password, tokens, internal
IDs, and sensitive payloads before retention.

Required device evidence:

- iPhone simulator and Android API 35 emulator launch from the canonical projects;
- exact-alias acceptance and confusable/suffix/prefix rejection in both clients;
- canonical iOS and Android debug/unit tests, plus release-unit compilation;
- iPhone standard and accessibility text sizes;
- Android compact/standard phone viewport and scalable-font behavior;
- iOS light/dark, landscape, reduced motion, increased contrast, and privacy cover;
- Android light/dark, portrait/landscape, reduced-motion equivalent, and secure-surface
  behavior;
- five-account sequential sign-in/sign-out in each canonical app;
- stale/expired access token refresh;
- server-side session revocation while app is open;
- wrong password, unknown alias, disabled account, network loss, timeout, ambiguous
  response, and service-unavailable behavior; and
- cross-account deep-link/resource substitution denial.

## 8. Automated test matrix

### 8.1 Provisioning

- clean creation;
- exact replay;
- password rotation without duplicate rows;
- preview immutability;
- missing secret;
- secret supplied through forbidden command argument;
- wrong environment/host;
- missing table;
- missing/duplicate/changed catalog release;
- missing/duplicate/wrong pathway key;
- wrong DRG code/title or mapping;
- changed stage/milestone count;
- foreign-owned principal/link/grant/encounter/projection;
- one missing member in a partial cohort;
- duplicate alias;
- cross-account grant or identity link;
- existing session/token during unsafe convergence;
- transaction failure on member five proves rollback of members one through four;
- concurrent apply serialization; and
- suspend/re-apply convergence.

### 8.2 Authentication and authorization

- all five handles authenticate with the runtime secret;
- email authentication remains backward compatible;
- alias case, whitespace, Unicode confusable, suffix/prefix, and out-of-range variants
  deny generically;
- unknown alias and wrong password follow dummy-hash timing and identical response shape;
- inactive/locked/revoked/foreign metadata deny;
- duplicate metadata alias fails closed;
- staff token cannot enter patient realm;
- patient token cannot enter staff/mobile realm;
- each token lists only its own encounter;
- all 20 directed cross-account resource substitutions deny without existence leakage;
- refresh-family replay revokes the family;
- logout, remote revocation, idle expiry, and absolute expiry deny subsequent reads; and
- rate limits apply equally to email and alias credentials.

### 8.3 Content and pathway safety

- exactly six projection kinds per account;
- exact scenario-to-DRG mapping;
- demo notice in every projection;
- no raw catalog section text;
- no source identifier, staff note, diagnosis assertion, order, dose, risk score, or
  outcome promise;
- every stage/milestone has stable UUID, title, state, and changeability semantics;
- no unsupported vocabulary;
- full stage/milestone navigation;
- freshness and uncertainty states;
- correction/retraction/withhold handling;
- inactive canonical catalog remains unchanged; and
- patient projection guard and release-policy gates remain enforced.

### 8.4 Security and operations

- repository and history secret scan;
- command-output/log/audit redaction tests;
- SQL injection and JSON-path injection attempts through aliases;
- mass-assignment denial;
- advisory-lock/concurrency test;
- IDOR/property tests;
- dependency and static analysis;
- DAST against the bounded demo surface;
- database backup and rollback rehearsal;
- kill-switch and account-revocation rehearsal;
- production read-after-write cardinality verification; and
- exact-SHA CI plus immutable deploy artifact verification.

## 9. Production execution gates

Production apply is allowed only when all of these are true:

- implementation and rollback are reviewed;
- focused and full tests pass;
- no plaintext secret appears in the diff or repository history;
- the branch is reconciled with current `main`;
- exact-SHA CI is green;
- `./deploy.sh --check` passes from clean, synchronized protected `main`;
- any required migration has separate path-scoped authorization, preview, logical backup,
  execution, and verification;
- the runtime secret is supplied non-interactively without shell history or process-list
  exposure;
- the existing single inactive sample has an explicit preserve/reconcile disposition;
- post-apply verify passes all cardinality and isolation assertions; and
- the cohort remains labeled demo-only with no real-patient or clinical-release claim.

The canonical deployment path is `./deploy.sh`; ad hoc SSH deployment, direct production
`git pull`, and blanket migrations are forbidden. Provisioning is a distinct governed
post-deploy action and must retain its redacted receipt separately from deployment logs.

## 10. Completion definition

This cohort item may be checked only when:

- five accounts exist and authenticate as specified;
- five exact DRG bindings reconcile to the authoritative catalog;
- each account has a complete synthetic patient-safe journey;
- one-to-one identity/encounter isolation and all cross-account denial tests pass;
- both canonical native apps pass their simulator/emulator journeys and the iOS app
  passes its TestFlight journey;
- credential secrecy and one-way hashing are proven;
- preview, verify, suspend, and re-apply are proven;
- production state is verified after application;
- checklist, devlog, evidence, PR, and exact-SHA CI are current; and
- no claim of clinical approval, real-patient readiness, pilot authorization, or
  production Nightingale activation is made.

## 11. Current implementation checkpoint and live checklist

The cohort stream was replayed as one isolated commit onto current `main`; the superseded
#92 foundation history is not part of the branch. PR #118 merged through protected
`main` as `06e3d0b363f2ef553f857aa426f220ccff7fc8a4`, and the same implementation remains
present in the subsequently deployed `main` commit
`a38044ca9f0df564b7de08cf915bdcc189a4d860`. Current implemented elements are:

- exact code-owned `demo1` through `demo5` aliases and five DRG/catalog bindings;
- PostgreSQL-only preview, atomic apply, read-only verify, and reversible suspend;
- runtime-only hidden password input, one-way hashing, and session/token revocation on
  credential rotation;
- five principals, identities, encounters, grants, and six synthetic projections per
  account;
- exact owner/product/cohort predicates for alias authentication and disclosure-policy
  selection;
- patient-safe demo notices, synthetic content generation, and raw catalog-prose
  exclusion;
- exact, fail-closed in-place adoption of the existing reference patient as `demo1`,
  preserving its principal/encounter identity and Hummingbird-template lineage;
- the canonical iOS and Android sign-in forms admitting only the five exact demo aliases
  in addition to email-shaped identifiers; and
- backend, iOS, and Android tests for alias boundaries and token-route compatibility.

| Work section                                                                                   | Status   | Current evidence                                                                                                                                    |
| ---------------------------------------------------------------------------------------------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Code-owned five-member scenario and exact DRG manifest                                         | Complete | Five exact pathway keys, codebook titles, stage counts, and milestone counts are pinned in code and tests.                                          |
| Patient-safe projection generation and catalog-content separation                              | Complete | Six synthetic projections per account; persistent notice and raw catalog-prose exclusion are asserted.                                              |
| Exact alias authentication and account-isolation boundary                                      | Complete | Email compatibility plus exact aliases; all 20 directed cross-account substitutions return generic denial.                                          |
| Atomic PostgreSQL preview/apply/verify/suspend implementation                                  | Complete | Serializable/advisory-locked apply, read-only preview/verify, rotation, suspend, and re-apply pass within 210 Patient tests/3,580 assertions.       |
| Runtime-secret and command safety                                                              | Complete | Hidden prompt only, no password/secret/credential CLI option, exact distinct confirmations, one-way hashing, and redacted results.                  |
| Existing reference sample adopted as `demo1`                                                   | Complete | Primary identity and encounter are preserved; changed lifecycle, contact data, or pre-existing linkage fails before cohort writes.                  |
| Canonical iOS exact-alias implementation and simulator regression                              | Complete | 67 unit tests, nine UI journeys, Release build, artifact boundary, and transport check pass in `hummingbird/iosPatientApp`.                         |
| Canonical Android exact-alias implementation and JVM regression                                | Complete | Debug/release unit, lint, build, artifact-boundary, and transport checks pass in `hummingbird/androidPatientApp`.                                   |
| Canonical Android API 35 connected-emulator regression                                         | Complete | All 16 tests pass at 1080×2400/420 dpi; tab changes reset shared content scroll to keep revision and urgent context visible.                        |
| PR #111 reconciliation without duplicate salvaged artifacts                                    | Complete | Cohort is rebased directly on the protected-main #111 merge; all live/foundation config keys remain and only the default-off demo gate is added.    |
| Successor PR, exact-SHA CI, protected-main merge, and canonical deployment                     | Complete | PR #118 merged as `06e3d0b3`; correction PR #121 passed all 19 jobs, merged as `ce9202cc`, and that exact SHA is deployed.                          |
| Portable, fail-closed catalog release identity                                                 | Complete | Dataset key, three evidence hashes, every aggregate/governance control, and environment-local UUID propagation pass locally, in CI, and production. |
| Production preview, atomic apply to unit 85, five live logins, read-after-write, and kill test | Complete | Zero-write preview, five-account apply, 30 projection reads, 20 isolation denials, suspend, five denied logins, re-apply, and final cleanup passed. |

All production-apply gates are closed. The exact-SHA correction was merged and deployed;
the zero-write preview reconciled the expected release and an empty cohort; the atomic
apply, read-after-write checks, five-account live proof, directed isolation matrix,
suspension, disabled-login proof, re-apply, restored-login proof, and final session/token
cleanup all passed. The suspend command remains the immediate cohort kill switch.

### 11.1 Production catalog-identity reconciliation (2026-08-05)

The first deployed `preview` correctly failed with
`nightingale_demo_catalog_release_not_exact` before opening a cohort write transaction.
Read-only inspection then established that production holds the same governed dataset and
evidence as the implementation fixture but with an environment-generated release UUID:

- production release UUID: `019f8702-a824-7172-b1dc-ab3612d2f1e8`;
- dataset key: `drg-care-pathways-verification-package-v43.1-20260721`;
- source CSV SHA-256:
  `2e3ac28238cdb8d7e1002117de6ad824d71882dae54df77fe4abd214b268a6ae`;
- verification workbook SHA-256:
  `42cadf84dce297c5a839784148ebd2c5375320350394c0d143411008ed5bd171`;
- declared baseline SHA-256:
  `6819c1e111985da1fc62f38cdd85dd2a34b69308f4cf9be9f8941fbce62bf8fd`;
- grouper `43.1`, 250 pathways, 802 pathway/DRG associations, 770 unique DRGs,
  10,123 claims, 811 sources, and 324 changes;
- evidence partition 96 verified plus 154 with limitations;
- disposition partition 96 signoff queue plus 148 specialist review plus 6 redesign;
- volume control 32,967,000 and coverage control 99.000%; and
- inactive state, zero clinical signoffs, no activation timestamp, and no withdrawal
  timestamp.

The correction deliberately does not pin the production UUID. It requires exactly one
row matching the dataset key and all three evidence hashes, independently validates every
aggregate and governance control above, and then propagates the observed UUID into each
account's immutable binding digest and product-owned preferences. Any fingerprint,
control, state, binding, stage count, or milestone count mismatch still fails before the
first cohort write. This reconciliation does not approve or activate the catalog.

### 11.2 Production execution closure (2026-08-06)

Protected-main PR #121 passed all 19 required exact-head checks and merged as
`ce9202cc70245afd4c2df1c1ed6620709978753d`. Main's exact-SHA verdict passed, and the
canonical deploy preflight verified the clean synchronized source, CI verdict, remote
checkout, and operator prerequisites. The confirmed canonical deployment installed that
exact release, built production assets, refreshed caches, and restarted Apache, the queue
worker, and Arena. A contemporaneous WAN outage prevented the script's final public-DNS
HTTPS request from connecting. Read-only verification over the documented internal path
then proved the exact `.release-commit`, all three active services, the Zephyrus HTTP
vhost, production Vite assets over its TLS vhost, required security headers, sensitive-
path denials, TRACE rejection, the installed edge-security contract, and writable
Laravel storage. No alternate deployment script, direct production pull, environment
change, DNS change, SSH configuration change, or migration was used.

The production preview against explicit synthetic-demo unit 85 returned:

- release UUID `019f8702-a824-7172-b1dc-ab3612d2f1e8`, state `inactive`, and clinical
  signoff incomplete;
- exactly `demo1` through `demo5` ready for reconciliation;
- zero cohort principals, identity links, encounter grants, sessions, tokens, or
  projections; and
- the existing reference sample ready for in-place `demo1` adoption.

The confirmed apply used the non-echoing runtime prompt and transient provisioning gate.
It produced exactly five active synthetic principals, five identity links, five grants,
five operational encounters, and 30 released synthetic projections. `demo1` retained the
reference sample's identity and encounter lineage. The other four members converged to
their deterministic cohort-owned identities. Each account reconciled to its exact DRG,
five draft stages, and the expected milestone count. Credential material was neither
accepted as a command option nor emitted in output.

Live black-box verification through the production TLS vhost proved:

- five successful token exchanges and five one-encounter lists;
- all 30 owned projection reads, six kinds per account;
- the exact demo/non-clinical-use notice on all 30 projections;
- `Cache-Control: no-store` on all 55 successful and denied patient responses checked;
- all 20 directed cross-account pathway substitutions returning generic content-free
  `404 not_found` responses without the target encounter handle or alias; and
- matching append-only audit totals of 5 token issues, 5 encounter disclosures,
  30 projection disclosures, and 20 isolation denials.

The kill-switch rehearsal suspended all five owned principals, removed every active
session and token, and caused all five live login attempts to return the identical
generic credential denial. Re-apply restored the five accounts without duplicating a
principal, identity, encounter, grant, policy, projection, or binding. All five live
logins then succeeded. One final credential rotation revoked those verification sessions
and tokens. The production resting state is five active demo principals, five exact
one-to-one encounters, 30 projections, zero active sessions, and zero tokens. The
catalog remains inactive, unsigned, unchanged, and explicitly non-clinical.

No retained evidence contains the shared password, a password hash, bearer token,
refresh token, session secret, patient source identifier, or raw catalog clinical prose.
No code or build action targeted the superseded `nightingale/iosApp` or
`nightingale/androidApp`; the canonical clients remain `hummingbird/iosPatientApp` and
`hummingbird/androidPatientApp`.
