# Nightingale background rights review acceptance evidence

**Date:** 2026-07-27

**Scope:** Hash-bound rights/source-archive review, fail-closed distribution hold, complete
Nightingale regression acceptance

**Functional checklist credit:** Immediate implementation milestone 23 only

**Parent checklist change:** None; remains 42/54 complete (77.78%)

## 1. Evidence boundary

This slice adds no image-rights approval. It establishes an auditable queue and prevents
the existing v0 record from claiming approval.

Accepted facts:

- seven review rows match the seven catalog source IDs, filenames, dimensions, and
  SHA-256 values;
- two exact Unsplash asset pages are identified;
- one current provider download matches its catalog source byte-for-byte;
- one provider asset is corroborated as the same resized/re-encoded image;
- five exact sources remain unresolved;
- zero durable source archives are recorded;
- zero durable terms snapshots are recorded;
- zero release-owner approvals are recorded; and
- zero assets are distribution eligible.

Not claimed:

- legal clearance;
- complete license or attribution evidence;
- a durable organization-controlled original archive;
- patient-advisor or accessibility approval;
- distribution signing or store readiness;
- pilot, production, marketing, deployment, or activation permission.

## 2. Provider-source observations

The two filename-embedded provider asset IDs were resolved to official asset pages:

| Catalog asset | Official provider page                                                                                        | Creator          | Provider dimensions | Current download relation                                                                       |
| ------------- | ------------------------------------------------------------------------------------------------------------- | ---------------- | ------------------- | ----------------------------------------------------------------------------------------------- |
| 05            | [Unsplash `VCq1vozVMbw`](https://unsplash.com/photos/a-small-bird-perched-on-a-tree-branch-VCq1vozVMbw)       | Miguel Alcântara | 2608x3912           | supplied 2400x3600 source is the same image after resize/re-encoding; not a byte-for-byte match |
| 06            | [Unsplash `g3mOCylYkmk`](https://unsplash.com/photos/a-small-bird-sitting-on-top-of-a-brick-wall-g3mOCylYkmk) | Muhammad Shakir  | 3456x5184           | current download SHA-256 exactly equals catalog source SHA-256 `507f7325…37f1`                  |

The pages and the [current provider license](https://unsplash.com/license) were observed
on 2026-07-27. The [provider terms](https://unsplash.com/terms) were inspected for the
license boundary. No full webpage was copied into the repository, and the live
observations are deliberately marked non-durable and unapproved.

No exact official source was established for catalog assets 01, 02, 03, 04, or 07. Search
results, filename wording, visually similar images, and photographer profiles were
rejected as insufficient evidence.

## 3. Mechanical acceptance

Commands:

```bash
node scripts/ci/verify-nightingale-background-assets.mjs . --self-test
node scripts/ci/verify-nightingale-background-rights.mjs . --self-test
bash scripts/ci/verify-nightingale-product-boundary.sh .
```

Accepted output:

```text
Nightingale background catalog verified: 7 exact metadata-stripped decorative JPEGs,
4439974 bytes, stable daily selection policy, accessibility safeguards, source lineage,
and explicit pre-distribution rights gate; fail-closed self-tests passed.

Nightingale background rights review verified: 7 catalog-bound assets, 2 provider pages
identified, 1 current provider binary match, 5 exact sources unresolved,
0 distribution-eligible assets; release remains on hold; 20 negative mutations passed.

Nightingale native product boundary verified
```

The 20 negative mutations covered:

1. distribution approval;
2. legal determination;
3. release-owner approval;
4. automated release;
5. missing asset;
6. duplicate asset;
7. source-hash drift;
8. per-asset release eligibility;
9. machine-local path promoted to archive;
10. invented archive checksum verification;
11. guessed provider for an unresolved source;
12. provider-asset-ID drift;
13. resized corroboration promoted to a binary match;
14. exact-download hash drift;
15. invented terms-snapshot location;
16. invented terms review;
17. rights-cleared count inflation;
18. distribution-eligible count inflation;
19. filename promoted to rights evidence; and
20. catalog-digest drift.

## 4. Full contract and backend acceptance

The complete 15-stage chain passed:

1. empty/default-off executable contract;
2. encounter-access candidate;
3. Today candidate;
4. patient-journey reference candidate;
5. identity/source candidates;
6. 65-source identity/input classification;
7. 130-source communication/notification classification;
8. 134-source journey/preference/release classification;
9. threat/hazard model;
10. dependency inventory;
11. namespace foundation;
12. controlled-pilot manifest;
13. background-rights review;
14. route-free/default-deny backend foundation; and
15. native product boundary.

The focused Laravel foundation suite passed:

```text
Tests: 23 passed (149 assertions)
```

## 5. iOS Simulator and Release artifact

The generated project was reproduced with XcodeGen. An isolated iPhone 16e simulator on
iOS 26.3.1 produced:

- Nightingale unit tests: 11/11 passed;
- Nightingale UI journeys: 6/6 passed;
- failures: 0;
- skipped: 0; and
- unsigned Release Simulator application boundary: passed.

The first combined invocation deliberately set `CODE_SIGNING_ALLOWED=NO` for all test
hosts. Its UI target passed 6/6, while the Keychain canary received
`errSecMissingEntitlement` (`-34018`) because the unsigned test host lacked its simulated
keychain entitlement. That aggregate invocation was rejected. The accepted rerun
separated the concerns:

- unit tests used normal simulator ad-hoc signing and passed 11/11;
- UI tests used normal simulator ad-hoc signing and passed 6/6 in a clean result bundle;
  and
- the Release artifact was built separately with signing disabled and passed the exact
  identity, version, orientation, privacy, dependency, no-network, no-deep-link,
  no-test-hook, English-copy, and seven-background verifier.

Only the Nightingale simulator used for the run was shut down.

## 6. Android API 35 and Release artifact

The existing API 35 emulator on port 5554 belonged to another process and remained
untouched. A second `hb` instance was cold-booted read-only on port 5556 with snapshots
disabled.

Accepted results:

- forced Debug JVM tests: 8/8 passed;
- forced Release JVM tests: 8/8 passed;
- Debug lint: passed;
- Release lint: passed;
- Debug assembly: passed;
- Release assembly: passed;
- forced build tasks: 108/108 executed;
- unsigned Release APK boundary: passed;
- installed API 35 journeys: 10/10 passed;
- installed failures/errors/skips: 0/0/0.

The Release verifier confirmed exact package identity, one package-local permission, no
network/deep-link/test hook, cleartext denial, exact English copy without Debug
pseudolocales, expected native libraries, unsigned state, and the exact seven background
JPEGs.

Only emulator 5556 was terminated. Emulator 5554 remained connected to its original
process.

## 7. Product and production boundary

The master checklist remains:

- checked: 42;
- open: 12;
- total: 54;
- count-based completion: 77.78%.

Immediate milestone 23 records completion of the evidence/control slice but does not add a
parent checkbox. The parent source-archive/license/attribution item remains open because
0/7 assets have the complete durable evidence and named release-owner decision.

This work made no production database query or mutation. The already-authorized
Nightingale reference patient remains pending/inactive and isolated exactly as recorded
in the
[sample-patient evidence](../production-sample-patient-2026-07-27/README.md). No
credential, route, source adapter, patient disclosure, clinical content, signing,
distribution, deployment, pilot, or activation was added.
