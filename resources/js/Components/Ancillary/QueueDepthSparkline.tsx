export interface QueueDepthPoint {
  at: string;
  value: number | null;
}

export function QueueDepthSparkline({ title, points }: { title: string; points: QueueDepthPoint[] }) {
  const available = points.filter((point): point is QueueDepthPoint & { value: number } => point.value !== null && Number.isFinite(point.value) && point.value >= 0);
  const max = Math.max(...available.map((point) => point.value), 1);
  const coordinates = available.map((point, index) => `${available.length === 1 ? 50 : (index / (available.length - 1)) * 100},${36 - (point.value / max) * 32}`).join(' ');

  return (
    <section aria-label={title} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h3 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h3>
      {available.length === 0 ? <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Queue depth unavailable.</p> : <svg role="img" aria-label={`${title} trend; table follows`} viewBox="0 0 100 40" className="mt-2 h-16 w-full text-healthcare-info dark:text-healthcare-info-dark"><polyline points={coordinates} fill="none" stroke="currentColor" strokeWidth="2" vectorEffect="non-scaling-stroke" /></svg>}
      <details className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <summary className="cursor-pointer focus:outline-none focus:ring-2 focus:ring-healthcare-info">View queue-depth data table</summary>
        <table className="mt-2 w-full"><caption className="sr-only">{title} values</caption><thead><tr><th scope="col" className="p-1 text-left">Time</th><th scope="col" className="p-1 text-right">Queue</th></tr></thead><tbody>{points.map((point) => <tr key={point.at}><th scope="row" className="p-1 text-left font-medium"><time dateTime={point.at}>{new Date(point.at).toLocaleTimeString()}</time></th><td className="p-1 text-right tabular-nums">{point.value ?? 'Unavailable'}</td></tr>)}</tbody></table>
      </details>
    </section>
  );
}
