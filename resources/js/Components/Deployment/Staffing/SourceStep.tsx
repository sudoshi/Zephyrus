import { useRef, useState } from 'react';
import { CheckCircle2, Upload, XCircle } from 'lucide-react';
import { EmptyState } from '@/Components/system';
import { useStaffingSources, useTestSource, useUpsertSource } from '@/features/deployment/staffing/hooks';
import type { StaffingSource } from '@/features/deployment/staffing/types';
import { BTN_GHOST, BTN_PRIMARY, INPUT, LABEL, SELECT } from './controls';

const CONNECTOR_TYPES = ['manual', 'hris', 'scheduling', 'credentialing', 'identity', 'ehr_master', 'on_call'];
const TRANSPORTS: { value: string; label: string }[] = [
  { value: 'file_upload', label: 'CSV upload' },
  { value: 'fhir_practitioner', label: 'FHIR Practitioner bundle' },
];

export interface UploadContent {
  transport: string;
  csv: string;
  bundle: unknown | null;
  fileName: string;
}

interface SourceStepProps {
  sourceId: number | null;
  facilityKey: string;
  content: UploadContent;
  onSelectSource: (source: StaffingSource) => void;
  onChangeFacility: (value: string) => void;
  onFile: (content: UploadContent) => void;
  onNext: () => void;
}

export function SourceStep({ sourceId, facilityKey, content, onSelectSource, onChangeFacility, onFile, onNext }: SourceStepProps) {
  const sources = useStaffingSources();
  const upsert = useUpsertSource();
  const test = useTestSource();
  const fileRef = useRef<HTMLInputElement>(null);

  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ source_key: '', display_name: '', connector_type: 'manual', transport: 'file_upload' });

  const list = sources.data ?? [];
  const ready = sourceId !== null && facilityKey.trim() !== '' && (content.csv !== '' || content.bundle !== null);

  async function readFile(file: File) {
    const text = await file.text();
    if (content.transport === 'fhir_practitioner') {
      try {
        onFile({ transport: 'fhir_practitioner', csv: '', bundle: JSON.parse(text), fileName: file.name });
      } catch {
        onFile({ transport: 'fhir_practitioner', csv: '', bundle: null, fileName: `${file.name} — invalid JSON` });
      }
    } else {
      onFile({ transport: 'file_upload', csv: text, bundle: null, fileName: file.name });
    }
  }

  async function saveSource() {
    const created = await upsert.mutateAsync({
      source_key: form.source_key.trim().toUpperCase().replace(/[^A-Z0-9_]/g, '_'),
      display_name: form.display_name.trim() || null,
      connector_type: form.connector_type,
      transport: form.transport,
      default_facility_key: facilityKey || null,
    });
    onSelectSource(created);
    setCreating(false);
  }

  const probe = test.data;

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2">
        {/* Source */}
        <div className="space-y-2">
          <label className={LABEL} htmlFor="wiz-source">Staffing source</label>
          {list.length === 0 && !creating ? (
            <EmptyState message="No staffing sources yet. Create one to begin." icon="heroicons:server-stack" />
          ) : (
            <select
              id="wiz-source"
              className={`${SELECT} w-full`}
              value={sourceId ?? ''}
              onChange={(e) => {
                const s = list.find((x) => x.staffing_source_id === Number(e.target.value));
                if (s) onSelectSource(s);
              }}
            >
              <option value="" disabled>Select a source…</option>
              {list.map((s) => (
                <option key={s.staffing_source_id} value={s.staffing_source_id}>
                  {s.display_name ?? s.source_key} · {s.transport}
                </option>
              ))}
            </select>
          )}
          <button type="button" className={BTN_GHOST} onClick={() => setCreating((v) => !v)}>
            {creating ? 'Cancel' : 'New source'}
          </button>
        </div>

        {/* Facility */}
        <div className="space-y-2">
          <label className={LABEL} htmlFor="wiz-facility">Target facility key</label>
          <input
            id="wiz-facility"
            className={`${INPUT} w-full`}
            placeholder="SUMMIT_REGIONAL"
            value={facilityKey}
            onChange={(e) => onChangeFacility(e.target.value)}
          />
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Assignments resolve against this facility's units and capability matrix.
          </p>
        </div>
      </div>

      {/* New-source form */}
      {creating && (
        <div className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-background p-4 dark:border-healthcare-border-dark dark:bg-white/5 sm:grid-cols-2">
          <div>
            <label className={LABEL} htmlFor="src-key">Source key</label>
            <input id="src-key" className={`${INPUT} mt-1 w-full`} placeholder="CSV_UPLOAD" value={form.source_key}
              onChange={(e) => setForm((f) => ({ ...f, source_key: e.target.value }))} />
          </div>
          <div>
            <label className={LABEL} htmlFor="src-name">Display name</label>
            <input id="src-name" className={`${INPUT} mt-1 w-full`} placeholder="Manual CSV roster" value={form.display_name}
              onChange={(e) => setForm((f) => ({ ...f, display_name: e.target.value }))} />
          </div>
          <div>
            <label className={LABEL} htmlFor="src-type">Connector type</label>
            <select id="src-type" className={`${SELECT} mt-1 w-full`} value={form.connector_type}
              onChange={(e) => setForm((f) => ({ ...f, connector_type: e.target.value }))}>
              {CONNECTOR_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
          <div>
            <label className={LABEL} htmlFor="src-transport">Transport</label>
            <select id="src-transport" className={`${SELECT} mt-1 w-full`} value={form.transport}
              onChange={(e) => setForm((f) => ({ ...f, transport: e.target.value }))}>
              {TRANSPORTS.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </div>
          <div className="sm:col-span-2">
            <button type="button" className={BTN_PRIMARY} disabled={form.source_key.trim() === '' || upsert.isPending} onClick={saveSource}>
              {upsert.isPending ? 'Saving…' : 'Save source'}
            </button>
            {upsert.isError && <span className="ml-3 text-xs text-healthcare-critical dark:text-healthcare-critical-dark">Could not save — check the source key.</span>}
          </div>
        </div>
      )}

      {/* Upload */}
      <div className="space-y-2">
        <span className={LABEL}>Roster file {content.transport === 'fhir_practitioner' ? '(FHIR bundle JSON)' : '(CSV)'}</span>
        <div className="flex flex-wrap items-center gap-3">
          <input
            ref={fileRef}
            type="file"
            accept={content.transport === 'fhir_practitioner' ? '.json' : '.csv,.txt'}
            className="hidden"
            onChange={(e) => e.target.files?.[0] && readFile(e.target.files[0])}
          />
          <button type="button" className={BTN_GHOST} onClick={() => fileRef.current?.click()}>
            <Upload className="size-4" /> Choose file
          </button>
          {content.fileName && (
            <span className="tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{content.fileName}</span>
          )}
        </div>
      </div>

      {/* Test connection */}
      <div className="flex flex-wrap items-center gap-3">
        <button
          type="button"
          className={BTN_GHOST}
          disabled={sourceId === null || (content.csv === '' && content.bundle === null) || test.isPending}
          onClick={() => sourceId !== null && test.mutate({ id: sourceId, input: { csv: content.csv || undefined, bundle: content.bundle ?? undefined } })}
        >
          {test.isPending ? 'Testing…' : 'Test connection'}
        </button>
        {probe && (
          <span className={`inline-flex items-center gap-1.5 text-xs ${probe.ok ? 'text-healthcare-success dark:text-healthcare-success-dark' : 'text-healthcare-critical dark:text-healthcare-critical-dark'}`}>
            {probe.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />}
            {probe.message}
          </span>
        )}
      </div>

      <div className="flex justify-end border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <button type="button" className={BTN_PRIMARY} disabled={!ready} onClick={onNext}>Map fields →</button>
      </div>
    </div>
  );
}
