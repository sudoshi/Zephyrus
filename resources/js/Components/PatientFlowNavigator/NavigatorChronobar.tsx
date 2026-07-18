import React, { useMemo } from 'react';
import type { ForecastAggregates } from '@/features/patientFlowNavigator/projections';
import type { PatientFlowFreshness } from '@/features/patientFlowNavigator/types';
import { formatDurationSeconds } from '@/lib/duration';

interface NavigatorChronobarProps {
  windowStart: number;
  windowEnd: number;
  nowMs: number;
  currentTime: number;
  /** Coverage of loaded flow events; null when no events are in the window. */
  dataStart: number | null;
  dataEnd: number | null;
  historical: boolean;
  freshness: PatientFlowFreshness;
  forecast: ForecastAggregates | null;
  /** When each open barrier began, for past-half ticks. */
  barrierTicks?: number[];
  /** True while the stored-replay stream is connected (N-8: never "live"). */
  replaying?: boolean;
  onScrub: (timeMs: number) => void;
}

const SLIDER_STEPS = 10000;

function fmtTime(ms: number): string {
  if (!Number.isFinite(ms) || ms <= 0) return '--';
  return new Date(ms).toLocaleString([], {
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

function relativeLabel(ms: number, nowMs: number): string {
  const deltaSeconds = (ms - nowMs) / 1_000;
  if (Math.abs(deltaSeconds) < 1) return 'now';
  const duration = formatDurationSeconds(Math.abs(deltaSeconds));
  return deltaSeconds > 0 ? `now +${duration}` : `now −${duration}`;
}

/** Shift-change detents (07:00 / 19:00 local) inside the window. */
function shiftDetents(windowStart: number, windowEnd: number): number[] {
  const detents: number[] = [];
  const cursor = new Date(windowStart);
  cursor.setMinutes(0, 0, 0);
  while (cursor.getTime() <= windowEnd) {
    if (cursor.getHours() === 7 || cursor.getHours() === 19) {
      const t = cursor.getTime();
      if (t >= windowStart) detents.push(t);
    }
    cursor.setHours(cursor.getHours() + 1);
  }
  return detents;
}

/**
 * The 48h Chronobar (FLOW-WINDOW-PLAN §5): [now−24h, now+24h], solid past,
 * dashed future, gold now-marker, 07:00/19:00 shift detents, dimmed track for
 * data-sparse regions. The native range input stays for interaction and
 * accessibility; the annotated strip above it carries the time grammar.
 */
export default function NavigatorChronobar({
  windowStart,
  windowEnd,
  nowMs,
  currentTime,
  dataStart,
  dataEnd,
  historical,
  freshness,
  forecast,
  barrierTicks = [],
  replaying = false,
  onScrub,
}: NavigatorChronobarProps) {
  const span = Math.max(1, windowEnd - windowStart);
  const pct = (ms: number): number => Math.min(100, Math.max(0, ((ms - windowStart) / span) * 100));

  const detents = useMemo(() => shiftDetents(windowStart, windowEnd), [windowStart, windowEnd]);

  const coverage = useMemo(() => {
    if (dataStart === null || dataEnd === null) return null;
    const start = Math.max(dataStart, windowStart);
    const end = Math.min(dataEnd, windowEnd);
    return end >= start ? { start, end } : null;
  }, [dataStart, dataEnd, windowEnd, windowStart]);

  const sliderValue = Math.round(((currentTime - windowStart) / span) * SLIDER_STEPS);
  const inFuture = currentTime > nowMs;

  return (
    <div className="patient-flow-chronobar">
      <div className="patient-flow-chronobar-head">
        <output>
          <span>{fmtTime(currentTime)}</span>
          <span className={`patient-flow-chronobar-rel ${inFuture ? 'future' : ''}`}>
            {historical
              ? `Historical replay · ${relativeLabel(currentTime, nowMs)}`
              : `${replaying ? 'Replay stream · ' : ''}${inFuture ? 'Projected · ' : ''}${relativeLabel(currentTime, nowMs)}`}
          </span>
        </output>
        <button
          type="button"
          className="patient-flow-chronobar-now-button"
          disabled={historical}
          title={historical ? 'Unavailable in historical replay' : 'Jump to now'}
          onClick={() => onScrub(nowMs)}
        >
          Now
        </button>
      </div>

      {/* Detents and barrier ticks are jump buttons (N-2); the wash layers
          underneath stay decorative. */}
      <div className="patient-flow-chronobar-track">
        <div aria-hidden="true" className="patient-flow-chronobar-past" style={{ width: historical ? '100%' : `${pct(nowMs)}%` }} />
        {!historical && (
          <div
            aria-hidden="true"
            className="patient-flow-chronobar-future"
            style={{ left: `${pct(nowMs)}%`, width: `${100 - pct(nowMs)}%` }}
          />
        )}
        {coverage && (
          <div
            aria-hidden="true"
            className="patient-flow-chronobar-coverage"
            style={{ left: `${pct(coverage.start)}%`, width: `${pct(coverage.end) - pct(coverage.start)}%` }}
          />
        )}
        {detents.map((detent) => {
          const detentLabel = new Date(detent).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });
          return (
            <button
              key={detent}
              type="button"
              className="patient-flow-chronobar-detent"
              style={{ left: `${pct(detent)}%` }}
              title={`Jump to ${detentLabel} shift change`}
              aria-label={`Jump to ${detentLabel} shift change`}
              onClick={() => onScrub(detent)}
            />
          );
        })}
        {barrierTicks.map((tick, index) => (
          <button
            key={`barrier-${index}-${tick}`}
            type="button"
            className="patient-flow-chronobar-barrier"
            style={{ left: `${pct(tick)}%` }}
            title={`Jump to barrier opened ${fmtTime(tick)}`}
            aria-label={`Jump to barrier opened ${fmtTime(tick)}`}
            onClick={() => onScrub(tick)}
          />
        ))}
        {!historical && nowMs >= windowStart && nowMs <= windowEnd && (
          <span aria-hidden="true" className="patient-flow-chronobar-now" style={{ left: `${pct(nowMs)}%` }} title="Now" />
        )}
      </div>

      <input
        type="range"
        min="0"
        max={SLIDER_STEPS}
        value={Number.isFinite(sliderValue) ? Math.min(SLIDER_STEPS, Math.max(0, sliderValue)) : 0}
        aria-label={historical ? 'Historical patient flow time scrubber' : '48-hour time scrubber (24h review, 24h projection)'}
        onChange={(event) => onScrub(windowStart + (Number(event.target.value) / SLIDER_STEPS) * span)}
      />

      {coverage === null ? (
        <p className="patient-flow-chronobar-empty">No replay events available</p>
      ) : (
        <p className={`patient-flow-chronobar-extent ${freshness}`}>
          {freshness === 'stale' ? 'Stale source' : freshness} · {fmtTime(dataStart!)} to {fmtTime(dataEnd!)}
        </p>
      )}

      {inFuture && forecast && (
        <dl className="patient-flow-forecast-hud">
          {forecast.censusTotal !== null && (
            <div>
              <dt>Census</dt>
              <dd>{forecast.censusTotal}</dd>
            </div>
          )}
          {forecast.arrivals && forecast.arrivals.value !== null && (
            <div>
              <dt>Arrivals/h</dt>
              <dd>
                {forecast.arrivals.value}
                {forecast.arrivals.band && (
                  <small> {forecast.arrivals.band.lower}–{forecast.arrivals.band.upper}</small>
                )}
              </dd>
            </div>
          )}
          {forecast.surge && forecast.surge.value !== null && (
            <div>
              <dt>Surge</dt>
              <dd>{forecast.surge.value}%</dd>
            </div>
          )}
          {forecast.staffingGaps > 0 && (
            <div>
              <dt>Shift gaps</dt>
              <dd>{forecast.staffingGaps}</dd>
            </div>
          )}
        </dl>
      )}
    </div>
  );
}
