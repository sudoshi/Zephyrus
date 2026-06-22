// resources/js/Components/CommandCenter/ForecastCurve.tsx
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { ForecastState } from '@/types/commandCenter';
import { useDarkMode } from '@/hooks/useDarkMode';
import { Panel } from './Panel';

export function ForecastCurve({ forecast }: { forecast: ForecastState }) {
  const [isDarkMode] = useDarkMode();
  // The lower-band area is filled with the panel background to "cut out" the
  // bottom of the upper band, leaving a shaded confidence interval. This fill
  // must match the surrounding card surface exactly in each mode.
  const panelFill = isDarkMode ? '#1E293B' : '#FFFFFF';
  const netColor = forecast.netBedPosition < 0 ? 'var(--critical)' : 'var(--success)';
  return (
    <Panel className="flex flex-col gap-2 p-3">
      <div className="flex flex-wrap gap-4 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span>Predicted discharges 24h:{' '}
          <strong className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{forecast.predictedDischarges24h}</strong></span>
        <span>ED arrivals 24h:{' '}
          <strong className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{forecast.predictedEdArrivals}</strong></span>
        <span>Net bed position:{' '}
          <strong style={{ color: netColor }}>{forecast.netBedPosition}</strong></span>
        <span>Surge probability:{' '}
          <strong className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{forecast.surgeProbabilityPct}%</strong></span>
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
            <Area type="monotone" dataKey="lowerPct" stroke="none" fill={panelFill} fillOpacity={1} />
            <Area type="monotone" dataKey="occupancyPct" stroke="var(--info)" fill="none" strokeWidth={1.5} />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </Panel>
  );
}
