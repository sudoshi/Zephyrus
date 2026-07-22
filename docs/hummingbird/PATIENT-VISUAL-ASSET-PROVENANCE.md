# Hummingbird Patient visual asset provenance

Status: **release-blocking review open**
Last verified: 2026-07-19
Technical owner: Hummingbird Patient mobile maintainers
Release approval owner: Product Design and Legal/Compliance (named approver not yet assigned)

This manifest covers the photographic Hummingbird backgrounds bundled in the
separate iOS and Android patient applications. It records technical lineage; it
does **not** establish copyright ownership or grant a license.

## Release rule

The four assets below are technically fit for local, offline use, but none is
approved for production distribution until a named release approver records the
copyright source, permitted uses, required attribution, and approval evidence.
The current release status for every row is therefore **hold — licensing and
attribution review required**.

## Asset ledger

| Patient scene                                                 | Repository source                                    | App-local copies                                                                                                                          | Copy/crop status                                                                                                                                                                                                             | Dimensions / bytes                                                               | SHA-256                                                                                                                                                        | Licensing and attribution review                                                                                                                             | Release owner / status                       |
| ------------------------------------------------------------- | ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------- |
| Calm green / Today                                            | `public/images/auth/hummingbirds/hummingbird-01.jpg` | iOS `Assets.xcassets/PatientCalmGreen.imageset/hummingbird-01.jpg`; Android `drawable-nodpi/patient_hummingbird_calm_green.jpg`           | iOS is a byte-for-byte copy. Android is a proportionally resized and recompressed derivative (2000 px high). Neither has a baked crop; runtime aspect-fill crop varies by viewport behind a deterministic readability scrim. | Source + iOS: 1689 x 2400 / 830,035 bytes. Android: 1407 x 2000 / 543,199 bytes. | Source + iOS: `9230a368fd2c0cab308280425b35b645b2a505871277a8136ec1c199cd53d6dc`. Android: `d5dc322481721ac5a29a6fe34c777bf727385dcd9d3d5aad1674182b5ccbec2d`. | **Not documented / not approved.** Creator, source URL, license terms, consent/model release (if applicable), and attribution requirement remain unverified. | Product Design + Legal/Compliance / **HOLD** |
| Airy flight / Welcome, authentication, loading, privacy cover | `public/images/auth/hummingbirds/hummingbird-06.jpg` | iOS `Assets.xcassets/PatientAiryFlight.imageset/hummingbird-06.jpg`; Android `drawable-nodpi/patient_hummingbird_airy_flight.jpg`         | iOS is a byte-for-byte copy. Android is a proportionally resized and recompressed derivative (2000 px high). Neither has a baked crop; runtime aspect-fill crop varies by viewport behind a deterministic readability scrim. | Source + iOS: 1600 x 2400 / 512,563 bytes. Android: 1333 x 2000 / 345,067 bytes. | Source + iOS: `65b640f035d8527d879f5b354e36eabb78ec3c9ca5c915356e05912a4aadc008`. Android: `a400063289b0ec8b62a7059c3e1e10618da545fe50871b2f3ddb35935e958117`. | **Not documented / not approved.** Creator, source URL, license terms, consent/model release (if applicable), and attribution requirement remain unverified. | Product Design + Legal/Compliance / **HOLD** |
| Human connection / Care Team and error states                 | `public/images/auth/hummingbirds/hummingbird-07.jpg` | iOS `Assets.xcassets/PatientCareConnection.imageset/hummingbird-07.jpg`; Android `drawable-nodpi/patient_hummingbird_care_connection.jpg` | iOS is a byte-for-byte copy. Android is a proportionally resized and recompressed derivative (2000 px high). Neither has a baked crop; runtime aspect-fill crop varies by viewport behind a deterministic readability scrim. | Source + iOS: 1600 x 2400 / 399,380 bytes. Android: 1333 x 2000 / 277,988 bytes. | Source + iOS: `b545c010ed87c9ee4150c616b6031f2e66b329bc0e339fbe4ac735d6f4236988`. Android: `b70ba3d0626f27bd29311a3e70a8e306768fd3c316cfae2554765b89603dcfbd`. | **Not documented / not approved.** Creator, source URL, license terms, consent/model release (if applicable), and attribution requirement remain unverified. | Product Design + Legal/Compliance / **HOLD** |
| Warm motion / My Path and empty states                        | `public/images/auth/hummingbirds/hummingbird-12.jpg` | iOS `Assets.xcassets/PatientWarmMotion.imageset/hummingbird-12.jpg`; Android `drawable-nodpi/patient_hummingbird_warm_motion.jpg`         | iOS is a byte-for-byte copy. Android is a proportionally resized and recompressed derivative (2000 px high). Neither has a baked crop; runtime aspect-fill crop varies by viewport behind a deterministic readability scrim. | Source + iOS: 1800 x 2400 / 461,289 bytes. Android: 1500 x 2000 / 319,131 bytes. | Source + iOS: `38e37231c4a14e3223823bbee531590aadd982bc7994c6538b02d291670b729d`. Android: `b3f127a8fbd2754d69adc12432fc01f22a9096ee8850b8fdab10e769e5105fb8`. | **Not documented / not approved.** Creator, source URL, license terms, consent/model release (if applicable), and attribution requirement remain unverified. | Product Design + Legal/Compliance / **HOLD** |

## Technical handling

- The iOS app-local copies match their repository sources byte for byte. The
  Android copies are documented optimized derivatives with the hashes above.
  Neither client requires a network image fetch.
- iOS uses `PatientPhotoBackground` and `PatientPhotoStateCard`; Android uses
  its patient visual-scene mapping. Both treat the photography as decorative,
  keep clinical content in the accessibility tree, and apply an opaque or
  near-opaque system-color scrim for readable text.
- iOS suppresses the photography when Reduce Transparency is enabled. The
  screen remains complete against an opaque system background.
- Runtime aspect-fill is presentation behavior, not a new derivative asset.
  Product Design must approve focal-point crops on every supported viewport
  before the release hold can be lifted.
- The first tracked repository commit currently discoverable for these source
  paths is `cd6d1b048ad44763f88e7f1a3474657645a8559b` (2026-07-02,
  “Beautify login with hummingbird slideshow”). That commit is a repository
  lineage marker only and is not licensing evidence.

## Approval record to complete before release

- [ ] Name the creator or authorized asset provider for each image.
- [ ] Record the authoritative source URL or acquisition record.
- [ ] Attach the license or written permission covering commercial mobile-app
      distribution, modification/cropping, and marketing screenshots.
- [ ] Determine and implement any required attribution.
- [ ] Confirm whether any recognizable person/property requires a release and
      attach that evidence.
- [ ] Approve focal-point crops in portrait, compact-height, dark mode,
      Increased Contrast, and supported Dynamic Type layouts.
- [ ] Record the named Product Design approver, Legal/Compliance approver,
      approval date, app version, and evidence location.
- [ ] Change each ledger row from **HOLD** only after all evidence is complete.

## Checksum verification

Run from the repository root:

```bash
sha256sum \
  public/images/auth/hummingbirds/hummingbird-{01,06,07,12}.jpg \
  hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientCalmGreen.imageset/hummingbird-01.jpg \
  hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientAiryFlight.imageset/hummingbird-06.jpg \
  hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientCareConnection.imageset/hummingbird-07.jpg \
  hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientWarmMotion.imageset/hummingbird-12.jpg \
  hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_{calm_green,airy_flight,care_connection,warm_motion}.jpg
```
