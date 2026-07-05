// resources/js/Components/cockpit/ScopePicker.tsx
//
// P8 WS-5 — the mount SCOPE PICKER. A compact native <select> that lets a
// caller mount the cockpit at any altitude they're authorized for (house /
// unit / department / service line). Native <select> is deliberate: it is
// a11y-safe and keyboard/screen-reader complete with zero custom-dropdown code.
//
// The picker is chrome, not safety-critical: on any parse or query error it
// fails quiet (returns null) rather than degrading the surface it sits over.
// Selection navigates with a FULL page load so the read-once ?scope state on
// /dashboard resets cleanly (a client-side nav would leave stale mount state).
import type { ChangeEvent } from 'react';
import { useCockpitScopes } from '@/features/cockpit/useCockpitScopes';
import { safeParseCockpitScopes } from '@/types/cockpitScopes';

export interface ScopePickerProps {
  activeToken: string | null;
  className?: string;
}

const SELECT_CLASS = [
  'rounded-md border border-healthcare-border dark:border-healthcare-border-dark',
  'bg-healthcare-surface dark:bg-healthcare-surface-dark',
  'px-2 py-1 text-xs',
  'text-healthcare-text-primary dark:text-healthcare-text-primary-dark',
].join(' ');

export function ScopePicker({ activeToken, className }: ScopePickerProps) {
  const query = useCockpitScopes(activeToken);
  const mergedClass = [SELECT_CLASS, className ?? ''].filter(Boolean).join(' ');

  // Loading: query has not resolved and has not errored — a disabled stub with
  // its OWN label so it is queryable/announceable distinctly from the ready select.
  if (query.isPending && !query.isError) {
    return (
      <select aria-label="Loading mount scopes" aria-busy="true" className={mergedClass} disabled value="">
        <option value="">Loading mounts…</option>
      </select>
    );
  }

  // Query error → fail quiet (chrome, not safety-critical).
  if (query.isError) return null;

  const parsed = safeParseCockpitScopes(query.data);
  if (!parsed.ok) {
    // Fail quiet in the UI, but leave a breadcrumb — a contract-drifted catalog
    // must be diagnosable in prod logs rather than vanishing silently.
    console.warn('[ScopePicker] cockpit scopes payload broke contract:', parsed.error);
    return null;
  }

  const { active, catalog } = parsed.data;
  const myUnits = catalog.units.filter((u) => u.assigned === true);
  const otherUnits = catalog.units.filter((u) => u.assigned !== true);
  const value = active.token || activeToken || 'house';

  const onChange = (e: ChangeEvent<HTMLSelectElement>): void => {
    const token = e.target.value;
    // Preserve the rest of the mount profile (?role=, ?display=wall, ?theme=) and
    // only swap the scope; the read-once drill/patient overlays reset on the remount.
    const params = new URLSearchParams(window.location.search);
    params.delete('drill');
    params.delete('patient');
    if (token === 'house') params.delete('scope');
    else params.set('scope', token);
    const qs = params.toString();
    window.location.assign(qs ? `/dashboard?${qs}` : '/dashboard');
  };

  return (
    <select aria-label="Mount scope" className={mergedClass} onChange={onChange} value={value}>
      <optgroup label="House">
        <option value={catalog.house.token}>{catalog.house.label}</option>
      </optgroup>

      {myUnits.length > 0 && (
        <optgroup label="My units">
          {myUnits.map((u) => (
            <option key={u.token} value={u.token}>
              {u.label}
            </option>
          ))}
        </optgroup>
      )}

      {otherUnits.length > 0 && (
        <optgroup label="Units">
          {otherUnits.map((u) => (
            <option key={u.token} value={u.token}>
              {u.label}
            </option>
          ))}
        </optgroup>
      )}

      {catalog.departments.length > 0 && (
        <optgroup label="Departments">
          {catalog.departments.map((d) => (
            <option key={d.token} value={d.token}>
              {d.label}
            </option>
          ))}
        </optgroup>
      )}

      {catalog.serviceLines.length > 0 && (
        <optgroup label="Service lines">
          {catalog.serviceLines.map((s) => (
            <option key={s.token} value={s.token}>
              {s.label}
            </option>
          ))}
        </optgroup>
      )}
    </select>
  );
}
