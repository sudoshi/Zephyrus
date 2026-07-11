import { describe, expect, it } from 'vitest';
import {
  boardSchema,
  conflictResponseSchema,
  templatesResponseSchema,
} from '@/features/virtualRounds/schemas';

const validMeta = {
  version: 1,
  generated_at: '2026-07-11T14:00:00Z',
  source_cutoff_at: '2026-07-11T13:59:30Z',
  scope: 'unit:42',
  lens: 'detail' as const,
};

const validPatient = {
  round_patient_uuid: 'a2f6c1de-0000-4000-8000-000000000001',
  status: 'queued',
  status_reason: null,
  queue_position: 1,
  priority_band: 3,
  priority_score: 30,
  priority_reasons: [
    {
      code: 'discharge_ready',
      band: 3,
      weight: 30,
      value: true,
      source: 'census',
      explanation: 'Expected discharge today',
      observed_at: '2026-07-11T13:00:00Z',
    },
  ],
  pinned: false,
  pin_reason: null,
  eta_window_start: '2026-07-11T14:10:00Z',
  eta_window_end: '2026-07-11T14:28:00Z',
  estimated_duration_minutes: 8,
  bed: '5E-01',
  unit_id: 42,
  service_line_code: null,
  version: 1,
  requirements: { satisfied: false, missing: [{ role: 'attending', section: 'clinical_plan', requirement: 'hard' }], stale: [], waived: [] },
  open_task_count: 0,
  open_question_count: 0,
  rounded_at: null,
  patient_label: 'PAT-1',
  patient_context_ref: 'ptok_abc',
  contributions: [],
};

const validBoard = {
  data: {
    run: {
      run_uuid: 'b2f6c1de-0000-4000-8000-000000000002',
      template: { template_uuid: 'c2f6c1de-0000-4000-8000-000000000003', name: 'Unit Round', version: 1 },
      scope_type: 'unit',
      scope_key: '42',
      scope_label: '5 East',
      mode: 'async',
      status: 'active',
      planned_start_at: '2026-07-11T14:00:00Z',
      window_end_at: null,
      started_at: '2026-07-11T14:00:00Z',
      completed_at: null,
      queue_version: 1,
      source_cutoff_at: '2026-07-11T13:59:30Z',
      completion_exception: null,
      created_by: 7,
    },
    progress: { total: 1, by_status: { queued: 1 }, rounded: 0 },
    patients: [validPatient],
    participants: [
      {
        participant_uuid: 'd2f6c1de-0000-4000-8000-000000000004',
        role_code: 'bedside_nurse',
        required: true,
        status: 'pending',
        user_id: null,
        waiver_reason: null,
      },
    ],
  },
  meta: validMeta,
};

describe('virtualRounds schemas', () => {
  it('accepts a well-formed board projection', () => {
    expect(boardSchema.safeParse(validBoard).success).toBe(true);
  });

  it('rejects a board missing the version envelope', () => {
    const broken = { ...validBoard, meta: { ...validMeta, version: undefined } };
    expect(boardSchema.safeParse(broken).success).toBe(false);
  });

  it('rejects an unknown patient status', () => {
    const broken = {
      ...validBoard,
      data: { ...validBoard.data, patients: [{ ...validPatient, status: 'exploded' }] },
    };
    expect(boardSchema.safeParse(broken).success).toBe(false);
  });

  it('rejects a lens outside detail/aggregate', () => {
    const broken = { ...validBoard, meta: { ...validMeta, lens: 'root' } };
    expect(boardSchema.safeParse(broken).success).toBe(false);
  });

  it('accepts aggregate rows with null patient identifiers', () => {
    const aggregate = {
      ...validBoard,
      meta: { ...validMeta, lens: 'aggregate' as const },
      data: {
        ...validBoard.data,
        patients: [
          { ...validPatient, patient_label: null, patient_context_ref: null, contribution_count: 2 },
        ],
      },
    };
    expect(boardSchema.safeParse(aggregate).success).toBe(true);
  });

  it('parses the 409 conflict body with an embedded current projection', () => {
    const conflict = {
      error: { code: 'rounds_conflict', message: 'Stale queue version: expected 1, current 2.' },
      current: validBoard,
    };
    const parsed = conflictResponseSchema.safeParse(conflict);
    expect(parsed.success).toBe(true);
    if (parsed.success) {
      expect(parsed.data.current?.meta.version).toBe(1);
    }
  });

  it('parses the templates response with the config-owned section allowlist', () => {
    const payload = {
      data: [
        {
          template_uuid: 'c2f6c1de-0000-4000-8000-000000000003',
          name: 'Unit Round',
          description: null,
          scope_types: ['unit'],
          mode: 'async',
          required_roles: [
            { role_code: 'bedside_nurse', sections: ['overnight_events'], requirement: 'hard' },
          ],
          version: 1,
        },
      ],
      meta: {
        sections: [
          {
            section_code: 'overnight_events',
            label: 'Overnight Events',
            roles: ['bedside_nurse'],
            fields: { events: 'text', family_availability: 'string' },
          },
        ],
        roles: { bedside_nurse: 'Bedside Nurse' },
      },
    };
    expect(templatesResponseSchema.safeParse(payload).success).toBe(true);
  });
});
