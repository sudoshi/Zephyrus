// resources/js/types/patientLens.ts
//
// Zephyrus 2.0 P8 WS-3 — the A2P patient-lens contract. The web surface
// consumes the SAME payload MobilePatientContextService::build() emits for the
// Hummingbird mobile app (one A2P authority, two surfaces). It is TOLERANT by
// design: the lens renders a subset (header, status spine, timeline,
// dependencies, recommendations, actions), so unknown/extra fields pass through
// and a backend addition never breaks the surface. Nullable wherever the
// service can legitimately emit null. The payload is PHI-minimized server-side
// (`patient.phi_minimized`) — the client never receives raw identity.
import { z } from 'zod';
import type { StatusLevel } from './commandCenter';

const personaSchema = z
  .object({
    role_id: z.string(),
    title: z.string(),
    focus: z.string().nullable().optional(),
    question: z.string().nullable().optional(),
  })
  .passthrough();

const headerSchema = z
  .object({
    current_location: z.string().nullable(),
    target_location: z.string().nullable(),
    service: z.string().nullable(),
    isolation_required: z.boolean().nullable().optional(),
    responsible_team: z.string().nullable().optional(),
    as_of: z.string().nullable().optional(),
  })
  .passthrough();

const spineItemSchema = z
  .object({
    domain: z.string(),
    label: z.string(),
    status: z.string(),
    at: z.string().nullable().optional(),
  })
  .passthrough();

const timelineItemSchema = z
  .object({
    event_type: z.string(),
    domain: z.string(),
    actor_role: z.string().nullable().optional(),
    status_after: z.string().nullable().optional(),
    occurred_at: z.string().nullable().optional(),
  })
  .passthrough();

const dependencySchema = z
  .object({
    dependency_type: z.string(),
    owner_role: z.string().nullable().optional(),
    status: z.string(),
    label: z.string(),
    entity_ref: z.string().nullable().optional(),
  })
  .passthrough();

const recommendationSchema = z
  .object({
    recommendation_uuid: z.string(),
    source: z.string().nullable().optional(),
    title: z.string(),
    status: z.string().nullable().optional(),
    risk_level: z.string().nullable().optional(),
    rationale: z.string().nullable().optional(),
  })
  .passthrough();

const actionSchema = z
  .object({
    kind: z.string(),
    label: z.string(),
    requires_online: z.boolean().nullable().optional(),
  })
  .passthrough();

export const patientLensSchema = z
  .object({
    altitude: z.literal('A2P'),
    persona: personaSchema,
    patient: z
      .object({
        patient_context_ref: z.string(),
        display: z.string().nullable().optional(),
        detail_authorized: z.boolean().nullable().optional(),
        phi_minimized: z.boolean().nullable().optional(),
      })
      .passthrough(),
    header: headerSchema,
    status_spine: z.array(spineItemSchema),
    timeline: z.array(timelineItemSchema),
    dependencies: z.array(dependencySchema),
    recommendations: z.array(recommendationSchema),
    actions: z.array(actionSchema),
    web: z
      .object({ href: z.string(), label: z.string() })
      .passthrough()
      .nullable()
      .optional(),
    phi_policy: z.record(z.string(), z.unknown()).optional(),
  })
  .passthrough();

export type PatientLens = z.infer<typeof patientLensSchema>;
export type PatientLensPersona = z.infer<typeof personaSchema>;
export type PatientLensHeader = z.infer<typeof headerSchema>;
export type PatientLensSpineItem = z.infer<typeof spineItemSchema>;
export type PatientLensTimelineItem = z.infer<typeof timelineItemSchema>;
export type PatientLensDependency = z.infer<typeof dependencySchema>;
export type PatientLensRecommendation = z.infer<typeof recommendationSchema>;
export type PatientLensAction = z.infer<typeof actionSchema>;

export type SafePatientLens =
  | { ok: true; data: PatientLens }
  | { ok: false; error: string };

export function safeParsePatientLens(input: unknown): SafePatientLens {
  // A server 403 envelope ({ error: { message, unauthorized_state } }) is a
  // valid, EXPECTED shape — surface its message as a typed error, not a crash.
  if (typeof input === 'object' && input !== null && 'error' in (input as Record<string, unknown>)) {
    const err = (input as { error?: { message?: string } }).error;
    return { ok: false, error: err?.message ?? 'Not authorized for this patient context.' };
  }
  const result = patientLensSchema.safeParse(input);
  if (result.success) return { ok: true, data: result.data };
  const first = result.error.issues[0];
  const where = first?.path?.length ? ` (at ${first.path.join('.')})` : '';
  return { ok: false, error: `${first?.message ?? 'Invalid patient lens payload'}${where}` };
}

// A patient-context drill token (ptok_…). ?patient= opens the A2P lens ONLY
// for a real context ref; an absent token keeps the cockpit unchanged (no
// extra fetch), exactly like isScopedMount for ?scope=.
export function isPatientContextRef(token: string | null): token is string {
  return token !== null && token.startsWith('ptok_');
}

// Operational statuses ('pending', 'boarding', 'placed', 'failed', …) are
// domain vocab, NOT cockpit states — map to a canon StatusLevel so the lens
// pairs every status with a SHAPE glyph (never color alone). Earned urgency:
// only genuinely blocking/failed states earn warning/critical; the rest stay
// neutral or land on success when the step is done.
export function operationalStatusLevel(status: string): StatusLevel {
  const s = status.toLowerCase();
  if (['failed', 'canceled', 'cancelled'].includes(s)) return 'critical';
  if (['pending', 'boarding', 'blocked', 'delayed', 'requested'].includes(s)) return 'warning';
  if (['completed', 'placed', 'resolved', 'departed', 'done'].includes(s)) return 'success';
  return 'neutral';
}
