interface ReliabilityTileProps { score: number | null }

export function ReliabilityTile({ score }: ReliabilityTileProps) {
  return (
    <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark p-5">
      <div className="text-label">Discharge Prediction Reliability</div>
      <div className="text-2xl font-semibold tabular-nums">{score === null ? '—' : `${Math.round(score * 100)}%`}</div>
      <div className="text-caption">Yesterday&apos;s predicted vs actual (RTDC Step 4)</div>
    </div>
  );
}
