import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section } from '@/Components/system';
import { SourceStep, type UploadContent } from '@/Components/Deployment/Staffing/SourceStep';
import { MappingStep } from '@/Components/Deployment/Staffing/MappingStep';
import { PreviewStep } from '@/Components/Deployment/Staffing/PreviewStep';
import { ReviewQueue } from '@/Components/Deployment/Staffing/ReviewQueue';
import { CommitStep } from '@/Components/Deployment/Staffing/CommitStep';
import { CoveragePanel } from '@/Components/Deployment/Staffing/CoveragePanel';
import { useStaffingReference } from '@/features/deployment/staffing/hooks';
import type { CommitResult, ImportResult, StaffingSource, StagedItem } from '@/features/deployment/staffing/types';
import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useMemo, useState } from 'react';

type Step = 'source' | 'mapping' | 'preview' | 'review' | 'commit' | 'coverage';

const STEPS: { id: Step; label: string }[] = [
  { id: 'source', label: 'Connect' },
  { id: 'mapping', label: 'Map fields' },
  { id: 'preview', label: 'Preview' },
  { id: 'review', label: 'Review' },
  { id: 'commit', label: 'Commit' },
  { id: 'coverage', label: 'Coverage' },
];

const emptyContent: UploadContent = { transport: 'file_upload', csv: '', bundle: null, fileName: '' };

function Stepper({ current, reached, onJump }: { current: Step; reached: Set<Step>; onJump: (s: Step) => void }) {
  const currentIndex = STEPS.findIndex((s) => s.id === current);
  return (
    <ol className="flex flex-wrap items-center gap-1 text-sm">
      {STEPS.map((step, i) => {
        const isCurrent = step.id === current;
        const done = i < currentIndex && reached.has(step.id);
        const clickable = reached.has(step.id) && !isCurrent;
        return (
          <li key={step.id} className="flex items-center gap-1">
            <button
              type="button"
              disabled={!clickable}
              onClick={() => clickable && onJump(step.id)}
              aria-current={isCurrent ? 'step' : undefined}
              className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 font-medium transition-colors duration-150 ${
                isCurrent
                  ? 'bg-healthcare-primary text-white'
                  : done
                    ? 'text-healthcare-primary hover:bg-healthcare-primary/10 dark:text-healthcare-primary-dark'
                    : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
              } ${clickable ? 'cursor-pointer' : 'cursor-default'}`}
            >
              <span className={`inline-flex size-5 items-center justify-center rounded-full text-xs tabular-nums ${
                isCurrent ? 'bg-white/20' : done ? 'bg-healthcare-primary/15 dark:bg-healthcare-primary-dark/20' : 'bg-healthcare-background dark:bg-white/5'
              }`}>
                {done ? <Check className="size-3" /> : i + 1}
              </span>
              {step.label}
            </button>
            {i < STEPS.length - 1 && <span className="px-1 text-healthcare-text-secondary/50 dark:text-healthcare-text-secondary-dark/50">›</span>}
          </li>
        );
      })}
    </ol>
  );
}

export default function StaffingWizard() {
  const [step, setStep] = useState<Step>('source');
  const [reached, setReached] = useState<Set<Step>>(new Set<Step>(['source']));
  const [sourceId, setSourceId] = useState<number | null>(null);
  const [facilityKey, setFacilityKey] = useState('SUMMIT_REGIONAL');
  const [content, setContent] = useState<UploadContent>(emptyContent);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [result, setResult] = useState<ImportResult | null>(null);
  const [committed, setCommitted] = useState<CommitResult | null>(null);

  const reference = useStaffingReference();

  function goTo(next: Step) {
    setReached((r) => new Set(r).add(next));
    setStep(next);
  }

  function selectSource(source: StaffingSource) {
    setSourceId(source.staffing_source_id);
    // Reset the upload when the transport changes so content matches the connector.
    setContent((c) => (c.transport === source.transport ? c : { ...emptyContent, transport: source.transport }));
  }

  function onImported(r: ImportResult) {
    setResult(r);
    goTo('preview');
  }

  const updateItem = useMemo(
    () => (item: StagedItem) =>
      setResult((prev) =>
        prev
          ? { ...prev, staged: { ...prev.staged, items: prev.staged.items.map((i) => (i.staff_member_id === item.staff_member_id ? item : i)) } }
          : prev,
      ),
    [],
  );

  function restart() {
    setSourceId(null);
    setContent(emptyContent);
    setMapping({});
    setResult(null);
    setCommitted(null);
    setReached(new Set<Step>(['source']));
    setStep('source');
  }

  return (
    <DashboardLayout>
      <Head title="Staffing Alignment Wizard" />
      <PageContentLayout
        title="Staffing Alignment Wizard"
        subtitle="Resolve a staffing source to facility × service line × role × unit — human-reviewed, evidence-backed, additive to accounts"
        headerContent={<Stepper current={step} reached={reached} onJump={setStep} />}
      >
        <div className="space-y-4">
          <Section
            title={STEPS.find((s) => s.id === step)?.label ?? ''}
            summary={SUMMARIES[step]}
            icon={ICONS[step]}
          >
            {step === 'source' && (
              <SourceStep
                sourceId={sourceId}
                facilityKey={facilityKey}
                content={content}
                onSelectSource={selectSource}
                onChangeFacility={setFacilityKey}
                onFile={setContent}
                onNext={() => goTo('mapping')}
              />
            )}

            {step === 'mapping' && sourceId !== null && (
              <MappingStep
                sourceId={sourceId}
                facilityKey={facilityKey}
                content={content}
                mapping={mapping}
                onChangeMapping={setMapping}
                onImported={onImported}
                onBack={() => setStep('source')}
              />
            )}

            {step === 'preview' && result && (
              <PreviewStep result={result} onNext={() => goTo('review')} onBack={() => setStep('mapping')} />
            )}

            {step === 'review' && result && (
              reference.data ? (
                <ReviewQueue
                  runId={result.run.staff_import_run_id}
                  staged={result.staged}
                  reference={reference.data}
                  onItemUpdated={updateItem}
                  onReresolved={setResult}
                  onNext={() => goTo('commit')}
                  onBack={() => setStep('preview')}
                />
              ) : (
                <div className="rounded-lg border border-healthcare-border p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                  Loading role &amp; service-line reference…
                </div>
              )
            )}

            {step === 'commit' && result && (
              <CommitStep
                runId={result.run.staff_import_run_id}
                staged={result.staged}
                onCommitted={(c) => { setCommitted(c); goTo('coverage'); }}
                onBack={() => setStep('review')}
              />
            )}

            {step === 'coverage' && (
              <CoveragePanel facilityKey={facilityKey} committed={committed} onRestart={restart} />
            )}
          </Section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}

const SUMMARIES: Record<Step, string> = {
  source: 'Choose a connector source, target facility, and upload the roster',
  mapping: 'Map source columns to canonical staff fields, then run a dry-run',
  preview: 'Dry-run staging — who is new, updated, and how each resolved',
  review: 'Approve, edit, reject, or reassign each person; promote rules to shrink the queue',
  commit: 'Confirm exactly what will be written, then commit',
  coverage: 'What committed, and staffed vs unstaffed coverage by service line',
};

const ICONS: Record<Step, string> = {
  source: 'heroicons:server-stack',
  mapping: 'heroicons:table-cells',
  preview: 'heroicons:eye',
  review: 'heroicons:clipboard-document-check',
  commit: 'heroicons:check-badge',
  coverage: 'heroicons:chart-bar',
};
