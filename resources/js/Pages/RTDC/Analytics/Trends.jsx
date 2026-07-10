import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, EmptyState, STATUS_VAR, metric } from '@/Components/system';
import TrendChart, { formatters } from '@/Components/Common/TrendChart';
import { formatDurationHours } from '@/lib/duration';

// Gold-standard build of RTDC Trends & Patterns on the shared design system.
// Pattern KPIs (peak occupancy day, averages, forecast bias) render as a KpiTile
// wall; predicted-vs-actual flow and forecast reliability render as multi-week
// trend charts; the day-of-week pattern is a dense table. All values are live,
// computed by App\Services\Rtdc\TrendsAnalyticsService from prod.* — the page is
// safe (empty states) when a series is absent.

const HOUSE_FILL = '#0EA5E9'; // healthcare-info (sky) — actual / primary series
const FORECAST_FILL = '#C9A227'; // gold — forecast / predicted overlay (focus layer)
const TEAL_FILL = '#2DD4BF'; // healthcare-success (teal) — admissions series

const fmtRange = (from, to) => {
  if (!from || !to) return 'No reconciliation history';
  const f = new Date(from).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  const t = new Date(to).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  return `${f} – ${t}`;
};

function buildKpis(kpis) {
  return [
    metric({
      key: 'peak-day',
      label: 'Peak occupancy day',
      value: kpis.peakOccupancy ?? 0,
      display: kpis.peakOccupancyDay ?? '—',
      status: 'warning',
      definition: 'Day of week with the highest average house occupancy across the census window.',
    }),
    metric({
      key: 'avg-occupancy',
      label: 'Avg occupancy',
      value: kpis.avgOccupancy ?? 0,
      unit: '%',
      status: (kpis.avgOccupancy ?? 0) >= 85 ? 'warning' : 'info',
      target: 80,
      goodWhenDown: true,
      definition: 'Mean occupied ÷ staffed beds across the observed census days.',
    }),
    metric({
      key: 'avg-admissions',
      label: 'Avg daily admissions',
      value: kpis.avgAdmissions ?? 0,
      status: 'info',
      definition: 'Mean actual admissions per day across the reconciliation window.',
    }),
    metric({
      key: 'avg-discharges',
      label: 'Avg daily discharges',
      value: kpis.avgDischarges ?? 0,
      status: 'success',
      definition: 'Mean actual discharges per day across the reconciliation window.',
    }),
    metric({
      key: 'avg-reliability',
      label: 'Forecast reliability',
      value: kpis.avgReliability ?? 0,
      unit: '%',
      status: (kpis.avgReliability ?? 0) >= 85 ? 'success' : (kpis.avgReliability ?? 0) >= 75 ? 'warning' : 'critical',
      target: 85,
      definition: 'Average daily prediction reliability score (predicted vs actual flow).',
    }),
    metric({
      key: 'forecast-bias',
      label: 'Discharge bias',
      value: kpis.forecastBias ?? 0,
      unit: '%',
      status: (kpis.forecastBias ?? 0) >= 90 && (kpis.forecastBias ?? 0) <= 110 ? 'success' : 'warning',
      target: 100,
      definition: 'Actual ÷ predicted discharges. 100% = calibrated; <100% = over-forecasting discharges.',
    }),
    metric({
      key: 'peak-admission-day',
      label: 'Busiest admission day',
      value: kpis.peakAdmissions ?? 0,
      display: kpis.peakAdmissionDay ?? '—',
      status: 'warning',
      definition: 'Day of week with the highest average admissions across the history.',
    }),
  ];
}

const FLOW_SERIES = [
  { dataKey: 'actualDischarges', name: 'Discharges (actual)' },
  { dataKey: 'predictedDischarges', name: 'Discharges (predicted)' },
  { dataKey: 'actualAdmissions', name: 'Admissions (actual)' },
  { dataKey: 'predictedAdmissions', name: 'Admissions (predicted)' },
];

const RELIABILITY_SERIES = [{ dataKey: 'reliability', name: 'Reliability' }];

export default function Trends({ kpis = {}, flowSeries = [], reliabilitySeries = [], dowPattern = [], meta = {} }) {
  const hasFlow = Array.isArray(flowSeries) && flowSeries.length > 0;
  const hasReliability = Array.isArray(reliabilitySeries) && reliabilitySeries.length > 0;
  const hasDow = Array.isArray(dowPattern) && dowPattern.length > 0;
  const windowLabel = fmtRange(meta.fromDate, meta.toDate);

  return (
    <RTDCPageLayout
      title="Trends & Patterns"
      subtitle="Multi-week patient-flow, occupancy, and forecast-reliability patterns"
    >
      <div className="flex flex-col gap-5">
        <Section
          title="Pattern indicators"
          icon="heroicons:chart-bar-square"
          summary={`${windowLabel} · ${formatDurationHours(meta.windowDays == null ? null : Number(meta.windowDays) * 24)} · ${meta.units ?? 0} units`}
        >
          <MetricGrid metrics={buildKpis(kpis)} emptyLabel="No trend data reporting" />
        </Section>

        <Section
          title="Patient flow · predicted vs actual"
          icon="heroicons:arrows-up-down"
          summary="Daily discharges and admissions against the RTDC forecast"
          drillHref="/rtdc/predictions/demand"
          drillLabel="Demand forecast"
        >
          {hasFlow ? (
            <TrendChart
              title="Discharges & admissions"
              description="Actual vs predicted daily volumes across the reconciliation window"
              data={flowSeries}
              series={FLOW_SERIES}
              colors={[HOUSE_FILL, FORECAST_FILL, TEAL_FILL, '#F59E0B']}
              yAxis={{ formatter: formatters.number }}
              tooltip={{ formatter: formatters.number }}
            />
          ) : (
            <EmptyState message="No reconciliation history to plot" />
          )}
        </Section>

        <Section
          title="Forecast reliability"
          icon="heroicons:shield-check"
          summary="Daily discharge-prediction reliability score over time"
        >
          {hasReliability ? (
            <TrendChart
              title="Reliability trend"
              description="Daily forecast reliability (higher is better; 85% is the target floor)"
              data={reliabilitySeries}
              series={RELIABILITY_SERIES}
              colors={[TEAL_FILL]}
              yAxis={{ formatter: formatters.percentage }}
              tooltip={{ formatter: formatters.percentage }}
            />
          ) : (
            <EmptyState message="No reliability history available" />
          )}
        </Section>

        <Section
          title="Day-of-week pattern"
          icon="heroicons:calendar-days"
          summary="Average admissions, discharges, net flow, and reliability by weekday"
        >
          {hasDow ? (
            <div className="overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
              <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                <thead>
                  <tr className="text-left text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <th scope="col" className="px-4 py-2">Day</th>
                    <th scope="col" className="px-4 py-2 text-right">Avg admissions</th>
                    <th scope="col" className="px-4 py-2 text-right">Avg discharges</th>
                    <th scope="col" className="px-4 py-2 text-right">Net flow</th>
                    <th scope="col" className="px-4 py-2 text-right">Reliability</th>
                    <th scope="col" className="px-4 py-2 text-right">Sample</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {dowPattern.map((row) => (
                    <tr
                      key={row.isodow}
                      className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-150"
                    >
                      <td className="px-4 py-2">
                        <span className="inline-flex items-center gap-2">
                          <span
                            aria-hidden="true"
                            className="inline-block h-2 w-2 rounded-full"
                            style={{ backgroundColor: STATUS_VAR[row.status] ?? STATUS_VAR.neutral }}
                          />
                          <span className="font-medium">{row.day}</span>
                        </span>
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums">{row.avgAdmissions}</td>
                      <td className="px-4 py-2 text-right tabular-nums">{row.avgDischarges}</td>
                      <td className="px-4 py-2 text-right tabular-nums">
                        <span style={{ color: STATUS_VAR[row.status] ?? undefined }}>
                          {row.netFlow > 0 ? `+${row.netFlow}` : row.netFlow}
                        </span>
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums">{row.reliability}%</td>
                      <td className="px-4 py-2 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {Number(row.sampleDays ?? 0).toLocaleString()} days
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <EmptyState message="No weekday pattern available" />
          )}
        </Section>
      </div>
    </RTDCPageLayout>
  );
}
