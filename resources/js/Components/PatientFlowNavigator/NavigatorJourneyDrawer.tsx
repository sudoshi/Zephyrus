import React, { useState } from 'react';

import {
  deviationEvidence,
  elementsMet,
  expectedObservedLanes,
  pathwayLabel,
  translateDeviation,
  PATHWAY_SPECS,
  PATTERN_LABELS,
} from '@/features/patientFlowNavigator/adherence';
import type { CaseVerdict } from '@/features/patientFlowNavigator/adherenceSchemas';
import {
  alignAnchorMs,
  alignedTimeLabel,
  dwellLabel,
} from '@/features/patientFlowNavigator/journey';
import type { JourneyAlignAnchor } from '@/features/patientFlowNavigator/journey';
import type {
  JourneyPathwayMilestone,
  JourneyPathwayProgress,
  PatientJourney,
} from '@/features/patientFlowNavigator/journeySchemas';

/**
 * The Patient Journey Drawer (FLOW-4D plan §7.1, finding PJ-1) — the plain-
 * HTML, AT-primary patient surface. Replaces the key/value inspector for
 * PATIENT selections only; every label comes from the lens-redacted journey
 * payload (the raw ref never reaches this component by construction).
 * Interval-first: dwell segments and phases are the story, events the detail.
 */

export type JourneyDrawerState = 'idle' | 'loading' | 'ok' | 'forbidden' | 'error';

export interface DrawerAdherence {
  state: 'loading' | 'ok' | 'error';
  verdicts: CaseVerdict[];
  /** Newest batch timestamp across the verdicts (C4 freshness honesty). */
  asOf: string | null;
  cadenceMinutes: number;
}

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
  /** Phase C adherence panel (§7.2). Absent (null) = surface off — the
   * drawer renders byte-identical to Phase B. */
  adherence?: DrawerAdherence | null;
  /** Phase D governed-pathway progress (§8 D5). Absent (null) = both the
   * serving gate and the demo overlay are off — nothing renders. Comes from the
   * journey payload; the orchestrator applies the belt-and-suspenders flag gate. */
  pathwayProgress?: JourneyPathwayProgress | null;
  /** Drafts a governed exception note; resolves true when it lands PENDING. */
  onExceptionNote?: (pathway: string, deviations: string[], note: string) => Promise<boolean>;
  /** "Explain this deviation" via the AI plane — only wired when
   * ARENA_AI_ENABLED (the copilot narrative path). */
  onExplainDeviation?: (pathway: string, deviationLabel: string, evidence: string | null) => void;
}

const ALIGN_OPTIONS: Array<{ key: JourneyAlignAnchor; label: string }> = [
  { key: 'clock', label: 'Clock' },
  { key: 'arrival', label: 'From arrival' },
  { key: 'admit', label: 'From admit' },
];

function phaseLabel(phase: string): string {
  return phase.replaceAll('_', ' ');
}

function batchTimeLabel(iso: string | null): string {
  if (!iso) return 'batch pending';
  const ms = Date.parse(iso);
  if (!Number.isFinite(ms)) return 'batch pending';
  return `as of ${new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} batch`;
}

type NoteState = { mode: 'idle' | 'editing' | 'sending' | 'sent' | 'failed'; text: string };

/**
 * The adherence panel (§7.2 C1): per cached pathway verdict — an ERAS
 * elements-met headline, pattern-language deviations with computed evidence,
 * an expected-vs-observed strip, a provenance + batch-time line (C4: never
 * "live"), and the governed exception-note draft. Variance-to-review framing
 * throughout — this is state to review, never a clinician scoreboard (CF-5).
 */
function AdherencePanel({
  adherence,
  at,
  onExceptionNote,
  onExplainDeviation,
}: {
  adherence: DrawerAdherence;
  at: (iso: string | null | undefined) => string;
  onExceptionNote?: (pathway: string, deviations: string[], note: string) => Promise<boolean>;
  onExplainDeviation?: (pathway: string, deviationLabel: string, evidence: string | null) => void;
}) {
  const [notes, setNotes] = useState<Record<string, NoteState>>({});

  const noteFor = (pathway: string): NoteState => notes[pathway] ?? { mode: 'idle', text: '' };
  const setNote = (pathway: string, next: NoteState): void =>
    setNotes((previous) => ({ ...previous, [pathway]: next }));

  const submitNote = (pathway: string, deviations: string[]): void => {
    const current = noteFor(pathway);
    if (!onExceptionNote || current.text.trim().length === 0) return;
    setNote(pathway, { ...current, mode: 'sending' });
    void onExceptionNote(pathway, deviations, current.text.trim()).then((landed) => {
      setNote(pathway, { mode: landed ? 'sent' : 'failed', text: landed ? '' : current.text });
    });
  };

  return (
    <section className="patient-flow-journey-section" aria-label="Pathway adherence">
      <h3>Pathways</h3>
      {adherence.state === 'loading' && (
        <p className="patient-flow-journey-note">Checking pathway conformance…</p>
      )}
      {adherence.state === 'error' && (
        <p className="patient-flow-journey-note">Pathway conformance unavailable</p>
      )}
      {adherence.state === 'ok' && adherence.verdicts.length === 0 && (
        <p className="patient-flow-journey-note">
          Not on a monitored pathway in the current conformance batch.
        </p>
      )}
      {adherence.state === 'ok' && adherence.verdicts.map((verdict) => {
        const codes = verdict.deviations ?? [];
        const timeline = verdict.activity_timeline ?? {};
        const { met, total } = elementsMet(verdict.pathway, codes);
        const spec = PATHWAY_SPECS[verdict.pathway];
        const lanes = expectedObservedLanes(verdict.pathway, timeline);
        const note = noteFor(verdict.pathway);
        return (
          <details
            key={verdict.pathway}
            className="patient-flow-adherence"
            open={!verdict.conformant}
          >
            <summary>
              <span>{pathwayLabel(verdict.pathway)}</span>
              <span className={`patient-flow-adherence-headline patient-flow-journey-num${verdict.conformant ? '' : ' deviant'}`}>
                {`${met} of ${total} elements met`}
              </span>
            </summary>

            {codes.length > 0 && (
              <ul className="patient-flow-adherence-deviations">
                {codes.map((code) => {
                  const translated = translateDeviation(verdict.pathway, code);
                  const evidence = deviationEvidence(code, timeline);
                  return (
                    <li key={code}>
                      <span className="patient-flow-adherence-pattern">{PATTERN_LABELS[translated.pattern]}</span>
                      <span>
                        {translated.label}
                        {evidence ? <small> — {evidence}</small> : null}
                      </span>
                      {onExplainDeviation && (
                        <button
                          type="button"
                          title="Ask the copilot to explain this deviation with its evidence"
                          onClick={() => onExplainDeviation(verdict.pathway, translated.label, evidence)}
                        >
                          Explain
                        </button>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}

            {lanes.length > 1 && (
              <ol className="patient-flow-adherence-lanes">
                {lanes.map((lane) => (
                  <li key={lane.key} className={lane.observedAt ? 'observed' : ''}>
                    <span>{lane.label}</span>
                    <span className="patient-flow-journey-num">
                      {lane.observedAt ? at(lane.observedAt) : 'not observed'}
                    </span>
                  </li>
                ))}
              </ol>
            )}

            <p className="patient-flow-adherence-provenance">
              Against {pathwayLabel(verdict.pathway)} v{verdict.pathway_version ?? spec?.version ?? 1}
              {spec ? ` · owner: ${spec.ownerLabel}` : ''}
              {' · '}
              <span className="patient-flow-journey-num">{batchTimeLabel(verdict.computed_at ?? adherence.asOf)}</span>
              {` · ${adherence.cadenceMinutes}-minute batch`}
            </p>

            {!verdict.conformant && onExceptionNote && (
              <div className="patient-flow-adherence-note">
                {note.mode === 'idle' && (
                  <button type="button" onClick={() => setNote(verdict.pathway, { mode: 'editing', text: '' })}>
                    Open an exception note
                  </button>
                )}
                {(note.mode === 'editing' || note.mode === 'sending' || note.mode === 'failed') && (
                  <>
                    <textarea
                      value={note.text}
                      rows={3}
                      maxLength={2000}
                      placeholder="Clinical context for this variance — lands as a pending draft for review."
                      aria-label={`Exception note for ${pathwayLabel(verdict.pathway)}`}
                      disabled={note.mode === 'sending'}
                      onChange={(event) => setNote(verdict.pathway, { ...note, mode: 'editing', text: event.target.value })}
                    />
                    <div className="patient-flow-adherence-note-actions">
                      <button
                        type="button"
                        disabled={note.mode === 'sending' || note.text.trim().length === 0}
                        onClick={() => submitNote(verdict.pathway, codes)}
                      >
                        {note.mode === 'sending' ? 'Drafting…' : 'Draft for review'}
                      </button>
                      <button
                        type="button"
                        disabled={note.mode === 'sending'}
                        onClick={() => setNote(verdict.pathway, { mode: 'idle', text: '' })}
                      >
                        Cancel
                      </button>
                    </div>
                    {note.mode === 'failed' && (
                      <p className="patient-flow-journey-note">Draft failed — nothing was recorded. Try again.</p>
                    )}
                  </>
                )}
                {note.mode === 'sent' && (
                  <p className="patient-flow-journey-note">Exception note drafted — pending human review.</p>
                )}
              </div>
            )}
          </details>
        );
      })}
    </section>
  );
}

// Status by glyph AND word — never colour alone (token canon). Decorative glyph
// is aria-hidden; the word carries the state to assistive tech.
const PATHWAY_STATUS_GLYPH: Record<string, string> = {
  completed: '✓',
  current: '◈',
  delayed: '!',
  canceled: '✕',
  planned: '○',
};

function pathwayExpectedLabel(milestone: JourneyPathwayMilestone): string {
  const expected = milestone.expected;
  if (expected?.display) return expected.display;
  const min = expected?.day_offset_min;
  const max = expected?.day_offset_max;
  if (typeof min === 'number' && typeof max === 'number' && min !== max) return `day ${min}–${max}`;
  const day = typeof max === 'number' ? max : min;
  return typeof day === 'number' ? `day ${day}` : '';
}

/**
 * Phase D governed-pathway progress (§8 D5). Shows a patient's assigned-pathway
 * stage/milestone progress — dark serving read OR the synthetic demo overlay.
 * Never guidance: `clinical_use` is always false, and the demo carries an
 * explicit "not clinical guidance" notice. ERAS elements-met framing mirrors the
 * adherence panel; status is glyph + word, never colour alone.
 */
function PathwayProgressPanel({ progress }: { progress: JourneyPathwayProgress }) {
  const { pathway, summary } = progress;
  return (
    <section className="patient-flow-journey-section" aria-label="Assigned pathway progress">
      <h3>
        Pathway
        {progress.demo && (
          <span className="patient-flow-pathway-demo" title="Synthetic demonstration — not clinical guidance">
            Demo
          </span>
        )}
      </h3>
      <div className="patient-flow-pathway-head">
        <span className="patient-flow-pathway-name">{pathway.label}</span>
        <span className="patient-flow-pathway-headline patient-flow-journey-num">{summary.elements_met_label}</span>
      </div>
      {progress.notice && <p className="patient-flow-journey-note">{progress.notice}</p>}
      <ol className="patient-flow-pathway-milestones">
        {progress.milestones.map((milestone) => {
          const expected = pathwayExpectedLabel(milestone);
          return (
            <li key={milestone.stable_key} className={`patient-flow-pathway-milestone status-${milestone.status}`}>
              <span className="patient-flow-pathway-glyph" aria-hidden="true">
                {PATHWAY_STATUS_GLYPH[milestone.status] ?? '○'}
              </span>
              <span className="patient-flow-pathway-title">{milestone.title}</span>
              <span className="patient-flow-pathway-meta">
                <span className="patient-flow-pathway-state">{milestone.status}</span>
                {expected ? <span className="patient-flow-journey-num"> · {expected}</span> : null}
              </span>
            </li>
          );
        })}
      </ol>
      <p className="patient-flow-journey-provenance">
        {pathway.semantic_version ? `Against ${pathway.key} v${pathway.semantic_version}` : pathway.key}
        {progress.source === 'synthetic_demo' ? ' · synthetic demo' : ` · ${batchTimeLabel(progress.as_of)}`}
      </p>
    </section>
  );
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
  adherence = null,
  pathwayProgress = null,
  onExceptionNote,
  onExplainDeviation,
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

          {adherence && (
            <AdherencePanel
              adherence={adherence}
              at={at}
              onExceptionNote={onExceptionNote}
              onExplainDeviation={onExplainDeviation}
            />
          )}

          {pathwayProgress && <PathwayProgressPanel progress={pathwayProgress} />}

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
