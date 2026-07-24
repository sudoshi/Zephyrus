# ADR: Mobile transport security and certificate-pinning decision

- **Status:** Accepted
- **Date:** 2026-07-24
- **Decision owners:** Hummingbird Platform and Zephyrus Security
- **Applies to:** Hummingbird Staff iOS/Android and Hummingbird Patient iOS/Android
- **Review trigger:** production PKI or hostname change, managed mobile-distribution change,
  material CA-threat-model change, or 2027-07-24—whichever occurs first

## Context

All Hummingbird credentials, operational data, patient projections, patient messages, and
realtime hints cross a hostile-network boundary. A release binary must therefore be unable
to downgrade to cleartext, silently redirect to another origin, trust a user-added
certificate authority, or install a permissive certificate verifier.

Before this decision, the staff apps selected production HTTPS/WSS in release builds, but
the controls were incomplete:

- the iOS compile condition selected local HTTP for every simulator build, including
  `Release`;
- both iOS products declared `NSAllowsLocalNetworking` in the sole application Info.plist,
  so the exception was also present in release artifacts;
- staff Android enabled cleartext for the entire debug application rather than only the
  emulator loopback origin;
- runtime endpoint validation was not symmetric across staff iOS and Android;
- the patient clients required HTTPS, but a release configuration was not bound to the
  approved production hostname;
- there was no documented certificate-pinning decision, rotation requirement, or
  cross-product verification gate.

The production endpoint observed on 2026-07-24 was
`zephyrus.acumenus.net:443`, using a Let's Encrypt certificate issued by `YE1`, valid from
2026-06-28 through 2026-09-26. That short certificate lifetime is normal for the current
public PKI, but it means an unmanaged leaf-certificate pin would create a predictable
availability hazard.

Apple documents that App Transport Security (ATS) requires HTTPS and performs additional
TLS checks, and that exceptions reduce those protections. Android provides declarative
Network Security Configuration for cleartext policy and trust anchors. Both platforms also
support pinning, but pinning creates an independent key-rotation and app-update dependency:

- [Apple App Transport Security](https://developer.apple.com/documentation/bundleresources/information-property-list/nsapptransportsecurity)
- [Apple pinned domains](https://developer.apple.com/documentation/bundleresources/information-property-list/nsapptransportsecurity/nspinneddomains)
- [Android Network Security Configuration](https://developer.android.com/privacy-and-security/security-config)

## Decision

### Release boundary

1. Staff and patient release builds accept API traffic only over HTTPS to
   `zephyrus.acumenus.net` on the default TLS port.
2. Staff release builds accept realtime traffic only over WSS to the same hostname on port 443.
3. Base URLs may not contain credentials, a path prefix, query, or fragment.
4. Credential-bearing API clients and staff realtime clients refuse every redirect,
   including same-origin redirects. A server route migration must return an explicit API
   response; the client does not replay a request or bearer credential at a `Location`
   target.
5. iOS uses default ATS and `URLSession` server-trust evaluation. Release Info.plists carry
   no ATS exception.
6. Android uses a Network Security Configuration with cleartext disabled and only the
   system certificate store as a trust anchor. User-added authorities are not trusted.
7. No client installs a permissive trust manager, hostname verifier, or URLSession
   authentication-challenge override.

### Development boundary

1. Staff iOS Simulator debug builds may use `http://localhost:8001` and
   `ws://localhost:8080`.
2. Staff iOS physical-device debug builds default to production. Overrides must be
   system-trusted HTTPS/WSS origins supplied as `HB_BASE_URL` and `HB_REVERB_URL`; arbitrary
   LAN cleartext is rejected.
3. Staff Android debug builds may use cleartext only for `localhost`, `127.0.0.1`, and the
   Android emulator host alias `10.0.2.2`. The base policy remains cleartext-deny.
4. Patient apps use HTTPS even in debug. Synthetic UI tests do not need a cleartext
   exception.
5. A Release simulator/emulator is a release artifact for policy purposes; it never receives
   a debug transport exception.

### Certificate pinning

**Do not ship static certificate or public-key pins in this tranche.**

This is an explicit risk decision, not an omission. The current controls use platform
hostname verification, ATS/Network Security Configuration, the system trust store, and
short-lived public certificates. Static pinning is deferred because the repository has no
evidence of all prerequisites needed to avoid a self-inflicted outage:

- two independently controlled, concurrently valid backup keys or issuing identities;
- an automated overlap/rotation process exercised before the active pin expires;
- a forced-upgrade and mobile kill-switch path that can recover clients with stale pins;
- production-like iOS and Android rotation drills, including offline/stale-app cohorts;
- named 24x7 ownership and an incident runbook for pin mismatch;
- monitoring that distinguishes PKI failure from API/realtime failure;
- an approved mobile release cadence that is shorter than the emergency recovery window.

Pinning may be reconsidered only when those controls are implemented and verified. If
adopted, it must use at least one active and one backup public-key identity, cover API and
WSS consistently, have a bounded expiration/review policy, and fail closed without
silently reverting to unpinned trust.

## Verification controls

The repository must continuously prove:

- source project files contain no release ATS exception;
- both Android products bind `networkSecurityConfig`, deny cleartext in the main policy,
  and trust only system roots;
- the staff Android debug override permits only the three declared loopback/emulator hosts;
- production URL and WSS policies reject scheme, host, port, credential, path, query, and
  fragment substitution;
- every API and realtime transport refuses redirects at runtime; release builds cannot
  inject an ungoverned iOS session, while Android rebuilds an injected HTTP client with
  HTTP and HTTPS redirect following disabled;
- patient production configuration rejects a non-Zephyrus hostname;
- no source introduces static pins or permissive trust hooks without changing this ADR and
  its verifier;
- Debug and Release native applications build, both platform unit suites pass, and the
  focused emulator/simulator journeys pass in CI;
- built Release Android manifests and their compiled Network Security Configurations, plus
  Release iOS Info.plists and executable origin strings, are inspected rather than inferred
  only from source.

The executable repository verifier is
`scripts/verify-hummingbird-transport-security.php`. Platform unit tests pin the runtime
allow/deny matrices. Native CI inspects the packaged release configuration.

## Consequences

### Positive

- Release endpoint substitution and cleartext downgrade fail before a request is sent.
- A bearer credential or request body is never replayed automatically to a redirect
  destination.
- The same policy covers HTTP and realtime transport.
- Debug convenience is isolated to emulator/loopback origins.
- Normal certificate renewal does not require a mobile release.
- A future pinning proposal has explicit operational acceptance criteria.

### Residual risk

- A publicly trusted CA compromise or incorrect issuance for the exact production hostname
  remains in scope until certificate transparency/pinning or an equivalent managed control
  is approved.
- System trust behavior depends on supported OS security updates.
- A compromised production DNS/TLS endpoint with a valid certificate remains a server-side
  incident scenario.

These residual risks must be tracked in the product threat model and incident-response
plan; this ADR does not close those broader checklist items.
