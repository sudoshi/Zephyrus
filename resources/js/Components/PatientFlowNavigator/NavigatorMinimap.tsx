import React, { useEffect, useMemo, useRef, useState } from 'react';
import type { PatientFlowLocations } from '@/features/patientFlowNavigator/types';
import type { OccupancyInsight } from '@/features/patientFlowNavigator/types';
import type { CameraView } from './NavigatorScene';
import { projectorForPoints } from '@/features/patientFlowNavigator/minimapProjection';

/**
 * Minimap / floor plate (E1): a top-down 2D schematic answering "where am I"
 * at a glance. Location dots, delay/deviation pips (shape-coded, never color
 * alone — CVD safe), the camera footprint (target + look direction), and the
 * selection dot. Click a spot to fly there; the header button fits the floor.
 *
 * Camera + selection are POLLED from the scene (not props) so the parent never
 * re-renders on the ~7 Hz camera stream — only this small SVG updates.
 * Suppressed on wall/kiosk (?wall=1) by the orchestrator.
 */

interface NavigatorMinimapProps {
  locations: PatientFlowLocations;
  /** Current floor filter ('all' or a floor number). */
  floor: string;
  /** Delayed/watch occupancy insights — the delay pips. */
  delayed: OccupancyInsight[];
  /** Location codes with an observed pathway deviation — the deviation pips. */
  deviationLocations: Set<string>;
  /** Reads the live camera pose (target + position) from the scene. */
  getCameraView: () => CameraView | null;
  /** Reads the current selection's world position from the scene. */
  getSelectionPoint: () => { x: number; y: number; z: number } | null;
  /** Fly to a clicked world point. */
  onNavigate: (point: { x: number; y: number; z: number }) => void;
  /** Frame the whole current floor (the header button). */
  onFitFloor: () => void;
}

const SIZE = 168;
const POLL_MS = 160;

export default function NavigatorMinimap({
  locations,
  floor,
  delayed,
  deviationLocations,
  getCameraView,
  getSelectionPoint,
  onNavigate,
  onFitFloor,
}: NavigatorMinimapProps) {
  const [open, setOpen] = useState(true);

  // Floor-filtered location points (world XZ) + the projector over them.
  const floorPoints = useMemo(() => {
    return Object.values(locations)
      .filter((loc) => loc.position_m && (floor === 'all' || String(loc.floor) === floor))
      .map((loc) => ({
        code: loc.location_code,
        x: loc.position_m!.x,
        z: loc.position_m!.z,
        y: loc.position_m!.y ?? 0,
      }));
  }, [locations, floor]);

  const projector = useMemo(
    () => projectorForPoints(floorPoints.map((point) => ({ x: point.x, z: point.z }))),
    [floorPoints],
  );

  // Poll the scene for camera + selection so the parent is never re-rendered
  // by the camera stream. Only this component ticks.
  const [camera, setCamera] = useState<CameraView | null>(null);
  const [selection, setSelection] = useState<{ x: number; y: number; z: number } | null>(null);
  const cameraKey = useRef('');
  const selectionKey = useRef('');
  useEffect(() => {
    if (!open) return undefined;
    const tick = (): void => {
      const view = getCameraView();
      const key = view ? `${view.target.x.toFixed(0)}|${view.target.z.toFixed(0)}|${view.position.x.toFixed(0)}|${view.position.z.toFixed(0)}` : '';
      if (key !== cameraKey.current) { cameraKey.current = key; setCamera(view); }
      const point = getSelectionPoint();
      const skey = point ? `${point.x.toFixed(1)}|${point.z.toFixed(1)}` : '';
      if (skey !== selectionKey.current) { selectionKey.current = skey; setSelection(point); }
    };
    tick();
    const id = window.setInterval(tick, POLL_MS);
    return () => window.clearInterval(id);
  }, [open, getCameraView, getSelectionPoint]);

  if (!projector || floorPoints.length === 0) return null;

  const toXY = (x: number, z: number): { x: number; y: number } => {
    const { u, v } = projector.project(x, z);
    return { x: u * SIZE, y: v * SIZE };
  };

  const delayedByCode = new Map(delayed.map((insight) => [insight.location, insight]));

  const handleClick = (event: React.MouseEvent<SVGSVGElement>): void => {
    const rect = event.currentTarget.getBoundingClientRect();
    const u = (event.clientX - rect.left) / rect.width;
    const v = (event.clientY - rect.top) / rect.height;
    const world = projector.unproject(u, v);
    onNavigate({ x: world.x, y: 0, z: world.z });
  };

  const cameraTarget = camera ? toXY(camera.target.x, camera.target.z) : null;
  const cameraFrom = camera ? toXY(camera.position.x, camera.position.z) : null;
  const selectionXY = selection ? toXY(selection.x, selection.z) : null;

  return (
    <section className="patient-flow-minimap" aria-label="Floor minimap">
      <button
        type="button"
        className="patient-flow-minimap-header"
        aria-expanded={open}
        title={open ? 'Collapse minimap' : 'Expand minimap'}
        onClick={() => setOpen((value) => !value)}
      >
        <span>{floor === 'all' ? 'House' : `Floor ${floor}`}</span>
        <span aria-hidden="true">{open ? '▾' : '▸'}</span>
      </button>

      {open && (
        <div className="patient-flow-minimap-body">
          <svg
            width={SIZE}
            height={SIZE}
            viewBox={`0 0 ${SIZE} ${SIZE}`}
            role="img"
            aria-label={`Top-down map of ${floor === 'all' ? 'the house' : `floor ${floor}`}. Click to fly there.`}
            onClick={handleClick}
          >
            {/* Location dots — the plate. */}
            {floorPoints.map((point) => {
              const { x, y } = toXY(point.x, point.z);
              const isDeviant = deviationLocations.has(point.code);
              const delayInsight = delayedByCode.get(point.code);
              if (isDeviant) {
                // Deviation pip — a square (bracket echo), never color alone.
                return (
                  <rect
                    key={point.code}
                    x={x - 3}
                    y={y - 3}
                    width={6}
                    height={6}
                    className="patient-flow-minimap-deviation"
                  />
                );
              }
              if (delayInsight && delayInsight.primaryStatus !== 'ok') {
                // Delay pip — a triangle (echoes the scene's delayed cue).
                return (
                  <polygon
                    key={point.code}
                    points={`${x},${y - 4} ${x - 3.5},${y + 3} ${x + 3.5},${y + 3}`}
                    className={`patient-flow-minimap-delay status-${delayInsight.primaryStatus}`}
                  />
                );
              }
              return <circle key={point.code} cx={x} cy={y} r={1.6} className="patient-flow-minimap-dot" />;
            })}

            {/* Camera footprint: look direction line + target crosshair. */}
            {cameraTarget && cameraFrom && (
              <line
                x1={cameraFrom.x}
                y1={cameraFrom.y}
                x2={cameraTarget.x}
                y2={cameraTarget.y}
                className="patient-flow-minimap-look"
              />
            )}
            {cameraTarget && (
              <g className="patient-flow-minimap-camera">
                <circle cx={cameraTarget.x} cy={cameraTarget.y} r={5} />
                <line x1={cameraTarget.x - 7} y1={cameraTarget.y} x2={cameraTarget.x + 7} y2={cameraTarget.y} />
                <line x1={cameraTarget.x} y1={cameraTarget.y - 7} x2={cameraTarget.x} y2={cameraTarget.y + 7} />
              </g>
            )}

            {/* Selection dot — a ring so it reads over any pip beneath it. */}
            {selectionXY && (
              <circle cx={selectionXY.x} cy={selectionXY.y} r={4} className="patient-flow-minimap-selection" />
            )}
          </svg>

          <button type="button" className="patient-flow-minimap-fit" onClick={onFitFloor}>
            Fit floor
          </button>
        </div>
      )}
    </section>
  );
}
