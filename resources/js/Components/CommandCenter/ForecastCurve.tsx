// resources/js/Components/CommandCenter/ForecastCurve.tsx
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { ForecastState } from '@/types/commandCenter';

export function ForecastCurve({ forecast }: { forecast: ForecastState }) {
  const netColor = forecast.netBedPosition < 0 ? 'var(--critical)' : 'var(--success)';
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap gap-4 text-xs" style={{ color: 'var(--text-secondary)' }}>
        <span>Predicted discharges 24h:{' '}
          <strong style={{ color: 'var(--text-primary)' }}>{forecast.predictedDischarges24h}</strong></span>
        <span>ED arrivals 24h:{' '}
          <strong style={{ color: 'var(--text-primary)' }}>{forecast.predictedEdArrivals}</strong></span>
        <span>Net bed position:{' '}
          <strong style={{ color: netColor }}>{forecast.netBedPosition}</strong></span>
        <span>Surge probability:{' '}
          <strong style={{ color: 'var(--text-primary)' }}>{forecast.surgeProbabilityPct}%</strong></span>
      </div>
      <div aria-label="24-hour occupancy forecast" style={{ width: '100%', height: 140 }}>
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={forecast.occupancyCurve}>
            <XAxis dataKey="hourOffset" tick={{ fontSize: 10 }} />
            <YAxis domain={[60, 100]} width={28} tick={{ fontSize: 10 }} />
            <Tooltip
              formatter={((value: number, name: string) => [`${value}%`, name]) as never}
              labelFormatter={((h: number) => `+${h}h`) as never}
            />
            <Area type="monotone" dataKey="upperPct" stroke="none" fill="var(--info)" fillOpacity={0.18} />
            <Area type="monotone" dataKey="lowerPct" stroke="none" fill="var(--surface-base)" fillOpacity={1} />
            <Area type="monotone" dataKey="occupancyPct" stroke="var(--info)" fill="none" strokeWidth={1.5} />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
