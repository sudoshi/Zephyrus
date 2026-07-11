import React, { useMemo } from 'react';
import type { ForecastAggregates } from '@/features/patientFlowNavigator/projections';

interface NavigatorChronobarProps {
  windowStart: number;
  windowEnd: number;
  nowMs: number;
  currentTime: number;
  /** Coverage of loaded flow events; null when no events are in the window. */
  dataStart: number | null;
  dataEnd: number | null;
  forecast: ForecastAggregates | null;
  /** When each open barrier began, for past-half ticks. */
  barrierTicks?: number[];
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
  });
}

function relativeLabel(ms: number, nowMs: number): string {
  const deltaHours = (ms - nowMs) / 3_600_000;
  if (Math.abs(deltaHours) < 0.05) return 'now';
  const rounded = Math.abs(deltaHours) >= 10 ? Math.round(Math.abs(deltaHours)) : Math.round(Math.abs(deltaHours) * 10) / 10;
  return deltaHours > 0 ? `now +${rounded}h` : `now −${rounded}h`;
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
  forecast,
  barrierTicks = [],
  onScrub,
}: NavigatorChronobarProps) {
  const span = Math.max(1, windowEnd - windowStart);
  const pct = (ms: number): number => Math.min(100, Math.max(0, ((ms - windowStart) / span) * 100));

  const detents = useMemo(() => shiftDetents(windowStart, windowEnd), [windowStart, windowEnd]);

  const coverage = useMemo(() => {
    if (dataStart === null || dataEnd === null) return null;
    const start = Math.max(dataStart, windowStart);
    const end = Math.min(dataEnd, nowMs);
    return end > start ? { start, end } : null;
  }, [dataStart, dataEnd, windowStart, nowMs]);

  const sliderValue = Math.round(((currentTime - windowStart) / span) * SLIDER_STEPS);
  const inFuture = currentTime > nowMs;

  return (
    <div className="patient-flow-chronobar">
      <output>
        <span>{fmtTime(currentTime)}</span>
        <span className={`patient-flow-chronobar-rel ${inFuture ? 'future' : ''}`}>
          {inFuture ? 'Projected · ' : ''}
          {relativeLabel(currentTime, nowMs)}
        </span>
      </output>

      <div className="patient-flow-chronobar-track" aria-hidden="true">
        <div className="patient-flow-chronobar-past" style={{ width: `${pct(nowMs)}%` }} />
        <div
          className="patient-flow-chronobar-future"
          style={{ left: `${pct(nowMs)}%`, width: `${100 - pct(nowMs)}%` }}
        />
        {coverage && (
          <div
            className="patient-flow-chronobar-coverage"
            style={{ left: `${pct(coverage.start)}%`, width: `${pct(coverage.end) - pct(coverage.start)}%` }}
          />
        )}
        {detents.map((detent) => (
          <span
            key={detent}
            className="patient-flow-chronobar-detent"
            style={{ left: `${pct(detent)}%` }}
            title={new Date(detent).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })}
          />
        ))}
        {barrierTicks.map((tick, index) => (
          <span
            key={`barrier-${index}-${tick}`}
            className="patient-flow-chronobar-barrier"
            style={{ left: `${pct(tick)}%` }}
            title={`Barrier opened ${fmtTime(tick)}`}
          />
        ))}
        <span className="patient-flow-chronobar-now" style={{ left: `${pct(nowMs)}%` }} title="Now" />
      </div>

      <input
        type="range"
        min="0"
        max={SLIDER_STEPS}
        value={Number.isFinite(sliderValue) ? Math.min(SLIDER_STEPS, Math.max(0, sliderValue)) : 0}
        aria-label="48-hour time scrubber (24h review, 24h projection)"
        onChange={(event) => onScrub(windowStart + (Number(event.target.value) / SLIDER_STEPS) * span)}
      />

      {coverage === null && (
        <p className="patient-flow-chronobar-empty">No replay events inside the 48h window</p>
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
