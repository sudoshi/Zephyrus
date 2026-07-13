# Admin Credential and Network Governance Runbook

**Status:** Branch implementation procedure; production enablement requires institutional approval and release evidence.

**Scope:** Secret-provider bootstrap, secret-safe credential references, immutable credential versions, certificate validation, dual-control rotation, and governed outbound routes for healthcare integrations.

## Non-negotiable controls

- Store only provider references in Zephyrus. Never place a secret, token, private key, certificate body, patient payload, or bootstrap credential in a request reason, audit field, source configuration, log, screenshot, or support ticket.
- Use a provider-owned immutable version. A provider response without a version makes credential readiness fail closed. Avoid `latest` aliases for production configuration even when a provider accepts them.
- Configure least-privilege provider bootstrap credentials outside the repository. The Admin console reports only whether a provider is configured.
- Select the exact Admin organization, facility, and source scope before changing an endpoint, credential, route, or governed request.
- Production source activation and credential rotation require recent step-up authentication, an independent approver, an unexpired request, and an exact payload match.
- Every production HTTPS connection requires a non-retired route for the exact source host and port. Target and proxy DNS are re-resolved and pinned on every connection; redirects are disabled.
- Zephyrus stores approved CIDR policy, but stores observed DNS addresses only as counts and SHA-256 fingerprints. Operators must use approved infrastructure tooling—not the Admin database—to investigate the resolved addresses.

## Supported reference forms

| Provider | Reference form | Bootstrap and allowlist |
|---|---|---|
| Sealed file | `file:///etc/zephyrus/secrets/epic/private-key.pem` | `INTEGRATION_SECRET_FILE_ROOT`; file provider enabled; guarded owner/group/mode/size/path |
| Vault KV | `vault://clinical-secrets/epic/backend#private_key` | HTTPS Vault address, deployment token, optional namespace, KV version, exact allowed mounts |
| AWS Secrets Manager | `aws-secretsmanager://us-east-1/zephyrus/epic#client_secret` | Access key/secret and optional session token; exact allowed regions; SigV4 |
| GCP Secret Manager | `gcp-secretmanager://zephyrus-prod/epic-key/5` | Access token or guarded service-account file; exact allowed projects |
| Azure Key Vault | `azure-keyvault://zephyrus-prod-vault/epic-key/0123456789abcdef0123456789abcdef` | Access token or tenant/client/service-principal secret; exact allowed vaults |

The optional fragment selects a scalar field from a JSON secret. References reject embedded credentials, query strings, traversal, control characters, and unsafe selectors. File and GCP bootstrap files must resolve beneath `INTEGRATION_SECRET_FILE_ROOT`, must not be symlinks, must be bounded by `INTEGRATION_SECRET_MAX_BYTES`, and must satisfy the runtime owner/group/mode checks.

## Provider bootstrap

1. Choose one provider for the integration. Prefer the institution's existing managed secret service; use sealed files only for an approved single-host operating model.
2. Grant the runtime identity read access only to the named secret versions required by the selected source. Grant no list, write, delete, policy, or administrative capability unless the institutional secret platform makes it unavoidable and the exception is documented.
3. Set the relevant `INTEGRATION_*` variables from `.env.example`. Keep every allowed mount, region, project, or vault list exact.
4. Clear Laravel configuration through the normal deployment workflow. Do not echo or inspect secret-bearing environment values in release evidence.
5. Open Integrations → Credentials → Provider, Rotation, and Certificate Authority. Confirm only the intended provider reports **Provider configured**.
6. Add the provider URI, validity/expiry/rotation dates, owner, and a non-sensitive change reason. Do not activate a production source yet.
7. Select **Validate**. Readiness must report a provider version and no failed requirement. For a leased provider, the lease must extend beyond the evaluated activation time.

Provider bootstrap currently supports Vault tokens, AWS access/session credentials, GCP access tokens or service-account files, and Azure access tokens or service principals. It does not claim AWS IAM-role credential discovery, GCP workload identity, Azure managed identity, or Vault agent/socket authentication. Add and validate those adapters before an institution requires them.

## Credential and certificate readiness

Credential validation evaluates the immutable current version at the requested time and records an append-only observation. It checks:

- authority state, valid-from, expiry, and rotation deadline;
- provider availability, immutable provider version, lease, and provider-managed expiry;
- certificate PEM structure, chain metadata, leaf validity, subject, SAN, issuer, SHA-256 fingerprint, key usages, and signature type;
- JWKS URL policy when configured;
- resolvable certificate and private-key references plus matching key pair for `mtls` credentials.

The console may show certificate metadata, version identifiers, dates, requirement codes, and fingerprints. It must never display resolved secret values or PEM bodies. The rotation state becomes `due_90`, `due_60`, `due_30`, `due_14`, or `due_7` as the earliest rotation/expiry deadline approaches. These are operator states, not delivered alerts; alert acknowledgement and escalation belong to INT-OBS.

## Dual-control credential rotation

1. Create a new immutable version in the external provider. Keep the old provider version available for the bounded overlap.
2. In Integrations → Credentials, select **Request rotation** for the exact source credential.
3. Enter only changed references and dates. Set `valid_from`, `expires_at`, `rotates_at`, and `rotation_overlap_ends_at` deliberately; do not create an open-ended overlap.
4. Enter a 10–500 character non-sensitive reason and complete step-up authentication if requested.
5. A different authorized operator selects the exact organization/facility/source boundary, inspects the request, records an independent rationale, and approves or rejects it after their own step-up.
6. After approval, re-enter exactly the approved target fields in the Governed Change Ledger and select **Execute exact approved rotation**. Any omitted, added, or different field fails the payload hash check.
7. Validate the new current version and run protocol health. Confirm the provider version, lease/expiry, certificate/key match when applicable, and an actual sandbox transaction.
8. Keep previous-version fallback only until `rotation_overlap_ends_at`. Remove or revoke the old provider version after the partner confirms cutover and Zephyrus has no dependent use.
9. Record the partner ticket/evidence reference through the source evidence workflow; do not paste keys, certificates, tokens, or confidential documents into the audit reason.

Revocation is terminal for the selected authority version. Credential removal in Admin requires a reason and records a revoked state rather than deleting its immutable history.

## Governed outbound network route

Create a route only after the source endpoint exists.

1. Open Integrations → Credentials → Outbound Network Authority and choose **Add route**.
2. Select the exact endpoint. Confirm the derived hostname, port, and TLS server name against the partner implementation guide.
3. Select the transport classification: `public_internet`, `vpn`, `private_link`, `direct_connect`, or `interface_engine`. This records and enforces route authority; it does not provision the external tunnel, circuit, firewall, or private endpoint.
4. Select the DNS policy:
   - `public_only`: every resolved address must be public;
   - `allowlist`: every resolved address must match an entered IPv4 or IPv6 CIDR;
   - `private_only`: every address must be private and must match an entered CIDR.
5. Enter the exact CIDRs required by the contract. Never use `0.0.0.0/0` or `::/0` as a convenience allowlist.
6. If an approved HTTPS proxy is required, enter its HTTPS URL. Zephyrus separately validates, re-resolves, and pins the proxy.
7. Enter the egress policy key that maps to the institution's firewall/change authority.
8. For client mTLS, select **Require mTLS** and an active same-source `mtls` credential containing matching certificate and private-key references.
9. Enter a non-sensitive change reason and select **Validate and save route**. Only one non-retired route may own an endpoint.
10. Confirm `validated`, a non-zero resolved-address count, and a policy fingerprint. The stored observation contains hashes, not addresses.
11. Validate the external firewall/tunnel/private-link/proxy configuration independently. Zephyrus transport labels do not prove that infrastructure exists.
12. Revalidate after partner DNS, certificate, proxy, firewall, or route changes. Connection-time validation still fails closed if DNS rebinding or policy drift occurs after a successful console validation.

Normal TLS CA and hostname verification remains active. Explicit partner CA bundles, certificate/SPKI pins, or other server-peer trust policy are not yet modeled; keep production activation blocked when a trading-partner contract requires those controls.

## Failure codes and response

| Code family | Meaning | Operator response |
|---|---|---|
| `credential_provider_not_configured` | Scheme exists but provider bootstrap is unavailable | Configure the intended provider outside Git; confirm the provider readiness card |
| `credential_*_not_allowed` | Mount, region, project, vault, path, or reference violates allowlist policy | Correct the reference or approved allowlist; do not widen globally |
| `credential_*_http_*` / `credential_*_token_*` | Provider authentication or request failed | Inspect provider-side audit logs and runtime identity; never log the token |
| `credential_*_version*` | Provider did not return a stable immutable version | Pin a real provider version and revalidate |
| `credential_certificate_*` / `credential.mtls_*` | Certificate is invalid/expired or the key pair does not match | Provision a valid chain and matching private key as separate references |
| `network_route_required` | A production connection has no governed exact route | Configure, validate, and approve the endpoint route before retrying |
| `network_*_address_required` / `network_address_not_allowlisted` | DNS result violates public/private/CIDR policy | Reconcile partner DNS with the approved network contract |
| `network_proxy_*` | Proxy URL or DNS failed validation | Correct the approved HTTPS proxy and its DNS/firewall path |
| `network_mtls_credential_not_ready` | Client mTLS authority is invalid at evaluation time | Validate or rotate the selected same-source mTLS credential |
| `network_dns_pinning_unavailable` / `network_mtls_blob_options_unavailable` | Runtime cURL lacks a required safe transport feature | Block activation and repair the PHP/cURL runtime |

After a failure, preserve the opaque request/correlation ID and sanitized error code. Use the provider audit trail, approved DNS tooling, firewall logs, and Zephyrus append-only observations to diagnose. Do not copy raw response bodies or secrets into a ticket.

## Production evidence gate

Before the first institutional source is approved, attach or reference evidence for:

- provider bootstrap identity, least-privilege policy, allowed scopes, version pinning, rotation and revocation drill;
- certificate chain and partner mTLS requirements, including explicit server-peer policy when contractually required;
- exact source endpoint, DNS/CIDRs, proxy/tunnel/private-link/firewall configuration, and egress change ticket;
- successful configuration-time and connection-time validation, protocol health, synthetic transaction, and bounded rotation-overlap test;
- backup/restore and disaster-recovery handling for credential authority and observations without backing up secret values from the provider;
- independent approval and exact-payload execution evidence;
- confirmation that logs, failed jobs, audit records, screenshots, and exported evidence contain no secret, token, PEM body, resolved DNS address, or PHI; approved CIDR policy remains visible to authorized administrators.

Production remains blocked if any requirement is missing, expired, mismatched, or only asserted by a console label without external evidence.
