# Nightingale Patient Product — Execution Log

**Initiative plan:**
[nightingale-patient-product-2026-07-26.md](../plans/nightingale-patient-product-2026-07-26.md)

## 2026-07-26 — Product direction and isolated development stream

### Decision record

Nightingale is the new dedicated patient product. Hummingbird is now the staff
operations product only. This is an architecture and product-identity decision, not a
clinical, patient-identity, content-release, pilot, database, migration, or production
deployment authorization.

### Completed evidence

- Created branch `codex/nightingale-patient-product` in a clean worktree at
  `/Users/sudoshi/Github/Zephyrus-nightingale-patient`, based on `origin/main` commit
  `446107ec`.
- Inspected the current independent staff targets (`net.acumenus.hummingbird`) and the
  legacy patient-reference targets (`net.acumenus.hummingbird.patient`) on both iOS and
  Android. The latter remain preserved as reference evidence and are not the Nightingale
  release target.
- Recorded supplied brand sources as RGBA 1254 × 1254 PNGs and pinned their SHA-256
  fingerprints in the initiative plan and product-specific provenance manifests.
- Added `scripts/brand/render-app-icon.swift`, a deterministic AppKit renderer. It created
  opaque iOS masters and Android density launcher derivatives, plus transparent Android
  adaptive-icon foregrounds with an 8% safe-zone inset. Hummingbird uses `#050B12` and
  Nightingale uses `#17120E` as reviewed opaque launcher backgrounds.
- Replaced the Hummingbird Staff iOS AppIcon/brand mark and Android
  launcher/round/foreground derivatives with the supplied hummingbird source. No legacy
  Hummingbird Patient asset or target was changed.
- Created the Nightingale iOS and Android roots, each with its own
  `net.acumenus.nightingale` application identity, unit/UI smoke tests, and an intentionally
  no-network, no-data foundation screen. The Android manifest has no `INTERNET` permission;
  its Gradle boundary task rejects staff product/endpoints. The iOS and Android boundary
  tests assert that live patient and staff endpoint access are disabled.
- Generated the Nightingale Xcode project from `project.yml`. The Nightingale iOS unit and
  UI tests pass on the booted iPhone 17 Pro simulator. Android unit/boundary tests and the
  API 35 (`hb`) emulator instrumentation smoke test pass. Android visual review captured
  the Nightingale Android 12+ splash and foundation screen, and the Hummingbird Staff splash.
- Verified Hummingbird Staff iOS project-generation drift and a Simulator Debug build. Its
  Android Debug APK builds, installs, and displays the supplied hummingbird mark on the
  Android 12+ splash surface.
- Confirmed the existing patient reference already has a distinct API, storage, and
  lifecycle boundary. That is useful migration input, not proof that the new Nightingale
  product is ready or authorized.

### Open work and holds

- Both Nightingale native targets now compile in Debug and Release, and both Hummingbird Staff
  targets compile in Debug and Release after the icon replacement. This does not satisfy
  signing, distribution, rights, or clinical/patient release requirements.
- Actual iOS launcher-surface inspection, Android round/adaptive launcher inspection, Android
  13+ monochrome-icon design, app-store/signing/rights release steps, and all clinical/patient
  authorization gates remain open. The current build and emulator evidence is only a
  foundation verification, not distribution approval.
- The existing patient contract remains governed compatibility input until a Nightingale
  contract/release migration is independently approved.
- No production patient, database record, session, grant, projection release, feature flag,
  migration, deployment, or pilot action was taken.

## 2026-07-26 — Branded privacy and accessibility foundation

### Completed evidence

- Classified the first seven legacy reference sources by safety primitive, product behavior,
  test/fixture-only, or rejected behavior. The classification explicitly holds every unlisted
  reference source and records why no patient API, credential, session, clinical projection,
  messaging behavior, or synthetic patient was migrated.
- Added a Nightingale-only scenic foundation using the supplied nightingale artwork, warm/cool
  low-contrast gradients, opaque content cards, decorative-image semantics, large-text image
  attenuation, iOS high-contrast image withholding, a tested Android high-contrast policy seam,
  and reduced-motion-aware iOS transitions. Android runtime high-contrast integration remains
  held for an approved Nightingale presentation preference rather than a private platform API.
- Added lifecycle privacy covers on iOS and Android with Nightingale-specific copy and
  accessibility identifiers. Foundation content is hidden from assistive interaction while
  the iOS cover is visible.
- Added mandatory Android `FLAG_SECURE`. On API 35 the app remained fully represented in the
  accessibility hierarchy while a system screenshot returned a black frame, confirming that
  the capture control protects visual content without erasing assistive semantics.
- Added `verify-nightingale-product-boundary.sh`, which rejects staff/legacy identifiers,
  staff endpoints, Android network permission, and native network-client symbols and verifies
  the independent iOS/Android application identifiers.

### Verification

- iOS: XcodeGen project generation and the full Nightingale unit/UI suite pass on iPhone 17 Pro
  Simulator. Both the normal foundation and forced privacy-cover states were visually reviewed.
- Android: unit/boundary tests pass; three API 35 instrumentation tests pass for launch copy,
  secure-window enforcement, and lifecycle privacy-cover state. Accessibility-tree inspection
  confirms the complete no-data patient-safe message.
- Boundary: no staff namespace, legacy patient package, staff endpoint, Android network
  permission, or native URL client is present in Nightingale application sources.

### Holds

- This slice contains no patient identity, credential storage, API client, clinical content,
  care-team communication, notification, analytics, production database access, feature
  activation, migration, deployment, or pilot enrollment.
- Remaining legacy sources are not migration-approved. Clinical, privacy/security,
  accessibility, patient-advisor, identity, legal/HIM, and release approvals remain open.

## 2026-07-26 — Product identity and launcher evidence hardening

### Completed evidence

- Added independent Android 13+ monochrome adaptive resources for Hummingbird Staff and
  Nightingale. The generated resources are white alpha silhouettes, not full-color
  foregrounds mislabeled as monochrome artwork.
- Ran the first round-mask review on the Android API 35 emulator, detected that the
  Hummingbird beak was clipped at the original 8% adaptive inset, corrected the
  Hummingbird adaptive/monochrome inset to 20%, rebuilt, reinstalled, and repeated the
  review. The corrected round and themed icons retain the complete subject.
- Captured non-PHI light/dark iOS launcher, Android round adaptive, Android light/dark
  themed-icon, and Android system-splash evidence for both products in
  [the brand evidence record](../evidence/nightingale/brand-identity-2026-07-26/README.md).
- Corrected the deterministic icon renderer so `opaque` outputs are RGB PNGs without alpha
  channels. Regenerated both iOS AppIcon masters and all legacy Android launcher/round
  density outputs.
- Added `verify-app-icon.swift` and `verify-mobile-brand-assets.sh`. They verify source
  checksums, dimensions, alpha-channel policy, visible/transparent pixel presence,
  monochrome pixel purity, Android v33 resource wiring, and cross-product distinction.
- Added the brand verifier as an independent macOS CI job so future non-documentation
  changes cannot silently reintroduce alpha, source drift, cross-product identity, or a
  malformed themed-icon resource.
- Added the
  [product identity and support naming checklist](../nightingale/PRODUCT-IDENTITY-AND-SUPPORT-NAMING-CHECKLIST-2026-07-26.md).
  It records canonical product names and app IDs while leaving all external reservations,
  signing, public support contacts, distribution rights, store metadata, and approvals
  explicitly pending.

### Verification

- Hummingbird Android Debug and Release builds accepted the corrected adaptive and
  monochrome resources.
- Nightingale Android Debug and Release builds and its product-boundary task accepted the
  independent resources.
- The brand-asset verifier confirms both iOS masters and legacy Android launchers have no
  alpha channel, while adaptive and monochrome foregrounds retain required transparency.
- Nightingale’s iOS XCTest/XCUITest scheme passed three tests with zero failures; its
  Android API 35 instrumentation suite passed three tests with zero failures. Hummingbird
  and Nightingale Android unit suites passed, and current Debug/Release builds succeeded.
- No patient data, credential, production database, patient record, feature activation,
  migration, deployment, or store-console mutation was used.

### Holds

- The remaining Stream B cross-surface audit covers notification, widget, installed
  upgrade, and future store-listing surfaces. It is not inferred from launcher evidence.
- Artwork ownership/distribution rights and independent product-design/accessibility
  review remain open.
- Apple/Google records, signing, support endpoints, privacy disclosures, analytics/crash
  boundaries, push identities, and pilot/release authorization remain open.

## 2026-07-26 — Pre-identity protected-state and volatile-input foundation

### Decisions and classification

- Added the
  [identity, recovery, and protected-state decision record](../nightingale/IDENTITY-RECOVERY-AND-PROTECTED-STATE-DECISIONS-2026-07-26.md)
  before adding storage code. It separates local deletion from remote revocation, prohibits
  durable access tokens and device UUIDs, withholds a refresh-token decision, reserves one
  future binding descriptor, defines recovery/account-transition clearing, and records
  device-compromise and memory-zeroization limitations.
- Classified the legacy iOS and Android secure stores as mixed reference sources. Their
  Hummingbird namespaces, access/refresh tokens, session/device UUIDs, and implicit
  migration behavior were rejected.
- Classified legacy iOS/Android message composition as product behavior. The useful
  no-durable-draft principle was retained, while legacy message UI, routing, clinical copy,
  and send operations remain held.

### Implemented foundation

- Added a dormant iOS generic-password Keychain store using the exact Nightingale service,
  the data-protection Keychain, `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`, disabled
  synchronization, explicit status errors, empty-value rejection, and idempotent
  service-wide deletion. There is no access-token, refresh-token, device-identity, network,
  or production caller.
- Added a dormant Android protected-state store using a non-exportable 256-bit AES
  `AndroidKeyStore` key, AES-GCM with a fresh 12-byte IV, authenticated application/schema
  context, a versioned ciphertext envelope, app-private storage, and explicit verified
  deletion of both key and ciphertext. Unknown/corrupt/tampered state fails closed.
- Kept Android application backup disabled and added explicit cloud-backup/device-transfer
  exclusion rules. Both native product-boundary checks now pin that posture.
- Added process-memory-only volatile-input state on both platforms. The active roots clear
  it when the app becomes inactive; tests also pin logout, identity transition, recovery,
  revocation, and local-removal reasons. The implementation explicitly does not claim
  immutable-string memory zeroization.

### Verification

- iOS: the normally signed iPhone 17 Pro Simulator run passed five unit tests and two UI
  tests. The real Keychain test round-tripped a synthetic canary, inspected the
  `WhenUnlockedThisDeviceOnly` and non-synchronizing attributes, and verified idempotent
  deletion. A deliberately unsigned diagnostic run produced `errSecMissingEntitlement`
  (`-34018`), proving the test does not silently bypass a missing platform capability; the
  signed run passed without weakening the query.
- Android: JVM tests, Debug assembly, AndroidTest assembly, and Release assembly pass. The
  API 35 `hb` emulator passed five instrumentation tests, including real Keystore/GCM
  round-trip, ciphertext-not-plaintext inspection, tamper rejection, deletion of key and
  ciphertext, idempotent deletion, and lifecycle draft clearing.
- The Nightingale no-network/product-boundary scans remain green. No network permission,
  API client, patient input UI, or legacy storage namespace was introduced.

### Holds

- The protected stores are platform foundations, not an approved credential design. Real
  identity-provider selection, proofing, representative access, refresh/session policy,
  user-presence policy, recovery, support, penetration testing, and pilot approval remain
  open.
- No production database, patient, credential, identity record, grant, session, API,
  feature flag, migration, deployment, or pilot state was read or changed.
