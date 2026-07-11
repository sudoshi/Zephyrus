// resources/js/Components/arena/review/DeltaBadge.tsx
//
// A this-vs-last delta. Colour encodes good/bad by direction (worse = up =
// critical), and the arrow names the direction so it never reads by colour
// alone. Only the 48-hour job can compute these, so they only appear here.
type Direction = 'up' | 'down' | 'flat';

const DIR_TEXT: Record<Direction, string> = {
  up: 'text-healthcare-critical dark:text-healthcare-critical-dark',
  down: 'text-healthcare-success dark:text-healthcare-success-dark',
  flat: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark',
};

const DIR_ARROW: Record<Direction, string> = { up: '▲', down: '▼', flat: '·' };

export function DeltaBadge({ direction, label }: { direction: Direction; label: string }) {
  return (
    <span className={`text-xs font-medium tabular-nums ${DIR_TEXT[direction]}`}>
      {label} <span aria-hidden="true">{DIR_ARROW[direction]}</span>
    </span>
  );
}
