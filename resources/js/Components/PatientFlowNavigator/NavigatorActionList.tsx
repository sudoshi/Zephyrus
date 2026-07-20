import React from 'react';
import type { NavigatorBarrier, OccupancyInsight } from '@/features/patientFlowNavigator/types';

interface NavigatorActionListProps {
  /** Non-ok occupancy insights (delayed/watch) — the coral/amber disks. */
  delayed: OccupancyInsight[];
  /** Open operational barriers (aggregate, patient-free). */
  barriers: NavigatorBarrier[];
  onSelectLocation: (location: string) => void;
  onSelectBarrier: (barrierId: number) => void;
}

const STATUS_WORD: Record<string, string> = {
  delayed: 'Delayed',
  watch: 'Watch',
  ok: 'On track',
};

/**
 * Non-pointer parity for the scene's aggregate signals (HFE audit F-8): the
 * canvas raycast can select delayed disks and barrier diamonds, but a keyboard
 * or AT user had no equivalent path — H1.2 closed patient selection only.
 * These are REAL buttons routed through the same `selectEntity` API the pointer
 * uses. Labels are location/barrier-level (never patient identity), so the list
 * is safe on a shared wall under any lens.
 */
export default function NavigatorActionList({
  delayed,
  barriers,
  onSelectLocation,
  onSelectBarrier,
}: NavigatorActionListProps) {
  if (delayed.length === 0 && barriers.length === 0) return null;

  return (
    <nav className="patient-flow-action-list" aria-label="Delayed locations and barriers">
      {delayed.length > 0 && (
        <section aria-label="Delayed and watched locations">
          <h3 className="patient-flow-action-list-heading">Delayed &amp; watch ({delayed.length})</h3>
          <ul>
            {delayed.map((insight) => (
              <li key={`loc-${insight.location}`}>
                <button
                  type="button"
                  className={`patient-flow-action-row status-${insight.primaryStatus}`}
                  onClick={() => onSelectLocation(insight.location)}
                >
                  <span className="patient-flow-action-row-label">
                    {insight.locationName ?? insight.location}
                  </span>
                  <span className="patient-flow-action-row-meta">
                    {STATUS_WORD[insight.primaryStatus] ?? insight.primaryStatus}
                    {insight.serviceLine ? ` · ${insight.serviceLine}` : ''}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      {barriers.length > 0 && (
        <section aria-label="Open barriers">
          <h3 className="patient-flow-action-list-heading">Barriers ({barriers.length})</h3>
          <ul>
            {barriers.map((barrier) => (
              <li key={`bar-${barrier.barrier_id}`}>
                <button
                  type="button"
                  className={`patient-flow-action-row category-${barrier.category}`}
                  onClick={() => onSelectBarrier(barrier.barrier_id)}
                >
                  <span className="patient-flow-action-row-label">
                    {barrier.unit_label ?? `Unit ${barrier.unit_id ?? '—'}`}
                  </span>
                  <span className="patient-flow-action-row-meta">
                    {barrier.category}
                    {barrier.reason_code ? ` · ${barrier.reason_code}` : ''}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
    </nav>
  );
}
