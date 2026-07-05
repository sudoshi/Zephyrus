import { useEffect } from 'react';
import { Wand2 } from 'lucide-react';
import { useDiscoverSource, useStartImport } from '@/features/deployment/staffing/hooks';
import type { ImportResult } from '@/features/deployment/staffing/types';
import type { UploadContent } from './SourceStep';
import { BTN_GHOST, BTN_PRIMARY, LABEL, SELECT } from './controls';

const CANONICAL: { value: string; label: string }[] = [
  { value: '', label: 'Ignore' },
  { value: 'external_id', label: 'External ID (required)' },
  { value: 'display_name', label: 'Display name' },
  { value: 'email', label: 'Email' },
  { value: 'npi', label: 'NPI' },
  { value: 'license_no', label: 'License #' },
  { value: 'employee_type', label: 'Employee type' },
  { value: 'employment_status', label: 'Employment status' },
  { value: 'job_code', label: 'Job code' },
  { value: 'job_title', label: 'Job title' },
  { value: 'specialty', label: 'Specialty' },
  { value: 'department', label: 'Department' },
  { value: 'cost_center', label: 'Cost center' },
  { value: 'home_unit', label: 'Home unit' },
  { value: 'fte', label: 'FTE' },
  { value: 'term_date', label: 'Term date' },
];

interface MappingStepProps {
  sourceId: number;
  facilityKey: string;
  content: UploadContent;
  mapping: Record<string, string>;
  onChangeMapping: (m: Record<string, string>) => void;
  onImported: (result: ImportResult) => void;
  onBack: () => void;
}

export function MappingStep({ sourceId, facilityKey, content, mapping, onChangeMapping, onImported, onBack }: MappingStepProps) {
  const discover = useDiscoverSource();
  const startImport = useStartImport();
  const isFhir = content.transport === 'fhir_practitioner';

  const probeInput = { csv: content.csv || undefined, bundle: content.bundle ?? undefined };

  // Discover columns once, and seed the mapping from the server's suggestion if empty.
  useEffect(() => {
    discover.mutate(
      { id: sourceId, input: probeInput },
      {
        onSuccess: (data) => {
          if (Object.keys(mapping).length === 0 && Object.keys(data.suggested_mapping).length > 0) {
            onChangeMapping(data.suggested_mapping);
          }
        },
      },
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sourceId]);

  const fields = discover.data?.fields ?? [];
  const mapped = Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== ''));

  function runImport() {
    startImport.mutate(
      {
        source_id: sourceId,
        facility_key: facilityKey,
        csv: content.csv || undefined,
        bundle: content.bundle ?? undefined,
        mapping: Object.keys(mapped).length > 0 ? mapped : undefined,
      },
      { onSuccess: onImported },
    );
  }

  return (
    <div className="space-y-5">
      {isFhir ? (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-background p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
          FHIR <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Practitioner</span> /{' '}
          <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">PractitionerRole</span> bundles are
          already normalized to canonical fields — no column mapping needed. Run the dry-run to stage the roster.
        </div>
      ) : discover.isPending ? (
        <div className="rounded-lg border border-healthcare-border p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          Discovering columns…
        </div>
      ) : fields.length === 0 ? (
        <div className="rounded-lg border border-healthcare-border p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          No columns detected. Check the uploaded file, then go back and re-upload.
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <Wand2 className="size-3.5" aria-hidden="true" />
            Suggested mappings are pre-filled — confirm or adjust. Map at least the external ID (used to dedupe people).
          </div>
          <div className="overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-healthcare-border text-left text-xs uppercase tracking-wide text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                  <th className="px-3 py-2 font-medium">Source column</th>
                  <th className="px-3 py-2 font-medium">Sample</th>
                  <th className="px-3 py-2 font-medium">Canonical field</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {fields.map((f) => (
                  <tr key={f.field}>
                    <td className="px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{f.field}</td>
                    <td className="max-w-[220px] truncate px-3 py-2 text-healthcare-text-secondary tabular-nums dark:text-healthcare-text-secondary-dark">
                      {f.samples.slice(0, 2).join(', ') || '—'}
                    </td>
                    <td className="px-3 py-2">
                      <select
                        className={`${SELECT} w-full`}
                        aria-label={`Map column ${f.field}`}
                        value={mapping[f.field] ?? ''}
                        onChange={(e) => onChangeMapping({ ...mapping, [f.field]: e.target.value })}
                      >
                        {CANONICAL.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                      </select>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Leave every column on <span className="font-medium">Ignore</span> if your headers already use canonical field names.
          </p>
        </div>
      )}

      {startImport.isError && (
        <div className="rounded-md bg-healthcare-critical/10 px-3 py-2 text-xs text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark">
          Import failed — verify the file matches the selected transport.
        </div>
      )}

      <div className="flex items-center justify-between border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <button type="button" className={BTN_GHOST} onClick={onBack}>← Back</button>
        <button type="button" className={BTN_PRIMARY} disabled={startImport.isPending} onClick={runImport}>
          {startImport.isPending ? 'Staging…' : 'Run dry-run import →'}
        </button>
      </div>
    </div>
  );
}
