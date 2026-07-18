import React from 'react';

/**
 * Floor stepper rail (N-4): the primary spatial filter as a one-click,
 * keyboard-steppable control on the right edge. Highest floor on top, "All"
 * at the bottom; ↑/↓ step floors while any rail button has focus. Selecting
 * a floor also frames it (fit-to-floor) via the orchestrator's onSelect.
 */

interface NavigatorFloorRailProps {
  /** Floors as sorted-ascending strings (the toolbar dropdown's source). */
  floors: string[];
  current: string;
  onSelect: (floor: string) => void;
}

export default function NavigatorFloorRail({ floors, current, onSelect }: NavigatorFloorRailProps) {
  if (floors.length === 0) return null;

  // Render top-down: highest floor first, All last — matching the building.
  const descending = [...floors].reverse();

  const step = (direction: 1 | -1): void => {
    // ↑ moves up the building (ascending floors); from All, ↑ enters at the bottom floor.
    const index = floors.indexOf(current);
    if (current === 'all' || index === -1) {
      onSelect(direction === 1 ? floors[0] : floors[floors.length - 1]);
      return;
    }
    const next = index + direction;
    if (next < 0 || next >= floors.length) return;
    onSelect(floors[next]);
  };

  const onKeyDown = (event: React.KeyboardEvent): void => {
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      step(1);
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      step(-1);
    }
  };

  return (
    <nav className="patient-flow-floor-rail" aria-label="Floor stepper" onKeyDown={onKeyDown}>
      {descending.map((floor) => (
        <button
          key={floor}
          type="button"
          aria-pressed={current === floor}
          className={current === floor ? 'active' : ''}
          title={`Show floor ${floor} and frame it`}
          onClick={() => onSelect(floor)}
        >
          {floor}
        </button>
      ))}
      <button
        type="button"
        aria-pressed={current === 'all'}
        className={`patient-flow-floor-all ${current === 'all' ? 'active' : ''}`}
        title="Show every floor"
        onClick={() => onSelect('all')}
      >
        All
      </button>
    </nav>
  );
}
