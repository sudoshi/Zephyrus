import React from 'react';

import {
  alignAnchorMs,
  alignedTimeLabel,
  dwellLabel,
} from '@/features/patientFlowNavigator/journey';
import type { JourneyAlignAnchor } from '@/features/patientFlowNavigator/journey';
import type { PatientJourney } from '@/features/patientFlowNavigator/journeySchemas';

/**
 * The Patient Journey Drawer (FLOW-4D plan §7.1, finding PJ-1) — the plain-
 * HTML, AT-primary patient surface. Replaces the key/value inspector for
 * PATIENT selections only; every label comes from the lens-redacted journey
 * payload (the raw ref never reaches this component by construction).
 * Interval-first: dwell segments and phases are the story, events the detail.
 */

export type JourneyDrawerState = 'idle' | 'loading' | 'ok' | 'forbidden' | 'error';

interface NavigatorJourneyDrawerProps {
  journey: PatientJourney | null;
  state: JourneyDrawerState;
  errorMessage?: string | null;
  align: JourneyAlignAnchor;
  onAlignChange: (anchor: JourneyAlignAnchor) => void;
  onClose: () => void;
  /** Frames the patient's trace in-scene (the explicit `F` path — G-6). */
  onFocus: () => void;
  followEnabled: boolean;
  onFollowToggle: (enabled: boolean) => void;
  onCopyLink: () => void;
  copiedLink: boolean;
}

const ALIGN_OPTIONS: Array<{ key: JourneyAlignAnchor; label: string }> = [
  { key: 'clock', label: 'Clock' },
  { key: 'arrival', label: 'From arrival' },
  { key: 'admit', label: 'From admit' },
];

function phaseLabel(phase: string): string {
  return phase.replaceAll('_', ' ');
}

export default function NavigatorJourneyDrawer({
  journey,
  state,
  errorMessage = null,
  align,
  onAlignChange,
  onClose,
  onFocus,
  followEnabled,
  onFollowToggle,
  onCopyLink,
  copiedLink,
}: NavigatorJourneyDrawerProps) {
  if (state === 'idle') return null;

  const anchorMs = journey ? alignAnchorMs(journey, align) : null;
  const at = (iso: string | null | undefined): string => alignedTimeLabel(iso, anchorMs);

  return (
    <aside className="patient-flow-journey" aria-label="Patient journey" aria-live="polite">
      <header className="patient-flow-journey-head">
        <div>
          <strong>{journey?.patient.display_label ?? 'Patient journey'}</strong>
          {journey?.header.current_location_name || journey?.header.current_location ? (
            <span className="patient-flow-journey-loc">
              {journey.header.current_location_name ?? journey.header.current_location}
              {journey.header.current_unit_code ? ` · ${journey.header.current_unit_code}` : ''}
            </span>
          ) : null}
          {typeof journey?.header.los_minutes === 'number' && (
            <span className="patient-flow-journey-los">LOS {dwellLabel(journey.header.los_minutes)}</span>
          )}
        </div>
        <button type="button" className="patient-flow-journey-close" aria-label="Close patient journey" onClick={onClose}>
          ×
        </button>
      </header>

      {state === 'loading' && <p className="patient-flow-journey-note">Loading journey…</p>}
      {state === 'forbidden' && (
        <p className="patient-flow-journey-note">
          This persona has no patient-journey access. The inspector below still carries the scene detail.
        </p>
      )}
      {state === 'error' && (
        <p className="patient-flow-journey-note">{errorMessage ?? 'Journey unavailable'}</p>
      )}

      {state === 'ok' && journey && (
        <>
          <div className="patient-flow-journey-controls">
            <div className="patient-flow-journey-align" role="radiogroup" aria-label="Align times">
              {ALIGN_OPTIONS.map((option) => (
                <button
                  key={option.key}
                  type="button"
                  role="radio"
                  aria-checked={align === option.key}
                  className={align === option.key ? 'active' : ''}
                  onClick={() => onAlignChange(option.key)}
                >
                  {option.label}
                </button>
              ))}
            </div>
            <div className="patient-flow-journey-actions">
              <button type="button" onClick={onFocus} title="Frame this patient's trace in the scene">
                Focus trace
              </button>
              <button
                type="button"
                aria-pressed={followEnabled}
                className={followEnabled ? 'active' : ''}
                onClick={() => onFollowToggle(!followEnabled)}
                title="Camera follows this patient during replay"
              >
                Follow
              </button>
              <button type="button" onClick={onCopyLink}>
                {copiedLink ? 'Link copied' : 'Copy link'}
              </button>
            </div>
          </div>

          {(journey.next.target_location || journey.next.expected_discharge_date) && (
            <section className="patient-flow-journey-section">
              <h3>Next</h3>
              <ul>
                {journey.next.target_location && (
                  <li>
                    <span>Target</span>
                    <span>{journey.next.target_location}</span>
                  </li>
                )}
                {journey.next.transport_needed_at && (
                  <li>
                    <span>Transport needed</span>
                    <span className="patient-flow-journey-num">{at(journey.next.transport_needed_at)}</span>
                  </li>
                )}
                {journey.next.expected_discharge_date && (
                  <li>
                    <span>Expected discharge</span>
                    <span className="patient-flow-journey-num">{journey.next.expected_discharge_date}</span>
                  </li>
                )}
              </ul>
            </section>
          )}

          <section className="patient-flow-journey-section">
            <h3>Stay segments</h3>
            {journey.segments.length === 0 && <p className="patient-flow-journey-note">No movement in window</p>}
            <ol className="patient-flow-journey-segments">
              {journey.segments.map((segment, index) => (
                <li key={`${segment.started_at}-${index}`} className={segment.open ? 'open' : ''}>
                  <div className="patient-flow-journey-segment-head">
                    <span>{segment.location_name ?? segment.location ?? 'Unknown'}</span>
                    <span className="patient-flow-journey-num">{dwellLabel(segment.dwell_minutes)}{segment.open ? ' · ongoing' : ''}</span>
                  </div>
                  <div className="patient-flow-journey-segment-sub patient-flow-journey-num">
                    {at(segment.started_at)}
                    {' → '}
                    {segment.ended_at ? at(segment.ended_at) : 'now'}
                    {segment.unit_code ? ` · ${segment.unit_code}` : ''}
                  </div>
                </li>
              ))}
            </ol>
          </section>

          {(journey.phases.ed.length > 0 || journey.phases.periop.length > 0) && (
            <section className="patient-flow-journey-section">
              <h3>Phases</h3>
              <ul>
                {journey.phases.ed.map((phase, index) => (
                  <li key={`ed-${phase.phase}-${index}`}>
                    <span>ED · {phaseLabel(phase.phase)}</span>
                    <span className="patient-flow-journey-num">
                      {phase.minutes !== null && phase.minutes !== undefined ? dwellLabel(phase.minutes) : 'ongoing'}
                    </span>
                  </li>
                ))}
                {journey.phases.periop.map((phase, index) => (
                  <li key={`periop-${phase.phase}-${index}`}>
                    <span>Periop · {phaseLabel(phase.phase)}</span>
                    <span className="patient-flow-journey-num">
                      {phase.minutes !== null && phase.minutes !== undefined ? dwellLabel(phase.minutes) : 'ongoing'}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {journey.milestones.length > 0 && (
            <section className="patient-flow-journey-section">
              <h3>Milestones</h3>
              <ul>
                {journey.milestones.map((milestone, index) => (
                  <li key={`${milestone.milestone_type}-${index}`}>
                    <span>
                      {phaseLabel(milestone.milestone_type)}
                      {milestone.required ? ' *' : ''}
                    </span>
                    <span className="patient-flow-journey-num">
                      {milestone.status}
                      {milestone.completed_at ? ` · ${at(milestone.completed_at)}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {journey.logistics.length > 0 && (
            <section className="patient-flow-journey-section">
              <h3>Logistics</h3>
              <ul>
                {journey.logistics.map((row, index) => (
                  <li key={`${row.domain}-${index}`}>
                    <span>
                      {row.domain === 'bed_request' ? 'Bed request' : row.domain === 'evs' ? 'EVS' : 'Transport'}
                      {row.destination ? ` → ${row.destination}` : row.required_unit_type ? ` → ${row.required_unit_type}` : ''}
                    </span>
                    <span className="patient-flow-journey-num">{row.status}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {journey.home.length > 0 && (
            <section className="patient-flow-journey-section">
              <h3>Home hospital</h3>
              <ul>
                {journey.home.map((episode, index) => (
                  <li key={`home-${index}`}>
                    <span>{episode.condition_label ?? 'Episode'}</span>
                    <span className="patient-flow-journey-num">{episode.status}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {journey.unit_context_barriers.length > 0 && (
            <section className="patient-flow-journey-section">
              <h3>Open barriers on stay units</h3>
              <p className="patient-flow-journey-note">Unit-level context — not attributed to this patient.</p>
              <ul>
                {journey.unit_context_barriers.map((barrier) => (
                  <li key={barrier.barrier_id}>
                    <span>{barrier.category}</span>
                    <span className="patient-flow-journey-num">{barrier.description ?? '—'}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <footer className="patient-flow-journey-foot patient-flow-journey-num">
            As of {new Date(journey.as_of).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
            {journey.epoch ? ` · epoch ${journey.epoch.epoch.slice(0, 8)}` : ''}
          </footer>
        </>
      )}
    </aside>
  );
}
