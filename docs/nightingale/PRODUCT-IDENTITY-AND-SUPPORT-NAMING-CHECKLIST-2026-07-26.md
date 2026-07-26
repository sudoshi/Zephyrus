# Hummingbird and Nightingale Product Identity and Support Naming Checklist

**Status:** Engineering identity registry and release-preparation checklist. No external
identifier, store record, signing entitlement, support endpoint, or distribution right is
claimed as reserved or approved by this document.

**Initiative plan:**
[Nightingale Patient Product](../plans/nightingale-patient-product-2026-07-26.md)

## 1. Canonical product registry

| Surface                | Hummingbird Staff             | Nightingale                                                |
| ---------------------- | ----------------------------- | ---------------------------------------------------------- |
| Audience               | Hospital staff                | Inpatients and permitted representatives                   |
| Display name           | `Hummingbird`                 | `Nightingale`                                              |
| Apple bundle ID        | `net.acumenus.hummingbird`    | `net.acumenus.nightingale`                                 |
| Android application ID | `net.acumenus.hummingbird`    | `net.acumenus.nightingale`                                 |
| Current API boundary   | Staff `/api/mobile/v1`        | None in the foundation build                               |
| Support class          | Workforce application support | Patient-facing support with clinical escalation boundaries |
| Product mark           | Supplied hummingbird artwork  | Supplied nightingale artwork                               |
| Release train          | Hummingbird Staff             | Independent Nightingale train                              |

Forbidden release names include `Hummingbird Patient`, `HummingbirdPatient`,
`hummingbird.patient`, `Patient mode`, and any presentation that makes Nightingale look
like a role switch inside Hummingbird. Historic migration evidence may retain those terms
only when clearly labeled as reference lineage.

## 2. External reservation and store records

Every unchecked row requires an authorized owner to complete it in the named external
system and attach non-secret evidence. Repository work must never fabricate a reservation
or put credentials, signing keys, private support contacts, or store-session tokens into
source control.

### Apple

- [ ] Confirm the organization-controlled Apple Developer account and accountable owner.
- [ ] Verify or register the explicit App ID `net.acumenus.nightingale`.
- [ ] Create the Nightingale App Store Connect record with an organization-approved SKU.
- [ ] Establish distribution signing and certificate-rotation ownership.
- [ ] Decide, register, and review any keychain access group before protected storage exists.
- [ ] Decide whether an app group is necessary; default to none.
- [ ] Decide Universal Links and associated domains from an approved allowlist; default to none.
- [ ] Create a Nightingale-specific APNs topic and environment separation only after push
      privacy review.
- [ ] Complete the privacy manifest and App Privacy disclosure from implemented behavior,
      not planned behavior.
- [ ] Approve category, age rating, subtitle, keywords, copyright, export-compliance
      answers, and review notes.
- [ ] Approve public support, privacy-policy, terms, and optional marketing URLs.
- [ ] Verify screenshots contain no patient information, stale product name, test hook,
      internal hostname, or staff workflow.
- [ ] Record distribution-rights approval for the supplied nightingale artwork.

### Google Play

- [ ] Confirm the organization-controlled Play Console account and accountable owner.
- [ ] Verify or register `net.acumenus.nightingale` without repackaging a legacy patient app.
- [ ] Create the independent Nightingale Play record and controlled tester tracks.
- [ ] Establish Play App Signing, upload-key custody, rotation, and recovery ownership.
- [ ] Decide App Links from an approved host/path allowlist; default to none.
- [ ] Create an independent FCM project/topic boundary only after push privacy review.
- [ ] Complete Data safety, content rating, target-audience, ads, and account-deletion
      declarations from implemented behavior.
- [ ] Approve title, short description, full description, category, release notes, and
      store-listing localization.
- [ ] Approve public support email, support website, privacy-policy URL, and terms URL.
- [ ] Verify phone/tablet screenshots contain no patient information, stale product name,
      test hook, internal hostname, or staff workflow.
- [ ] Record distribution-rights approval for the supplied nightingale artwork.

### Hummingbird Staff reconciliation

- [ ] Confirm the existing Apple and Google records are owned by the organization and use
      `Hummingbird`, never `Hummingbird Patient`.
- [ ] Confirm signing, APNs/FCM, associated links, analytics, crash reporting, support
      routing, and screenshots remain staff-only.
- [ ] Record distribution-rights approval for the supplied hummingbird artwork.
- [ ] Verify the icon replacement does not alter the existing Hummingbird application IDs
      or silently create a new store listing.

## 3. Support naming and routing

Nightingale support must be patient-readable and operationally accountable before any
patient receives the app. Public contact values remain deliberately absent from this
repository until approved.

- [ ] Name the accountable product-support owner, clinical escalation owner, privacy
      incident owner, identity/recovery owner, accessibility owner, and after-hours owner.
- [ ] Approve a public support name that says `Nightingale Support`; do not route patients
      to `Hummingbird Support` or an individual staff member.
- [ ] Approve support email, phone, hours, language/interpreter coverage, accessibility
      accommodations, expected response time, and downtime wording.
- [ ] Define separate flows for technical help, account/identity recovery, privacy concern,
      medical-record correction, care-information question, delayed message, and urgent
      clinical need.
- [ ] Ensure every clinical or messaging support surface states that urgent needs use the
      bedside call button or immediate staff assistance, not delayed app messaging.
- [ ] Ensure support tooling receives the minimum necessary information and never displays
      raw clinical prose, staff notes, credentials, access tokens, or unrestricted logs.
- [ ] Define ticket retention, redaction, audit, correction, representative authority, and
      cross-facility routing.
- [ ] Define incident and kill-switch messages under the Nightingale name with a reviewed
      rollback owner and expiry.

## 4. Namespace checklist

Before a capability is introduced, its identifier must be independent and reviewed:

- [ ] Keychain/encrypted-storage service and key names.
- [ ] Deep-link scheme, Universal Links, and Android App Links.
- [ ] Notification channels, categories, topics, and generic notification copy.
- [ ] Analytics and crash project, event names, user properties, and opt-out behavior.
- [ ] Support diagnostics and correlation identifiers.
- [ ] Accessibility identifiers and automated-test hooks.
- [ ] Localized string catalogs and store metadata.
- [ ] Build, artifact, SBOM, signing, and release-manifest names.
- [x] Reserve the repository-local foundation dependency-inventory identity as
      `net.acumenus.nightingale.foundation-dependency-inventory` at schema version 1 and
      the canonical path
      `docs/nightingale/supply-chain/foundation-dependency-inventory.v0.json`. This closes
      only the current inventory name; the combined build/artifact/SBOM/signing/
      release-manifest item above remains open.
- [ ] Privacy, terms, status, support, and account-deletion URLs.

No Nightingale namespace may contain `hummingbird.patient`, and no Hummingbird namespace
may carry a Nightingale credential, patient grant, patient projection, or patient
communication.

## 5. Engineering release scan

The following checks are necessary but do not replace Apple, Google, legal, privacy,
security, clinical, accessibility, or patient-advisor approval:

- [x] iOS and Android application IDs are distinct and compile.
- [x] User-facing foundation copy uses only the correct product name.
- [x] Hummingbird and Nightingale source artwork and generated output fingerprints are
      distinct and pinned.
- [x] iOS AppIcon masters are RGB PNGs without alpha channels.
- [x] Android legacy, adaptive, round, and Android 13+ themed resources compile.
- [x] Actual iOS 26.3 and Android API 35 launcher/splash evidence shows the correct marks.
- [x] Nightingale’s compile-time boundary scan rejects Hummingbird identifiers and network
      clients.
- [x] The generated, source-hash-bound foundation dependency inventory records seven direct
      Android Release runtime declarations, 83 resolved components, 457 edges, zero iOS
      third-party packages, and four Apple system-module imports; it expressly withholds
      SBOM-conformance, vulnerability, provenance, license, signing, and approval claims.
- [x] Hummingbird's repository-owned WidgetKit, Live Activity, app-group, APNs, Android
      widget, and notification-channel identities remain staff-only.
- [x] Nightingale has no unapproved notification, push, widget, extension, app-group,
      shortcut, or Android receiver surface.
- [x] Repository scans find no native product-root store-listing package, and every
      corresponding Hummingbird/Nightingale launcher, brand-mark, adaptive, round, and
      monochrome asset remains distinct.
- [x] Source-predecessor Hummingbird builds install in place on clean iOS and Android
      emulators, preserve a synthetic private-data canary, and retain exact application and
      widget identities. This is not released-artifact or distribution-signing evidence.
- [ ] Retained released Hummingbird artifacts upgrade to approved release candidates with
      monotonic version/build values, distribution-signing continuity, app-private and
      protected-state continuity, installed-widget/Live Activity continuity, and verified
      rollback behavior.
- [ ] Approved notification implementations render the correct product identity, generic
      content, icon, actions, localization, redaction, and safe deep-link behavior on
      supported devices.
- [ ] Store records, signing, public support endpoints, distribution rights, privacy
      disclosures, and release approvals are complete.

## 6. Approval record template

For each external action, record:

| Field                | Required value                                                |
| -------------------- | ------------------------------------------------------------- |
| Product and platform | Exact product plus Apple or Google                            |
| External record      | Non-secret record ID or console URL                           |
| Accountable owner    | Named organization role and person                            |
| Reviewer             | Independent reviewer and review domain                        |
| Evidence date        | ISO date                                                      |
| Approved values      | Exact display name, ID, URL, and audience                     |
| Explicit exclusions  | Names, capabilities, and data not authorized                  |
| Residual risks       | Open risks accepted by the approval owner                     |
| Rollback/recovery    | Revocation, key recovery, listing rollback, and contact owner |
| Expiry/re-review     | Trigger or date requiring revalidation                        |

An unchecked row is a hold, not an implicit approval.
