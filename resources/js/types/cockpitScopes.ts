// resources/js/types/cockpitScopes.ts
//
// Zephyrus 2.0 P8 (WS-5) — the Zod contract for GET /api/cockpit/scopes, the
// catalog that feeds the mount SCOPE PICKER. The endpoint returns the currently
// active mount plus the RBAC-scoped catalog of every mountable altitude the
// caller may descend to: the house, the departments/service-lines they can see,
// and the units (with a `assigned` flag so the picker can foreground "My units").
//
// Same unknown-out discipline as the rest of the cockpit: the API layer returns
// `unknown`, the picker parses defensively with safeParseCockpitScopes and — as
// non-safety-critical chrome — fails quiet (renders nothing) on any parse miss.
import { z } from 'zod';
import { cockpitScopeRefSchema } from '@/types/cockpit';

// A unit ref carries the base scope-ref shape plus a few catalog-only extras the
// picker uses to group and label (`assigned` foregrounds a caller's own units).
// `.passthrough()` keeps the contract tolerant to server-side additions.
export const cockpitScopeUnitSchema = cockpitScopeRefSchema
  .extend({
    serviceLine: z.string().nullable().optional(),
    type: z.string().nullable().optional(),
    assigned: z.boolean().optional(),
  })
  .passthrough();

export const cockpitScopesResponseSchema = z.object({
  active: cockpitScopeRefSchema,
  catalog: z.object({
    house: cockpitScopeRefSchema,
    departments: z.array(cockpitScopeRefSchema),
    serviceLines: z.array(cockpitScopeRefSchema),
    units: z.array(cockpitScopeUnitSchema),
  }),
});

export type CockpitScopesResponse = z.infer<typeof cockpitScopesResponseSchema>;
export type CockpitScopeUnit = z.infer<typeof cockpitScopeUnitSchema>;

export type SafeCockpitScopes =
  | { ok: true; data: CockpitScopesResponse }
  | { ok: false; error: string };

// Mirror of safeParseCockpitFace: parse cleanly or return the first issue's
// message + path. The picker treats any failure as "no catalog" and renders
// nothing — the read-once ?scope mount is unaffected.
export function safeParseCockpitScopes(input: unknown): SafeCockpitScopes {
  const result = cockpitScopesResponseSchema.safeParse(input);
  if (result.success) return { ok: true, data: result.data };
  const first = result.error.issues[0];
  const where = first?.path?.length ? ` (at ${first.path.join('.')})` : '';
  return { ok: false, error: `${first?.message ?? 'Invalid cockpit scopes payload'}${where}` };
}
