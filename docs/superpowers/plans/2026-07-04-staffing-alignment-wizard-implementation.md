# Staffing Alignment Wizard — Implementation Plan

**Date:** 2026-07-04
**Status:** Proposed implementation plan
**Scope:** A meticulous, admin-only wizard that integrates external staffing systems (HRIS / scheduling / credentialing / identity / EHR provider master) and resolves every staff member to `facility × service line × role × unit` — with human-in-the-loop review, confidence, and evidence — then provisions Zephyrus operational role/workflow **additively**, without ever modifying the protected authentication system.
**Parent plan:** `docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md` (this is the standalone deep-dive for that plan's §11 + Phase 7 + Phase F4). The parent owns the service-line registry, IDN geography, capability matrix, and facility-space layers this wizard consumes.
**Research baseline:** `docs/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md`
**Protected-auth constraint:** `.claude/rules/auth-system.md` — every change here is additive; the registration / login / `/change-password` / temp-password + Resend flow / `must_change_password` / `admin@acumenus.net` superuser are untouched.

---

## 1. Objective

The parent plan's capability matrix (`hosp_org.facility_service_capabilities`) says **what a facility can do**. This wizard answers **who does it, and where** — it resolves each staff member to one or more `facility × service line × role × unit` memberships, so Zephyrus can:

- populate service-line and unit coverage (staffed vs unstaffed) for RTDC / ED / periop / command surfaces;
- drive role-appropriate operational access (workflow + permissions) without touching the auth role of record;
- keep membership current as staffing systems change, with drift detection and a termination sweep.

It does this by ingesting external staffing sources through pluggable connectors, running a layered resolution engine (override → deterministic rule → heuristic → unmatched), routing everything that is not high-confidence-deterministic to a review queue, and committing only what an admin approves — with full provenance.

### 1.1 Where this sits relative to the auth system

Zephyrus already has a production-deployed auth system (`.claude/rules/auth-system.md`): accounts are created via the "Create Account" flow, a 12-char temp password is emailed via Resend, and `must_change_password` forces a password change on first login. `prod.users.role` is the **auth role of record**.

This wizard adds a parallel **operational assignment graph** (`hosp_org.staff_assignments`) on top of that account. It links to `prod.users` by nullable FK when an app account exists, and — only on explicit admin commit — layers operational role/workflow + `is_active` onto the linked account. It never creates accounts, never sets passwords, never changes the login/registration path, and never alters the superuser. See §11.

### 1.2 Non-goals

- **No account creation.** The wizard resolves and provisions *operational access* for people who already have (or later get) accounts via the protected flow. It does not mint accounts or passwords.
- **No scheduling engine.** It ingests shift/on-call signal to derive `coverage_model`; it does not build rosters or manage shifts.
- **No PHI.** Staff identity is PII, not PHI. No patient data enters this layer.

---

## 2. Design principles

1. **Additive to auth, never a replacement.** Per `.claude/rules/auth-system.md`, the wizard does not touch registration, login, `/change-password`, the temp-password/Resend flow, `must_change_password`, or the `admin@acumenus.net` superuser. It writes an *operational* layer (`hosp_org.staff_assignments`) and, on explicit commit, sets operational role/workflow + `is_active` on a linked account. `prod.users.role` remains the auth role of record.
2. **Multi-membership is normal.** A hospitalist can be `hospital_medicine` (primary) + `critical_care` (co-management); an intensivist `critical_care` + a specific ICU unit; a float nurse spans units. Modeled as multiple `staff_assignments`, exactly one `primary_flag` per person.
3. **Nothing commits without provenance.** Every assignment carries `confidence`, `resolution_source` (override / rule / heuristic / imported), `evidence` (source field + rule id + matched value), and `review_status`. Regulated roles (trauma attending, transplant surgeon) cannot reach `source_verified` without explicit reviewer confirmation and a credentialing/EHR-master evidence class.
4. **The wizard learns.** A reviewer decision can be promoted to a `staff_mapping_rule`, shrinking the review queue on the next sync.
5. **Reuse the integration spine.** Transport + secrets live in `integration.sources` / `raw.inbound_messages` / `integration.canonical_events`; FHIR `Practitioner`/`PractitionerRole` uses the existing `fhir` schema. `hosp_org.staffing_sources` stores only a reference + a reusable field-mapping template — never secrets.
6. **PII-minimized.** Staff name/email are PII (not PHI); stored minimally in `hosp_org.staff_members`, RBAC-gated (`manageDeploymentConfig`), and connector secrets are never sent to the browser.
7. **Reversible and auditable.** Assignments are effective-dated and soft-deactivated, never hard-deleted. Every commit is an auditable `staff_import_run` + `staff_mapping_reviews` trail (who / when / why).

---

## 3. Data Model

All staffing tables ship in one migration, `2026_07_04_000150_create_staffing_alignment_tables.php`, following the raw-SQL `DB::unprepared` idiom of `2026_06_25_000010_create_facility_blueprint_model_tables.php` (schema-qualified names, `CREATE SCHEMA IF NOT EXISTS`, `IF NOT EXISTS` on tables/columns/indexes, idempotent FK blocks). It depends on the parent plan's Phase 0 (`hosp_ref.service_lines`, `hosp_ref.programs`) and Phase 1 (`hosp_org.organizations`, `hosp_org.facilities`) migrations having run first.

Staff → service-line/role assignment is modeled additively and **never touches the protected auth system**: `prod.users.role` and the temp-password/`must_change_password`/Resend login flow are untouched. This layer adds an *operational* assignment graph on top of the existing account, and links to `prod.users` by nullable FK when an app account exists.

```sql
-- 2026_07_04_000150_create_staffing_alignment_tables.php

-- Role taxonomy (reference; authored in config/hospital/staff-roles.php, projected here)
CREATE TABLE IF NOT EXISTS hosp_ref.staff_roles (
  role_code            text PRIMARY KEY,          -- intensivist|hospitalist|charge_nurse|staff_nurse|resp_therapist|pharmacist|case_manager|transport_tech|...
  display_name         text NOT NULL,
  role_category        text NOT NULL,             -- physician|apn_pa|nursing|allied_health|ancillary|support|leadership
  is_provider          boolean NOT NULL DEFAULT false,
  is_nursing           boolean NOT NULL DEFAULT false,
  is_clinical          boolean NOT NULL DEFAULT true,
  default_workflow     text,                       -- rtdc|ed|periop|emergency|improvement|command|none
  default_app_permissions text[] NOT NULL DEFAULT '{}',
  sort_order           integer NOT NULL DEFAULT 100,
  metadata             jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Connector configuration for external staffing systems (reuses integration.sources for transport/secrets)
CREATE TABLE IF NOT EXISTS hosp_org.staffing_sources (
  staffing_source_id bigserial PRIMARY KEY,
  organization_id    bigint REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
  integration_source_id bigint,                    -- FK into integration.sources (do NOT duplicate secrets/transport)
  source_key         text UNIQUE NOT NULL,         -- WORKDAY_PROD|QGENDA|AMION|EPIC_PROVIDER|ENTRA_SCIM|CSV_UPLOAD
  connector_type     text NOT NULL,                -- hris|scheduling|credentialing|identity|ehr_master|on_call|manual
  transport          text NOT NULL,                -- rest_api|sftp|scim|hl7_mfn|fhir_practitioner|db_view|file_upload
  mapping_template   jsonb NOT NULL DEFAULT '{}'::jsonb, -- saved source-field -> canonical-field mapping (reused per sync)
  sync_schedule      text,                          -- cron expr; null = manual only
  is_active          boolean NOT NULL DEFAULT true,
  last_synced_at     timestamptz,
  metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- One wizard/import batch
CREATE TABLE IF NOT EXISTS hosp_org.staff_import_runs (
  staff_import_run_id bigserial PRIMARY KEY,
  staffing_source_id  bigint NOT NULL REFERENCES hosp_org.staffing_sources(staffing_source_id) ON DELETE CASCADE,
  status              text NOT NULL DEFAULT 'staged', -- staged|resolved|in_review|committed|failed|cancelled
  mapping_snapshot    jsonb NOT NULL DEFAULT '{}'::jsonb,
  counts              jsonb NOT NULL DEFAULT '{}'::jsonb, -- {new,updated,departed,auto_approved,needs_review,conflicts,unmatched}
  dry_run             boolean NOT NULL DEFAULT true,
  initiated_by        bigint,                         -- prod.users.id (admin who ran the wizard)
  started_at          timestamptz NOT NULL DEFAULT now(),
  completed_at        timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT staff_import_runs_status_chk CHECK (status IN ('staged','resolved','in_review','committed','failed','cancelled'))
);

-- Canonical staff identity (PII-minimized; links to app account when one exists)
CREATE TABLE IF NOT EXISTS hosp_org.staff_members (
  staff_member_id  bigserial PRIMARY KEY,
  staff_key        text UNIQUE NOT NULL,            -- stable dedupe key (source_system + external_id)
  source_system    text NOT NULL,
  external_id      text NOT NULL,
  user_id          bigint,                          -- nullable FK -> prod.users.id (app account), never required
  npi              text,
  license_no       text,
  display_name     text,
  email            text,
  employee_type    text,                            -- employed|contracted|locum|resident|fellow|student|agency
  employment_status text,                           -- active|leave|terminated|pending
  is_active        boolean NOT NULL DEFAULT true,
  first_seen_at    timestamptz NOT NULL DEFAULT now(),
  last_seen_at     timestamptz NOT NULL DEFAULT now(),
  metadata         jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (source_system, external_id)
);
CREATE INDEX IF NOT EXISTS idx_staff_members_user ON hosp_org.staff_members(user_id);
CREATE INDEX IF NOT EXISTS idx_staff_members_npi  ON hosp_org.staff_members(npi);

-- The core output: staff -> facility x service line x role x unit (multi-membership, effective-dated)
CREATE TABLE IF NOT EXISTS hosp_org.staff_assignments (
  staff_assignment_id bigserial PRIMARY KEY,
  staff_member_id   bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
  facility_key      text NOT NULL,
  service_line_code text NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
  role_code         text NOT NULL REFERENCES hosp_ref.staff_roles(role_code),
  program_code      text REFERENCES hosp_ref.programs(program_code),
  unit_id           bigint REFERENCES prod.units(unit_id) ON DELETE SET NULL,
  primary_flag      boolean NOT NULL DEFAULT false,
  coverage_model    text,                            -- in_house|on_call|tele|daytime|float
  fte               numeric(4,2),
  confidence        numeric(5,4),                    -- resolution confidence [0,1]
  resolution_source text,                            -- override|rule|heuristic|imported
  review_status     text NOT NULL DEFAULT 'assumed', -- assumed|source_verified|client_verified|unknown
  evidence          jsonb NOT NULL DEFAULT '{}'::jsonb, -- {source_field, rule_id, matched_value}
  effective_start   date,
  effective_end     date,
  decided_by        bigint,                          -- prod.users.id (reviewer)
  decided_at        timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (staff_member_id, facility_key, service_line_code, role_code, COALESCE(unit_id, 0)),
  CONSTRAINT staff_assignments_review_chk CHECK (review_status IN ('assumed','source_verified','client_verified','unknown'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_one_primary
  ON hosp_org.staff_assignments(staff_member_id) WHERE primary_flag;
CREATE INDEX IF NOT EXISTS idx_staff_assignments_facility_sl ON hosp_org.staff_assignments(facility_key, service_line_code, role_code);

-- Deterministic crosswalk rules the resolver applies (learned from reviewer decisions)
CREATE TABLE IF NOT EXISTS hosp_org.staff_mapping_rules (
  staff_mapping_rule_id bigserial PRIMARY KEY,
  staffing_source_id bigint REFERENCES hosp_org.staffing_sources(staffing_source_id) ON DELETE CASCADE, -- null = global
  match_field        text NOT NULL,                 -- cost_center|department|specialty|job_code|job_title|home_unit
  match_operator     text NOT NULL DEFAULT 'equals',-- equals|prefix|contains|regex
  match_value        text NOT NULL,
  target_service_line_code text REFERENCES hosp_ref.service_lines(service_line_code),
  target_role_code   text REFERENCES hosp_ref.staff_roles(role_code),
  target_unit_hint   text,
  priority           integer NOT NULL DEFAULT 100,  -- lower runs first
  confidence         numeric(5,4) NOT NULL DEFAULT 0.90,
  is_active          boolean NOT NULL DEFAULT true,
  created_by         bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_staff_rules_lookup ON hosp_org.staff_mapping_rules(match_field, is_active, priority);

-- Human decision log (audit + rule promotion)
CREATE TABLE IF NOT EXISTS hosp_org.staff_mapping_reviews (
  staff_mapping_review_id bigserial PRIMARY KEY,
  staff_import_run_id bigint NOT NULL REFERENCES hosp_org.staff_import_runs(staff_import_run_id) ON DELETE CASCADE,
  staff_member_id     bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
  proposed            jsonb NOT NULL DEFAULT '{}'::jsonb, -- resolver output
  final               jsonb NOT NULL DEFAULT '{}'::jsonb, -- reviewer's committed decision
  action              text NOT NULL,                 -- accept|edit|split|defer|reject|deactivate
  reviewer_id         bigint,                         -- prod.users.id
  note                text,
  promoted_to_rule_id bigint REFERENCES hosp_org.staff_mapping_rules(staff_mapping_rule_id),
  created_at timestamptz NOT NULL DEFAULT now()
);
```

New models: `App\Models\Reference\StaffRole`; `App\Models\Org\{StaffingSource,StaffImportRun,StaffMember,StaffAssignment,StaffMappingRule,StaffMappingReview}`; a `staffAssignments(): HasMany` relation on the existing `App\Models\User`. Secrets live in `integration.sources` (encrypted), never in `hosp_org.staffing_sources`, which stores only a reference.

### 3.1 Why an array/lookup mix, and why nullable `user_id`

- `hosp_org.staff_members.user_id` is **nullable by design**: staffing sources routinely list people who have no Zephyrus account yet (or never will — e.g. a locum who only appears in the schedule). Membership is authoritative independent of account existence; provisioning is a separate, later step that only fires when a linked account is present.
- `staff_assignments` uses a composite natural key (`staff_member_id, facility_key, service_line_code, role_code, COALESCE(unit_id,0)`) so re-sync upserts instead of duplicating. The partial unique index `uq_staff_one_primary` enforces "exactly one primary membership per person."
- `evidence` is `jsonb` so the resolver can record *why* it proposed an assignment (source field, matched value, rule id) without a rigid column-per-source schema.

---

## 4. Source systems and connectors

```php
// App\Services\Staffing\Contracts\StaffingConnector — mirrors the Connector Contract style in transport-operations plan
interface StaffingConnector {
    public function testConnection(): ConnectionResult;          // reachability + auth, no data
    public function discoverSchema(): array;                     // source field descriptors for auto-mapping
    public function pullStaff(PullWindow $window): iterable;      // yields RawStaffRecord (streamed)
    public function capabilities(): ConnectorCapabilities;        // incremental? on_call? credentials? push?
}
```

| Connector | System class | Transport | Notes |
| --- | --- | --- | --- |
| `RestApiConnector` (base) | HRIS: Workday, SAP SuccessFactors, Oracle HCM | REST + OAuth2 client-creds | employment, job code, cost center, FTE, term dates |
| `UkgKronosConnector` / `QgendaConnector` / `AmionConnector` | Scheduling / on-call | REST or SFTP | shift + on-call → `coverage_model` |
| `EpicProviderMasterConnector` | EHR provider + location master | REST/FHIR or DB view | NPI, department, `home_unit`, Epic dept ↔ `unit_id` |
| `Hl7StaffMasterConnector` | HL7v2 MFN^M02 (STF/PRA) | HL7 via `raw.inbound_messages` | legacy staff master feeds |
| `FhirPractitionerConnector` | FHIR R4 `Practitioner` + `PractitionerRole` | FHIR bundle | `PractitionerRole.specialty/location` → service line/unit |
| `ScimConnector` | Identity: Entra ID, Okta | SCIM 2.0 push | account lifecycle → `is_active`, group → role hint |
| `CredentialingConnector` | Medical staff office / privileging | SFTP/REST | privilege set → regulated-role verification |
| `CsvUploadConnector` | Manual | file upload | always available fallback; drives the wizard's zero-integration path |

**Ingestion path (reuse, do not duplicate):** every connector normalizes into `raw.inbound_messages` → `integration.canonical_events`, exactly as the transport-operations plan does. FHIR `Practitioner`/`PractitionerRole` uses the existing `fhir` schema. The connector's only staffing-specific output is a stream of `RawStaffRecord` value objects that the orchestrator stages.

### 4.1 `RawStaffRecord` and `PullWindow`

- `RawStaffRecord` — a normalized value object: `{external_id, source_system, display_name?, email?, npi?, license_no?, employee_type?, employment_status?, job_code?, job_title?, specialty?, department?, cost_center?, home_unit?, fte?, term_date?, raw: array}`. `raw` preserves the untouched source row for evidence/audit.
- `PullWindow` — `{since?: DateTimeImmutable, full: bool}`; connectors that report `capabilities().incremental === true` honor `since`, others do a full pull and let the orchestrator diff.

---

## 5. Resolution rules engine (`ServiceLineRoleResolver`)

Layered precedence, first match wins, each layer stamps confidence + evidence:

```text
1. Explicit override          per-person manual pin                       confidence 1.00  evidence=override
2. Deterministic rule         hosp_org.staff_mapping_rules (priority asc)  confidence=rule.confidence (≈0.9)
     match_field: cost_center | department | specialty | job_code | job_title | home_unit
     -> target_service_line_code + target_role_code + target_unit_hint
3. Heuristic fallback         title/specialty normalization + service-line default map    confidence 0.5–0.75
4. Unresolved                 no match -> bucket 'unmatched' (manual assignment required)
```

Role → workflow: `role_code` maps (via `hosp_ref.staff_roles.default_workflow` + `default_app_permissions`) to the Zephyrus workflow (`rtdc`/`ed`/`periop`/`emergency`/`improvement`/`command`) that provisioning applies — layered on, not replacing, the auth role.

### 5.1 Bucketing after resolution

The orchestrator sorts every resolved person into one of five buckets, which the Review Queue renders directly:

- **Auto-approved** — high-confidence deterministic hit (override or rule, `confidence ≥ auto_approve_threshold`, default `0.90`), non-regulated role.
- **Needs review** — heuristic hit, multi-candidate rule match, ambiguous specialty, or FTE/coverage anomaly.
- **Conflicts** — source proposal disagrees with an existing committed `staff_assignment` (e.g. moved cost center, role change).
- **Unmatched** — no rule and no heuristic hit; requires manual assignment.
- **Departed** — present in Zephyrus, absent from the source pull → candidate for deactivation after grace.

Regulated roles (trauma attending, transplant surgeon, etc.) are **never** auto-approved regardless of confidence; they always route to Needs review and require explicit reviewer confirmation plus a credentialing/EHR-master evidence class to reach `source_verified`.

---

## 6. Backend implementation (Phase 7 of the parent plan)

The meticulous staff → service-line/role layer. Backend tasks:

1. Add migration `2026_07_04_000150_create_staffing_alignment_tables.php` (§3) + models.
2. Author `config/hospital/staff-roles.php`; add `deployment:seed-staff-roles` (idempotent role taxonomy seed).
3. Add the `StaffingConnector` interface + implementations (`CsvUploadConnector`, `FhirPractitionerConnector`, `ScimConnector`, `Hl7StaffMasterConnector`, `RestApiConnector` base for Workday/UKG/QGenda/Amion/Epic) — ingest via `raw.inbound_messages` → `integration.canonical_events` (reuse; do not duplicate).
4. Add `App\Services\Staffing\StaffIdentityResolver` (dedupe + match to `prod.users` by email/NPI/employee_id) and `App\Services\Staffing\ServiceLineRoleResolver` (rule engine: override → `staff_mapping_rules` → heuristic, each with confidence + evidence).
5. Add `App\Services\Staffing\StaffImportOrchestrator` (stage → resolve → bucket → commit) writing `staff_import_runs`, `staff_members`, `staff_assignments`, `staff_mapping_reviews`.
6. Add `App\Services\Staffing\StaffProvisioningService` — on commit, set operational role/workflow + `is_active` on the linked account **additively** (never touches registration/login/`must_change_password`/temp-password flow; superuser `admin@acumenus.net` immutable).
7. Add `App\Services\Staffing\CoverageService` (staffed vs unstaffed units per service line) and `deployment:staffing-drift {source}` (source vs Zephyrus divergence + termination sweep with grace).
8. Add artisan `deployment:staffing-sync {source} {--commit}` for scheduled headless runs.

### 6.1 Service class responsibilities

- **`StaffIdentityResolver`** — collapses a `RawStaffRecord` onto a `staff_member` (`UNIQUE (source_system, external_id)`), then attempts an app-account link to `prod.users` by, in order: exact email, NPI, employee_id in `metadata`. A link is a *suggestion* with its own confidence; it is never auto-committed for regulated roles.
- **`ServiceLineRoleResolver`** — the §5 engine. Pure and deterministic given `(RawStaffRecord, rules snapshot)`; returns `ResolvedAssignment[]` (multi-membership) each with `confidence`, `resolution_source`, `evidence`.
- **`StaffImportOrchestrator`** — owns the `staff_import_run` lifecycle (`staged → resolved → in_review → committed`), writes buckets to `counts`, and is the only writer of `staff_assignments` on commit. Dry-run by default (`dry_run=true`); commit is a separate, explicit call.
- **`StaffProvisioningService`** — the **only** class that touches `prod.users`, and only additively: it sets an operational workflow/permission set and `is_active`, guarded so it can never write `password`, `must_change_password`, `email`, `username`, or the superuser row. See §11.
- **`CoverageService`** — reads `staff_assignments` × `hosp_org.facility_service_capabilities` × `prod.units` to report staffed/unstaffed units per service line (feeds parent Acceptance Criterion 12).

---

## 7. Wizard steps (admin surface, `Pages/Deployment/StaffingWizard.tsx`)

```text
Step 0  Scope            pick organization + facility(ies); confirm registry + role taxonomy seeded
Step 1  Connect source   choose connector + transport; enter creds (write-only); Test Connection; save source
Step 2  Field mapping    auto-discover columns -> canonical staff fields; confidence; save reusable template
Step 3  Import preview    dry-run: pull -> stage; identity resolution (match to prod.users + prior staff_members);
                          buckets new / updated / departed with match evidence
Step 4  Resolve           run rules engine -> proposed service_line + role + unit per person, with confidence
Step 5  Review & reconcile REVIEW QUEUE (the meticulous core):
                            - Auto-approved (high-confidence deterministic)
                            - Needs review (low confidence / multi-candidate / ambiguous specialty)
                            - Conflicts (source disagrees with an existing assignment)
                            - Unmatched (no rule -> manual assign)
                            - Departed (source-absent -> deactivate w/ grace)
                          per row: accept | edit | split (multi-assignment) | defer | reject | deactivate
                          + "promote to rule"; regulated roles require explicit confirm
Step 6  Facility binding  bind facility_key + unit_id + coverage_model + fte + effective window;
                          warn if capability_matrix[facility][service_line] = none/screen
Step 7  Approve & commit  final diff (created/updated/deactivated + provisioning deltas) -> commit;
                          writes staff_assignments + staff_mapping_reviews; StaffProvisioningService applies role/workflow additively
Step 8  Schedule & govern  recurring sync (cron); drift detection; termination sweep w/ grace; re-review triggers
```

---

## 8. API surface (admin-gated)

All under `routes/api.php`, controllers in `App\Http\Controllers\Api\Deployment\Staffing\`, middleware `['api','auth','throttle:60,1']`, gated by the `manageDeploymentConfig` ability (§11). JSON only; connector secrets are write-only and never returned.

- `POST /api/deployment/staffing/sources` / `POST .../sources/{id}/test` — create + test connector.
- `POST /api/deployment/staffing/sources/{id}/discover` — schema discovery for mapping.
- `POST /api/deployment/staffing/imports` — start a dry-run (`staff_import_run`).
- `GET  /api/deployment/staffing/imports/{run}` — staged results + buckets.
- `POST /api/deployment/staffing/imports/{run}/resolve` — run the rules engine.
- `PATCH /api/deployment/staffing/imports/{run}/reviews/{staffMember}` — record a human decision.
- `POST /api/deployment/staffing/imports/{run}/commit` — commit + provision.
- `POST /api/deployment/staffing/rules` — promote a decision to a rule.
- `GET  /api/deployment/staffing/coverage?facility=&service_line=` — coverage dashboard.
- `POST /api/deployment/staffing/schedule` — configure recurring sync.

---

## 9. Frontend implementation (Phase F4 of the parent plan)

The admin-only wizard. A `HeroUI`-stepper multi-step flow under `Pages/Deployment/StaffingWizard.tsx`, gated by `manageDeploymentConfig`. Respect the Token Canon in `CLAUDE.md`: Figtree via `font-sans`, weights 400/500/600 only (no `font-bold`), `healthcare-*` tokens with `dark:` pairs (no raw Tailwind palette), `Components/ui/Surface` (`Card`/`Panel`), `shadow-sm` resting, gold `:focus-visible`, dark-default. Status by icon+label, never color alone.

- `resources/js/features/staffing/{types,api,hooks}.ts` — typed `StaffingSource`, `FieldMapping`, `StaffImportRun`, `ResolutionBucket`, `StaffAssignmentDraft`, `CoverageCell`.
- `SourceConnectStep` — connector picker + transport config + **Test Connection** (secrets never rendered back; write-only fields).
- `FieldMappingStep` — auto-detected source columns → canonical staff fields grid with confidence + saved template reuse.
- `ImportPreviewStep` — dry-run staging summary (new / updated / departed) with identity-match evidence.
- `ResolutionReviewStep` — the centerpiece **Review Queue**: buckets (Auto-approved / Needs review / Conflicts / Unmatched / Departed); per-row accept / edit / split (multi-assignment) / defer / reject / deactivate; "promote to rule" affordance; regulated roles require explicit confirm.
- `FacilityBindingStep` — bind facility_key + unit + coverage + FTE + effective window; capability-matrix validation warnings.
- `CommitStep` — final diff (assignments created/updated/deactivated, provisioning deltas) + explicit commit.
- `CoverageDashboard` + `ScheduleSyncPanel` — post-commit coverage-by-service-line/unit and recurring-sync config.
- Token canon: `tabular-nums` for counts/FTE/confidence; status by icon+label; ration color (coral only for regulated-role-without-evidence or a hard conflict); dark-default; no `font-bold`.

---

## 10. Testing and validation

Backend (`php artisan test`):
- `--filter=StaffingResolver` — rules-engine precedence, confidence, multi-membership, unmatched bucketing.
- `--filter=StaffingImportCommit` — dry-run → review → commit; idempotent re-sync; termination sweep with grace.
- `--filter=StaffingAuthInvariants` — provisioning does NOT alter registration/login/change-password; `admin@acumenus.net.must_change_password` stays `false`. **Write this test first (red) before building `StaffProvisioningService`.**
- `--testsuite=Feature --filter=Auth` — the protected auth flow is untouched by staffing provisioning.

Frontend:
- `npx vitest run resources/js/features/staffing`
- `/impeccable audit` on `Pages/Deployment/StaffingWizard.tsx` and `Components/Deployment/Staffing/*`.

Lint / hygiene:
- `./vendor/bin/pint app/Services/Staffing app/Http/Controllers/Api/Deployment/Staffing`
- `git diff --check`

Acceptance:
- A CSV upload and a FHIR `Practitioner`+`PractitionerRole` bundle both stage into one `staff_import_run` with populated `counts`.
- ≥80% of a well-formed source resolves deterministically (`resolution_source='rule'`); the remainder route to `needs_review`/`unmatched`.
- Every committed `staff_assignment` has FK-valid `service_line_code` + `role_code`, a `facility_key`, a `confidence`, `review_status`, and `evidence`.
- Terminated staff (present in Zephyrus, absent from source) are soft-deactivated after grace; **auth tests green** (`php artisan test --testsuite=Feature --filter=Auth`).
- Re-running `deployment:staffing-sync` is idempotent (no duplicate assignments; `uq_staff_one_primary` holds).
- An admin can walk CSV upload → mapping → dry-run → review → commit end-to-end in `dev` mock mode and against a seeded FHIR fixture.
- The Review Queue correctly buckets a synthetic source (deterministic hits auto-approved, ambiguous specialty routed to review, unmatched job code surfaced).
- Promoting a decision to a rule reduces the review count on the next dry-run.

---

## 11. Governance, security, and safety

- **Admin-only.** `manageDeploymentConfig` ability (`role IN ('superuser','ops-leader')`); frontline never sees the wizard.
- **Protected auth is inviolate.** The wizard obeys `.claude/rules/auth-system.md`: it never modifies registration, login, `/change-password`, the temp-password/Resend flow, `must_change_password`, or the `admin@acumenus.net` superuser. Staff→role/service-line resolution is an *operational* layer; provisioning is additive (operational role/workflow + `is_active`) and is covered by the `StaffingAuthInvariants` regression test.
- **`StaffProvisioningService` guardrails.** It is the only writer to `prod.users` and is constructed so it *cannot* write `password`, `must_change_password`, `email`, `username`, or the `admin@acumenus.net` row (hard allow-list of settable columns + a superuser short-circuit). Any attempt is a thrown exception, asserted by `StaffingAuthInvariants`.
- **Reversible.** Assignments are effective-dated and soft-deactivated (never hard-deleted); every commit is an auditable `staff_import_run` + `staff_mapping_reviews` trail (who / when / why).
- **Idempotent + drift-aware.** Re-sync upserts; `deployment:staffing-drift` reports source↔Zephyrus divergence and sweeps terminations after a configurable grace period, routing survivors to re-review.
- **Evidence-gated verification.** Regulated roles require a `credentialing`/`ehr_master` evidence class to reach `source_verified`.
- **Staffing PII minimization.** Staff name/email are PII; stored minimally in `hosp_org.staff_members`, admin-only (`manageDeploymentConfig`), connector secrets encrypted in `integration.sources` and never sent to the browser.
- **No PHI.** No patient identifiers enter this layer.

---

## 12. Rollout / land order

This layer is Phase 7 of the parent plan and lands **after** Phases 0–6 (registry, IDN geography, capability matrix, facility-space bridge). Specifically:

1. Staffing migration + role-taxonomy seed (`deployment:seed-staff-roles`) — inert until consumed.
2. `StaffingConnector` interface + `CsvUploadConnector` (zero-integration path) first; API connectors as client integrations are provisioned.
3. Wizard behind `deployment.staffing_wizard_enabled` (default **off**). CSV upload first; confirm `Auth` feature tests green before enabling.
4. Provisioning stays dry-run-first; every commit is explicit and auditable.

Deploy via `./deploy.sh` only (no ad hoc SSH / prod `git pull`, per `AGENTS.md`).

---

## 13. Risks and controls

- **Risk:** Staffing provisioning inadvertently alters the protected auth flow (`.claude/rules/auth-system.md`). **Control:** `StaffProvisioningService` is additive-only (operational role/workflow + `is_active`) with a hard column allow-list and superuser short-circuit; a `StaffingAuthInvariants` test (written first, red) asserts registration/login/change-password and superuser invariants; wizard ships behind `deployment.staffing_wizard_enabled` (default off).
- **Risk:** Connector credentials leak to the browser or logs. **Control:** secrets live encrypted in `integration.sources`; API fields are write-only; `hosp_org.staffing_sources` stores only a reference; admin-only RBAC (`manageDeploymentConfig`); no secrets in `read_network_requests`-visible payloads.
- **Risk:** Auto-resolution mis-assigns staff to the wrong service line/role at scale. **Control:** only high-confidence deterministic hits auto-approve; everything else routes to the review queue; regulated roles require explicit confirm; assignments are effective-dated and reversible; drift + coverage dashboards catch errors post-commit.
- **Risk:** Termination sweep deactivates someone still employed (source gap / partial pull). **Control:** deactivation only after a configurable grace period; a partial-pull guard skips the sweep when the source returns implausibly few rows vs. the prior run; survivors route to re-review, never silent hard-delete.
- **Risk:** Duplicate staff identities across sources (HRIS + scheduling + EHR). **Control:** `staff_key`/`(source_system, external_id)` dedupe plus NPI/email cross-source linking in `StaffIdentityResolver`, surfaced as "Conflicts" for reviewer confirmation rather than auto-merged.

---

## 14. Acceptance criteria (map to parent §16, criteria 12–13)

Implemented as `DeploymentReadinessService` checks (parent plan) + `CoverageService`:

- **12.** Every active staffed unit has ≥1 `staff_assignment` at an appropriate role (a unit with no charge/staff-nurse coverage is flagged) — *report unstaffed units per service line* (`CoverageService`).
- **13.** Every committed `staff_assignment` has FK-valid `service_line_code` + `role_code`, a `facility_key`, and non-null `confidence` + `evidence` — *pass = 0 violations*; regulated roles at `source_verified` carry `credentialing`/`ehr_master` evidence.

Plus this plan's own gates: the resolver ≥80%-deterministic target, idempotent re-sync, rule-promotion shrinks the queue, and — non-negotiable — the `StaffingAuthInvariants` + `Auth` suites stay green.

---

## 15. Immediate next work items

1. Author `config/hospital/staff-roles.php` and scaffold the `StaffingConnector` interface + `CsvUploadConnector`; **write the `StaffingAuthInvariants` test first (red)** to lock the auth guardrail before building `StaffProvisioningService`.
2. Add migration `2026_07_04_000150_create_staffing_alignment_tables.php` + the `App\Models\Org\Staff*` / `App\Models\Reference\StaffRole` models (depends on parent Phases 0–1 migrations).
3. Build `ServiceLineRoleResolver` + `StaffIdentityResolver` with unit tests over the §5 precedence ladder.
4. Prototype the wizard against a synthetic CSV of Summit providers/nurses (from the manifest's 30 providers + 36 nurses) to validate the resolver + review queue end-to-end before wiring real connectors.
5. Add `StaffImportOrchestrator` + `StaffProvisioningService` (additive-only), then the admin API surface (§8) and `Pages/Deployment/StaffingWizard.tsx` (§9).

---

## References

- `docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md` (parent plan — registry, geography, capability matrix, facility-space layers)
- `docs/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md` (research baseline)
- `docs/superpowers/plans/2026-06-24-transport-operations.md` (Connector Contract pattern reused for `StaffingConnector`)
- `.claude/rules/auth-system.md` (protected auth — additive-only constraint on this wizard)
- `app/Support/Hospital/HospitalManifest.php`, `config/hospital/hospital-1.php` (Summit provider/nurse roster used for the prototype)
- `AGENTS.md`, `CLAUDE.md` (build/deploy conventions + Token Canon)
