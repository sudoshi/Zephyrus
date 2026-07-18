// Saved views (N-7): three persona-keyed camera/floor/layers bookmarks in
// localStorage. Pure parse/serialize helpers so the round-trip is unit-tested
// without a scene; garbage in storage degrades to empty slots, never a crash.
import { z } from 'zod';
import type { PatientLayerState } from './types';

const vectorSchema = z.object({ x: z.number(), y: z.number(), z: z.number() });

const savedViewSchema = z.object({
  camera: z.object({ position: vectorSchema, target: vectorSchema }),
  floor: z.string(),
  layers: z.object({
    base: z.boolean(),
    tokens: z.boolean(),
    trails: z.boolean(),
    heat: z.boolean(),
    ghosts: z.boolean(),
    barriers: z.boolean(),
    rounds: z.boolean(),
  }),
});

export type SavedView = z.infer<typeof savedViewSchema>;

export const SAVED_VIEW_SLOTS = 3;

export function savedViewsKey(role: string | null | undefined): string {
  return `flow4d.views.${role ?? 'house'}`;
}

/** Parse a storage payload into exactly SAVED_VIEW_SLOTS slots (null = empty). */
export function parseSavedViews(raw: string | null): Array<SavedView | null> {
  const empty: Array<SavedView | null> = Array.from({ length: SAVED_VIEW_SLOTS }, () => null);
  if (!raw) return empty;
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return empty;
    return empty.map((_, index) => {
      const candidate = savedViewSchema.safeParse(parsed[index]);
      return candidate.success ? candidate.data : null;
    });
  } catch {
    return empty;
  }
}

export function serializeSavedViews(views: Array<SavedView | null>): string {
  return JSON.stringify(views.slice(0, SAVED_VIEW_SLOTS));
}

/** Layers restore defensively — unknown keys dropped, current state fills gaps. */
export function mergeLayers(current: PatientLayerState, saved: SavedView['layers']): PatientLayerState {
  return { ...current, ...saved };
}
