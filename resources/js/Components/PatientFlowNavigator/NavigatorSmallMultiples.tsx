import React, { useCallback, useMemo, useRef, useState } from 'react';
import type { PatientFlowEvent, PatientFlowLocations } from '@/features/patientFlowNavigator/types';
import { heatBounds, hourlySlices } from '@/features/patientFlowNavigator/trailHeat';
import type { HourlySlice } from '@/features/patientFlowNavigator/trailHeat';

/**
 * Hourly small multiples (E4): six top-down density plates for the last six
 * hours, side by side — the analysis path that replaces forced replay. A quiet
 * hour reads quiet (patient count shown), a busy corridor reads hot. Density is
 * opacity on ONE neutral ink (aggregate flow, never a status color). Collapsed
 * by default; "Export" saves the strip as an SVG for a report.
 */

interface NavigatorSmallMultiplesProps {
  tracks: Map<string, PatientFlowEvent[]>;
  locations: PatientFlowLocations;
  floor: string;
  /** End of the window (wall-clock now, or the scrubbed instant). */
  endMs: number;
  /** E4 GSTC flatten: whether the last-6h density is drawn on the 3D floor. */
  floorHeatOn: boolean;
  onToggleFloorHeat: () => void;
}

const TILE = 84;

function SlicePlate({ slice }: { slice: HourlySlice }) {
  const { grid } = slice;
  const cellW = TILE / grid.cols;
  const cellH = TILE / grid.rows;
  return (
    <figure className="patient-flow-sm-plate">
      <svg width={TILE} height={TILE} viewBox={`0 0 ${TILE} ${TILE}`} role="img" aria-label={`Flow density ${slice.label}, ${slice.patients} patients`}>
        <rect x={0} y={0} width={TILE} height={TILE} className="patient-flow-sm-bg" />
        {grid.cells.map((count, index) => {
          if (count === 0 || grid.max === 0) return null;
          const col = index % grid.cols;
          const row = Math.floor(index / grid.cols);
          return (
            <rect
              key={index}
              x={col * cellW}
              y={row * cellH}
              width={cellW}
              height={cellH}
              className="patient-flow-sm-cell"
              fillOpacity={0.12 + 0.88 * (count / grid.max)}
            />
          );
        })}
      </svg>
      <figcaption>
        <span>{slice.label}</span>
        <small>{slice.patients} pt</small>
      </figcaption>
    </figure>
  );
}

export default function NavigatorSmallMultiples({
  tracks,
  locations,
  floor,
  endMs,
  floorHeatOn,
  onToggleFloorHeat,
}: NavigatorSmallMultiplesProps) {
  const [open, setOpen] = useState(false);
  const stripRef = useRef<HTMLDivElement>(null);

  const bounds = useMemo(() => heatBounds(locations, floor), [locations, floor]);
  const slices = useMemo(
    () => (bounds ? hourlySlices(tracks, locations, bounds, endMs, 6, floor) : []),
    [bounds, tracks, locations, endMs, floor],
  );

  const exportStrip = useCallback((): void => {
    const svgs = stripRef.current?.querySelectorAll('svg');
    if (!svgs || svgs.length === 0) return;
    const width = TILE * svgs.length + 8 * (svgs.length - 1);
    const parts: string[] = [];
    svgs.forEach((svg, index) => {
      parts.push(`<g transform="translate(${index * (TILE + 8)},0)">${svg.innerHTML}</g>`);
    });
    const doc = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${TILE}" viewBox="0 0 ${width} ${TILE}"><rect width="${width}" height="${TILE}" fill="#121514"/>${parts.join('')}</svg>`;
    const blob = new Blob([doc], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `flow-6h-${floor === 'all' ? 'house' : `floor${floor}`}.svg`;
    anchor.click();
    URL.revokeObjectURL(url);
  }, [floor]);

  if (!bounds) return null;

  return (
    <section className="patient-flow-small-multiples" aria-label="Hourly flow density">
      <button
        type="button"
        className="patient-flow-sm-toggle"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        Last 6 h <span aria-hidden="true">{open ? '▾' : '▸'}</span>
      </button>

      {open && (
        <div className="patient-flow-sm-body">
          <div className="patient-flow-sm-strip" ref={stripRef}>
            {slices.map((slice) => (
              <SlicePlate key={slice.startMs} slice={slice} />
            ))}
          </div>
          <div className="patient-flow-sm-footer">
            <span>Flow density · one hour per plate · analysis, not live</span>
            <div className="patient-flow-sm-actions">
              <button
                type="button"
                className={`patient-flow-sm-floor ${floorHeatOn ? 'active' : ''}`}
                aria-pressed={floorHeatOn}
                title="Flatten the last 6 hours of movement onto the 3D floor"
                onClick={onToggleFloorHeat}
              >
                On floor
              </button>
              <button type="button" className="patient-flow-sm-export" onClick={exportStrip}>
                Export SVG
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
