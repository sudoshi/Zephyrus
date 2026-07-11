// Structured contribution input. Sections and their fields come from the
// server allowlist (config/rounds.php via /api/rounds/templates meta) —
// nothing here invents a field the backend would reject.
import { useMemo, useState } from 'react';
import { Send } from 'lucide-react';
import type { RoundSection } from '@/features/virtualRounds/types';

const inputClass =
  'w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 py-1.5 text-sm ' +
  'text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark ' +
  'dark:text-healthcare-text-primary-dark';

interface Props {
  sections: RoundSection[];
  roles: Record<string, string>;
  busy: boolean;
  onSubmit: (input: {
    section_code: string;
    author_role: string;
    structured_data: Record<string, unknown>;
    summary?: string;
    submit: boolean;
  }) => void;
}

export default function ContributionComposer({ sections, roles, busy, onSubmit }: Props) {
  const [sectionCode, setSectionCode] = useState<string>(sections[0]?.section_code ?? '');
  const section = useMemo(
    () => sections.find((s) => s.section_code === sectionCode) ?? null,
    [sections, sectionCode],
  );
  const [role, setRole] = useState<string>(section?.roles[0] ?? '');
  const [values, setValues] = useState<Record<string, string>>({});
  const [summary, setSummary] = useState('');

  const effectiveRole = section && section.roles.includes(role) ? role : (section?.roles[0] ?? '');

  const handleSection = (code: string) => {
    setSectionCode(code);
    setValues({});
    const next = sections.find((s) => s.section_code === code);
    if (next && !next.roles.includes(role)) {
      setRole(next.roles[0] ?? '');
    }
  };

  const submit = (asDraft: boolean) => {
    if (!section || !effectiveRole) {
      return;
    }

    const structured: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(values)) {
      if (value.trim() !== '') {
        structured[key] = value;
      }
    }

    onSubmit({
      section_code: section.section_code,
      author_role: effectiveRole,
      structured_data: structured,
      summary: summary.trim() !== '' ? summary : undefined,
      submit: !asDraft,
    });
    setValues({});
    setSummary('');
  };

  if (sections.length === 0) {
    return null;
  }

  return (
    <div className="space-y-2 rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h3 className="text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Add contribution
      </h3>

      <div className="flex flex-wrap gap-2">
        <select
          className={inputClass + ' max-w-56'}
          value={sectionCode}
          onChange={(e) => handleSection(e.target.value)}
          aria-label="Section"
          data-testid="composer-section"
        >
          {sections.map((s) => (
            <option key={s.section_code} value={s.section_code}>
              {s.label}
            </option>
          ))}
        </select>

        <select
          className={inputClass + ' max-w-56'}
          value={effectiveRole}
          onChange={(e) => setRole(e.target.value)}
          aria-label="Contributing as"
          data-testid="composer-role"
        >
          {(section?.roles ?? []).map((r) => (
            <option key={r} value={r}>
              {roles[r] ?? r}
            </option>
          ))}
        </select>
      </div>

      {section &&
        Object.entries(section.fields).map(([field, type]) => {
          const label = field.replaceAll('_', ' ');

          if (type.startsWith('enum:')) {
            const options = type.slice(5).split(',');
            return (
              <label key={field} className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <span className="mb-0.5 block capitalize">{label}</span>
                <select
                  className={inputClass}
                  value={values[field] ?? ''}
                  onChange={(e) => setValues((v) => ({ ...v, [field]: e.target.value }))}
                >
                  <option value="">—</option>
                  {options.map((opt) => (
                    <option key={opt} value={opt}>
                      {opt.replaceAll('_', ' ')}
                    </option>
                  ))}
                </select>
              </label>
            );
          }

          if (type === 'text') {
            return (
              <label key={field} className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <span className="mb-0.5 block capitalize">{label}</span>
                <textarea
                  className={inputClass}
                  rows={2}
                  value={values[field] ?? ''}
                  onChange={(e) => setValues((v) => ({ ...v, [field]: e.target.value }))}
                />
              </label>
            );
          }

          return (
            <label key={field} className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span className="mb-0.5 block capitalize">{label}</span>
              <input
                className={inputClass}
                type="text"
                value={values[field] ?? ''}
                onChange={(e) => setValues((v) => ({ ...v, [field]: e.target.value }))}
              />
            </label>
          );
        })}

      <label className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span className="mb-0.5 block">Summary (shown on the board)</span>
        <input
          className={inputClass}
          type="text"
          maxLength={2000}
          value={summary}
          onChange={(e) => setSummary(e.target.value)}
          data-testid="composer-summary"
        />
      </label>

      <div className="flex gap-2">
        <button
          type="button"
          className="inline-flex items-center gap-1.5 rounded-md bg-healthcare-primary px-2.5 py-1.5 text-sm font-medium text-white hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark disabled:opacity-50"
          onClick={() => submit(false)}
          disabled={busy}
          data-testid="composer-submit"
        >
          <Send className="h-3.5 w-3.5" aria-hidden />
          Submit
        </button>
        <button
          type="button"
          className="rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark disabled:opacity-50"
          onClick={() => submit(true)}
          disabled={busy}
        >
          Save draft
        </button>
      </div>
    </div>
  );
}
