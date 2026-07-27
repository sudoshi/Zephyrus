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

- Application development does not create production fixtures, sample principals, active
  grants, releases, sessions, or feature flags. The sole exception is the separately and
  explicitly authorized 2026-07-27 production sample clone: one synthetic operational
  encounter and one pending/inactive, contactless/passwordless Nightingale principal,
  documented under `docs/evidence/nightingale/production-sample-patient-2026-07-27/`.
  It has no identity link, grant, challenge, session, content, route, client, or activation.
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
non-clinical empty/transition contexts. The seven images supplied under
`nightingale/backgrounds` are the product-directed background collection. The mark and
background collection must not decorate urgent instructions, obscure text, imply a
clinical outcome, identify a species to the patient, or substitute for status language.

1. Start with reading comfort: system-or-larger text, readable cards, explicit timing
   uncertainty, non-color status cues, images-off/high-contrast modes, and no essential
   information embedded in imagery.
2. Use the seven governed Nightingale backgrounds only behind opaque or governed
   high-contrast surfaces. Both platforms use the exact same metadata-stripped derivatives
   and select the same stable image for each local Gregorian day using
   `floorMod(epochDay, 7)`. There is no carousel, parallax, autonomous animation, or
   clinical/state-driven image selection.
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
- [x] Generate and mechanically verify the bounded foundation dependency inventory:
      seven direct Android Release runtime declarations, 83 resolved components, 457
      dependency edges, zero iOS third-party packages, and four Apple system-module
      imports, all bound to exact source hashes. This is not a standards-conformant SBOM,
      vulnerability/provenance assessment, license review, or supply-chain approval.
- [x] Add dedicated Nightingale Android and iOS foundation CI jobs that verify source and
      governance boundaries, dependency drift, Debug/Release tests and builds, native
      simulator/emulator journeys, exact Release product identity, absence of networking
      and Release test hooks, and the exact governed background set. Exact-SHA CI evidence
      remains required for each published change.

**Exit evidence:** both empty Nightingale targets build in Debug and Release, have no staff
source imports or endpoints, expose only the Nightingale name, and have a generated
dependency inventory. The bounded Stream A engineering exit evidence is present; external
identifier reservation, signing, distribution, and every live-feature gate remain separate
and open. No patient feature becomes enabled.

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
- [x] Admit the seven user-supplied files under `nightingale/backgrounds` into a
      Nightingale-owned decorative-background catalog: retain source filename, SHA-256,
      dimensions, and byte-count lineage; commit exactly seven no-upscale,
      metadata-stripped progressive JPEG derivatives; and mechanically reject missing,
      additional, malformed, metadata-bearing, resized, or hash-drifted files.
- [x] Package the exact same seven derivatives in both native products and implement one
      stable cross-platform selection per local Gregorian day. Keep the photos outside the
      accessibility tree and input path; preserve text and actions when imagery is hidden;
      suppress imagery for the patient preference and stronger supported system contrast/
      transparency settings; place all readable content on opaque or governed scrimmed
      surfaces; and prohibit species, care-state, risk, urgency, or outcome semantics.
- [x] Verify the background foundation with native catalog/resource tests, iOS Simulator
      visual inspection, Android API 35 semantic inspection, Release artifact hash/set
      checks, Android `FLAG_SECURE` capture proof, and negative asset-verifier mutations.
- [ ] Record a durable source-archive location and applicable license/attribution evidence
      for all seven supplied backgrounds before external, pilot, App Store, Play Store,
      production, or marketing distribution.
- [ ] Obtain named patient-advisor and accessibility review of the image set, crops,
      legibility, comfort, cultural interpretation, and images-hidden behavior on supported
      physical devices, text sizes, appearances, languages, and orientations.
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
- [x] Complete the bounded communication and notification source-classification slice:
      classify and SHA-256 pin 130 exact contract, backend, database, patient-native,
      staff-native/web, and test files; trace patient acceptance through staff routing and
      readback; document absent patient push and automatic refresh, ambiguous-retry
      duplication, delivery-wording overstatement, iOS escalation decode failure, Android
      state-label gaps, and ungoverned locale/copy; and add negative enforcement for zero
      runtime, route, provider, channel, payload, polling, offline-queue, production, and
      patient-creation permissions.
- [x] Classify each existing Hummingbird Patient source file as reusable safety primitive,
      reusable product behavior, test/fixture-only, or rejected legacy behavior. The
      machine-checked universe contains 256 tracked product sources: 122 unique sources
      covered by the two prior ledgers plus a final 134-source journey, preference,
      presentation, synthetic/debug, persistence, and release ledger. The union covers all
      256 sources with exact path-list and byte checksums. Classification completeness does
      not approve any source for migration or runtime adoption.
- [x] Correct the mainline iOS release-path collision without repurposing the legacy
      reference app: restore its exact Hummingbird Patient bundle/display identities, move
      the Nightingale export policy to `nightingale/iosApp`, map the Apple registry only to
      the independent Nightingale project/scheme, reject future collisions in CI, and
      verify exported IPA identity/build metadata with one positive and four negative
      mutations. Signing, upload, store acceptance, tester distribution, and pilot
      authorization remain open.
- [ ] Port primitives into Nightingale by reviewed commits, not a blind directory copy:
      patient API boundary, protected storage, lifecycle/screen-capture privacy cover,
      accessibility presentation preferences, patient-safe vocabulary, and volatile drafts.
      Protected storage, lifecycle/screen-capture protection, the foundation accessibility
      presentation subset, device-local reduced-motion and decorative-imagery preferences,
      volatile-input foundations, and an empty contract governance boundary are complete.
      Patient API runtime behavior and all vocabulary code/copy remain held.
- [x] Reissue all product strings, accessibility IDs, test hooks, storage keys, telemetry
      event names, and diagnostics under the Nightingale namespace.
      The deterministic namespace manifest now binds 12 exact sources and inventories ten
      shared accessibility IDs, four iOS Debug hooks, ten fully qualified persistent
      identifiers, zero telemetry events, and zero diagnostic channels. Its independent
      verifier reproduces the manifest byte-for-byte, rejects eight mutations, rejects
      legacy patient tokens and unregistered logging/analytics primitives, and is enforced
      by contract and native CI. This closes only the identifiers implemented by the
      current offline foundation; every future string, event, channel, or identifier must
      extend the governed inventory.
- [x] Require a compile-time scan proving Nightingale has no `hummingbird.patient` package,
      bundle, endpoint, storage, or user-facing string except explicit migration provenance.
- [ ] Preserve the reference app untouched until the Nightingale migration evidence is
      independently approved; then retire it through a separately authorized change.

**Exit evidence:** Nightingale Debug/Release binaries contain no staff capability, endpoint,
or legacy patient namespace; iOS and Android migration suites pass equivalent safety tests.

### Stream D — Patient journeys and content controls

- [x] Define the first non-runnable Today projection candidate without adding an
      operation: use the held opaque inpatient-context handle; require a governed context
      on every patient-visible value; distinguish released, released-empty, and
      not-available sections; define correction/retraction and generic non-disclosure
      behavior; and mechanically verify 68 synthetic outcomes plus 24 adversarial
      mutations. The executable contract still has zero paths and every activation remains
      false.
- [ ] Implement and validate released Today, My Path, Care Team, education/teach-back,
      discharge preparation, and capability-scoped communication only from approved projection
      contracts.
- [x] Add a held, non-runnable reference catalog for admission, transfer, procedure,
      delayed test/result, pre-rounds question, handoff, changed discharge estimate,
      discharge mid-thread, identity correction, representative limits, language/interpreter,
      accommodations, sensitive-data denial, source outage/staleness, and content
      retraction/correction. The machine-verified catalog contains all 15 required families,
      27 synthetic cases, 12 exact evidence-source checksums, and 23 adversarial mutations;
      it adds no operation and keeps every runtime, representative, clinical release,
      communication, notification, database, production, and deployment permission false.
- [x] Prove generic non-disclosure for unknown, revoked, expired, cross-principal,
      wrong-encounter, and omitted-resource requests. The route-free Nightingale domain
      gate returns one exact identifier-free 404/code/cache tuple for all 39
      non-disclosable combinations in its exhaustive 40-row truth table; the sole positive
      row only continues to later governed projection evaluation. There is still no route,
      query, patient content, or authorization.
- [x] Keep clinical approval, content release, feature activation, pilot enrollment, and
      source-connector deployment separate and default-off. Five distinct state types,
      code-owned negative configuration, five false executable-contract facts, runtime
      non-registration scans, and an exhaustive 32-row truth table now enforce the
      separation. Thirty-one incomplete combinations hold; the all-positive combination
      only continues to later operation-specific release evaluation and does not authorize
      identity, disclosure, production, or pilot use.

**Exit evidence:** every displayed field has an approved source/release/provenance/freshness/
uncertainty/correction/translation/offline rule, and every mutation has deterministic
authorization, idempotency, audit, and patient-visible outcome evidence.

### Stream E — Accessibility, privacy, and human validation

- [x] Reissue and rerun the foundation subset of automated privacy controls under Nightingale
      identities: lifecycle cover, Android `FLAG_SECURE`, no-network boundary, large-text image
      attenuation, iOS high-contrast image withholding, an Android high-contrast policy seam,
      and patient-safe accessibility semantics.
- [x] Implement and rerun the bounded display-comfort subset under Nightingale identities:
      separate device-local preferences from future account preferences, apply the stronger
      system/patient reduced-motion choice to every current motion site, allow decorative
      imagery to be disabled without hiding essential content, persist both choices across
      relaunch, and bind native tests to exact local Debug/Release artifacts.
- [x] Complete the bounded foundation accessibility-layout matrix: correct the failing iOS
      dark-background accent, add explicit Android light/dark schemes, mechanically prove
      every current semantic text pair at 4.5:1 or greater, preserve ordered landmarks,
      make both controls scroll-reachable at iOS accessibility XXXL and Android font scale
      `2.0` in landscape, and prove 44-point iOS plus 48 dp Android targets. This closes
      only the current offline shell; future screens, manual assistive-technology review,
      language/RTL coverage, full WCAG conformance, and named approvals remain open.
- [x] Complete the bounded offline-shell accessibility/language-readiness subset: move all
      15 nonclinical strings into exact, reconciled native catalogs; expose exactly three
      ordered headings; use two restrained status-announcement paths; exercise iOS rendered
      double length plus Debug-only RTL layout and Android Debug `en-XA`/`ar-XB`; and reject
      pseudolocales from both Release artifacts. This is readiness evidence only. No
      translation, human VoiceOver/TalkBack review, full WCAG conformance, or future-screen
      accessibility is approved.
- [x] Integrate the governed seven-photo Nightingale background catalog into the bounded
      offline shell without making any image essential: cross-platform local-day selection,
      deterministic packaging, strong scrims/cards, images-hidden and high-contrast/
      reduced-transparency suppression, no motion loop, no accessibility announcement, and
      exact derivative hashes are mechanically and natively tested.
- [x] Establish the draft foundation threat and clinical-hazard model: version current and
      future trust boundaries, 27 implemented-control claims with their limits, 22
      security/privacy threats, 22 clinical hazards, 18 abuse cases, 20 activation gates,
      verification/incident requirements, platform asymmetries, and 17 open risks; bind it
      mechanically to the unchanged default-off foundation with 11 negative self-tests.
      This is engineering governance, not a safety case, compliance claim, residual-risk
      acceptance, penetration test, tabletop, or named approval.
- [x] Carry forward the existing automated privacy controls only as candidate evidence and
      rerun them under Nightingale app IDs and assets: package and exactly verify an iOS
      offline privacy manifest, declare only app-local `UserDefaults` reason `CA92.1`,
      explicitly deny Android cleartext and user-added/debug trust while retaining no
      network permission and no backup/transfer, reject runtime networking/logging/tracking/
      clipboard primitives, and inspect installed and Release artifacts on both platforms.
      Store declarations, future connected-product data flows, privacy approval, signed
      distribution, and penetration testing remain open.
- [ ] Meet WCAG 2.2 AA plus iOS/Android guidance for screen-reader navigation, focus order,
      headings, labels/actions, live-region restraint, target size, system text, high contrast,
      motion, landscape where supported, language expansion, captions/transcripts, and images
      disabled.
- [ ] Complete independent patient-advisor, accessibility, privacy/security, clinical
      safety, language/interpreter, legal/HIM, nursing, medical-staff, pharmacy, and support
      review. Automated checks never substitute for these approvals.
- [ ] Obtain independent clinical-safety/privacy/security ratification of the foundation
      threat/hazard model and run red-team, tabletop, penetration, mobile security,
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
7. [x] Complete the bounded 130-file patient communication, staff-handoff, native
       experience, notification registration/delivery, urgency/offline, and error
       classification. Mechanically pin the absent patient push and automatic-refresh
       implementations, human-retry identity loss, acceptance-versus-delivery distinction,
       iOS escalation-state contract mismatch, Android rendering gaps, and the false
       staff-close decode hypothesis.
8. [x] Update the master checklist, migration index, authorization matrix, documentation
       index, execution log, and CI for the verified communication/notification slice while
       leaving every Nightingale operation, network client, notification provider, patient
       mutation, production query, and production activation disabled.
9. [x] Close the remaining 134-source journey, preference, accessibility presentation,
       synthetic/debug, persistence, and release-classification slice. Mechanically prove
       full 256-source universe coverage; pin direct top-level Messages navigation, unsafe
       first-record selection, iOS aggregate-context drift, Android composite-path context
       drift, preference-surface mismatch, semantically inert Android reduced motion,
       test-only release exclusion, rejected deployed reference provisioning, and
       pathway-only two-person release coverage.
10. [x] Update the checklist, migration and authorization records, documentation index,
        execution log, and CI for complete source classification; rerun the independent
        Nightingale iOS and Android emulator suites and Release builds; and leave every
        implementation, network, source, mutation, synthetic runtime, production, migration,
        release, deployment, and patient-creation permission false.
11. [x] Define a held, non-runnable Today projection candidate with a Nightingale-owned
        path and opaque handles, eight explicit section states, field-level release/
        freshness/uncertainty/language/correction/offline contexts, bounded error and audit
        semantics, 68 synthetic outcomes, 14 direct-source checksums, and 24 negative
        verifier mutations. Do not add the path to OpenAPI or Laravel.
12. [ ] Obtain named identity/source and field-level clinical/content/language/privacy/
        accessibility approvals before implementing any Today route, adapter, projection
        query, generated client, or native rendering. Emulator verification of the unchanged
        offline foundation does not satisfy these approvals.
13. [x] Implement the approved offline presentation-preferences foundation on both native
        platforms: two Nightingale-only device preferences, stronger-system-setting
        precedence, effective reduced motion, decorative-imagery suppression, relaunch
        persistence, Release-artifact boundary scans, and clean iOS Simulator/Android API 35
        suites. This does not close full accessibility conformance or any human approval.
14. [x] Audit and harden the current foundation at maximum text, landscape, dark appearance,
        and increased contrast: replace the failing fixed iOS accent with an appearance-aware
        palette, add Android system-selected dark colors, correct semantic reading order,
        expand complete interaction rows, declare both iOS landscape orientations, require
        actual landscape application geometry, add native contrast/target/reflow tests, and
        bind the result to exact local Debug/Release artifacts. The broader Stream E
        conformance and independent-review items remain open.
15. [x] Establish and mechanically enforce the draft foundation threat and clinical-hazard
        model. It separates current offline exposure from future activation risk, keeps all
        high/critical live-data hazards disabled, records no approval or accepted residual
        risk, and leaves independent ratification, red-team/tabletop, penetration, signed
        artifact, non-production integration, pilot, and release evidence open.
16. [x] Generate and mechanically enforce the foundation dependency inventory. Resolve the
        Android Release runtime graph through Gradle’s structured resolution model, bind
        seven declarations/83 components/457 edges and the zero-package iOS application
        target to source hashes, and explicitly withhold standards, vulnerability,
        provenance, license, signing, artifact, distribution, and supply-chain approval
        claims.
17. [x] Govern and integrate the seven supplied Nightingale background images across both
        native foundations. Preserve source lineage, commit only exact metadata-stripped
        derivatives, use the same local-Gregorian-epoch-day catalog index on iOS and
        Android, keep imagery decorative and suppressible, enforce readable surfaces and
        exact Release packaging, and validate on iOS Simulator plus Android API 35.
        Distribution rights/attribution, physical-device review, patient-advisor review,
        and exact-SHA publication evidence remain separate gates.
18. [x] Complete the bounded offline privacy/release-control carry-forward under Nightingale
        identity. Package and parse the exact iOS privacy manifest, require only app-local
        `UserDefaults` reason `CA92.1`, declare no current-foundation tracking or collected
        data, add Android cleartext denial with system-only trust and no debug override,
        prove no Android network permission or backup, reject networking/logging/tracking/
        clipboard primitives, and inspect the installed apps and Release artifacts. This
        remains candidate engineering evidence; any live identity, data, telemetry,
        networking, store declaration, signed distribution, or privacy approval is held.
19. [x] Reconcile and fulfill the separately requested production sample-patient objective.
        The first authorized review was read-only and found one legacy Hummingbird reference
        foundation. A later explicit direction required a Nightingale sample using that
        deprecated product's sample as the template. Two serializable, advisory-locked,
        cardinality-checked transactions created exactly one Nightingale-owned operational
        synthetic encounter and one fresh UUIDv7 patient principal. Only principal type,
        locale/timezone, unit, acuity, and safe lifecycle shape were carried forward.
        Credentials, sessions, challenges, grants, identity links, digests, encrypted
        references, external identifiers, projections, and content were not copied. The
        Nightingale principal remains pending/inactive and the Hummingbird template remains
        unchanged. Exact evidence is retained under
        `docs/evidence/nightingale/production-sample-patient-2026-07-27/`.
20. [x] Close the current offline shell's bounded accessibility/language-readiness gap.
        Govern 15 exact cross-platform English strings, three headings, and two restrained
        status-announcement paths; pass iOS double-length/RTL and Android `en-XA`/`ar-XB`
        emulator journeys; verify Debug-only test behavior and English-only Release
        packaging; and retain non-PHI screenshots/hierarchies. Keep every translation,
        human assistive-technology review, full WCAG claim, live screen, identity/source
        approval, patient capability, distribution, pilot, and production gate open.
21. [x] Reconcile current `main` without allowing its legacy iOS release-path collision to
        redefine Nightingale. Restore the reference Hummingbird Patient project to its
        exact prior identity, move the export policy and registry mapping to
        `nightingale/iosApp`, add fail-closed registry/export/IPA checks, and revalidate
        both checksum-pinned migration ledgers plus the dependent Today evidence against
        the corrected 256-source universe. Do not sign, upload, distribute, activate, or
        claim store readiness.

## 10. Explicit holds

- No further production database access for patient creation, fixture insertion, identity
  enrollment, data correction, or feature activation belongs to this stream without a new
  explicit authorization. The bounded 2026-07-27 exception created only the documented
  synthetic encounter and pending/inactive principal.
- The production credential supplied by the operator is runtime-only. It is not committed,
  copied into source/documentation/fixtures/tests, or treated as an application secret
  source.
- One Nightingale synthetic sample exists, but it is not identity-linked, login-capable,
  encounter-authorized, content-bearing, routed, enrolled, activated, or a real patient.
  No patient feature, migration, or production application release occurs from this plan
  alone.
- No staff Hummingbird code is copied into Nightingale, and no patient code is made a staff
  Hummingbird runtime mode.
