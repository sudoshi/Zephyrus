// resources/js/Components/arena/objectTypePalette.ts
//
// Categorical colours for the OC-DFG's object types. This is a *sanctioned
// data-viz exception* to the two-system canon (CLAUDE.md): object types are
// categories, not status, so they use a categorical palette — deliberately
// excluding brand gold (#C9A227) and crimson (#9B1B30), which stay reserved for
// the brand/focus layer, and avoiding alarm hues so colour never implies urgency.
// A transition touched by more than one object type renders muted (mixed).

const PALETTE = [
  '#2563eb', // blue
  '#0d9488', // teal
  '#7c3aed', // violet
  '#0891b2', // cyan
  '#65a30d', // lime
  '#db2777', // pink
  '#4f46e5', // indigo
  '#0369a1', // deep blue
  '#7e22ce', // purple
  '#0e7490', // dark cyan
  '#4d7c0f', // olive
  '#6d28d9', // deep violet
] as const;

export const MIXED_EDGE_COLOR = '#94a3b8'; // neutral slate — a multi-type transition

/**
 * Stable colour for an object type, keyed by its position in the log's
 * object-type list so the same type keeps its colour across renders.
 */
export function objectTypeColor(objectType: string, orderedTypes: readonly string[]): string {
  const idx = orderedTypes.indexOf(objectType);
  const safeIdx = idx >= 0 ? idx : 0;
  return PALETTE[safeIdx % PALETTE.length];
}
