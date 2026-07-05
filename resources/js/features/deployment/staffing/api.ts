// Staffing Alignment Wizard — the §8 write-API client. Zod schemas are the single
// source of truth (TS types are inferred + re-exported by ./types), so the wire shape
// and the UI types never drift. axios has CSRF + creds pre-wired in bootstrap.js.
//
// Scoped under features/deployment/staffing to stay clear of features/staffing (the
// unrelated Staffing Office). Every endpoint is gated by manageDeploymentConfig.
import axios from 'axios';
import { z } from 'zod';

const BASE = '/api/deployment/staffing';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

// ── Buckets + reviewer actions (shared vocab) ────────────────────────────────
export const BUCKETS = ['auto_approved', 'needs_review', 'conflicts', 'unmatched', 'departed'] as const;
export const REVIEW_ACTIONS = ['accept', 'edit', 'split', 'reject', 'defer', 'deactivate'] as const;

const bucketSchema = z.enum(BUCKETS);
const actionSchema = z.enum(REVIEW_ACTIONS);

// ── Source ───────────────────────────────────────────────────────────────────
const sourceSchema = z.object({
  staffing_source_id: z.number(),
  source_key: z.string(),
  display_name: z.string().nullable(),
  connector_type: z.string(),
  transport: z.string(),
  organization_id: z.number().nullable(),
  mapping_template: z.record(z.string(), z.string()),
  default_facility_key: z.string().nullable(),
  sync_schedule: z.string().nullable(),
  is_active: z.boolean(),
  last_synced_at: z.string().nullable(),
});

const connectionResultSchema = z.object({
  ok: z.boolean(),
  message: z.string(),
  details: z.record(z.string(), z.unknown()),
});

const discoverSchema = z.object({
  fields: z.array(z.object({ field: z.string(), samples: z.array(z.string()) })),
  suggested_mapping: z.record(z.string(), z.string()),
});

// ── Staged import ─────────────────────────────────────────────────────────────
const proposedSchema = z.object({
  service_line_code: z.string(),
  role_code: z.string(),
  confidence: z.number(),
  resolution_source: z.string(),
  evidence: z.record(z.string(), z.unknown()),
  unit_hint: z.string().nullable(),
  program_code: z.string().nullable(),
  primary: z.boolean(),
  regulated: z.boolean(),
});

const decisionSchema = z.object({
  action: actionSchema,
  assignments: z
    .array(
      z.object({
        service_line_code: z.string(),
        role_code: z.string(),
        unit_hint: z.string().nullable().optional(),
        program_code: z.string().nullable().optional(),
        primary: z.boolean().optional(),
      }),
    )
    .optional(),
  note: z.string().nullable().optional(),
});

const accountLinkSchema = z.object({ method: z.string(), confidence: z.number() });

const stagedItemSchema = z.object({
  staff_member_id: z.number(),
  staff_key: z.string(),
  display_name: z.string().nullable(),
  email: z.string().nullable(),
  employee_type: z.string().nullable(),
  employment_status: z.string().nullable(),
  user_id: z.number().nullable(),
  account_link: accountLinkSchema.nullable().optional(),
  source_system: z.string(),
  bucket: bucketSchema,
  conflicts: z.array(z.number()),
  proposed: z.array(proposedSchema),
  decision: decisionSchema.nullable(),
});

const stagedSchema = z.object({
  facility_key: z.string().nullable(),
  items: z.array(stagedItemSchema),
});

const runSchema = z.object({
  staff_import_run_id: z.number(),
  staffing_source_id: z.number(),
  source_key: z.string().nullable(),
  status: z.string(),
  dry_run: z.boolean(),
  counts: z.record(z.string(), z.number()),
  facility_key: z.string().nullable(),
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
});

const importResultSchema = z.object({ run: runSchema, staged: stagedSchema });

const commitSummarySchema = z.object({
  run: runSchema,
  summary: z.record(z.string(), z.number()),
});

// ── Rules ──────────────────────────────────────────────────────────────────
const ruleSchema = z.object({
  staff_mapping_rule_id: z.number(),
  staffing_source_id: z.number().nullable(),
  match_field: z.string(),
  match_operator: z.string(),
  match_value: z.string(),
  target_service_line_code: z.string(),
  target_role_code: z.string(),
  target_unit_hint: z.string().nullable(),
  priority: z.number(),
  confidence: z.number(),
  is_active: z.boolean(),
});

// ── Reference (option lists for edit + rule forms) ───────────────────────────
const referenceSchema = z.object({
  service_lines: z.array(z.object({ code: z.string(), name: z.string(), clinical_domain: z.string().nullable() })),
  roles: z.array(
    z.object({
      role_code: z.string(),
      display_name: z.string(),
      role_category: z.string(),
      is_regulated: z.boolean(),
      is_provider: z.boolean(),
      is_nursing: z.boolean(),
    }),
  ),
});

// ── Coverage ──────────────────────────────────────────────────────────────
const coverageSchema = z.object({
  facility_key: z.string(),
  summary: z.object({
    units_total: z.number(),
    units_staffed: z.number(),
    units_unstaffed: z.number(),
  }),
  service_lines: z.array(
    z.object({
      service_line_code: z.string().nullable(),
      units_total: z.number(),
      units_staffed: z.number(),
      assignments: z.number(),
    }),
  ),
  units: z.array(
    z.object({
      unit_id: z.number(),
      abbreviation: z.string().nullable(),
      name: z.string().nullable(),
      service_line_code: z.string().nullable(),
      assignment_count: z.number(),
      staffed: z.boolean(),
    }),
  ),
});

// ── Inferred types (single source of truth; re-exported by ./types) ──────────
export type Bucket = z.infer<typeof bucketSchema>;
export type ReviewAction = z.infer<typeof actionSchema>;
export type StaffingSource = z.infer<typeof sourceSchema>;
export type ConnectionResult = z.infer<typeof connectionResultSchema>;
export type DiscoverResult = z.infer<typeof discoverSchema>;
export type ProposedAssignment = z.infer<typeof proposedSchema>;
export type ReviewDecision = z.infer<typeof decisionSchema>;
export type StagedItem = z.infer<typeof stagedItemSchema>;
export type StagedPayload = z.infer<typeof stagedSchema>;
export type ImportRun = z.infer<typeof runSchema>;
export type ImportResult = z.infer<typeof importResultSchema>;
export type CommitResult = z.infer<typeof commitSummarySchema>;
export type MappingRule = z.infer<typeof ruleSchema>;
export type CoverageReport = z.infer<typeof coverageSchema>;
export type StaffingReference = z.infer<typeof referenceSchema>;
export type ServiceLineOption = StaffingReference['service_lines'][number];
export type RoleOption = StaffingReference['roles'][number];

// Payload shapes the wizard sends.
export interface UpsertSourceInput {
  source_key: string;
  display_name?: string | null;
  connector_type: string;
  transport: string;
  organization_id?: number | null;
  default_facility_key?: string | null;
  mapping_template?: Record<string, string>;
  sync_schedule?: string | null;
}

export interface ProbeInput {
  csv?: string;
  bundle?: unknown;
  mapping?: Record<string, string>;
}

export interface StartImportInput {
  source_id: number;
  facility_key: string;
  csv?: string;
  bundle?: unknown;
  mapping?: Record<string, string>;
}

export interface AssignmentDraft {
  service_line_code: string;
  role_code: string;
  unit_hint?: string | null;
  program_code?: string | null;
  primary?: boolean;
}

export interface DecisionInput {
  action: ReviewAction;
  assignments?: AssignmentDraft[];
  note?: string | null;
}

export interface CreateRuleInput {
  staffing_source_id?: number | null;
  match_field: string;
  match_operator?: string;
  match_value: string;
  target_service_line_code: string;
  target_role_code: string;
  target_unit_hint?: string | null;
  priority?: number;
  confidence?: number;
  staff_import_run_id?: number;
  staff_member_id?: number;
  note?: string;
}

// ── Fetchers / mutators ──────────────────────────────────────────────────────
export async function fetchSources(): Promise<StaffingSource[]> {
  const res = await axios.get(`${BASE}/sources`);
  return envelope(z.array(sourceSchema)).parse(res.data).data;
}

export async function upsertSource(input: UpsertSourceInput): Promise<StaffingSource> {
  const res = await axios.post(`${BASE}/sources`, input);
  return envelope(sourceSchema).parse(res.data).data;
}

export async function testSource(id: number, input: ProbeInput): Promise<ConnectionResult> {
  const res = await axios.post(`${BASE}/sources/${id}/test`, input);
  return envelope(connectionResultSchema).parse(res.data).data;
}

export async function discoverSource(id: number, input: ProbeInput): Promise<DiscoverResult> {
  const res = await axios.post(`${BASE}/sources/${id}/discover`, input);
  return envelope(discoverSchema).parse(res.data).data;
}

export async function startImport(input: StartImportInput): Promise<ImportResult> {
  const res = await axios.post(`${BASE}/imports`, input);
  return envelope(importResultSchema).parse(res.data).data;
}

export async function fetchImport(runId: number): Promise<ImportResult> {
  const res = await axios.get(`${BASE}/imports/${runId}`);
  return envelope(importResultSchema).parse(res.data).data;
}

export async function reresolveImport(runId: number): Promise<ImportResult> {
  const res = await axios.post(`${BASE}/imports/${runId}/resolve`);
  return envelope(importResultSchema).parse(res.data).data;
}

export async function recordReview(runId: number, staffMemberId: number, decision: DecisionInput): Promise<StagedItem> {
  const res = await axios.patch(`${BASE}/imports/${runId}/reviews/${staffMemberId}`, decision);
  return envelope(z.object({ item: stagedItemSchema })).parse(res.data).data.item;
}

export async function commitImport(runId: number): Promise<CommitResult> {
  const res = await axios.post(`${BASE}/imports/${runId}/commit`);
  return envelope(commitSummarySchema).parse(res.data).data;
}

export async function fetchRules(sourceId?: number): Promise<MappingRule[]> {
  const res = await axios.get(`${BASE}/rules`, { params: sourceId ? { staffing_source_id: sourceId } : {} });
  return envelope(z.array(ruleSchema)).parse(res.data).data;
}

export async function createRule(input: CreateRuleInput): Promise<MappingRule> {
  const res = await axios.post(`${BASE}/rules`, input);
  return envelope(ruleSchema).parse(res.data).data;
}

export async function fetchCoverage(facilityKey: string): Promise<CoverageReport> {
  const res = await axios.get(`${BASE}/coverage`, { params: { facility: facilityKey } });
  return envelope(coverageSchema).parse(res.data).data;
}

export async function fetchReference(): Promise<StaffingReference> {
  const res = await axios.get(`${BASE}/reference`);
  return envelope(referenceSchema).parse(res.data).data;
}
