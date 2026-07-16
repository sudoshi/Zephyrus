import * as Dialog from '@radix-ui/react-dialog';
import { AlertTriangle, X } from 'lucide-react';

export interface AncillaryBarrier {
  key: string;
  label: string;
  owner: string | null;
  ageMinutes: number | null;
  severity: 'warning' | 'breach';
  explanation: string;
  nextAction: string | null;
}

export function BarrierChip({ barrier }: { barrier: AncillaryBarrier }) {
  const critical = barrier.severity === 'breach';
  return (
    <Dialog.Root>
      <Dialog.Trigger asChild><button type="button" className={`inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-healthcare-info ${critical ? 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark' : 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark'}`}><AlertTriangle className="size-3.5" aria-hidden="true" />{barrier.label}</button></Dialog.Trigger>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-40 bg-black/40" />
        <Dialog.Content className="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l border-healthcare-border bg-healthcare-surface p-6 shadow-xl focus:outline-none dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <div className="flex items-start justify-between gap-3"><div><Dialog.Title className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.label}</Dialog.Title><Dialog.Description className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Ancillary operational barrier details</Dialog.Description></div><Dialog.Close className="rounded-md p-1 focus:outline-none focus:ring-2 focus:ring-healthcare-info" aria-label="Close barrier details"><X className="size-5" aria-hidden="true" /></Dialog.Close></div>
          <dl className="mt-6 space-y-4 text-sm"><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Severity</dt><dd className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{critical ? 'Breach' : 'Warning'}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Owner</dt><dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.owner ?? 'Unassigned'}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Age</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.ageMinutes === null ? 'Unavailable' : `${barrier.ageMinutes} minutes`}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Evidence</dt><dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.explanation}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Next action</dt><dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.nextAction ?? 'No next action documented.'}</dd></div></dl>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
