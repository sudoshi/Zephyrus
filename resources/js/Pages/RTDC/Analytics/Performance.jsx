import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';
import TrendChart, { formatters } from '@/Components/Common/TrendChart';
import { Icon } from '@iconify/react';
import { formatDurationHours } from '@/lib/duration';

// RTDC Performance Metrics rebuilt on the gold-standard design system: the KPI
// wall is one MetricGrid of KpiTiles (status dot + value + gauge + target +
// caption), detail tables live in Panels under Section headers. All values are
// server-computed from the live `prod` schema (PerformanceAnalyticsService); the
// page renders zeros / empty states rather than fabricating data.

const num = (v, d = 2) => Number(v ?? 0).toFixed(d);
const formatHours = (value) => {
    if (value === null || value === undefined || value === '') return formatDurationHours(null);
    const hours = Number(value);

    return formatDurationHours(Number.isFinite(hours) ? hours : null);
};
const formatDays = (value) => {
    if (value === null || value === undefined || value === '') return formatDurationHours(null);
    const days = Number(value);

    return formatDurationHours(Number.isFinite(days) ? days * 24 : null);
};

export default function Performance({
  kpis = null,
  reliabilityTrend = [],
  losByType = [],
  reconciliationRows = [],
  meta = null,
}) {
  const k = kpis ?? {};
  const hasData = meta?.hasData ?? false;
  const windowDays = meta?.windowDays ?? 14;

  const losStatus =
    !k.losIndex || k.losIndex === 0 ? 'info'
      : k.losIndex >= 1.2 ? 'critical'
        : k.losIndex >= 1.05 ? 'warning' : 'success';
  const noonStatus = (k.dischargeByNoonRate ?? 0) >= 50 ? 'success' : (k.dischargeByNoonRate ?? 0) >= 35 ? 'warning' : 'critical';
  const boardStatus = (k.avgBoardingHours ?? 0) <= 2 ? 'success' : (k.avgBoardingHours ?? 0) <= 4 ? 'warning' : 'critical';
  const relStatus = (k.forecastReliability ?? 0) >= 85 ? 'success' : (k.forecastReliability ?? 0) >= 70 ? 'warning' : 'critical';

  const kpiMetrics = [
    metric({ key: 'avg-los', label: 'Avg LOS vs GMLOS', value: Number(k.avgLos ?? 0), display: formatDays(k.avgLos),
      status: losStatus, target: Number(k.gmlos ?? 0), targetDisplay: `${formatDays(k.gmlos)} GMLOS`,
      caption: `${k.dischargedTotal ?? 0} discharges · ${(k.losDelta ?? 0) >= 0 ? '+' : ''}${formatDays(k.losDelta)} vs reference`,
      definition: 'Average observed length of stay against the geometric-mean LOS reference.' }),
    metric({ key: 'discharge-noon', label: 'Discharge by noon', value: Number(k.dischargeByNoonRate ?? 0), unit: '%',
      status: noonStatus, target: 50, caption: `Completed before 12:00 (n=${k.dischargedTotal ?? 0})`,
      definition: 'Share of discharges completed before noon. Target 50%.' }),
    metric({ key: 'ed-boarding', label: 'ED boarding', value: Number(k.avgBoardingHours ?? 0), display: formatHours(k.avgBoardingHours),
      status: boardStatus, target: 2, targetDisplay: formatHours(2), goodWhenDown: true,
      caption: `${k.boardedCount ?? 0} admits · ${formatHours(k.totalBoardingHours)} total board time`,
      definition: 'Mean time admitted ED patients wait for an inpatient bed.' }),
    metric({ key: 'turnaround', label: 'Bed-request turnaround', value: Number(k.avgTurnaroundHours ?? 0),
      display: (k.placedCount ?? 0) > 0 ? formatHours(k.avgTurnaroundHours) : '—', status: 'info', goodWhenDown: true,
      caption: (k.placedCount ?? 0) > 0 ? `${k.placedCount} requests placed in window` : 'No completed placements in window',
      definition: 'Time from bed request to placement.' }),
    metric({ key: 'forecast-reliability', label: 'Forecast reliability', value: Number(k.forecastReliability ?? 0), unit: '%',
      status: relStatus, target: 85, caption: 'Mean reconciliation reliability (latest day)',
      definition: 'How closely predicted discharges/admissions matched actuals.' }),
    metric({ key: 'los-index', label: 'LOS index', value: Number(k.losIndex ?? 0), display: `${num(k.losIndex)}×`,
      status: losStatus, target: 1, targetDisplay: '1.00× = at GMLOS', goodWhenDown: true,
      caption: (k.losIndex ?? 0) <= 1 ? 'At or under expected length of stay' : 'Length of stay running over reference',
      definition: 'Observed LOS ÷ GMLOS. 1.00× is at expected.' }),
  ];

  const reliabilityChartData = (reliabilityTrend ?? []).map((row) => ({
    date: row.date, reliability: row.reliability, predicted: row.predicted, actual: row.actual,
  }));

  if (!hasData) {
    return (
      <RTDCPageLayout title="Performance Metrics" subtitle="Throughput and forecast-reliability scorecard">
        <Panel className="p-4">
          <EmptyState message="No performance data yet. Metrics populate once census, encounter, and reconciliation feeds report." icon="heroicons:chart-bar-square" />
        </Panel>
      </RTDCPageLayout>
    );
  }

  return (
    <RTDCPageLayout title="Performance Metrics" subtitle={`Throughput and forecast reliability · trailing ${formatDays(windowDays)}`}>
      <div className="flex flex-col gap-5">
        <Section title="Throughput & reliability" icon="heroicons:chart-bar-square"
                 summary={`Trailing ${formatDays(windowDays)} · ${k.dischargedTotal ?? 0} discharges`}>
          <MetricGrid metrics={kpiMetrics} />
        </Section>

        <Section title="Forecast reliability trend" icon="heroicons:presentation-chart-line"
                 summary="Daily predicted vs actual discharges and mean reliability">
          {reliabilityChartData.length > 0 ? (
            <Panel className="p-4">
              <TrendChart
                data={reliabilityChartData}
                series={[
                  { dataKey: 'reliability', name: 'Reliability %' },
                  { dataKey: 'predicted', name: 'Predicted discharges' },
                  { dataKey: 'actual', name: 'Actual discharges' },
                ]}
                xAxis={{ dataKey: 'date', type: 'category',
                  formatter: (v) => new Date(v).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}
                yAxis={{ formatter: formatters.number }}
                tooltip={{ formatter: formatters.number }}
              />
            </Panel>
          ) : (
            <Panel className="p-4"><EmptyState message="No reconciliation history in the trailing window." icon="heroicons:presentation-chart-line" /></Panel>
          )}
        </Section>

        <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <Section title="Length of stay by unit type" icon="heroicons:clock"
                   summary="Observed average LOS vs the GMLOS reference">
            <Panel className="p-4">
              {losByType.length > 0 ? (
                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  <thead>
                    <tr>
                      {['Unit type', 'Avg LOS', 'GMLOS', 'Index', 'n'].map((h, i) => (
                        <th key={h} className={`px-3 py-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${i === 0 ? 'text-left' : 'text-right'}`}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {losByType.map((row) => (
                      <tr key={row.type} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200">
                        <td className="px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark whitespace-nowrap">{row.label}</td>
                        <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{formatDays(row.avgLos)}</td>
                        <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{formatDays(row.gmlos)}</td>
                        <td className="px-3 py-2 text-sm text-right tabular-nums font-semibold whitespace-nowrap" style={{ color: STATUS_VAR[row.status] }}>{row.index.toFixed(2)}×</td>
                        <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.discharged}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : <EmptyState message="No discharged encounters in the trailing window." icon="heroicons:clock" />}
            </Panel>
          </Section>

          <Section title="Forecast reliability by unit" icon="heroicons:check-badge"
                   summary="Latest reconciliation per unit — lowest reliability first">
            <Panel className="p-4">
              {reconciliationRows.length > 0 ? (
                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  <thead>
                    <tr>
                      {['Unit', 'Pred / Act DC', 'Pred / Act Adm', 'Reliability'].map((h, i) => (
                        <th key={h} className={`px-3 py-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${i === 0 ? 'text-left' : 'text-right'}`}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {reconciliationRows.map((row) => (
                      <tr key={row.unitId} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200">
                        <td className="px-3 py-2 whitespace-nowrap">
                          <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.unit}</div>
                          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.type}</div>
                        </td>
                        <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.predictedDischarges} / {row.actualDischarges}</td>
                        <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.predictedAdmissions} / {row.actualAdmissions}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">
                          <span className="inline-flex items-center gap-1 text-sm font-semibold tabular-nums" style={{ color: STATUS_VAR[row.status] }}>
                            <Icon icon={row.status === 'success' ? 'heroicons:arrow-trending-up' : row.status === 'critical' ? 'heroicons:arrow-trending-down' : 'heroicons:minus'} className="w-4 h-4" />
                            {row.reliability}%
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : <EmptyState message="No reconciliation rows available." icon="heroicons:check-badge" />}
            </Panel>
          </Section>
        </div>
      </div>
    </RTDCPageLayout>
  );
}
