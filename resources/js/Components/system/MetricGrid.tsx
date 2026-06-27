// resources/js/Components/system/MetricGrid.tsx
//
// THE one metric wall. A responsive auto-fit grid of KpiTiles — the dense
// instrument surface that replaces every bespoke MetricsCard / MetricCard /
// SummaryCard grid in the app. Density is on by default (detailed): every tile
// shows its sparkline + detail breakdown. Pass `min` to tune column width
// (168px = compact KPI; 220px = roomier).
import type { KpiMetric } from '@/types/commandCenter';
import { KpiTile } from '@/Components/CommandCenter/KpiTile';
import { EmptyState } from '@/Components/CommandCenter/states';

export interface MetricGridProps {
  metrics: KpiMetric[];
  detailed?: boolean;
  min?: number;
  emptyLabel?: string;
}

export function MetricGrid({
  metrics, detailed = true, min = 184, emptyLabel = 'No metrics reporting',
}: MetricGridProps) {
  if (!metrics.length) return <EmptyState message={emptyLabel} />;
  return (
    <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(auto-fit, minmax(${min}px, 1fr))` }}>
      {metrics.map((m) => <KpiTile key={m.key} metric={m} detailed={detailed} />)}
    </div>
  );
}
