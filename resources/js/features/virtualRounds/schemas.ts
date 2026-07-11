// Zod boundary for /api/rounds/* projections. Pages keep query data `unknown`
// and safeParse at the boundary (arena pattern) — a malformed payload degrades
// to an in-place error card, never a white screen.
import { z } from 'zod';

export const roundsMetaSchema = z.object({
  version: z.number(),
  generated_at: z.string(),
  source_cutoff_at: z.string().nullable(),
  scope: z.string(),
  lens: z.enum(['detail', 'aggregate']),
});

export const priorityReasonSchema = z.object({
  code: z.string(),
  band: z.number(),
  weight: z.number(),
  value: z.unknown().optional(),
  source: z.string(),
  explanation: z.string(),
  observed_at: z.string(),
});

export const requirementMissingSchema = z.object({
  role: z.string(),
  section: z.string(),
  requirement: z.enum(['hard', 'soft']),
});

export const requirementsSchema = z.object({
  satisfied: z.boolean(),
  missing: z.array(requirementMissingSchema),
  stale: z.array(z.object({ role: z.string(), section: z.string(), submitted_at: z.string() })),
  waived: z.array(
    z.object({ role: z.string(), waived_by: z.number().nullable(), reason: z.string().nullable() }),
  ),
});

export const patientStatusSchema = z.enum([
  'queued',
  'in_progress',
  'awaiting_input',
  'ready_for_review',
  'rounded',
  'deferred',
  'skipped',
]);

export const runStatusSchema = z.enum([
  'draft',
  'scheduled',
  'active',
  'paused',
  'closing',
  'completed',
  'cancelled',
]);

export const boardContributionSchema = z.object({
  contribution_uuid: z.string(),
  section_code: z.string(),
  author_role: z.string(),
  status: z.enum(['draft', 'submitted', 'superseded', 'withdrawn']),
  summary: z.string().nullable(),
  submitted_at: z.string().nullable(),
  version: z.number(),
});

export const boardPatientSchema = z.object({
  round_patient_uuid: z.string(),
  status: patientStatusSchema,
  status_reason: z.string().nullable(),
  queue_position: z.number(),
  priority_band: z.number(),
  priority_score: z.number(),
  priority_reasons: z.array(priorityReasonSchema),
  pinned: z.boolean(),
  pin_reason: z.string().nullable(),
  eta_window_start: z.string().nullable(),
  eta_window_end: z.string().nullable(),
  estimated_duration_minutes: z.number().nullable(),
  bed: z.string().nullable(),
  unit_id: z.number().nullable(),
  service_line_code: z.string().nullable(),
  version: z.number(),
  requirements: requirementsSchema,
  open_task_count: z.number(),
  open_question_count: z.number(),
  rounded_at: z.string().nullable(),
  patient_label: z.string().nullable(),
  patient_context_ref: z.string().nullable(),
  contributions: z.array(boardContributionSchema),
  contribution_count: z.number().optional(),
});

export const runSummarySchema = z.object({
  run_uuid: z.string(),
  template: z.object({
    template_uuid: z.string().nullable(),
    name: z.string().nullable(),
    version: z.number(),
  }),
  scope_type: z.string(),
  scope_key: z.string(),
  scope_label: z.string().nullable(),
  mode: z.string(),
  status: runStatusSchema,
  planned_start_at: z.string().nullable(),
  window_end_at: z.string().nullable(),
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  queue_version: z.number(),
  source_cutoff_at: z.string().nullable(),
  completion_exception: z
    .object({ reason: z.string(), recorded_by: z.number(), recorded_at: z.string() })
    .passthrough()
    .nullable(),
  created_by: z.number().nullable(),
});

export const participantSchema = z.object({
  participant_uuid: z.string(),
  role_code: z.string(),
  required: z.boolean(),
  status: z.enum(['pending', 'invited', 'accepted', 'declined', 'contributed', 'waived']),
  user_id: z.number().nullable(),
  waiver_reason: z.string().nullable(),
});

export const boardSchema = z.object({
  data: z.object({
    run: runSummarySchema,
    progress: z.object({
      total: z.number(),
      by_status: z.record(z.string(), z.number()),
      rounded: z.number(),
    }),
    patients: z.array(boardPatientSchema),
    participants: z.array(participantSchema),
  }),
  meta: roundsMetaSchema,
});

export const detailContributionSchema = boardContributionSchema.extend({
  author_user_id: z.number(),
  structured_data: z.record(z.string(), z.unknown()),
  source_refs: z.array(z.unknown()),
  authored_at: z.string().nullable(),
  supersedes_uuid: z.string().nullable().optional(),
});

export const patientDetailSchema = z.object({
  data: z.object({
    round_patient_uuid: z.string(),
    status: patientStatusSchema,
    status_reason: z.string().nullable(),
    version: z.number(),
    priority_band: z.number(),
    priority_reasons: z.array(priorityReasonSchema),
    pinned: z.boolean(),
    pin_reason: z.string().nullable(),
    eta_window_start: z.string().nullable(),
    eta_window_end: z.string().nullable(),
    bed: z.string().nullable(),
    unit_id: z.number().nullable(),
    requirements: requirementsSchema.extend({ open_task_count: z.number() }),
    rounded_at: z.string().nullable(),
    patient_label: z.string().nullable(),
    patient_context_ref: z.string().nullable(),
    contributions: z.array(detailContributionSchema),
    questions: z.array(
      z.object({
        question_uuid: z.string(),
        question_text: z.string(),
        target_role: z.string().nullable(),
        status: z.enum(['open', 'answered', 'dismissed', 'expired']),
        due_at: z.string().nullable(),
      }),
    ),
    tasks: z.array(
      z.object({
        task_uuid: z.string(),
        title: z.string(),
        category: z.string().nullable(),
        owner_role: z.string().nullable(),
        status: z.enum(['open', 'in_progress', 'completed', 'cancelled']),
        due_at: z.string().nullable(),
        ops_action_uuid: z.string().nullable(),
      }),
    ),
  }),
  meta: roundsMetaSchema,
});

export const templateSchema = z.object({
  template_uuid: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  scope_types: z.array(z.string()),
  mode: z.string(),
  required_roles: z.array(
    z.object({
      role_code: z.string(),
      sections: z.array(z.string()),
      requirement: z.enum(['hard', 'soft']).optional(),
    }),
  ),
  version: z.number(),
});

export const sectionSchema = z.object({
  section_code: z.string(),
  label: z.string(),
  roles: z.array(z.string()),
  fields: z.record(z.string(), z.string()),
});

export const templatesResponseSchema = z.object({
  data: z.array(templateSchema),
  meta: z.object({
    sections: z.array(sectionSchema),
    roles: z.record(z.string(), z.string()),
  }),
});

export const scopeSchema = z.object({
  scope_type: z.string(),
  scope_key: z.string(),
  label: z.string(),
  abbreviation: z.string().nullable(),
});

export const scopesResponseSchema = z.object({ data: z.array(scopeSchema) });

export const runsResponseSchema = z.object({ data: z.array(runSummarySchema) });

// 409 body: the server includes the current projection for recovery.
export const conflictResponseSchema = z.object({
  error: z.object({ code: z.string(), message: z.string() }),
  current: boardSchema.optional(),
});

// 4D scene overlay stop — opaque tokens + location + round state ONLY
// (plan §8.1): no patient identifier for any lens.
export const roundStopSchema = z.object({
  round_patient_uuid: z.string(),
  status: patientStatusSchema,
  priority_band: z.number(),
  pinned: z.boolean(),
  discharge_ready: z.boolean(),
  missing_input: z.boolean(),
  queue_position: z.number(),
  unit_id: z.number().nullable(),
  facility_space_id: z.number().nullable(),
  bed: z.string().nullable(),
});

export const sceneResponseSchema = z.object({
  data: z.object({
    run: runSummarySchema,
    stops: z.array(roundStopSchema),
  }),
  meta: roundsMetaSchema,
});
