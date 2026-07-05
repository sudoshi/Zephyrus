// Small presentational formatters shared across Deployment Console surfaces.

/** snake_case / kebab-case → "Title Case" for codes shown to humans. */
export function humanize(value: string | null | undefined): string {
  if (!value) return '—';
  return value
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** IDN role codes are long; keep the human label but drop redundant words. */
export function roleLabel(value: string | null | undefined): string {
  return humanize(value);
}
