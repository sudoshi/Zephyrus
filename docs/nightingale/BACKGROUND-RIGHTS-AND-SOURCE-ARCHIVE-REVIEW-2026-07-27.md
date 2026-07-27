# Nightingale background rights and source-archive review

**Date:** 2026-07-27

**State:** Evidence inventory complete; external distribution remains on hold

**Scope:** Seven decorative Nightingale background sources and their existing catalog
lineage

**Legal determination:** None

**Distribution-eligible assets:** 0 of 7

## 1. Outcome

This review converts the prior single prose hold into an exact, per-asset, machine-checked
rights and archive queue. It does not clear any image for pilot, production, App Store,
Play Store, marketing, or other external distribution.

The repository now proves all of the following:

- the review covers the exact seven catalog entries, in order;
- every review row is bound to the catalog source filename, dimensions, and SHA-256;
- two provider asset pages are identified from the provider asset IDs embedded in the
  supplied filenames;
- one current provider download is byte-for-byte identical to the catalog source;
- the other identified provider asset is strongly corroborated as the same image after
  resize/re-encoding, but its exact catalog source binary is not available from the
  provider at the checked download URL;
- five exact provider or purchase sources remain unresolved;
- no organization-controlled original archive, durable provider/purchase record, durable
  license snapshot, or release-owner approval is recorded for any image; and
- no code or document can promote the v0 review to an approved distribution state without
  failing CI.

The machine-readable source of this result is the
[v0 rights review](../../nightingale/backgrounds/rights/rights-review.v0.json). The
independent verifier is
[`verify-nightingale-background-rights.mjs`](../../scripts/ci/verify-nightingale-background-rights.mjs).

## 2. Evidence standard

The review uses a deliberately narrow standard:

1. A filename is lineage, not proof of a provider, author, purchase, or license.
2. A photographer profile is not proof that a specific image came from that profile.
3. A live provider page identifies an asset only when its asset ID, creator, dimensions,
   composition, and supplied-file lineage reconcile.
4. A live license page is a current observation, not a durable record of the terms that
   applied when the file was obtained.
5. A developer workstation path is not an organization-controlled archive.
6. A committed optimized derivative is not the original source archive.
7. A binary match proves identity to the checked download; it does not itself establish
   the applicable rights, preserve the terms, or authorize a release.
8. Automated checks can prove record completeness and prevent overstatement. They cannot
   issue a legal determination or appoint the release owner.

## 3. Catalog reconciliation

| Catalog ID                  | Supplied source filename                                                              | Provider/source result                                       | Binary reconciliation                                                                                                       | Durable archive | Release eligible |
| --------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- | --------------- | ---------------- |
| `nightingale-background-01` | `1031776-1400x1980-phone-hd-robin-bird-background-photo.jpg`                          | exact provider or purchase source unresolved                 | catalog hash retained only                                                                                                  | no              | no               |
| `nightingale-background-02` | `HD-wallpaper-nightingale-bird-feathers-bright.jpg`                                   | exact provider or purchase source unresolved                 | catalog hash retained only                                                                                                  | no              | no               |
| `nightingale-background-03` | `beautiful-sparrow-sitting-thin-branch-tree.jpg`                                      | exact provider or purchase source unresolved                 | catalog hash retained only                                                                                                  | no              | no               |
| `nightingale-background-04` | `common-linnet-bird-carduelis-cannabina-perched-branch-tree-with-yellow-blossoms.jpg` | exact provider or purchase source unresolved                 | catalog hash retained only                                                                                                  | no              | no               |
| `nightingale-background-05` | `miguel-alcantara-VCq1vozVMbw-unsplash.jpg`                                           | Unsplash asset `VCq1vozVMbw`, Miguel Alcântara, corroborated | provider currently serves 2608x3912; supplied 2400x3600 file has normalized RMSE `0.0112669` and pHash distance `0.0247332` | no              | no               |
| `nightingale-background-06` | `muhammad-shakir-g3mOCylYkmk-unsplash.jpg`                                            | Unsplash asset `g3mOCylYkmk`, Muhammad Shakir, exact         | current 3456x5184 download SHA-256 equals catalog source SHA-256 `507f7325…37f1`                                            | no              | no               |
| `nightingale-background-07` | `vertical-closeup-shot-brown-shrike-bird-perched-branch.jpg`                          | exact provider or purchase source unresolved                 | catalog hash retained only                                                                                                  | no              | no               |

The two identified provider pages are:

- [Miguel Alcântara, Unsplash asset `VCq1vozVMbw`](https://unsplash.com/photos/a-small-bird-perched-on-a-tree-branch-VCq1vozVMbw)
- [Muhammad Shakir, Unsplash asset `g3mOCylYkmk`](https://unsplash.com/photos/a-small-bird-sitting-on-top-of-a-brick-wall-g3mOCylYkmk)

On 2026-07-27, both pages labeled the images free to use under the
[Unsplash License](https://unsplash.com/license). The corresponding
[Unsplash terms](https://unsplash.com/terms) distinguish the image license from depicted
trademarks, recognizable people, and works of authorship. These bird images do not visibly
introduce those three depicted-subject categories, but that observation is not a legal
clearance or a substitute for the required archived terms and release-owner review.

## 4. Why the two identified assets remain held

Assets 05 and 06 are materially closer to closure than the other five, but neither meets
the release gate:

- the exact catalog source binary is not stored in an organization-controlled,
  checksum-verifiable archive;
- the source page and applicable license/terms have not been captured into a durable,
  versioned rights record;
- no dated release-owner or legal review is recorded; and
- the v0 record intentionally cannot express approval.

Asset 05 has an additional limitation: the current provider download is a larger source
encoding, while the supplied catalog source is a 2400x3600 re-encoding. The image
comparison corroborates identity, but a release archive must retain the exact catalog
source SHA-256 rather than reconstructing it later.

## 5. Required durable archive

For each of the seven assets, the release owner must create a record with:

| Required field            | Acceptance rule                                                                                                     |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Original binary location  | organization-controlled, access-controlled, retrievable without one person’s workstation, and governed by retention |
| Original binary checksum  | retrieved bytes must equal the catalog `source.sha256`                                                              |
| Source or purchase record | exact provider asset/purchase locator and acquisition evidence, not a search result or profile                      |
| Creator/rightsholder      | named source-backed creator or other rightsholder                                                                   |
| Applicable terms          | durable snapshot of the license, purchase terms, or written permission that applies to that exact asset             |
| Terms identity            | capture timestamp or version plus SHA-256 of the archived record                                                    |
| Attribution disposition   | exact required credit, or an explicit source-backed statement that attribution is not required                      |
| Release-owner decision    | named reviewer, date, decision, scope, exceptions, and successor review record                                      |

An archive is acceptable only when it is organization-controlled, access-controlled,
versioned or object-locked, checksum-verifiable, retention-governed, and independent of a
single workstation. Repository paths to optimized derivatives and local absolute paths
are explicitly rejected as release evidence.

## 6. Fail-closed mechanical control

Run:

```bash
node scripts/ci/verify-nightingale-background-assets.mjs . --self-test
node scripts/ci/verify-nightingale-background-rights.mjs . --self-test
```

The rights verifier:

- hashes the exact catalog file and checks the digest recorded by the review;
- reconciles every review row to catalog ID, filename, source dimensions, and source hash;
- requires exactly five unresolved source rows and exactly two identified Unsplash rows;
- requires the exact observed creator, asset ID, canonical page, publication timestamp,
  dimensions, current download hash, and reconciliation method for those two rows;
- keeps all seven archive locations, archive checks, approvals, and eligibility facts
  empty or false;
- requires the global legal determination to be `none`, distribution status to be `hold`,
  and automated release permission to be false;
- verifies summary counts of 7 total, 2 identified pages, 1 byte-for-byte current download
  match, 5 unresolved sources, 0 durable archives, 0 rights-cleared assets, and 0
  distribution-eligible assets; and
- executes 20 adversarial mutations covering invented approval, release eligibility,
  archive claims, provider guesses, source/hash drift, terms claims, summary inflation,
  filename-only evidence, and catalog-digest drift.

The verifier is called by both the Nightingale contract CI job and the native product
boundary chain. It performs no network request: volatile provider observations are stored
as review evidence, while a future lift requires a deliberately reviewed successor record
and verifier revision.

## 7. Closure procedure

1. Resolve exact provider or purchase sources for assets 01, 02, 03, 04, and 07.
2. Place all seven exact originals in the approved durable archive.
3. Retrieve each archived object and verify its SHA-256 against the catalog.
4. Archive exact source/purchase evidence and applicable terms with their own checksums.
5. Record creator/rightsholder and attribution requirements for every image.
6. Obtain a named release-owner or legal decision for each image and the seven-image set.
7. Create a versioned successor review; do not edit v0 into an approval.
8. Update the catalog distribution state and verifier only in the same reviewed change.
9. Run the full Nightingale contract, native artifact, iOS Simulator, and Android emulator
   acceptance matrix before any external distribution.

Until all nine steps pass, the plan’s parent rights/archive checkbox remains open and the
seven images remain foundation-only assets.

## 8. Residual risks

- Five sources may prove to require paid licenses, attribution, replacement, or removal.
- Provider terms can change; the live observations in this record are not durable terms
  snapshots.
- Asset 05’s current provider encoding is not the catalog source binary.
- No provider warranties, model/property releases, or indemnities have been evaluated.
- The rights record does not replace the separate named patient-advisor and accessibility
  review of image comfort, crop, legibility, cultural interpretation, or images-hidden
  behavior.
- No signed artifact, store submission, pilot, production deployment, or marketing use is
  authorized by this work.
