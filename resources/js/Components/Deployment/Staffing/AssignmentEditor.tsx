import { Plus, Trash2 } from 'lucide-react';
import type { AssignmentDraft, StaffingReference } from '@/features/deployment/staffing/types';
import { humanize } from '@/Components/Deployment/format';
import { BTN_GHOST, INPUT, SELECT } from './controls';

// Group roles by category for a navigable <optgroup> list.
function groupRoles(reference: StaffingReference) {
  const groups = new Map<string, StaffingReference['roles']>();
  for (const role of reference.roles) {
    const list = groups.get(role.role_category) ?? [];
    list.push(role);
    groups.set(role.role_category, list);
  }
  return Array.from(groups.entries());
}

interface AssignmentEditorProps {
  assignments: AssignmentDraft[];
  onChange: (assignments: AssignmentDraft[]) => void;
  reference: StaffingReference;
}

export function AssignmentEditor({ assignments, onChange, reference }: AssignmentEditorProps) {
  const roleGroups = groupRoles(reference);

  function update(index: number, patch: Partial<AssignmentDraft>) {
    onChange(assignments.map((a, i) => (i === index ? { ...a, ...patch } : a)));
  }

  function setPrimary(index: number) {
    onChange(assignments.map((a, i) => ({ ...a, primary: i === index })));
  }

  function add() {
    onChange([
      ...assignments,
      { service_line_code: reference.service_lines[0]?.code ?? '', role_code: '', unit_hint: '', primary: assignments.length === 0 },
    ]);
  }

  function remove(index: number) {
    const next = assignments.filter((_, i) => i !== index);
    if (next.length > 0 && !next.some((a) => a.primary)) next[0].primary = true;
    onChange(next);
  }

  return (
    <div className="space-y-2">
      {assignments.map((assignment, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2 rounded-md border border-healthcare-border bg-healthcare-surface p-2 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <select
            className={`${SELECT} min-w-[9rem] flex-1`}
            aria-label="Service line"
            value={assignment.service_line_code}
            onChange={(e) => update(i, { service_line_code: e.target.value })}
          >
            <option value="" disabled>Service line…</option>
            {reference.service_lines.map((s) => <option key={s.code} value={s.code}>{s.name}</option>)}
          </select>

          <select
            className={`${SELECT} min-w-[9rem] flex-1`}
            aria-label="Role"
            value={assignment.role_code}
            onChange={(e) => update(i, { role_code: e.target.value })}
          >
            <option value="" disabled>Role…</option>
            {roleGroups.map(([category, roles]) => (
              <optgroup key={category} label={humanize(category)}>
                {roles.map((r) => (
                  <option key={r.role_code} value={r.role_code}>
                    {r.display_name}{r.is_regulated ? ' ⚑' : ''}
                  </option>
                ))}
              </optgroup>
            ))}
          </select>

          <input
            className={`${INPUT} w-28`}
            placeholder="Unit (opt.)"
            aria-label="Unit hint"
            value={assignment.unit_hint ?? ''}
            onChange={(e) => update(i, { unit_hint: e.target.value })}
          />

          <label className="inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <input
              type="radio"
              name="primary-membership"
              className="accent-healthcare-primary"
              checked={!!assignment.primary}
              onChange={() => setPrimary(i)}
            />
            Primary
          </label>

          <button
            type="button"
            aria-label="Remove membership"
            className="rounded-md p-1 text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
            onClick={() => remove(i)}
          >
            <Trash2 className="size-4" />
          </button>
        </div>
      ))}

      <button type="button" className={BTN_GHOST} onClick={add}>
        <Plus className="size-4" /> Add membership
      </button>
    </div>
  );
}
