import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import BarChart from '@/Components/Dashboard/Charts/BarChart';
import { Icon } from '@iconify/react';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';

// Resource Planning on the shared gold-standard design system. It answers one
// operational question for the next planning horizon: does each unit's STAFFED
// capacity cover its PREDICTED demand, and where should the next nursing
// resource go? KPI wall (Section + MetricGrid) → per-unit demand-vs-capacity
// chart → recommended-staffing table. Values are computed live from prod.* by
// ResourcePlanningAnalyticsService; the authored demo literals below keep the
// page legible when the page is opened before props are wired.

// --- demo fallbacks (used only when no live props arrive) -------------------
const DEMO_KPIS = {
  predictedDemand: 132,
  staffedCapacity: 118,
  coverage: 89,
  netGap: 14,
  bedNeed: 6,
  unitsAtRisk: 5,
  unitsPlanned: 28,
  recommendedRn: 7,
};

const DEMO_DEMAND_VS_CAPACITY = [
  { unitId: 5, unit: 'ICU', type: 'Critical', predictedDemand: 7, staffedCapacity: 4, bedNeed: 2, gap: 3, coverage: 57, recommendedRn: 2, status: 'critical' },
  { unitId: 4, unit: '6E', type: 'Med/Surg', predictedDemand: 8, staffedCapacity: 6, bedNeed: 0, gap: 2, coverage: 75, recommendedRn: 1, status: 'warning' },
  { unitId: 25, unit: 'BURN3', type: 'Critical', predictedDemand: 6, staffedCapacity: 4, bedNeed: 1, gap: 2, coverage: 67, recommendedRn: 1, status: 'warning' },
  { unitId: 2, unit: '5E', type: 'Med/Surg', predictedDemand: 7, staffedCapacity: 7, bedNeed: 0, gap: 0, coverage: 100, recommendedRn: 0, status: 'success' },
  { unitId: 13, unit: 'MS4A', type: 'Med/Surg', predictedDemand: 4, staffedCapacity: 6, bedNeed: 0, gap: -2, coverage: 100, recommendedRn: 0, status: 'success' },
];

const DEMO_RECOMMENDATIONS = [
  { unitId: 5, unit: 'ICU', unitName: 'Intensive Care Unit', type: 'Critical', predictedDemand: 7, staffedCapacity: 4, gap: 3, coverage: 57, bedNeed: 2, recommendedRn: 2, action: 'Add 2 RNs', priority: 'High', status: 'critical' },
  { unitId: 4, unit: '6E', unitName: '6 East', type: 'Med/Surg', predictedDemand: 8, staffedCapacity: 6, gap: 2, coverage: 75, bedNeed: 0, recommendedRn: 1, action: 'Add 1 RN', priority: 'Medium', status: 'warning' },
  { unitId: 25, unit: 'BURN3', unitName: 'Burn Unit', type: 'Critical', predictedDemand: 6, staffedCapacity: 4, gap: 2, coverage: 67, bedNeed: 1, recommendedRn: 1, action: 'Add 1 RN', priority: 'Medium', status: 'warning' },
];

// --- status → token helpers (healthcare-* only) -----------------------------
const STATUS_TEXT = {
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  success: 'text-healthcare-success dark:text-healthcare-success-dark',
  info: 'text-healthcare-info dark:text-healthcare-info-dark',
};

const PRIORITY_BADGE = {
  High: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/15 dark:text-healthcare-critical-dark',
  Medium: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/15 dark:text-healthcare-warning-dark',
  Low: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/15 dark:text-healthcare-info-dark',
};

const cssVar = (name, fallback) => {
  if (typeof window === 'undefined') return fallback;
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  return v ? `rgb(${v})` : fallback;
};

export default function ResourcePlanning({
  kpis,
  demandVsCapacity,
  recommendations,
  horizon = 'By midnight',
  serviceDate = null,
}) {
  const k = kpis ?? DEMO_KPIS;
  const rows =
    demandVsCapacity && demandVsCapacity.length > 0 ? demandVsCapacity : DEMO_DEMAND_VS_CAPACITY;
  const recs =
    recommendations && recommendations.length > 0 ? recommendations : DEMO_RECOMMENDATIONS;

  const coverageStatus = k.coverage >= 95 ? 'success' : k.coverage >= 85 ? 'warning' : 'critical';
  const gapStatus = k.netGap <= 0 ? 'success' : k.netGap <= 5 ? 'warning' : 'critical';

  const kpiMetrics = [
    metric({
      key: 'predicted-demand',
      label: 'Predicted demand',
      value: k.predictedDemand,
      status: 'info',
      definition: `Expected incoming bed demand house-wide at the ${horizon.toLowerCase()} horizon.`,
      drillHref: '/rtdc/predictions/demand',
    }),
    metric({
      key: 'staffed-capacity',
      label: 'Staffed capacity',
      value: k.staffedCapacity,
      status: 'neutral',
      definition: 'Beds the present nursing line can safely cover at target ratios.',
    }),
    metric({
      key: 'coverage',
      label: 'Coverage',
      value: k.coverage,
      unit: '%',
      status: coverageStatus,
      target: 100,
      goodWhenDown: false,
      definition: 'Staffed capacity ÷ predicted demand. Below 85% signals a resourcing gap.',
    }),
    metric({
      key: 'net-gap',
      label: 'Net bed gap',
      value: k.netGap,
      display: `${k.netGap > 0 ? '+' : ''}${k.netGap.toLocaleString('en-US')}`,
      status: gapStatus,
      goodWhenDown: true,
      definition: 'Predicted demand minus staffed capacity across all planned units.',
    }),
    metric({
      key: 'units-at-risk',
      label: 'Units at risk',
      value: k.unitsAtRisk,
      display: `${k.unitsAtRisk} / ${k.unitsPlanned}`,
      status: k.unitsAtRisk === 0 ? 'success' : k.unitsAtRisk <= 3 ? 'warning' : 'critical',
      goodWhenDown: true,
      definition: 'Units whose staffed capacity does not cover predicted demand.',
    }),
    metric({
      key: 'recommended-rn',
      label: 'Recommended RNs',
      value: k.recommendedRn,
      status: k.recommendedRn === 0 ? 'success' : 'warning',
      goodWhenDown: true,
      definition: 'Additional registered nurses to close every gap at the safe ratio.',
      drillHref: '/rtdc/huddle/service',
    }),
  ];

  // Bar chart: per-unit predicted demand vs staffed capacity (worst gaps first).
  const chartRows = [...rows].sort((a, b) => b.gap - a.gap).slice(0, 12);
  const chartData = {
    labels: chartRows.map((r) => r.unit),
    datasets: [
      {
        label: 'Predicted demand',
        data: chartRows.map((r) => r.predictedDemand),
        backgroundColor: cssVar('--color-healthcare-warning', '#F59E0B'),
        borderRadius: 4,
        maxBarThickness: 18,
      },
      {
        label: 'Staffed capacity',
        data: chartRows.map((r) => r.staffedCapacity),
        backgroundColor: cssVar('--color-healthcare-info', '#0EA5E9'),
        borderRadius: 4,
        maxBarThickness: 18,
      },
    ],
  };
  const chartOptions = {
    plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } },
  };

  const dateSuffix = serviceDate ? ` · ${serviceDate}` : '';

  return (
    <RTDCPageLayout
      title="Resource Planning"
      subtitle={`Predicted demand vs staffed capacity — ${horizon} horizon${dateSuffix}`}
    >
      <div className="flex flex-col gap-4">
        {/* KPI wall */}
        <Section
          title="Planning headline"
          icon="heroicons:scale"
          summary={`${k.staffedCapacity}/${k.predictedDemand} beds covered · ${k.coverage}% house-wide`}
          drillHref="/rtdc/predictions/demand"
          drillLabel="Demand forecast"
        >
          <MetricGrid metrics={kpiMetrics} />
        </Section>

        {/* Demand vs staffed capacity chart */}
        <Section
          title="Demand vs staffed capacity"
          icon="heroicons:chart-bar"
          summary="Per-unit predicted demand against beds the staffed line can cover"
        >
          <Panel className="p-4">
            {chartRows.length > 0 ? (
              <div className="h-80">
                <BarChart data={chartData} options={chartOptions} />
              </div>
            ) : (
              <EmptyState message="No predictions reporting for this horizon" />
            )}
          </Panel>
        </Section>

        {/* Recommended staffing table */}
        <Section
          title="Recommended staffing"
          icon="heroicons:clipboard-document-list"
          summary={`${recs.length} unit${recs.length === 1 ? '' : 's'} need attention · prioritized by gap`}
          drillHref="/rtdc/huddle/service"
          drillLabel="Service huddle"
        >
          <Panel className="overflow-hidden">
            {recs.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark text-left">
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Unit</th>
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">Demand</th>
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">Capacity</th>
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">Gap</th>
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">Coverage</th>
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Recommended action</th>
                      <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Priority</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recs.map((r) => (
                      <tr
                        key={r.unitId}
                        className="border-b border-healthcare-border dark:border-healthcare-border-dark last:border-0 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200"
                      >
                        <td className="px-4 py-3">
                          <div className="flex flex-col">
                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                              {r.unit}
                            </span>
                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              {r.type}
                            </span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {r.predictedDemand}
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {r.staffedCapacity}
                        </td>
                        <td className={`px-4 py-3 text-right tabular-nums font-medium ${r.gap > 0 ? STATUS_TEXT[r.status] ?? STATUS_TEXT.warning : STATUS_TEXT.success}`}>
                          {r.gap > 0 ? `+${r.gap}` : r.gap}
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          <span className="inline-flex items-center gap-1">
                            <Icon
                              icon={r.coverage >= 100 ? 'heroicons:check-circle' : 'heroicons:exclamation-triangle'}
                              className={`h-3.5 w-3.5 ${r.coverage >= 100 ? STATUS_TEXT.success : STATUS_TEXT[r.status] ?? STATUS_TEXT.warning}`}
                              aria-hidden="true"
                            />
                            {r.coverage}%
                          </span>
                        </td>
                        <td className="px-4 py-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {r.action}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${PRIORITY_BADGE[r.priority] ?? PRIORITY_BADGE.Low}`}>
                            {r.priority}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-4">
                <EmptyState message="All units staffed to predicted demand — no action needed" />
              </div>
            )}
          </Panel>
        </Section>
      </div>
    </RTDCPageLayout>
  );
}
