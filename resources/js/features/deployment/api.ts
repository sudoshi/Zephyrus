import axios from 'axios';
import { z } from 'zod';

// ── Reference / registry (Layer 2) ──────────────────────────────────────────
const serviceLineSchema = z.object({
  code: z.string(),
  name: z.string(),
  clinical_domain: z.string(),
  adult_or_pediatric: z.string(),
  care_setting_default: z.string(),
  default_workflow: z.string().nullable(),
  sort_order: z.number(),
  is_active: z.boolean(),
});

const programSchema = z.object({
  program_code: z.string(),
  service_line_code: z.string(),
  display_name: z.string(),
  designation_type: z.string().nullable(),
  designation_body: z.string().nullable(),
});

const capabilityTagSchema = z.object({
  tag_code: z.string(),
  tag_category: z.string(),
  display_name: z.string(),
});

const vocabSchema = z.object({ code: z.string(), display_name: z.string() }).passthrough();
const evidenceClassSchema = z.object({ code: z.string(), display_name: z.string(), is_regulated: z.boolean() });

const catalogSchema = z.object({
  service_lines: z.array(serviceLineSchema),
  programs: z.array(programSchema),
  capability_tags: z.array(capabilityTagSchema),
  capability_levels: z.array(z.object({ code: z.string(), display_name: z.string(), rank: z.number() })),
  idn_roles: z.array(vocabSchema),
  location_roles: z.array(vocabSchema),
  evidence_classes: z.array(evidenceClassSchema),
});

// ── IDN geography (Layer 1) ─────────────────────────────────────────────────
const organizationSummarySchema = z.object({
  organization_key: z.string(),
  name: z.string(),
  short_name: z.string().nullable(),
  kind: z.string().nullable(),
  headquarters_state: z.string().nullable(),
  markets_count: z.number(),
  facilities_count: z.number(),
});

const marketSchema = z.object({
  market_key: z.string(),
  name: z.string(),
  region: z.string().nullable(),
  state: z.string().nullable(),
});

const orgFacilitySchema = z.object({
  facility_key: z.string(),
  facility_name: z.string(),
  short_name: z.string().nullable(),
  idn_role: z.string().nullable(),
  state: z.string().nullable(),
  region: z.string().nullable(),
  licensed_beds: z.number().nullable(),
  cad_facility_code: z.string().nullable(),
  review_status: z.string().nullable(),
  is_active: z.boolean(),
});

const organizationDetailSchema = z.object({
  organization_key: z.string(),
  name: z.string(),
  short_name: z.string().nullable(),
  kind: z.string().nullable(),
  headquarters_state: z.string().nullable(),
  markets: z.array(marketSchema),
  facilities: z.array(orgFacilitySchema),
});

// ── Facility detail / capabilities / transfers ──────────────────────────────
const capabilitySchema = z.object({
  service_line_code: z.string(),
  service_line_name: z.string().nullable(),
  capability_level: z.string().nullable(),
  coverage_model: z.string().nullable(),
  hours: z.string().nullable(),
  programs_present: z.array(z.string()),
  source_evidence_type: z.string().nullable(),
  review_status: z.string().nullable(),
});

const facilityTransferSchema = z.object({
  source_facility_key: z.string(),
  destination_facility_key: z.string().nullable(),
  destination_external_name: z.string().nullable(),
  service_line_code: z.string().nullable(),
  transport_mode: z.string().nullable(),
  direction: z.string().nullable(),
  typical_minutes: z.number().nullable(),
  is_external_partner: z.boolean(),
});

const facilityDetailSchema = z.object({
  facility: z.object({
    facility_key: z.string(),
    facility_name: z.string(),
    short_name: z.string().nullable(),
    idn_role: z.string().nullable(),
    state: z.string().nullable(),
    region: z.string().nullable(),
    county: z.string().nullable(),
    licensed_beds: z.number().nullable(),
    trauma_level_adult: z.string().nullable(),
    stroke_level: z.string().nullable(),
    maternal_level: z.string().nullable(),
    neonatal_level: z.string().nullable(),
    burn_center_status: z.string().nullable(),
    transplant_center_status: z.string().nullable(),
    cad_facility_code: z.string().nullable(),
    review_status: z.string().nullable(),
  }),
  capabilities: z.array(capabilitySchema),
  transfers: z.array(facilityTransferSchema),
});

const spaceSchema = z.object({
  space_code: z.string(),
  space_name: z.string().nullable(),
  space_category: z.string().nullable(),
  floor_number: z.number().nullable(),
  primary_service_line: z.string().nullable(),
  location_role: z.string().nullable(),
  acuity_level: z.string().nullable(),
  service_lines: z.array(z.string()),
  capability_tags: z.array(z.string()),
  operational_targets: z.array(z.object({ target_kind: z.string(), target_id: z.number() })),
  status: z.string().nullable(),
});

// ── Capability matrix (Layer 3) ─────────────────────────────────────────────
const matrixCellSchema = z.object({
  service_line_code: z.string(),
  service_line_name: z.string().nullable(),
  clinical_domain: z.string().nullable(),
  capability_level: z.string().nullable(),
  capability_rank: z.number().nullable(),
  coverage_model: z.string().nullable(),
  hours: z.string().nullable(),
  source_evidence_type: z.string().nullable(),
  review_status: z.string().nullable(),
});

const capabilityMatrixSchema = z.object({
  facility_key: z.string(),
  cells: z.array(matrixCellSchema),
});

// ── Transfer graph ──────────────────────────────────────────────────────────
const transferEdgeSchema = z.object({
  transfer_relationship_id: z.number(),
  source_facility_key: z.string(),
  destination_facility_key: z.string().nullable(),
  destination_external_name: z.string().nullable(),
  service_line_code: z.string().nullable(),
  program_code: z.string().nullable(),
  transport_mode: z.string().nullable(),
  direction: z.string().nullable(),
  weight: z.number().nullable(),
  typical_minutes: z.number().nullable(),
  typical_miles: z.number().nullable(),
  is_external_partner: z.boolean(),
  review_status: z.string().nullable(),
});

// ── Readiness scorecard (§16) ───────────────────────────────────────────────
const readinessCheckSchema = z.object({
  criterion: z.number(),
  key: z.string(),
  title: z.string(),
  status: z.enum(['pass', 'fail', 'warn', 'not_applicable', 'info']),
  count: z.number(),
  failures: z.array(z.record(z.string(), z.unknown())),
});

const readinessReportSchema = z.object({
  facility_key: z.string(),
  facility_name: z.string().nullable(),
  deployment_ready: z.boolean(),
  summary: z.record(z.string(), z.number()),
  checks: z.array(readinessCheckSchema),
});

// ── Inferred types (single source of truth; re-exported by types.ts) ─────────
export type ServiceLine = z.infer<typeof serviceLineSchema>;
export type Program = z.infer<typeof programSchema>;
export type CapabilityTag = z.infer<typeof capabilityTagSchema>;
export type Vocab = z.infer<typeof vocabSchema>;
export type EvidenceClass = z.infer<typeof evidenceClassSchema>;
export type ServiceLineCatalog = z.infer<typeof catalogSchema>;
export type OrganizationSummary = z.infer<typeof organizationSummarySchema>;
export type Market = z.infer<typeof marketSchema>;
export type OrgFacility = z.infer<typeof orgFacilitySchema>;
export type OrganizationDetail = z.infer<typeof organizationDetailSchema>;
export type Capability = z.infer<typeof capabilitySchema>;
export type FacilityTransfer = z.infer<typeof facilityTransferSchema>;
export type FacilityDetail = z.infer<typeof facilityDetailSchema>;
export type FacilitySpace = z.infer<typeof spaceSchema>;
export type MatrixCell = z.infer<typeof matrixCellSchema>;
export type CapabilityMatrix = z.infer<typeof capabilityMatrixSchema>;
export type TransferEdge = z.infer<typeof transferEdgeSchema>;
export type ReadinessCheck = z.infer<typeof readinessCheckSchema>;
export type ReadinessReport = z.infer<typeof readinessReportSchema>;
export type ReadinessStatus = ReadinessCheck['status'];
export type ReviewStatus = 'assumed' | 'source_verified' | 'client_verified' | 'unknown';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchServiceLineCatalog(): Promise<ServiceLineCatalog> {
  const res = await axios.get('/api/deployment/service-lines');
  return envelope(catalogSchema).parse(res.data).data;
}

export async function fetchOrganizations(): Promise<OrganizationSummary[]> {
  const res = await axios.get('/api/deployment/organizations');
  return envelope(z.array(organizationSummarySchema)).parse(res.data).data;
}

export async function fetchOrganization(key: string): Promise<OrganizationDetail> {
  const res = await axios.get(`/api/deployment/organizations/${encodeURIComponent(key)}`);
  return envelope(organizationDetailSchema).parse(res.data).data;
}

export async function fetchFacility(facilityKey: string): Promise<FacilityDetail> {
  const res = await axios.get(`/api/deployment/facilities/${encodeURIComponent(facilityKey)}`);
  return envelope(facilityDetailSchema).parse(res.data).data;
}

export async function fetchFacilitySpaces(facilityKey: string): Promise<FacilitySpace[]> {
  const res = await axios.get(`/api/deployment/facilities/${encodeURIComponent(facilityKey)}/spaces`);
  return envelope(z.array(spaceSchema)).parse(res.data).data;
}

export async function fetchCapabilityMatrix(facilityKey: string): Promise<CapabilityMatrix> {
  const res = await axios.get('/api/deployment/capability-matrix', { params: { facility: facilityKey } });
  return envelope(capabilityMatrixSchema).parse(res.data).data;
}

export async function fetchTransfers(params: { facility?: string; service_line?: string; direction?: string } = {}): Promise<TransferEdge[]> {
  const res = await axios.get('/api/deployment/transfers', { params });
  return envelope(z.array(transferEdgeSchema)).parse(res.data).data;
}

export async function fetchReadiness(facilityKey: string): Promise<ReadinessReport> {
  const res = await axios.get(`/api/deployment/readiness/${encodeURIComponent(facilityKey)}`);
  return envelope(readinessReportSchema).parse(res.data).data;
}
