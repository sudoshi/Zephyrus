# Nightingale Five-Patient Investor-Demo Cohort Devlog

**Execution date:** 2026-08-06

**Plan:**
[nightingale-investor-demo-patient-cohort-2026-07-27.md](../plans/nightingale-investor-demo-patient-cohort-2026-07-27.md)

**Reusable evidence:**
[investor-demo-cohort-planning-2026-07-27](../evidence/nightingale/investor-demo-cohort-planning-2026-07-27/README.md)

**Disposition:** production cohort complete; demo only; not clinically approved; public
WAN smoke should be repeated after the contemporaneous network outage clears

## Outcome

Five isolated Nightingale investor-demo accounts are provisioned in production against
five governed MS-DRG pathway bindings. Each account owns one synthetic principal,
identity link, operational encounter, access grant, and six released patient-safe
synthetic projections. The cohort passed live authentication, projection, cache-control,
cross-account isolation, kill-switch, restoration, idempotency, and post-proof credential
cleanup checks.

The repository contains no demo password or token. Runtime credential entry used only the
provisioner's non-echoing prompt. Outputs retained here are cardinalities and non-secret
states only.

## Release lineage

| Gate                        | Evidence                                                                                                                                                                                   |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Foundation ownership        | Protected-main PR #111 owns the activation/disclosure gates, 63 governance documents, privacy manifest, foundation configuration, and app-identity uniqueness check; none were duplicated. |
| Cohort implementation       | PR #118 merged as `06e3d0b363f2ef553f857aa426f220ccff7fc8a4`.                                                                                                                              |
| Catalog-identity correction | PR #121 passed all 19 exact-head jobs and merged as `ce9202cc70245afd4c2df1c1ed6620709978753d`.                                                                                            |
| Main verdict                | The exact merged main SHA passed the required CI verdict.                                                                                                                                  |
| Deployment                  | Canonical `./deploy.sh --check` passed; confirmed canonical apply installed exact SHA `ce9202cc70245afd4c2df1c1ed6620709978753d`.                                                          |
| Migrations                  | None required or executed.                                                                                                                                                                 |

The catalog correction replaced a non-portable generated fixture UUID assertion with an
immutable dataset identity: dataset key, source CSV SHA-256, verification workbook
SHA-256, declared-baseline SHA-256, grouper version, all aggregate control totals,
inactive state, and zero clinical signoff. It still records the environment-local UUID
as provenance after the immutable evidence match succeeds.

## Verification before production writes

Local and exact-SHA CI evidence included:

- 210 Patient feature tests and 3,580 assertions with no failures;
- focused catalog-drift, authentication, adoption, rotation, suspend, replay, and
  cross-account tests;
- full repository Pint and secret scanning;
- canonical iOS simulator, Release, artifact-boundary, and transport checks from
  `hummingbird/iosPatientApp`;
- canonical Android JVM, Release, API 35 emulator, artifact-boundary, and transport
  checks from `hummingbird/androidPatientApp`; and
- seven unique iOS identifiers and two unique Android identifiers under the PR #111
  fail-closed uniqueness check.

The production preview was read-only and proved an empty owned cohort, the single exact
inactive/unsigned catalog release, all five pathway/DRG bindings, unit 85, and the
pre-existing reference sample's readiness for in-place adoption as `demo1`.

## Production apply and read-after-write proof

The atomic apply returned exactly:

| State                            | Count |
| -------------------------------- | ----: |
| Active synthetic principals      |     5 |
| Identity links                   |     5 |
| Active encounter grants          |     5 |
| Operational synthetic encounters |     5 |
| Released synthetic projections   |    30 |
| Projection kinds per account     |     6 |
| Accounts permitting clinical use |     0 |

The account-to-pathway result was:

| Handle  | MS-DRG | Scenario                                                                            | Stages | Milestones |
| ------- | ------ | ----------------------------------------------------------------------------------- | -----: | ---------: |
| `demo1` | `293`  | Heart Failure and Shock without CC/MCC                                              |      5 |         41 |
| `demo2` | `195`  | Simple Pneumonia and Pleurisy without CC/MCC                                        |      5 |         44 |
| `demo3` | `470`  | Major Hip and Knee Joint Replacement or Reattachment of Lower Extremity without MCC |      5 |         49 |
| `demo4` | `399`  | Appendix Procedures without CC/MCC                                                  |      5 |         36 |
| `demo5` | `807`  | Vaginal Delivery without Sterilization or D&C without CC/MCC                        |      5 |         44 |

Black-box production API verification checked all five logins and all six projections per
account. It observed 30 successful projection responses, 30 exact demo notices, and 55
checked responses with `Cache-Control: no-store`. Every one of the 20 directed
cross-account pathway substitutions returned the same generic content-free 404 without
echoing the owner handle or encounter UUID. The append-only audit independently recorded
5 token issues, 5 encounter disclosures, 30 projection disclosures, and 20 matching
`projection_not_available` isolation denials with no grant disclosure.

## Kill switch, recovery, and resting state

The exact-confirmation suspend action disabled all five owned accounts and grants and
revoked their active session/token families. Database proof then showed five suspended
principals, zero active sessions, and zero tokens. Five live login attempts all returned
the same generic `401 invalid_credentials` response.

The exact-confirmation re-apply restored all five accounts with the runtime-only password
and did not duplicate any owned row. Structural verification passed, and five live login
attempts returned bearer-token success. A final idempotent apply rotated the credential
hashes and revoked those test sessions and tokens. Final read-after-write state:

- five active synthetic demo principals;
- five exact identity/encounter/grant relationships;
- 30 released synthetic projections;
- zero active sessions;
- zero access or refresh tokens;
- catalog state `inactive`;
- zero catalog clinical signoffs; and
- clinical use prohibited for every demo account and projection.

## Deployment outage note

The canonical deployment performed its source, exact-SHA, remote-checkout, dependency,
asset-build, release-sync, cache, service-restart, and HTTP-vhost stages successfully.
Its final public-DNS HTTPS probe could not connect during the acknowledged WAN outage.
Read-only checks over the documented internal production route proved the exact release
marker, active Apache/queue/Arena services, production-built assets through the Zephyrus
TLS vhost, required edge headers, sensitive-path denial, TRACE rejection, installed edge
contract, and Laravel storage permissions. No deployment script, production configuration,
DNS, SSH configuration, or application code was altered to bypass the outage. Repeat the
ordinary public-WAN smoke after connectivity returns; no cohort mutation is required.

## Product-boundary confirmation

- `hummingbird/iosPatientApp` is the canonical TestFlight patient app.
- `hummingbird/androidPatientApp` is its canonical Android counterpart.
- `nightingale/iosApp` and `nightingale/androidApp` remain superseded and untouched.
- PR #111 remains the sole owner of the previously salvaged foundation assets.
- The demo projection layer remains separate from raw draft pathway prose and from any
  clinical approval, activation, pilot, or real-patient claim.
