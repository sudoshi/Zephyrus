# Nightingale communication and notification source classification

**Date:** 2026-07-26
**Status:** complete evidence classification for this bounded slice; not approved for
implementation, runtime adoption, patient disclosure, mutation, notification delivery,
production data use, or release
**Reviewed source commit:** `b6ea087747d7ea88c8a076f06f4c91a2636ea029`
**Last source revalidation:** 2026-07-27
**Machine-readable ledger:**
[`migration/candidates/v0/communication-notification-source-classification.json`](./migration/candidates/v0/communication-notification-source-classification.json)
**Verifier:**
[`scripts/ci/verify-nightingale-communication-notification-classification.mjs`](../../scripts/ci/verify-nightingale-communication-notification-classification.mjs)

## 1. Executive conclusion

The Hummingbird Patient communication foundation contains several safety properties worth
reimplementing, but it is not functionally safe or semantically complete enough to become
Nightingale by renaming, routing, copying, or enabling it.

The 130-file review establishes five material conclusions:

1. **The server-side accountable-message core is the strongest part of the legacy
   implementation.** It revalidates the current patient relationship, uses immutable
   encrypted message bodies, content-free routing facts, append-only receipts and events,
   optimistic concurrency, transaction locks, replay digests, staff responsibility pools,
   effective responder membership, and fresh consumer heartbeats.
2. **Patient notification delivery does not exist end to end.** The backend can encrypt,
   register, rotate, rebind, and revoke an APNs/FCM provider token, but registration is not
   delivery. There is no patient provider worker, payload policy, delivery lifecycle,
   patient-native registration, iOS remote-notification capability, or Android patient
   notification service/permission flow.
3. **A human retry after an ambiguous network result is not safely idempotent in either
   patient client.** The backend can replay an operation only when the same operation and
   client-message identifiers are reused. iOS and Android generate fresh identifiers for a
   new user retry, so an accepted request whose response was lost can be duplicated.
4. **Patient-visible delivery wording exceeds the evidence recorded by the server.** The
   first receipt is `server_accepted` with visible state `sent`, followed by an asynchronous
   `staff_inbox` outbox record. That proves durable server acceptance, not staff projection,
   individual receipt, or care-team review. Both native clients use wording that implies
   delivery to the care team or responsibility pool.
5. **The cross-platform state contract has one decode-breaking iOS gap and incomplete
   Android rendering.** Escalation writes patient-visible delivery state `escalated`, while
   the contract and iOS delivery enum omit it. Android accepts strings but fails to map all
   valid ownership and delivery states to precise patient language.

This classification does not authorize a Nightingale route, provider, payload, message,
topic, urgent-help string, staff workflow, poller, queue, migration, source query, patient,
feature flag, or deployment. Nightingale remains a separate, zero-operation,
network-disabled product foundation.

## 2. Scope and inventory

### 2.1 Required domains

Every source is classified against one or more of these six domains:

| Domain                               | Classified sources | Question answered                                                                                     |
| ------------------------------------ | -----------------: | ----------------------------------------------------------------------------------------------------- |
| Patient communication contract       |                 77 | What paths, schemas, state values, copy, permissions, and response claims exist?                      |
| Patient mutation and delivery        |                103 | How are create, reply, amend, close, replay, receipt, and delivery facts governed?                    |
| Staff handoff and routing            |                 89 | How does accepted patient input become accountable staff work, and who can act on it?                 |
| Notification registration/delivery   |                 69 | What exists for device registration, provider delivery, payloads, lifecycle, consent, and revocation? |
| Native patient experience            |                 48 | How do iOS and Android compose, retry, refresh, render states, explain urgency, and fail offline?     |
| Error, offline, and urgency behavior |                130 | What can be inferred, overstated, queued, lost, duplicated, or exposed when dependencies fail?        |

### 2.2 Reviewed surfaces

| Surface                      | Sources |
| ---------------------------- | ------: |
| Legacy backend               |      65 |
| Legacy database              |       5 |
| Legacy contract/reference    |       3 |
| Legacy backend tests         |       9 |
| Legacy patient iOS           |       6 |
| Legacy patient iOS tests     |       6 |
| Legacy patient Android       |       8 |
| Legacy patient Android tests |       5 |
| Legacy staff iOS             |       8 |
| Legacy staff Android         |       7 |
| Legacy staff web             |       8 |
| **Total**                    | **130** |

The exact path inventory has SHA-256 digest
`ca199936c1d6dc58fb9ba21accbd7376a1d72512f6b1adc42d8f9719672306a0`.
Every file also has its own SHA-256 digest in the machine-readable ledger. Removal,
replacement, duplication, or content drift fails CI.

### 2.3 Base-refresh revalidation

The feature stream was synchronized with `origin/main` at
`b6ea087747d7ea88c8a076f06f4c91a2636ea029` after the original classification. All 130
classified paths were mechanically compared with that exact commit and match it. Exactly
one source digest changed: `routes/api.php`, from
`a1443123cbee888a55f988aa1ca713fe31eac39fa6cf05a0306eef0def3aad35` to
`052d9afd466b2646385986297e9ce3ec6a886d299b35c76067f5a0f21d7fa082`.

The twelve added lines register three Zephyrus staff/operations reads: scoped Arena
per-case conformance, an aggregate patient-flow epoch, and a scoped patient-flow journey.
They do not add, alias, enable, or modify a Nightingale route, patient communication
operation, notification provider, patient-native transport, or communication delivery
state. The broad `routes/api.php` evidence source therefore remains classified under
`routes_rejected`; no implementation permission, finding, disposition, or activation gate
changes. This is a source revalidation of the same v1 classification, not a new functional
approval.

### 2.4 Dispositions

| Disposition                | Sources | Meaning                                                                                                     |
| -------------------------- | ------: | ----------------------------------------------------------------------------------------------------------- |
| Evidence only              |      55 | Retain behavior and tests as compatibility/hazard evidence; they cannot authorize Nightingale behavior.     |
| Reimplement principle only |      27 | A bounded safety property is valuable, but no code, identifier, schema, copy, state, or policy is reusable. |
| Held                       |      42 | Product, clinical, privacy, security, content, accessibility, notification, or operations decisions remain. |
| Reject                     |       6 | Legacy routes and configuration ownership are incompatible with the independent Nightingale boundary.       |

There is no `approved`, `migrate`, `copy`, `enable`, or `production-ready` disposition.

## 3. Review method and evidence standard

The review traced each patient mutation in both directions:

1. patient-visible composer and urgent-help presentation;
2. native request construction and retry behavior;
3. API contract, route, feature gates, scopes, policy, and request validation;
4. current encounter/grant and thread authorization;
5. transaction, lock, replay, encryption, receipt, routing event, and audit;
6. content-free staff inbox outbox;
7. handoff consumer, responsibility pool, responder eligibility, and heartbeat;
8. staff web/iOS/Android read and mutation surfaces;
9. acknowledgement, response, reassignment, reroute, escalation, closure, and
   patient-visible serialization;
10. failure, stale state, outage, offline, notification, and post-discharge behavior.

Claims are classified using this evidence hierarchy:

| Evidence level      | What it can prove                                                                                    |
| ------------------- | ---------------------------------------------------------------------------------------------------- |
| Schema/constraint   | Allowed persisted values, immutability, uniqueness, relationships, and structural invariants         |
| Service/transaction | Runtime checks, lock order, replay behavior, encryption use, emitted events, and state transitions   |
| Contract/route      | Publicly described request/response vocabulary and declared authorization boundary                   |
| Native/web client   | Actual request identity, refresh, wording, state rendering, and local failure behavior               |
| Test                | A checked example or hazard regression; not production readiness, clinical approval, or completeness |
| Strategy document   | Intent only; not an implemented provider, payload, permission, queue, or lifecycle                   |

No production database, runtime secret, patient record, provider console, or deployed
environment was required or accessed for this classification.

## 4. End-to-end behavior found

### 4.1 Patient message submission

The legacy mutation service:

- derives an operation digest from the authenticated principal, operation name, and
  idempotency key;
- derives a payload digest from the client message UUID, message-body HMAC, thread,
  optimistic version, and urgent-guidance version;
- revalidates ownership before the transaction and again under a row lock;
- obtains advisory locks for operation and client-message identities;
- detects replay by either operation digest or client-message UUID and rejects
  cross-payload reuse;
- requires an approved mutation policy, matching urgent-guidance version, current
  disclosable encounter, routable responsibility pool, open thread, and matching version;
- encrypts the body with contextual associated data;
- appends a `server_accepted` receipt with patient-visible state `sent`;
- appends a content-free routing event;
- appends a `staff_inbox` outbox fact;
- records disclosure/mutation audit after serialization succeeds.

These are strong candidate principles. They do not resolve the client retry gap or make
`server_accepted` equivalent to staff delivery.

### 4.2 Staff handoff

The database handoff consumer:

- selects content-free `staff_inbox` outbox records;
- projects accountable staff work without placing message content in the transport record;
- resolves the configured responsibility pool;
- requires an effective eligible responder and a fresh consumer heartbeat before patient
  compose can be considered routable;
- records append-only delivery attempts and staff actions;
- supports claim, reply, close, release, reassignment, reroute, lifecycle reconciliation,
  and response-window escalation.

Staff web, iOS, and Android surfaces poll while foregrounded, generally every 20 seconds.
This is evidence that the staff side anticipates changing responsibility state. It does not
provide a patient-side update mechanism.

### 4.3 Patient readback

Patient thread serialization returns the latest receipt’s raw
`patient_visible_state`. Consequently, the public contract and every patient client must
accept every state that the database and services can emit. The current implementation does
not meet that invariant for `escalated`.

## 5. Confirmed safety properties worth reimplementing

The following properties may inform a new Nightingale design after named approvals. Their
legacy implementations are not approved for reuse.

### 5.1 Authorization and non-disclosure

- Product, operation, token ability, principal relationship, encounter/grant, resource
  ownership, mutation policy, and staff readiness are separate gates.
- Thread UUID possession is not authorization.
- The encounter/grant is revalidated at mutation time and under lock.
- Ordinary ownership failures collapse to non-disclosing not-found behavior.
- Cross-principal notification-device access collapses to not found.
- Response protection and audit are centralized.

### 5.2 Content protection and audit

- Message bodies are application-encrypted and context-bound.
- Body HMACs support replay comparison without placing plaintext in routing metadata.
- Staff outbox, routing, delivery attempt, heartbeat, and audit facts are content free.
- Corrections and retractions append; the source message is never rewritten or deleted.
- Delivery receipts and routing events are append-only ledgers.
- Device provider tokens are encrypted and never returned by the API.

### 5.3 Concurrency and replay

- Operation and client-message identities are independently checked.
- Advisory locks serialize both replay identities.
- Payload digests bind identity to semantic content.
- Thread and staff work-item versions prevent blind overwrites.
- Transactional revalidation prevents an authorization check from becoming stale before
  persistence.

### 5.4 Responsibility and lifecycle

- Topics resolve to explicit responsibility pools.
- A pool is not routable merely because it exists; membership, capability, effective
  window, and consumer health are checked.
- Claim and responder actions are attributable.
- Escalation and reconciliation are explicit events rather than silent state changes.
- Patient-visible receipts are structurally separate from staff operational events.

## 6. Blocking gaps and incompatibilities

### 6.1 Patient push delivery is absent

The `notification_devices` feature registers an encrypted and revocable provider token.
The configuration itself states that this does not enable a provider, payload delivery, or
push. The reviewed source contains no patient APNs/FCM sender, payload builder, localization
release, collapse/deduplication policy, priority policy, expiry, delivery worker, retry
budget, dead-letter policy, or provider receipt reconciliation.

The patient native clients also do not complete the lifecycle:

| Requirement                                     | iOS patient | Android patient |        Server        |
| ----------------------------------------------- | :---------: | :-------------: | :------------------: |
| Permission rationale and request                |     No      |       No        |         N/A          |
| Obtain provider token                           |     No      |       No        |         N/A          |
| Register/rotate/revoke through patient API      |     No      |       No        |    Registry only     |
| Logout/account-switch revocation evidence       |     No      |       No        |    Endpoint only     |
| APNs/FCM provider integration                   |     N/A     |       N/A       |          No          |
| Approved generic payload policy                 |     No      |       No        |          No          |
| Deep-link authorization after notification      |     No      |       No        |          No          |
| Delivery attempt and provider receipt lifecycle |     No      |       No        | Schema concepts only |
| Actual-notification accessibility/privacy QA    |     No      |       No        |          No          |

Nightingale must not expose “App notification,” email, or SMS delivery preferences until
the corresponding governed channel actually exists. A preference is not consent, and a
registered provider token is not a delivered notification.

### 6.2 Patient automatic refresh is absent

The patient clients support manual refresh/retry. They do not poll foreground messaging and
they cannot receive patient push. Therefore an acknowledgement, response, reroute,
escalation, or closure can remain invisible until the patient manually refreshes.

Nightingale must choose and approve a freshness model before implementation:

- foreground polling with bounded cadence and backoff;
- server-driven push that carries no clinical content and only prompts an authorized
  refresh;
- an authorized streaming mechanism;
- or an explicit manual-only policy with patient-facing freshness language.

No option is selected by this record. “Copy the staff 20-second poller” is not an approved
decision because patient battery, network, accessibility, support, and privacy conditions
differ from the staff application.

### 6.3 Ambiguous retries can duplicate patient mutations

The backend replay contract is sound only when the client preserves operation identity.
The current native behavior is:

| Stage                                       | iOS patient                                                | Android patient                                                     | Consequence                                           |
| ------------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------------------- |
| First compose attempt                       | Generates new idempotency key and client-message UUID      | Generates new values inside coordinator call                        | Server can replay this exact request                  |
| Automatic token refresh inside same request | Closure/request object retains values                      | Request object retains values                                       | Same-call authentication replay is bounded            |
| Response lost after server commit           | Draft remains visible, operation identity is not persisted | Draft may remain in volatile UI, operation identity is not retained | Client cannot know whether commit occurred            |
| Patient taps retry                          | Generates new operation and message identities             | Generates new identities                                            | Server sees a new mutation and may create a duplicate |

An offline mutation queue is **not** approved as the remedy. The minimum future design must
define a volatile pending-operation record that binds:

- action and target;
- normalized payload digest;
- client message UUID;
- idempotency key;
- expected thread/work-item version;
- creation and expiry timestamps;
- outcome `not_started`, `in_flight`, `unknown`, `accepted`, `rejected`, or `superseded`;
- explicit rules for retry, refresh-first reconciliation, discard, logout, account switch,
  backgrounding, process death, and sensitive-memory clearing.

Whether that record may survive process death is a security and product decision. Until
approved, Nightingale has neither an offline queue nor durable pending-message storage.

### 6.4 “Sent to your care team” overstates delivery

On initial acceptance, the server writes:

1. immutable patient message;
2. receipt type `server_accepted`, visible state `sent`;
3. routing event `message_submitted`, state `awaiting_team`;
4. asynchronous outbox destination `staff_inbox`.

At that moment the server has not proved that:

- the handoff consumer projected the work;
- a staff client fetched it;
- an eligible responder claimed it;
- any named team member saw it;
- a response will occur within the displayed window.

The iOS success string “Your message was sent to your care team” and Android strings that
say it was sent to the responsible care-team pool exceed this evidence. A future
Nightingale vocabulary should distinguish at least:

| Evidence fact                    | Candidate patient meaning                | Prohibited inference                                |
| -------------------------------- | ---------------------------------------- | --------------------------------------------------- |
| Server transaction committed     | “Saved securely” or approved equivalent  | A team member received/read it                      |
| Staff inbox projection delivered | “Delivered to the care-team inbox”       | A responder claimed/read it                         |
| Responsibility assigned          | “Assigned to the responsible team”       | A specific person read it                           |
| Staff acknowledgement            | “Care team acknowledged it”              | A clinical response is complete                     |
| Staff patient-visible response   | “Care team responded”                    | Patient has read/understood response                |
| SLA escalation                   | Approved transparent escalation language | Emergency handling or guaranteed immediate response |

Patient-language, clinical operations, legal/privacy, accessibility, localization, and
support owners must approve the final words and evidence-to-label map.

### 6.5 Escalated delivery state can break iOS decoding

The database and escalation service allow and emit:

- thread ownership state `escalated`;
- routing event patient-visible state `escalated`;
- latest patient-message receipt type and visible state `escalated`.

The patient API serializer returns the latest receipt state without normalization. The
OpenAPI delivery-state enum and iOS `PatientMessageDeliveryState` omit `escalated`. A
strict iOS decode of an escalated thread can therefore fail the entire thread response.

This is not merely a display-label issue. Nightingale must enforce a generated or otherwise
mechanically reconciled state registry across:

- schema constraints;
- backend transition emitters;
- serializer;
- contract;
- fixtures;
- iOS decoding and labels;
- Android decoding and labels;
- accessibility text;
- analytics/telemetry;
- support documentation.

Unknown future states must follow an explicitly approved compatibility rule. Silently
mapping an unknown state to “sent” or failing the whole conversation are both unsafe
defaults.

### 6.6 Android state rendering is incomplete

The backend/contract ownership states are:
`awaiting_team`, `assigned`, `acknowledged`, `responded`, `rerouted`, `escalated`, and
`closed`.

Android checks `team_acknowledged`, which is not the contract value, and does not provide
precise patient labels for all emitted ownership values. Its delivery rendering also lacks
specific handling for valid `assigned`, `responded`, `escalated`, and `closed` states.
Permissive string decoding prevents a hard parse failure but produces ambiguous patient
language.

### 6.7 Urgent guidance is not a governed locale-bound release

The server correctly requires the client to echo an urgent-guidance version and rejects a
stale version. That is a strong anti-stale principle. The content itself is still a static
configuration value, and topic labels/descriptions are code-owned English strings. They are
not bound to:

- product release;
- facility/unit/cohort;
- locale and reading level;
- clinical/content approval;
- accessibility review;
- interpreter and communication-support workflow;
- effective period and rollback;
- checksum;
- support and downtime instructions.

iOS additionally shows fixed local urgent/offline wording. Android relies more heavily on
the server-provided urgent text and lacks the same always-visible no-offline-queue
explanation. The products therefore do not have proven safety-copy parity.

### 6.8 Error and absence states are under-specified

Patient messaging load generally interprets a 404-like result as disabled/unavailable.
Nightingale must decide which states may be distinguished without creating an oracle:

- operation disabled;
- access not granted;
- grant revoked;
- encounter no longer current;
- source unavailable;
- staff route not ready;
- policy/version unavailable;
- temporary network failure;
- server accepted but outcome unknown;
- stale thread version;
- message amended/closed by another actor;
- post-discharge retention boundary.

The response must remain non-disclosing, but collapsing every state into the same UI can
also cause unsafe retry or false reassurance. The contract needs an approved safe-error
registry plus exact client transitions and accessibility announcements.

### 6.9 Notification registration input is less strict than messaging input

Messaging request objects explicitly reject unknown JSON properties and control characters.
The notification-device registration request does not apply the same unknown-property
rejection. This does not prove an exploit, but it is an unnecessary contract asymmetry.
Nightingale should make exact JSON shape, size, canonicalization, platform/provider
allowlists, and unknown-field behavior explicit before any device endpoint exists.

### 6.10 Staff close reasons do not break patient decoding

This suspected hazard was tested against the service path and is explicitly recorded as
**not present**. The staff work item retains staff-specific reasons such as operational
reason codes, while the patient thread is normalized to `question_answered`. The patient
serializer therefore returns a value accepted by the patient contract and iOS enum.

This negative finding is pinned so a future review does not repeat or incorrectly report
the concern. It also establishes a useful rule: operational reason detail and
patient-visible reason vocabulary may differ, but their mapping must be explicit,
versioned, and tested.

## 7. Cross-platform semantic reconciliation

### 7.1 Ownership states

| Backend/contract state | Backend emits | iOS decodes | Android decodes |            Android precise label            | Nightingale status                    |
| ---------------------- | :-----------: | :---------: | :-------------: | :-----------------------------------------: | ------------------------------------- |
| `awaiting_team`        |      Yes      |     Yes     |       Yes       |                   Partial                   | Held; approve evidence-based language |
| `assigned`             |      Yes      |     Yes     |       Yes       |                     Yes                     | Held                                  |
| `acknowledged`         |      Yes      |     Yes     |       Yes       | No; checks non-contract `team_acknowledged` | Reject current mapping                |
| `responded`            |      Yes      |     Yes     |       Yes       |                   Generic                   | Held                                  |
| `rerouted`             |      Yes      |     Yes     |       Yes       |                   Generic                   | Held                                  |
| `escalated`            |      Yes      |     Yes     |       Yes       |                   Generic                   | High-risk held                        |
| `closed`               |      Yes      |     Yes     |       Yes       |                   Generic                   | Held                                  |

### 7.2 Delivery states

| Persisted/emitted state | Contract accepts | iOS accepts | Android precise label | Finding                                          |
| ----------------------- | :--------------: | :---------: | :-------------------: | ------------------------------------------------ |
| `sent`                  |       Yes        |     Yes     |          Yes          | Means server accepted; copy currently overstates |
| `delivered`             |       Yes        |     Yes     |          Yes          | Evidence definition needs approval               |
| `assigned`              |       Yes        |     Yes     |          No           | Android parity gap                               |
| `acknowledged`          |       Yes        |     Yes     |          Yes          | Evidence-to-language mapping held                |
| `responded`             |       Yes        |     Yes     |          No           | Android parity gap                               |
| `escalated`             |        No        |     No      |          No           | Contract/iOS decode-breaking gap                 |
| `closed`                |       Yes        |     Yes     |          No           | Android parity gap                               |

### 7.3 Refresh and delivery mechanisms

| Capability                          | Patient iOS | Patient Android |             Staff iOS             |      Staff Android       |        Staff web         |
| ----------------------------------- | :---------: | :-------------: | :-------------------------------: | :----------------------: | :----------------------: |
| Manual refresh                      |     Yes     |       Yes       |                Yes                |           Yes            |           Yes            |
| Foreground polling                  |     No      |       No        |           20 s evidence           |      20 s evidence       |      20 s evidence       |
| Patient push                        |     No      |       No        |                N/A                |           N/A            |           N/A            |
| Offline mutation queue              |     No      |       No        |     Not Nightingale evidence      | Not Nightingale evidence | Not Nightingale evidence |
| Retry identity survives human retry |     No      |       No        | Not assessed for patient contract |       Not assessed       |       Not assessed       |

## 8. Nightingale implementation gates

This section is an implementation plan, not approval to implement.

### Gate C1 — Product and clinical communication model

- [ ] Name the Nightingale communication product owner.
- [ ] Name clinical operations owners for every facility/unit/cohort.
- [ ] Define eligible topics and explicitly prohibited uses.
- [ ] Define responsibility pool, coverage hours, response window, escalation, downtime,
      and no-responder behavior per topic.
- [ ] Define emergency/urgent escape routes and prove they are continuously reachable.
- [ ] Define representative/minor/sensitive-service and post-discharge communication rules.
- [ ] Approve patient-visible evidence-to-state language.
- [ ] Approve correction, retraction, closure, retention, legal hold, and audit semantics.

### Gate C2 — Contract and state registry

- [ ] Create Nightingale-owned operations in a candidate contract; do not copy paths or
      operation IDs from Hummingbird.
- [ ] Define one version/checksum-bound registry for topics, ownership states, delivery
      states, close reasons, safe errors, and urgent content.
- [ ] Generate or mechanically reconcile backend, iOS, Android, fixtures, and documentation.
- [ ] Add exhaustive transition and unknown-state tests.
- [ ] Define response freshness and provenance without claiming source freshness from
      request time.
- [ ] Prove IDOR, grant revocation, encounter transfer, stale source, stale version, and
      non-disclosure cases.

### Gate C3 — Mutation identity and reconciliation

- [ ] Define operation identity creation, retention, expiry, and clearing.
- [ ] Preserve the same operation and client-message identities after an ambiguous result.
- [ ] Refresh/reconcile before creating a new identity.
- [ ] Prove process death, logout, account switch, backgrounding, auth refresh, network
      timeout, server 5xx, response truncation, and duplicate-tap cases.
- [ ] Do not create an offline queue unless separately approved.
- [ ] Provide patient-visible `unknown outcome` handling that does not encourage duplicate
      resubmission.

### Gate C4 — Accountable staff handoff

- [ ] Specify pool resolution from authoritative encounter/facility/unit context.
- [ ] Specify responder eligibility, capabilities, effective windows, and delegation.
- [ ] Define heartbeat, queue health, lag, retry, poison-message, and dead-letter policy.
- [ ] Prove compose fails closed before any patient text is accepted when the staff route is
      not ready.
- [ ] Prove accepted input becomes one accountable work item or is transparently reconciled.
- [ ] Prove reassignment, reroute, escalation, closure, discharge, transfer, and downtime.
- [ ] Keep message content out of queue, routing, metrics, logs, and notification payloads.

### Gate N1 — Notification channel decision

- [ ] Decide whether Nightingale needs push, email, SMS, none, or a phased subset.
- [ ] Separate presentation preferences from communication consent and channel enrollment.
- [ ] Approve provider projects/accounts, credentials, rotation, environment separation,
      and least privilege.
- [ ] Define installation/device identity without reusing Hummingbird identifiers.
- [ ] Define registration, rotation, rebind, logout, account switch, uninstall, expiry,
      provider invalidation, and deletion evidence.
- [ ] Define generic payload, localization, collapse, deduplication, TTL, priority, quiet
      hours, and sensitive-state suppression.
- [ ] Require notification taps to reauthenticate/re-authorize and fetch current data; never
      trust payload content as patient data.

### Gate N2 — Native notification implementation

- [ ] Add iOS capability/entitlement only after provider approval.
- [ ] Add Android runtime permission and service only after provider approval.
- [ ] Implement registration/revocation against Nightingale-owned endpoints.
- [ ] Test denied, provisional, restricted, disabled, token-rotation, and provider-failure
      states.
- [ ] Test lock-screen preview, accessibility, localization, account switch, app reinstall,
      backup/restore, and device transfer.
- [ ] Prove no clinical detail, patient identity, room, diagnosis, medication, test, or
      message text appears in payload, logs, analytics, or provider console.

### Gate C5 — Patient freshness and offline experience

- [ ] Select and document patient refresh mechanism and cadence.
- [ ] Define background/foreground transitions and exponential backoff.
- [ ] Expose last successful refresh without mislabeling it as clinical source freshness.
- [ ] Distinguish unsent, in flight, outcome unknown, securely accepted, staff-inbox
      delivered, assigned, acknowledged, responded, escalated, and closed.
- [ ] Keep unsent text volatile by default; define clearing for logout, account switch,
      backgrounding, recovery, and memory pressure.
- [ ] Complete equivalent iOS and Android accessibility announcements and recovery actions.

### Gate C6 — Verification and release

- [ ] Contract-schema validation and state-registry drift checks pass.
- [ ] Backend authorization, replay, encryption, routing, lifecycle, and negative tests pass.
- [ ] iOS unit, UI, accessibility, Debug, Release, notification, offline, and upgrade tests
      pass on supported simulators/devices.
- [ ] Android unit, instrumentation, accessibility, Debug, Release, notification, offline,
      and upgrade tests pass on supported emulators/devices.
- [ ] Production-like queue lag, worker failure, EHR/source outage, provider outage,
      rollback, and kill-switch exercises pass.
- [ ] Named clinical, privacy, security, content, accessibility, identity, operations,
      support, and release owners sign the exact artifact checksum.
- [ ] Only then add a default-off non-production implementation behind approved ports.
- [ ] Production activation remains a separate protected-main release and pilot decision.

## 9. CI enforcement

The verifier pins:

- exact 130-source inventory, source hashes, six domain counts, 11 surface counts, four
  disposition counts, and 17 decision records;
- the ten material positive and negative findings;
- every runtime, route, copy, provider, device, channel, payload, polling, offline,
  retry-regeneration, delivery-overstatement, production-data, production-query,
  production-replay, and patient-creation permission to `false`;
- the zero-operation Nightingale contract and dormant backend configuration;
- absence of credential-like literals in the ledger.

Its negative self-test proves rejection of:

- notification provider activation;
- patient foreground polling activation;
- offline queue activation;
- retry identity regeneration;
- server acceptance treated as care-team delivery;
- production replay;
- false resolution of the iOS escalation mismatch;
- false assertion of a staff-close decode failure;
- invalid hashes;
- source removal;
- duplicate inventory paths;
- an `approved` disposition.

## 10. Completion boundary

This bounded communication and notification source-classification section is complete when:

- the 130-file ledger validates against the reviewed source commit;
- the negative verifier passes;
- the Nightingale contract remains empty and default off;
- the master plan, migration index, contract matrix, documentation index, and devlog link
  this record;
- existing Nightingale iOS and Android emulator suites still pass without gaining network,
  messaging, notification, patient-data, or production capabilities.

Completion of this section does **not** mean communication or notifications are implemented.
It means the predecessor behavior, reusable principles, incompatibilities, and approval
gates are now explicit and mechanically protected.
