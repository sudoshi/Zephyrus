# Nightingale Patient Product — Direction, Migration, and Implementation Plan

**Status:** Active product-direction plan; no patient feature is activated or pilot-ready.

**Companion execution log:**
[DEVLOG-nightingale-patient-product-2026-07-26.md](../devlog/DEVLOG-nightingale-patient-product-2026-07-26.md)

**Supersedes for new patient-product work:** the patient-facing portions of
[the Hummingbird parity and patient-experience plan](../hummingbird/ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md).
It does **not** supersede Hummingbird Staff parity work, the existing patient safety
evidence, or any clinical/governance decision.

## 1. Decision and product boundary

Zephyrus will have two independent mobile products:

| Product         | Audience and purpose                                                                                                                                    | Native identities                                                  | Explicitly excluded                                                                                                                                 |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Hummingbird** | Hospital staff: operational awareness and accountable action during a shift                                                                             | iOS `net.acumenus.hummingbird`; Android `net.acumenus.hummingbird` | Patient enrollment, patient credentials, patient-facing pathway views, and patient messaging UI                                                     |
| **Nightingale** | Inpatients and permitted representatives: understand released care information, prepare questions, and communicate through approved care-team workflows | iOS `net.acumenus.nightingale`; Android `net.acumenus.nightingale` | Staff operational boards, staff identities/tokens, raw clinical source data, order entry, diagnosis/triage, and autonomous clinical recommendations |

`Hummingbird Patient` is therefore **not** a branded production product. Its current
native targets, patient BFF contract, safety controls, and tests are retained as an
auditable implementation reference. They must not be renamed in place, re-issued under a
new bundle ID, or activated by configuration. Nightingale is a fresh product stream with
its own release identity, support model, signing posture, app-store records, analytics and
crash boundaries, privacy review, and pilot authorization.

This decision strengthens the already-accepted patient-product architectural boundary:
separate patient artifacts are required, but now they also carry a distinct patient product
name, brand, ownership, and release train.

## 2. What changes now

### 2.1 Product naming

- **Hummingbird** means the staff mobile companion only. Staff routes remain under
  `/api/mobile/v1`; staff identifiers, notifications, widgets, and operational roles stay
  exclusive to Hummingbird.
- **Nightingale** means the patient mobile product only. The existing
  `/api/patient/v1` boundary remains a backend _compatibility boundary_ until a separately
  reviewed Nightingale contract/version migration is complete; it is not a license to
  ship the old Hummingbird Patient binary.
- User-facing copy, App Store metadata, support guides, notification copy, deep links,
  app groups, signing, keychain aliases, analytics/crash projects, screenshots, and
  accessibility labels must use the correct product name. No binary may display both
  brands as if they are alternate modes of one application.

### 2.2 Brand assets supplied for this direction

| Asset             | Product use                  | Source fingerprint                                                         | Processing rule                                                                                                          |
| ----------------- | ---------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `Hummingbird.png` | Hummingbird staff app icon   | SHA-256 `5ecc70c2a85d9d6471aabb76cbc49b42a976f6b66ba22c84af065a625fe6e8ad` | Create platform-specific, opaque icon derivatives; preserve the supplied source outside release binaries for provenance. |
| `Nightingale.png` | Nightingale patient app icon | SHA-256 `e97191b7d1eccc32c6a1aa95f0ba2329e1cfb4c1ac1c9b3d2d540872b3327c76` | Create platform-specific, opaque icon derivatives only in the new Nightingale targets.                                   |

Both supplied PNGs are 1254 × 1254 RGBA source assets. App-icon derivatives must be
rendered against a reviewed opaque background: iOS icons must not contain transparency,
and Android adaptive icons need a safe-zone foreground plus an opaque background and
monochrome fallback. This is branding implementation, not a clinical-content approval or
an assertion of intellectual-property clearance; ownership/license provenance must be
recorded before App Store or pilot distribution.

## 3. Current-state reconciliation

| Current item                                                              | Disposition                                               | Why                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `hummingbird/iosApp`, `hummingbird/androidApp`                            | Continue as Hummingbird Staff                             | Already use staff-only app IDs and staff mobile BFF. Their icon will be replaced with the supplied Hummingbird mark without changing identity, data scope, or release policy.                                                                                                        |
| `hummingbird/iosPatientApp`, `hummingbird/androidPatientApp`              | Freeze as a non-production reference                      | They contain useful patient projection, privacy-cover, secure-storage, messaging, accessibility, and emulator evidence, but their app names/IDs still bind them to Hummingbird. No new feature or production activation work lands there after the Nightingale scaffold is accepted. |
| `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml`               | Treat as a governed compatibility input                   | Preserve its route and test evidence. Do not silently rename, widen, or activate it. Build a Nightingale contract/ownership surface before its first product release.                                                                                                                |
| Patient identity, grants, projections, messaging kernel, release policies | Reuse only through explicit Nightingale adapters          | No patient data model or raw source is copied to a client. Existing patient controls are candidate implementation inputs, not proof of Nightingale clinical approval.                                                                                                                |
| Existing Hummingbird plan/checklist                                       | Keep as historic evidence; redirect new patient rows here | Never rewrite past evidence to imply Nightingale completion. Each completed Nightingale control receives its own dated evidence and checklist entry.                                                                                                                                 |

## 4. Nightingale product charter

Nightingale helps an inpatient answer four patient-centered questions without making
clinical decisions on the patient's behalf:

1. **What is happening now?** — a released, plain-language Today view with freshness,
   uncertainty, correction/retraction, and urgent-help boundaries.
2. **What may happen next?** — a clinician-approved and release-governed pathway,
   discharge preparation, education, and questions to ask; never a diagnosis, promise,
   order, or automated care plan.
3. **Who can help me?** — an approved Care Team view that exposes only released roles and
   allowed contact routes, never staff schedules, direct personal data, or internal notes.
4. **How can I communicate safely?** — a capability- and encounter-scoped conversation
   workflow with clear non-emergency guidance, accountable routing, handoff, escalation,
   retention, correction, and accurate delivery state.

The product is not a general portal, a replacement for the bedside call system, a staff
workstation, a Home Hospital app, or an AI clinician. Nightingale Eddy, if ever explored,
is a separate non-actioning explanation study and remains disabled unless its own safety
case is approved.

## 5. Non-negotiable architecture

### 5.1 Native application isolation

Create independent source roots and targets:

```text
nightingale/
  iosApp/                       # target, tests, assets, entitlements, project generator
  androidApp/                   # one Android application module and tests
  brand/                        # source/provenance only; not referenced at runtime
docs/nightingale/
  README.md                     # product/contract and evidence index
  api-contract/                 # Nightingale-owned contract and fixtures
  safety/                       # approved matrices and release evidence
```

The future iOS target uses `net.acumenus.nightingale`; Android uses
`net.acumenus.nightingale`. Each has its own signing IDs, keychain service or encrypted
storage namespace, Universal/App Links, notification channel/topic, deep-link host/path
allowlist, analytics/crash project, privacy manifest, release artifact, and support
telemetry. Neither source tree may import Hummingbird Staff packages or make staff API
calls. Shared code is limited to reviewed, non-PHI build tooling, design-token generation,
and generated contract artifacts where the generator proves no staff operation enters the
output.

### 5.2 Backend and contract isolation

- Keep patient authorization fail-closed by principal, effective relationship, active
  encounter/grant, scope, facility/unit policy, feature gate, content release, and
  disclosure policy.
- Maintain patient APIs as a separate contract namespace and independently version its
  Nightingale ownership. A route alias, client migration, or endpoint rename requires an
  ADR, compatibility fixture suite, deprecation window, audit verification, and rollback.
- Read only released patient projections. Raw EHR/FHIR material, staff notes, operational
  scores, inferred risks, draft/review content, source identifiers, and unreleased pathway
  state must be impossible to serialize into Nightingale envelopes.
- Patient writes remain deterministic, explicit, idempotent where specified, auditable,
  and never queued into an opaque offline outbox. A network ambiguity must not claim that
  a message, acknowledgement, consent, or goal change succeeded.

### 5.3 Safety, privacy, and content isolation

- No production fixture, sample patient, synthetic patient principal, active grant, release,
  patient session, or feature flag is created by application development work.
- Clinical content requires distinct source provenance, clinical review, patient-language
  review, accessibility review, release authority, and rollback/retraction behavior.
- App-switcher/capture protection, protected storage, generic notifications, secure
  deletion, logging redaction, accessibility semantics, and device compromise handling are
  release gates rather than styling tasks.
- Patient communications must visibly state that urgent or emergency help is available
  through the bedside call button or staff, not through delayed app messaging.

## 6. Experience and visual direction

Nightingale should feel calm, clear, and unhurried without hiding important information.
The supplied nightingale mark belongs in the app icon, welcome identity, and restrained
non-clinical empty/transition contexts. It must not decorate urgent instructions, obscure
text, imply a clinical outcome, or substitute for status language.

1. Start with reading comfort: system-or-larger text, readable cards, explicit timing
   uncertainty, non-color status cues, images-off/high-contrast modes, and no essential
   information embedded in imagery.
2. Use scenic imagery only behind opaque or high-contrast content surfaces with deterministic
   crops, contrast checks, reduced-motion behavior, and an images-disabled fallback.
3. Use a simple three-domain navigation model only after patient-advisor validation:
   **Today**, **My Path**, and **Care Team**. Messaging is reached only from an allowed
   care-team or question flow, with a clear urgent-help escape route.
4. Treat every clinical label as reviewed content. Unknown vocabulary, incompatible
   state vocabularies, stale projections, and withdrawn content must withhold rather than
   invent a friendly explanation.

## 7. Migration workstreams and acceptance criteria

### Stream A — Product foundation and identity

- [x] Create the isolated `codex/nightingale-patient-product` development stream from
      `origin/main` at `446107ec`.
- [x] Inventory current staff and patient native targets, patient contracts, visual assets,
      and existing safety evidence without changing production state.
- [x] Create the `nightingale/iosApp` and `nightingale/androidApp` application roots with
      independent app IDs, build/test schemes, signing placeholders, and Debug/Release
      configuration. App groups and protected-storage namespaces are deferred until a
      Nightingale security architecture task introduces patient state.
- [x] Create `docs/nightingale/` and an index for future Nightingale-owned contract,
      fixture, safety, and release documentation, with explicit lineage to the former patient
      reference.
- [x] Establish an app-store and support naming checklist; reserve identifiers only through
      authorized Apple/Google/organization processes.

**Exit evidence:** both empty Nightingale targets build in Debug and Release, have no staff
source imports or endpoints, expose only the Nightingale name, and have a generated
software-bill-of-materials/dependency inventory. No patient feature becomes enabled.

### Stream B — Brand and icon implementation

- [x] Replace the Hummingbird iOS staff AppIcon and Android launcher/adaptive/splash/login
      derivatives with the supplied hummingbird artwork.
- [x] Create Nightingale iOS and Android icon sets from the supplied nightingale artwork,
      including an iOS opaque 1024 px master and Android density/adaptive variants.
- [x] Add asset source, checksum, dimensions, background, crop/safe-zone, generation
      command, and reviewer/rights fields to a provenance manifest.
- [x] Design and verify Android 13+ monochrome themed-icon variants for both products; do not
      treat a full-color adaptive foreground as an accessible monochrome design.
- [x] Render both icons on actual iOS Simulator and Android API 35 emulator launchers;
      inspect normal, dark, round/adaptive, and Android 12+ splash surfaces.
- [x] Verify repository-level notification, widget, extension, Live Activity, app-group,
      shortcut, and generated-asset ownership; confirm no repository-owned store-listing
      package exists; and prove source-predecessor Hummingbird in-place replacement with a
      synthetic private-data canary on iOS and Android emulators.
- [ ] Verify retained released-artifact upgrades, distribution signing, monotonic
      version/build values, external store records/screenshots, installed-widget persistence,
      and actual notification rendering after those release surfaces exist and are approved.

**Exit evidence:** platform tooling accepts every icon, all targets build Release, and
human visual review records correct brand, crop, contrast, and no unwanted transparency.

### Stream C — Patient-safe application migration

- [x] Classify and reimplement the first foundation subset only: lifecycle privacy cover,
      Android secure-window protection, accessibility-aware decorative imagery, and a
      released-content-only no-data state. All unlisted legacy sources remain held.
- [x] Document the pre-identity threat decisions for recovery, namespace isolation,
      device compromise, local/remote logout ambiguity, deletion, backup/transfer, and
      volatile input before introducing protected storage.
- [x] Classify and reimplement only the second foundation subset: a dormant, token-agnostic
      iOS Keychain primitive, an Android Keystore AES-GCM primitive, explicit fail-closed
      deletion semantics, backup/transfer exclusions, and lifecycle-cleared volatile inputs.
      Tests use and remove synthetic canaries; production code has no caller.
- [x] Establish Nightingale contract ownership without creating an API: inventory all 23
      legacy paths/25 operations, trace the layered authorization controls, record each
      operation's held disposition, and add a machine-verified `0.0.0-governance` artifact
      with zero paths, no usable server/security scheme/client permission, and every
      activation state false.
- [x] Classify the legacy patient-state vocabulary boundary without porting it. Reconcile
      backend, contract, iOS, and Android coverage; record the 12-domain/49-pair backend
      registry, native coverage gaps, category schema-placement conflict, Android Today
      omissions, unknown-value differences, and the rejected absent-version compatibility
      rule. All codes and labels remain held.
- [x] Define the first read-only encounter-access candidate without adding an operation:
      remove legacy grant internals, restrict the held candidate to zero/one self inpatient
      context, define a Nightingale-only opaque handle, document identity/source/audit/race
      requirements, and mechanically verify 42 synthetic outcome cases plus negative
      verifier mutations. The OpenAPI contract still has zero paths and both apps remain
      offline.
- [x] Adopt and mechanically enforce the route/compatibility foundation: reserve
      `/api/nightingale/v1` and the held `/inpatient-contexts` operation name, reject every
      alias/proxy/redirect/fallback to legacy patient or staff APIs, keep route registration
      prohibited, and add request-scoped default-deny identity/current-inpatient ports plus a
      fail-closed precondition truth table. The foundation has no route/controller/provider
      binding, database query, OpenAPI operation, or native client.
- [x] Complete the non-runnable identity/session/recovery and current-inpatient-source
      candidate designs without choosing a provider or adapter: reconcile exact legacy source
      hashes, define self-only and fail-closed state semantics, prohibit guessed freshness
      thresholds, and mechanically pin 64 identity plus 42 source cases with nine adversarial
      verifier mutations. Every activation, provider, credential, route, query, client,
      representative, patient-access, and production field remains disabled, null, or held.
- [x] Complete the bounded source-by-source identity-input, enrollment/recovery, first-read,
      and error/non-disclosure slice: classify and SHA-256 pin 65 exact contract, backend,
      database, iOS, Android, and test files; document the missing recovery workflow,
      unsafe first-record selection, cross-platform absence/error divergence, random UUID
      substitution, and server-message passthrough; and add a negative verifier that keeps
      all runtime, route, provider, credential-migration, production, and patient-creation
      permissions false.
- [ ] Classify each existing Hummingbird Patient source file as reusable safety primitive,
      reusable product behavior, test/fixture-only, or rejected legacy behavior. Foundation,
      protected-state, contract, vocabulary, first-read candidate, identity/source candidate,
      and the 65-file identity-input/enrollment/first-read/error slice are complete; remaining
      journey, communication, notification, preference, presentation, synthetic/debug, and
      release sources remain held.
- [ ] Port primitives into Nightingale by reviewed commits, not a blind directory copy:
      patient API boundary, protected storage, lifecycle/screen-capture privacy cover,
      accessibility presentation preferences, patient-safe vocabulary, and volatile drafts.
      Protected storage, lifecycle/screen-capture protection, the foundation accessibility
      presentation subset, volatile-input foundations, and an empty contract governance
      boundary are complete. Patient API runtime behavior and all vocabulary code/copy
      remain held.
- [ ] Reissue all product strings, accessibility IDs, test hooks, storage keys, telemetry
      event names, and diagnostics under the Nightingale namespace.
      Current foundation storage keys, test hooks, and diagnostics are Nightingale-only;
      no telemetry namespace exists yet.
- [x] Require a compile-time scan proving Nightingale has no `hummingbird.patient` package,
      bundle, endpoint, storage, or user-facing string except explicit migration provenance.
- [ ] Preserve the reference app untouched until the Nightingale migration evidence is
      independently approved; then retire it through a separately authorized change.

**Exit evidence:** Nightingale Debug/Release binaries contain no staff capability, endpoint,
or legacy patient namespace; iOS and Android migration suites pass equivalent safety tests.

### Stream D — Patient journeys and content controls

- [ ] Implement and validate released Today, My Path, Care Team, education/teach-back,
      discharge preparation, and capability-scoped communication only from approved projection
      contracts.
- [ ] Add reference scenarios for admission, transfer, procedure, delayed test/result,
      pre-rounds question, handoff, changed discharge estimate, discharge mid-thread, identity
      correction, representative limits, language/interpreter, accommodations, sensitive-data
      denial, source outage/staleness, and content retraction.
- [ ] Prove generic non-disclosure for unknown, revoked, expired, cross-principal,
      wrong-encounter, and omitted-resource requests.
- [ ] Keep clinical approval, content release, feature activation, pilot enrollment, and
      source-connector deployment separate and default-off.

**Exit evidence:** every displayed field has an approved source/release/provenance/freshness/
uncertainty/correction/translation/offline rule, and every mutation has deterministic
authorization, idempotency, audit, and patient-visible outcome evidence.

### Stream E — Accessibility, privacy, and human validation

- [x] Reissue and rerun the foundation subset of automated privacy controls under Nightingale
      identities: lifecycle cover, Android `FLAG_SECURE`, no-network boundary, large-text image
      attenuation, iOS high-contrast image withholding, an Android high-contrast policy seam,
      and patient-safe accessibility semantics.
- [ ] Carry forward the existing automated privacy controls only as candidate evidence;
      rerun them under Nightingale app IDs and assets.
- [ ] Meet WCAG 2.2 AA plus iOS/Android guidance for screen-reader navigation, focus order,
      headings, labels/actions, live-region restraint, target size, system text, high contrast,
      motion, landscape where supported, language expansion, captions/transcripts, and images
      disabled.
- [ ] Complete independent patient-advisor, accessibility, privacy/security, clinical
      safety, language/interpreter, legal/HIM, nursing, medical-staff, pharmacy, and support
      review. Automated checks never substitute for these approvals.
- [ ] Run threat-model, hazard-log, red-team, tabletop, penetration, mobile security,
      dependency/SBOM, secrets, and release-readiness reviews.

**Exit evidence:** named reviewers sign the current matrices; resolved risks and explicit
residual risks are versioned; pilot criteria remain independently ratified.

### Stream F — Controlled pilot and scale

- [ ] Define facility/unit/cohort/language/exclusion/support-hour configuration with a
      default-off, audited, expiry-bound manifest.
- [ ] Complete identity proofing, recovery, representatives, consent, sensitive-service,
      device, push, retention, incident, kill-switch, downtime, and support procedures.
- [ ] Run production-like integration, load, failover, EHR outage, push outage, recovery,
      monitoring, and rollback exercises before any patient activation.
- [ ] Authorize one controlled pilot through named governance owners; deploy using the
      protected Zephyrus release workflow only after the approved Nightingale artifact is
      merged and exact-SHA CI passes.

**Exit evidence:** a signed go/no-go record, verified rollback, production deployment
manifest, and clinical/privacy/security/accessibility approvals. This is intentionally not
part of application development work.

## 8. Verification matrix

| Layer            | Required evidence before a Nightingale milestone is checked                                                                                                                     |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Laravel/contract | route inventory, contract validation, authorization/IDOR/non-disclosure tests, fixtures, database migration review, and no activation state change                              |
| iOS              | generated project drift check, unit tests, iPhone Simulator UI/accessibility journeys, Debug and Release builds, binary namespace/debug-hook scan, icon visual review           |
| Android          | Gradle boundary check, unit tests, API 35 emulator instrumentation/accessibility journeys, Debug and Release builds, APK namespace/debug-hook scan, launcher/splash icon review |
| Product safety   | checklist entry, source/evidence links, state of clinical/content/privacy/identity approvals, residual-risk statement, and rollback relevance                                   |
| Release          | clean protected `main`, approved review, exact-SHA green CI, `./deploy.sh --check`, then `./deploy.sh`; migrations are separate path-scoped, backed-up approvals                |

## 9. Immediate implementation sequence

1. [x] Define and mechanically pin the route/compatibility ADR, independent product
       namespace, held candidate path, and default-deny identity/source prerequisite ports. The
       contract remains at zero paths and neither route registration nor native networking is
       enabled.
2. [x] Complete held, non-runnable identity/session/recovery and authoritative
       current-inpatient-source state designs and exhaustive synthetic fixtures. The result
       defines 106 cases and negative enforcement but deliberately selects no provider,
       credential, representative authority, adapter, query, or freshness threshold.
3. [ ] Obtain named independent approvals for identity, proofing, enrollment, session,
       recovery, representatives, current-inpatient source, cohort, lifecycle, freshness,
       linkage, audit, support, and rollback. Only then implement default-off non-production
       adapters behind the existing ports; do not query a patient source before approval.
4. [x] Complete the repository-level cross-surface brand audit for notification, widget,
       extension, Live Activity, app group, shortcut, store-package absence, and
       source-predecessor installed upgrade. The audit passes on clean iOS and Android
       emulators while explicitly withholding released-artifact, distribution-signing,
       store-console, installed-widget, and actual-notification-rendering claims.
5. [x] Complete source-by-source classification for identity input, enrollment/recovery,
       first-read projection models, and error/non-disclosure handling. The 65-file,
       checksum-pinned ledger and negative verifier treat the legacy contract, tests,
       candidate fixtures, and vocabulary as evidence, not approved implementation; no
       runtime code, route, provider, production query, or patient was added.
6. [x] Update this checklist and its devlog for the verified 65-file classification slice.
       Clinical, privacy, accessibility, identity, source, pilot, and deployment completion
       remain explicitly open.

## 10. Explicit holds

- No production database access for patient creation, fixture insertion, identity enrollment,
  data correction, or feature activation belongs to this stream.
- No credential pasted into chat, shell history, source, documentation, fixture, or test is a
  permitted secret source.
- No Nightingale patient is created, no patient feature is enabled, no migration is run, and
  no production release occurs from this plan alone.
- No staff Hummingbird code is copied into Nightingale, and no patient code is made a staff
  Hummingbird runtime mode.
