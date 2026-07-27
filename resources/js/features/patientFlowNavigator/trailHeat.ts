// Trail heat + small multiples (E4): the analysis path that replaces forced
// replay. Instead of scrubbing time, flatten movement over a window into a
// density grid (GSTC flatten), and slice the last N hours into small multiples
// so a shift's flow reads at a glance. Pure + pinned by trailHeat.test.ts.
import type { PatientFlowEvent, PatientFlowLocations } from './types';
import { parseTime, positionFor } from './stateProjection';

export interface HeatBounds {
  minX: number;
  maxX: number;
  minZ: number;
  maxZ: number;
}

export interface DensityGrid {
  cols: number;
  rows: number;
  /** Row-major counts, length cols*rows. */
  cells: number[];
  /** The single busiest cell count, for normalization. */
  max: number;
}

export interface HourlySlice {
  startMs: number;
  endMs: number;
  label: string;
  grid: DensityGrid;
  /** Distinct patients present in this hour. */
  patients: number;
}

/** Padded XZ bounds over the floor's placed locations (the grid's frame). */
export function heatBounds(locations: PatientFlowLocations, floor: string): HeatBounds | null {
  let minX = Infinity;
  let maxX = -Infinity;
  let minZ = Infinity;
  let maxZ = -Infinity;
  let any = false;
  for (const loc of Object.values(locations)) {
    if (!loc.position_m) continue;
    if (floor !== 'all' && String(loc.floor) !== floor) continue;
    any = true;
    minX = Math.min(minX, loc.position_m.x);
    maxX = Math.max(maxX, loc.position_m.x);
    minZ = Math.min(minZ, loc.position_m.z);
    maxZ = Math.max(maxZ, loc.position_m.z);
  }
  if (!any) return null;
  const padX = Math.max(1, (maxX - minX) * 0.06);
  const padZ = Math.max(1, (maxZ - minZ) * 0.06);
  return { minX: minX - padX, maxX: maxX + padX, minZ: minZ - padZ, maxZ: maxZ + padZ };
}

/**
 * Bin the presence points (resolved event locations) in [startMs, endMs] into
 * a cols×rows density grid over `bounds`. Each in-window event on a matching
 * floor adds one to its cell — where flow concentrated, without replay.
 */
export function densityGrid(
  tracks: Map<string, PatientFlowEvent[]>,
  locations: PatientFlowLocations,
  bounds: HeatBounds,
  startMs: number,
  endMs: number,
  cols = 12,
  rows = 12,
  floor = 'all',
): DensityGrid {
  const cells = new Array<number>(cols * rows).fill(0);
  const spanX = Math.max(1e-6, bounds.maxX - bounds.minX);
  const spanZ = Math.max(1e-6, bounds.maxZ - bounds.minZ);
  let max = 0;

  for (const track of tracks.values()) {
    for (const event of track) {
      const at = parseTime(event.occurred_at);
      if (at < startMs || at > endMs) continue;
      if (floor !== 'all' && event.location_floor != null && String(event.location_floor) !== floor) continue;
      const position = positionFor(locations, event.to_location);
      if (!position) continue;
      const u = (position.x - bounds.minX) / spanX;
      const v = (position.z - bounds.minZ) / spanZ;
      if (u < 0 || u > 1 || v < 0 || v > 1) continue;
      const col = Math.min(cols - 1, Math.floor(u * cols));
      const row = Math.min(rows - 1, Math.floor(v * rows));
      const index = row * cols + col;
      cells[index] += 1;
      if (cells[index] > max) max = cells[index];
    }
  }

  return { cols, rows, cells, max };
}

function pad2(value: number): string {
  return value < 10 ? `0${value}` : String(value);
}

function hourLabel(startMs: number): string {
  const date = new Date(startMs);
  return `${pad2(date.getHours())}:00`;
}

/**
 * Slice [endMs − hours·h, endMs] into `hours` hourly density grids (oldest
 * first) — the small-multiples strip. Each slice also counts distinct patients
 * present, so a quiet hour reads as quiet, not just empty.
 */
export function hourlySlices(
  tracks: Map<string, PatientFlowEvent[]>,
  locations: PatientFlowLocations,
  bounds: HeatBounds,
  endMs: number,
  hours = 6,
  floor = 'all',
  cols = 12,
  rows = 12,
): HourlySlice[] {
  const hourMs = 3_600_000;
  const slices: HourlySlice[] = [];
  for (let index = hours - 1; index >= 0; index -= 1) {
    const sliceEnd = endMs - index * hourMs;
    const sliceStart = sliceEnd - hourMs;
    const grid = densityGrid(tracks, locations, bounds, sliceStart, sliceEnd, cols, rows, floor);
    const patients = new Set<string>();
    for (const [patientId, track] of tracks.entries()) {
      if (track.some((event) => {
        const at = parseTime(event.occurred_at);
        return at >= sliceStart && at <= sliceEnd
          && (floor === 'all' || event.location_floor == null || String(event.location_floor) === floor);
      })) {
        patients.add(patientId);
      }
    }
    slices.push({ startMs: sliceStart, endMs: sliceEnd, label: hourLabel(sliceStart), grid, patients: patients.size });
  }
  return slices;
}

/** Flatten a density grid into scene heat cells (world-centered tiles). */
export function gridToWorldCells(
  grid: DensityGrid,
  bounds: HeatBounds,
  y = 0.4,
): Array<{ x: number; y: number; z: number; intensity: number }> {
  if (grid.max === 0) return [];
  const cellW = (bounds.maxX - bounds.minX) / grid.cols;
  const cellD = (bounds.maxZ - bounds.minZ) / grid.rows;
  const out: Array<{ x: number; y: number; z: number; intensity: number }> = [];
  for (let row = 0; row < grid.rows; row += 1) {
    for (let col = 0; col < grid.cols; col += 1) {
      const count = grid.cells[row * grid.cols + col];
      if (count === 0) continue;
      out.push({
        x: bounds.minX + (col + 0.5) * cellW,
        y,
        z: bounds.minZ + (row + 0.5) * cellD,
        intensity: count / grid.max,
      });
    }
  }
  return out;
}
