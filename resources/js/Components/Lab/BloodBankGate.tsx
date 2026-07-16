import { Link } from '@inertiajs/react';
import { Droplets } from 'lucide-react';
import type { BloodBankCaseGate } from '@/features/lab/schemas';

const STYLE = {
  blocked: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  mtp_active: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  ready: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  not_applicable: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  unknown: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
} as const;

const label = (gate: BloodBankCaseGate) => {
  if (gate.state === 'not_applicable') return 'Blood Bank · no requirement';
  if (gate.state === 'unknown') return 'Blood Bank · readiness unknown';
  if (gate.state === 'mtp_active') return `MTP active · ${gate.units.allocated}/${gate.units.requested} allocated`;
  if (gate.state === 'ready') return `Blood Bank ready · ${gate.units.allocated}/${gate.units.requested} allocated`;
  return `Blood Bank gated · crossmatch ${gate.crossmatchState.replaceAll('_', ' ')}`;
};

export default function BloodBankGate({ gate }: { gate: BloodBankCaseGate | null | undefined }) {
  if (!gate) return null;

  return <Link href={gate.drillHref} aria-label={`${label(gate)}. ${gate.explanation}`} className={`inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium ${STYLE[gate.state]}`}>
    <Droplets className="size-3.5 shrink-0" aria-hidden="true" /><span className="truncate">{label(gate)}</span>
  </Link>;
}
