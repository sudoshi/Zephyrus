import axios from 'axios';
import { z } from 'zod';

// ENT-REG — enterprise registry import preview/commit client contract.

const previewRowSchema = z.object({
  naturalKey: z.string(),
  displayName: z.string(),
  changeKind: z.enum(['create', 'update', 'no_change', 'conflict', 'skipped', 'blocked']),
  changedFields: z.array(z.string()),
  conflictKey: z.string(),
  conflictReason: z.string().nullable(),
  blockedReason: z.string().nullable(),
});

const previewEntitySchema = z.object({
  total: z.number(),
  rows: z.array(previewRowSchema),
});

const previewConflictSchema = z.object({
  conflictKey: z.string(),
  entityType: z.string(),
  naturalKey: z.string(),
  reason: z.string().nullable(),
  collidingNaturalKey: z.string().nullable(),
  resolution: z.enum(['adopt', 'skip']).nullable(),
});

export const importPreviewSchema = z.object({
  summary: z.object({
    create: z.number(),
    update: z.number(),
    conflict: z.number(),
    no_change: z.number(),
    blocked: z.number(),
  }),
  entities: z.record(z.string(), previewEntitySchema),
  conflicts: z.array(previewConflictSchema),
  unresolvedConflictCount: z.number(),
  readiness: z.object({
    score: z.number(),
    committable: z.boolean(),
    appliedCount: z.number(),
    blockedCount: z.number(),
    unresolvedConflictCount: z.number(),
  }),
  payloadSha256: z.string(),
});

export type ImportPreview = z.infer<typeof importPreviewSchema>;
export type ImportPreviewRow = z.infer<typeof previewRowSchema>;
export type ImportConflict = z.infer<typeof previewConflictSchema>;

export type ConflictResolutions = Record<string, 'adopt' | 'skip'>;

export type RegistryPayload = Record<string, unknown>;

export async function previewEnterpriseImport(
  payload: RegistryPayload,
  conflictResolutions: ConflictResolutions,
): Promise<ImportPreview> {
  const response = await axios.post('/admin/enterprise/import/preview', {
    payload,
    conflict_resolutions: conflictResolutions,
  });

  return importPreviewSchema.parse(response.data);
}

const changeCreatedSchema = z.object({
  changeRequestUuid: z.string(),
  payloadSha256: z.string(),
  expiresAtIso: z.string(),
});

export async function requestEnterpriseImportCommit(
  payload: RegistryPayload,
  conflictResolutions: ConflictResolutions,
  changeReason: string,
): Promise<z.infer<typeof changeCreatedSchema>> {
  const response = await axios.post('/admin/enterprise/import/changes', {
    payload,
    conflict_resolutions: conflictResolutions,
    change_reason: changeReason,
  });

  return changeCreatedSchema.parse(response.data);
}

export async function decideEnterpriseImport(
  changeRequestUuid: string,
  approve: boolean,
  reason: string,
): Promise<void> {
  await axios.post(`/admin/enterprise/import/changes/${changeRequestUuid}/decision`, {
    approve,
    reason,
  });
}

export async function applyEnterpriseImport(
  changeRequestUuid: string,
  payload: RegistryPayload,
  conflictResolutions: ConflictResolutions,
): Promise<Record<string, number>> {
  const response = await axios.post(`/admin/enterprise/import/changes/${changeRequestUuid}/apply`, {
    payload,
    conflict_resolutions: conflictResolutions,
  });

  return z.record(z.string(), z.number()).parse(response.data.applied);
}
