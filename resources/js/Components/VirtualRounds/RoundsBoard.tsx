// The dense operational queue — the primary rounds surface (plan §14: dense,
// quiet, optimized for repeated scanning). Each row explains its own priority
// and shows exactly which requirement is missing; a progress percentage never
// hides that.
import { AlertTriangle, Box, CheckCircle2, HelpCircle, ListTodo, Pin } from 'lucide-react';
import type { BoardPatient } from '@/features/virtualRounds/types';
import {
  formatWindow,
  PATIENT_STATUS_CLASS,
  PATIENT_STATUS_LABEL,
  PRIORITY_BAND_LABEL,
  priorityBandClass,
} from './format';

interface Props {
  patients: BoardPatient[];
  selectedUuid: string | null;
  lens: 'detail' | 'aggregate';
  onSelect: (uuid: string) => void;
}

export default function RoundsBoard({ patients, selectedUuid, lens, onSelect }: Props) {
  if (patients.length === 0) {
    return (
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
        No patients enrolled in this run.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-healthcare-border text-left text-xs font-medium text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            <th scope="col" className="px-3 py-2 text-right">#</th>
            <th scope="col" className="px-3 py-2">Patient</th>
            <th scope="col" className="px-3 py-2">Bed</th>
            <th scope="col" className="px-3 py-2">Status</th>
            <th scope="col" className="px-3 py-2">Priority</th>
            <th scope="col" className="px-3 py-2">Window</th>
            <th scope="col" className="px-3 py-2">Needs</th>
            <th scope="col" className="px-3 py-2">
              <span className="sr-only">Locate in 4D</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {patients.map((patient) => {
            const selected = patient.round_patient_uuid === selectedUuid;
            const hardMissing = patient.requirements.missing.filter((m) => m.requirement === 'hard');
            const topReason = patient.priority_reasons[0];

            return (
              <tr
                key={patient.round_patient_uuid}
                className={
                  'cursor-pointer border-b border-healthcare-border/60 last:border-b-0 dark:border-healthcare-border-dark/60 ' +
                  (selected
                    ? 'bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/15'
                    : 'hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark')
                }
                onClick={() => onSelect(patient.round_patient_uuid)}
                aria-selected={selected}
                data-testid={`rounds-row-${patient.queue_position}`}
              >
                <td className="px-3 py-2 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {patient.queue_position}
                </td>
                <td className="px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  <span className="inline-flex items-center gap-1.5">
                    {patient.pinned && (
                      <Pin
                        className="h-3.5 w-3.5 text-healthcare-warning dark:text-healthcare-warning-dark"
                        aria-label={`Pinned: ${patient.pin_reason ?? 'no reason recorded'}`}
                      />
                    )}
                    <span className="tabular-nums">
                      {lens === 'detail' ? (patient.patient_label ?? '—') : 'Patient (restricted)'}
                    </span>
                  </span>
                </td>
                <td className="px-3 py-2 tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {patient.bed ?? '—'}
                </td>
                <td className="px-3 py-2">
                  <span
                    className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${PATIENT_STATUS_CLASS[patient.status]}`}
                  >
                    {patient.status === 'rounded' && <CheckCircle2 className="h-3 w-3" aria-hidden />}
                    {PATIENT_STATUS_LABEL[patient.status]}
                  </span>
                </td>
                <td className="px-3 py-2">
                  <span
                    className={`text-xs font-medium ${priorityBandClass(patient.priority_band)}`}
                    title={topReason?.explanation}
                  >
                    {PRIORITY_BAND_LABEL[patient.priority_band] ?? `Band ${patient.priority_band}`}
                  </span>
                </td>
                <td className="px-3 py-2 tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {formatWindow(patient.eta_window_start, patient.eta_window_end)}
                </td>
                <td className="px-3 py-2">
                  <span className="inline-flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {hardMissing.length > 0 && (
                      <span
                        className="inline-flex items-center gap-1 text-healthcare-warning dark:text-healthcare-warning-dark"
                        title={hardMissing.map((m) => `${m.role}: ${m.section}`).join(', ')}
                      >
                        <AlertTriangle className="h-3.5 w-3.5" aria-hidden />
                        {hardMissing.length} required
                      </span>
                    )}
                    {patient.open_question_count > 0 && (
                      <span className="inline-flex items-center gap-1">
                        <HelpCircle className="h-3.5 w-3.5" aria-hidden />
                        <span className="tabular-nums">{patient.open_question_count}</span>
                      </span>
                    )}
                    {patient.open_task_count > 0 && (
                      <span className="inline-flex items-center gap-1">
                        <ListTodo className="h-3.5 w-3.5" aria-hidden />
                        <span className="tabular-nums">{patient.open_task_count}</span>
                      </span>
                    )}
                    {hardMissing.length === 0 &&
                      patient.open_question_count === 0 &&
                      patient.open_task_count === 0 && <span>—</span>}
                  </span>
                </td>
                <td className="px-3 py-2 text-right">
                  {/* R-2: jump to this stop's ring in the 4D navigator. */}
                  <a
                    href={`/rtdc/patient-flow-navigator?focus_stop=${patient.round_patient_uuid}`}
                    className="inline-flex rounded-md p-1 text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                    title="Locate in 4D navigator"
                    aria-label={`Locate queue position ${patient.queue_position} in the 4D navigator`}
                    onClick={(event) => event.stopPropagation()}
                  >
                    <Box className="h-3.5 w-3.5" aria-hidden />
                  </a>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
