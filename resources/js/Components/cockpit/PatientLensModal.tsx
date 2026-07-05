// resources/js/Components/cockpit/PatientLensModal.tsx
//
// Zephyrus 2.0 P8 WS-3 — the A2P patient lens as an in-place cockpit drill.
// This is the bottom of the drill graph: a bed/board row (wired in WS-4) or a
// ?patient={ptok} deep link opens the operational context OVER the current
// mount — house grid or scoped face alike — without leaving the cockpit. Built
// on the same Radix ui/dialog primitives DrillModal uses (ESC + backdrop +
// focus-trap + focus-restore + aria-modal over the SOLID scrim; no
// glassmorphism). The body delegates entirely to PatientLens, which owns the
// loading / access-limited / error / ready states.
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/Components/ui/dialog';
import { PatientLens } from './PatientLens';

export interface PatientLensModalProps {
  /** The A2P context ref (ptok_…) to open, or null when closed. */
  contextRef: string | null;
  onClose: () => void;
}

export function PatientLensModal({ contextRef, onClose }: PatientLensModalProps) {
  return (
    <Dialog open={contextRef !== null} onOpenChange={(open: boolean) => { if (!open) onClose(); }}>
      <DialogContent
        className="flex max-h-[88vh] w-[calc(100vw-2rem)] max-w-[1080px] flex-col gap-0 overflow-hidden p-0"
        data-testid="cockpit-patient-lens-modal"
        data-context={contextRef ?? undefined}
      >
        <header className="border-b border-healthcare-border dark:border-healthcare-border-dark p-4 pr-12">
          <DialogTitle>Patient operational context</DialogTitle>
          <DialogDescription className="mt-1">
            A2P — one patient&apos;s flow, dependencies, and timeline (PHI-minimized).
          </DialogDescription>
        </header>

        <div className="flex min-h-0 flex-1 flex-col overflow-y-auto p-4">
          {contextRef !== null && <PatientLens contextRef={contextRef} />}
        </div>
      </DialogContent>
    </Dialog>
  );
}
