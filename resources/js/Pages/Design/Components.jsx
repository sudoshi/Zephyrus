import React from 'react';
import {
  AgingHeatmap,
  BarrierChip,
  FilterSummary,
  QueueDepthSparkline,
  ReadinessVector,
  SlaComplianceTile,
  SourceFreshnessBadge,
} from '@/Components/Ancillary';
import { Button } from '@/Components/ui/button';

const asOf = '2026-07-11T14:00:00Z';
const fresh = { status: 'fresh', asOf, sourceCutoffAt: '2026-07-11T13:59:00Z', lagMinutes: 1, sourceLabel: 'Ancillary demo', explanation: null };
const definition = {
  definitionUuid: '22222222-2222-4222-8222-222222222222', department: 'rad', metricKey: 'rad.stat_order_final', label: 'STAT order to final',
  startMilestoneCode: 'RAD_ORDERED', stopMilestoneCode: 'RAD_FINAL', priority: 'stat', patientClass: null, statistic: 'compliance_rate',
  warningMinutes: 45, breachMinutes: 60, targetValue: 90, direction: 'higher_is_better', unit: 'percent', effectiveFrom: '2026-07-01T00:00:00Z',
  effectiveTo: null, version: 1, active: true, definitionText: 'Share of selected STAT radiology orders finalized within 60 minutes.', sourceReferenceId: 'governance:rad-stat-v1',
};

const stateExamples = [
  ['normal', 'Within target', '94%', 94, null],
  ['warning', 'Warning cohort', '84%', 84, 'Performance is approaching the governed threshold.'],
  ['breach', 'Breached cohort', '71%', 71, 'Performance is outside the governed threshold.'],
  ['stale', 'Stale source', '—', null, 'The last source cutoff is outside the freshness window.'],
  ['no_data', 'Empty cohort', '—', null, 'No qualifying orders in the selected interval.'],
  ['degraded', 'Degraded feed', '82%', 82, 'Only ordered and final milestones are available.'],
  ['loading', 'Loading state', '—', null, null],
].map(([status, label, displayValue, value, explanation], index) => ({
  key: `example-${status}`, label, status, value, displayValue, unit: 'percent', cohortCount: value === null ? 0 : 48 - index,
  median: value === null ? null : 31 + index, p90: value === null ? null : 52 + index, definition, explanation,
  freshness: status === 'stale' ? { ...fresh, status: 'stale', sourceCutoffAt: '2026-07-11T11:00:00Z', lagMinutes: 180, explanation: 'No recent heartbeat.' } : fresh,
}));

const readiness = [
  { key: 'imaging', label: 'Imaging', status: 'ready', pendingCount: 0, oldestAgeMinutes: 0, blocking: false, freshness: fresh, drillTarget: '/rtdc/radiology', explanation: null },
  { key: 'lab', label: 'Lab', status: 'pending', pendingCount: 2, oldestAgeMinutes: 44, blocking: true, freshness: fresh, drillTarget: '/rtdc/lab', explanation: null },
  { key: 'medication', label: 'Medication', status: 'blocked', pendingCount: 1, oldestAgeMinutes: 61, blocking: true, freshness: { ...fresh, status: 'batch' }, drillTarget: '/rtdc/pharmacy', explanation: null },
  { key: 'pathology', label: 'Pathology', status: 'not_applicable', pendingCount: 0, oldestAgeMinutes: null, blocking: false, freshness: { ...fresh, status: 'unknown', sourceCutoffAt: null, lagMinutes: null }, drillTarget: null, explanation: null },
];

export default function Components() {
  return (
    <main className="space-y-8 bg-healthcare-background p-6 text-healthcare-text-primary dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark">
      <header><h1 className="text-2xl font-semibold">Design Components</h1><p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Canonical operational states and accessible fallbacks for the shared ancillary query kit.</p></header>

      <section aria-labelledby="buttons-heading"><h2 id="buttons-heading" className="mb-4 text-xl font-semibold">Buttons</h2><div className="flex flex-wrap gap-4"><Button>Default Button</Button><Button variant="outline">Outline Button</Button><Button variant="ghost">Ghost Button</Button></div></section>

      <section aria-labelledby="states-heading" className="space-y-4"><div><h2 id="states-heading" className="text-xl font-semibold">Ancillary metric states</h2><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Normal, warning, breach, stale, no-data, degraded, and loading are explicit data states.</p></div><FilterSummary resultCount={48} items={[{ key: 'priority', label: 'Priority', value: 'STAT' }, { key: 'department', label: 'Department', value: 'Radiology' }]} /><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">{stateExamples.map((value) => <SlaComplianceTile key={value.key} value={value} />)}</div></section>

      <section aria-labelledby="visuals-heading" className="space-y-4"><h2 id="visuals-heading" className="text-xl font-semibold">Operational visual fallbacks</h2><div className="grid gap-4 lg:grid-cols-2"><AgingHeatmap title="Open orders by service and age" cells={[{ key: 'rad-0', rowLabel: 'Radiology', columnLabel: '0–30 min', count: 12, state: 'normal' }, { key: 'rad-1', rowLabel: 'Radiology', columnLabel: '31–60 min', count: 5, state: 'warning' }, { key: 'lab-1', rowLabel: 'Lab', columnLabel: '61+ min', count: 3, state: 'breach' }, { key: 'rx-1', rowLabel: 'Pharmacy', columnLabel: '61+ min', count: null, state: 'no_data' }]} /><QueueDepthSparkline title="Radiology queue depth" points={[{ at: '2026-07-11T10:00:00Z', value: 8 }, { at: '2026-07-11T11:00:00Z', value: 11 }, { at: '2026-07-11T12:00:00Z', value: 7 }, { at: '2026-07-11T13:00:00Z', value: 14 }]} /></div></section>

      <section aria-labelledby="readiness-heading" className="space-y-4"><div className="flex flex-wrap items-center justify-between gap-2"><h2 id="readiness-heading" className="text-xl font-semibold">Readiness and source state</h2><SourceFreshnessBadge value={fresh} /></div><ReadinessVector axes={readiness} /><BarrierChip barrier={{ key: 'rad-final', label: 'Final report pending', owner: 'Radiology reading room', ageMinutes: 71, severity: 'breach', explanation: 'Exam complete is selected, but no final report assertion is available.', nextAction: 'Escalate to the assigned reading queue.' }} /></section>
    </main>
  );
}
