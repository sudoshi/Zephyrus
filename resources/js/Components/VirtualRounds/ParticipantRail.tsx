// Run-level role slots: who the template requires and where each stands.
import { CheckCircle2, CircleDashed, UserX } from 'lucide-react';
import type { Participant } from '@/features/virtualRounds/types';

interface Props {
  participants: Participant[];
  roles: Record<string, string>;
}

const STATUS_META: Record<Participant['status'], { label: string; className: string }> = {
  pending: {
    label: 'Pending',
    className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark',
  },
  invited: {
    label: 'Invited',
    className: 'text-healthcare-info dark:text-healthcare-info-dark',
  },
  accepted: {
    label: 'Accepted',
    className: 'text-healthcare-info dark:text-healthcare-info-dark',
  },
  declined: {
    label: 'Declined',
    className: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  },
  contributed: {
    label: 'Contributed',
    className: 'text-healthcare-success dark:text-healthcare-success-dark',
  },
  waived: {
    label: 'Waived',
    className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark',
  },
};

export default function ParticipantRail({ participants, roles }: Props) {
  if (participants.length === 0) {
    return null;
  }

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h3 className="mb-2 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Care team inputs
      </h3>
      <ul className="space-y-1.5">
        {participants.map((p) => {
          const meta = STATUS_META[p.status];
          return (
            <li key={p.participant_uuid} className="flex items-center justify-between gap-2 text-sm">
              <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {roles[p.role_code] ?? p.role_code}
                {p.required && (
                  <span className="ml-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    (required)
                  </span>
                )}
              </span>
              <span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}>
                {p.status === 'contributed' ? (
                  <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
                ) : p.status === 'declined' ? (
                  <UserX className="h-3.5 w-3.5" aria-hidden />
                ) : (
                  <CircleDashed className="h-3.5 w-3.5" aria-hidden />
                )}
                {meta.label}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
