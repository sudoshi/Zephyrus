# Parthenon Administrator Port Plan for Zephyrus

**Date:** 2026-07-10  
**Status:** In progress  
**Current release:** Administration foundation and user audit trail implemented  
**Authority:** This document governs Parthenon Administrator capability ports into Zephyrus.

## 1. Objective

Port the useful administration patterns from Parthenon into a Zephyrus-native control surface without importing Parthenon's research-platform domain model.

Parthenon administers a clinical research and evidence platform. Its administrator surfaces center on studies, vocabularies, OHDSI services, cohort tooling, research data sources, and specialized research infrastructure.

Zephyrus administers a hospital operations platform. Its administrator surfaces must center on:

- workforce access and identity;
- operational accountability;
- cockpit and workflow governance;
- facility and staffing readiness;
- healthcare integration reliability;
- Eddy provider governance;
- platform health needed to sustain hospital operations.

The implementation may reuse interaction patterns, safeguards, and lifecycle controls from Parthenon. It must not copy research-specific concepts merely because they exist in the sister project.

## 2. Product Boundary

### Zephyrus administration owns

- Human users, roles, active state, authentication posture, and identity linkage.
- Login, logout, failed-login, lockout, page-access, and state-change accountability.
- Cockpit threshold governance.
- Enterprise facility, service-line, and staffing configuration.
- Hummingbird token access and mobile authentication lifecycle.
- Authentication-provider configuration.
- Eddy provider profiles, routing policy, usage governance, and knowledge review.
- Integration control-plane access, connector readiness, and FHIR operational status.
- Platform health signals that affect operational availability.

### Existing domain ledgers retain ownership

- `integration.configuration_audits` remains the detailed connector-configuration ledger.
- `ops.operational_events` remains the operational and mobile activity ledger.
- Transport, staffing, patient-flow, and other domain event tables remain authoritative for clinical operations.
- The user audit ledger stores a PHI-free accountability summary and must not absorb domain payloads.

### Zephyrus does not inherit by default

- OHDSI Atlas migration.
- OHDSI WebAPI registry administration.
- OMOP vocabulary import and release administration.
- Solr administration.
- Honest Broker workflows.
- Research mapping-review queues.
- GIS research imports.
- PACS administration.
- Research FHIR bulk export.
- LiveKit research-session configuration.
- Chroma vector-store studio tools.

These capabilities require an independent Zephyrus use case before implementation.

## 3. Parthenon Administrator Inventory

| Parthenon section | Parthenon purpose | Zephyrus disposition | Zephyrus destination |
|---|---|---|---|
| Administrator dashboard | Entry point and status summary | Port and redesign | `/admin` operations administration |
| Users | Account lifecycle | Port first | Existing `/users`, linked from `/admin` |
| User audit | Login and feature access history | Port first and exceed | `/admin/user-audit` append-only accountability |
| Roles | Role and permission management | Adapt after capability contract | Future `/admin/roles` |
| Authentication providers | OIDC/provider settings | Port | Future page backed by existing auth-provider API |
| AI providers | Provider configuration | Adapt to Eddy | Future `/admin/ai-providers` |
| AI agents | Research-agent settings | Do not copy directly | Eddy agent governance where operationally justified |
| Abby provider policy | Abby routing and subscription policy | Adapt to Eddy | Eddy provider profiles and surface policies |
| System health | Service diagnostics | Port and redefine | Future `/admin/system-health` |
| FHIR connections | Research-source connections and sync | Merge into existing control plane | `/integrations` and admin integration APIs |
| FHIR sync dashboard | Research sync monitoring | Adapt | Integration health and FHIR run monitoring |
| Library | Research asset governance | Pattern only | Improvement/knowledge governance where needed |
| Notifications | Administrative notification controls | Defer | Platform notification policy release |
| Atlas migration | OHDSI Atlas migration | Reject current scope | None |
| WebAPI registry | OHDSI service registry | Reject current scope | None |
| Vocabulary | OMOP vocabulary operations | Reject current scope | None |
| Solr | Research search administration | Reject current scope | None |
| Honest Broker | Research identity separation | Reject current scope | None |
| Mapping review | Research data-mapping queue | Reject current scope | None |
| GIS import | Research geography import | Reject current scope | None |
| PACS connections | Imaging-research connectivity | Defer pending operations use case | None |
| FHIR export | Bulk research export | Reject current scope | None |
| LiveKit | Research collaboration runtime | Reject current scope | None |
| Chroma Studio | Vector-store inspection | Keep inside Eddy engineering tools | No general administrator page |

## 4. Delivered: Release A and B

### 4.1 Administration shell

- Added `/admin` as the Zephyrus Administration overview.
- Kept Administration out of the primary altitude navigation.
- Added Administration Overview and User Audit to the user menu and command palette.
- Reused the canonical navigation source in `resources/js/config/navigationConfig.ts`.
- Added explicit `viewAdministration` and `viewUserAudit` capabilities.
- Preserved the stricter, independent gates for integrations and deployment configuration.
- Removed the unused generated `users.show` route.

The dashboard reports:

- total and active users;
- privileged users;
- users requiring a password change;
- successful and failed logins today;
- active users in the last seven days;
- recent accountability events;
- availability of user, audit, Cockpit, enterprise, staffing, and integration sections.

### 4.2 Append-only user audit ledger

Added `audit.user_events` with:

- bigint internal cursor and UUIDv7 public identifier;
- `timestamptz` occurrence and recording timestamps;
- actor user ID, username snapshot, and role snapshot;
- HMAC references for attempted principals and sessions;
- namespaced action, category, outcome, and reason code;
- authentication method and source surface;
- PHI-free target type and identifier;
- route name and route URI template, never the concrete request URL;
- method and response status;
- request UUID, client IP, and bounded user agent;
- allowlisted change and metadata JSON;
- PostgreSQL indexes for the administrator query paths;
- a PostgreSQL trigger rejecting update and delete.

### 4.3 Authentication coverage

| Event | Web password | OIDC | Demo session | Hummingbird |
|---|---:|---:|---:|---:|
| Successful login | Yes | Yes | Yes | Yes |
| Failed login | Yes | Yes | N/A | Yes |
| Inactive-account denial | Yes | OIDC reconciliation path | N/A | Yes |
| Rate-limit lockout | Yes | Provider managed | N/A | Endpoint throttle |
| Logout or token revoke | Yes | Web logout | Yes | Yes |
| Token refresh | N/A | N/A | N/A | Yes |
| Password-change challenge | Web redirect state | N/A | N/A | Yes |
| Password change | Auth event or request audit | Auth event or request audit | N/A | Yes |
| Registration | Yes | JIT identity creation represented by login | N/A | N/A |

Web password authentication now denies inactive accounts. The failure response remains generic to avoid account enumeration.

### 4.4 Activity coverage

The global audit middleware records:

- every authenticated user-facing page visit;
- every authenticated `POST`, `PUT`, `PATCH`, and `DELETE` request;
- outcome derived from response status;
- route templates rather than request paths;
- no request body or query-string values.

It intentionally does not record repeated authenticated GET/HEAD API polling. Those reads would flood the accountability view and are already represented by page access and domain telemetry.

Explicit domain audit events replace the generic mutation record for:

- user creation, update, and deletion;
- authentication-provider update;
- Cockpit KPI threshold update;
- Hummingbird authentication lifecycle.

User and configuration mutations write their audit event in the same database transaction. An audit insertion failure therefore rolls back the privileged change.

### 4.5 Administrator audit experience

The `/admin/user-audit` page includes:

- total events, logins today, failed logins today, and seven-day active-user metrics;
- server-side search across actor identity, action, route, URI template, and IP;
- action, category, outcome, authentication-method, and date filters;
- deterministic pagination capped at 100 rows;
- actor, time, event, outcome, source, and IP columns;
- expandable route, status, target, user-agent, change, and metadata detail;
- frontend defense-in-depth redaction for sensitive metadata keys;
- responsive desktop and mobile layouts without document overflow.

## 5. Privacy and Security Contract

The user audit ledger must never store:

- passwords or password confirmations;
- bearer, refresh, change-password, machine, or Eddy tokens;
- OIDC authorization codes, ID tokens, claims, nonce, state, or client secrets;
- cookies or authorization headers;
- request bodies;
- MRNs, patient identifiers, encounter identifiers, or clinical notes;
- arbitrary before/after model snapshots;
- exception messages that may contain upstream payloads.

Allowed administrator change data is limited to machine-safe fields such as role, active state, password-change state, provider enabled state, and numeric Cockpit threshold values.

Attempted login identifiers and session IDs are stored only as domain-separated HMAC values. The actor username snapshot is retained because Zephyrus currently permits hard user deletion and an immutable audit event must remain attributable afterward.

## 6. Remaining Releases

### Release C: Authentication-provider page

- Build `/admin/auth-providers` as an Inertia page.
- List local password and OIDC availability without exposing secrets.
- Edit display label, enabled state, issuer/discovery settings, scopes, allowed groups, and administrator groups.
- Add discovery and connection tests with bounded timeouts.
- Record every provider change in `audit.user_events` and retain provider-specific diagnostics.
- Resolve existing-user OIDC group-removal behavior before calling the surface complete.

### Release D: Eddy provider governance

- Build `/admin/ai-providers` around Zephyrus `EddyProviderPolicyService`.
- Use Eddy naming throughout; do not expose Abby labels.
- Manage provider profiles, model selection, capability readiness, and active state.
- Manage surface policies and fallback order.
- Provide dry-run routing simulation.
- Display cost and usage summaries without prompt or patient content.
- Preserve guarded archive/delete behavior for referenced profiles.
- Audit policy changes, simulations, archive, and delete decisions.

### Release E: System health

- Build `/admin/system-health` and `/admin/system-health/{key}`.
- Cover database, queue, scheduler, cache, broadcast, Eddy, integration runtime, and configured external dependencies.
- Separate health observations from configuration writes.
- Expose last observation, latency, freshness, degraded reason, and operator-safe remediation context.
- Do not copy Parthenon service names or research infrastructure checks.
- Audit administrator-triggered diagnostics without recording service secrets.

### Release F: Integration and FHIR administration

- Extend the existing `/integrations` control plane instead of creating a parallel FHIR administrator product.
- Add connection lifecycle views, capability discovery, health history, polling history, replay controls, and dead-letter governance.
- Keep configuration details in `integration.configuration_audits`.
- Emit a PHI-free user-accountability summary for administrator-triggered changes and commands.
- Preserve the stricter `viewIntegrations` and `manageIntegrations` gates.

### Release G: Roles and capabilities

- Establish one canonical role normalization contract before adding a role editor.
- Reconcile scalar `users.role`, Spatie roles, Laravel gates, mobile personas, and Sanctum abilities.
- Define capabilities around hospital responsibilities, not Parthenon research roles.
- Prevent self-demotion and loss of the last privileged administrator.
- Require a reason for privilege changes.
- Audit role membership, capability grants, and revocation.

### Release H: User lifecycle modernization

- Replace hard delete as the normal administrator action with deactivation.
- Add session and token revocation.
- Surface local versus OIDC identity source.
- Add last successful login and last meaningful activity from the audit ledger.
- Add protected-account rules for the bootstrap administrator.
- Link workforce identity to facility, service line, unit, and staffing role where data is authoritative.

## 7. Validation Standard

Every administration release must include:

- capability and non-capability authorization tests;
- scalar-role and supported Spatie-role parity tests;
- migration and rollback checks;
- secret and PHI leakage tests;
- append-only mutation rejection where a ledger is involved;
- transaction rollback tests for privileged changes;
- server-side filter and deterministic pagination tests;
- focused React interaction tests;
- production frontend build;
- desktop and mobile runtime screenshots;
- route-table verification;
- `git diff --check` and Laravel Pint.

## 8. Deployment Requirements

The application code requires:

```bash
php artisan migrate --force
npm run build
```

Production deployment remains manual through `./deploy.sh`. GitHub Actions must not deploy Zephyrus.

The new audit migration must be present before the administrator pages are exposed. Authentication uses best-effort audit writes to preserve hospital access during an audit-store outage, but privileged configuration changes are transactionally fail-closed.

## 9. Next Implementation Target

Proceed with Release C and Release D in this order:

1. Add the authentication-provider page over the existing secret-safe API.
2. Correct existing-user OIDC group enforcement and add provider diagnostics.
3. Add the Eddy provider-policy page using the existing Zephyrus policy service.
4. Audit all provider and policy lifecycle changes.
5. Then implement system health before expanding role management.

This order makes identity and AI governance visible before adding broader platform controls, while preserving the user audit trail as the accountability foundation for every later release.
