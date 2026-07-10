import React, { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';
import { Icon } from '@iconify/react';
import { formatDurationHours } from '@/lib/duration';

// ED Resource Optimization (Predictions). Answers one operational question for
// the next 8h horizon: does the on-shift roster cover the staffing/bed load
// implied by PREDICTED arrivals and their acuity, and where should the next
// resource go? Rebuilt on the gold-standard design system: KPI wall is one
// MetricGrid, the required-vs-available chart + allocation table + recommendation
// /acuity lists live in Panels under Section headers (the resource-type toggle
// lives in the Section `actions` slot). Values are computed live from
// prod.ed_visits by ResourceOptimizationService; the authored demo literals keep
// the page legible if it renders before props are wired.

// --- demo fallbacks (used only when no live props arrive) -------------------
const DEMO_AVAILABLE = { nurses: 6, providers: 3, beds: 40 };

const formatRecommendationTime = (value) => {
  const match = String(value).match(/^Next\s+(\d+(?:\.\d+)?)h$/i);

  return match ? `Next ${formatDurationHours(Number(match[1]))}` : value;
};

const DEMO_FORECAST = [
  { hour: '14:00', predictedArrivals: 7, weightedDemand: 16.4, requiredNurses: 6, requiredProviders: 3, requiredBeds: 11, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'optimal' },
  { hour: '15:00', predictedArrivals: 9, weightedDemand: 21.1, requiredNurses: 8, requiredProviders: 4, requiredBeds: 14, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'critical' },
  { hour: '16:00', predictedArrivals: 8, weightedDemand: 18.7, requiredNurses: 7, requiredProviders: 3, requiredBeds: 12, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'warning' },
  { hour: '17:00', predictedArrivals: 8, weightedDemand: 18.7, requiredNurses: 7, requiredProviders: 3, requiredBeds: 12, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'warning' },
  { hour: '18:00', predictedArrivals: 6, weightedDemand: 14.0, requiredNurses: 5, requiredProviders: 2, requiredBeds: 9, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'optimal' },
  { hour: '19:00', predictedArrivals: 5, weightedDemand: 11.7, requiredNurses: 4, requiredProviders: 2, requiredBeds: 8, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'optimal' },
  { hour: '20:00', predictedArrivals: 6, weightedDemand: 14.0, requiredNurses: 5, requiredProviders: 2, requiredBeds: 9, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'optimal' },
  { hour: '21:00', predictedArrivals: 5, weightedDemand: 11.7, requiredNurses: 4, requiredProviders: 2, requiredBeds: 8, availableNurses: 6, availableProviders: 3, availableBeds: 40, status: 'optimal' },
];

const DEMO_KPIS = {
  peakArrivals: { value: 9, hour: '15:00', trend: 'up', trendValue: 9 },
  nurseGap: { value: 2, hour: '15:00', trend: 'down', trendValue: 2 },
  bedPressure: { value: 35, trend: 'up', trendValue: 35 },
  highAcuityShare: { value: 23, trend: 'down', trendValue: 23 },
};

const DEMO_RECOMMENDATIONS = [
  { id: 1, priority: 'critical', resource: 'Nursing', hour: '15:00', detail: 'Add 2 nurses for 9 predicted arrivals', delta: 2 },
  { id: 2, priority: 'warning', resource: 'Nursing', hour: '16:00', detail: 'Add 1 nurse for 8 predicted arrivals', delta: 1 },
  { id: 3, priority: 'warning', resource: 'Provider', hour: '15:00', detail: 'Add 1 provider to hold door-to-provider target', delta: 1 },
];

const DEMO_ACUITY_MIX = [
  { label: 'Resuscitation', esi: 1, count: 0, pct: 3.0 },
  { label: 'Emergent', esi: 2, count: 0, pct: 20.0 },
  { label: 'Urgent', esi: 3, count: 0, pct: 48.0 },
  { label: 'Semi-Urgent', esi: 4, count: 0, pct: 20.0 },
  { label: 'Non-Urgent', esi: 5, count: 0, pct: 9.0 },
];

// --- status / priority → token helpers (healthcare-* only) ------------------
const STATUS_TEXT = {
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  optimal: 'text-healthcare-success dark:text-healthcare-success-dark',
  success: 'text-healthcare-success dark:text-healthcare-success-dark',
  info: 'text-healthcare-info dark:text-healthcare-info-dark',
};

const STATUS_DOT = {
  critical: 'bg-healthcare-critical dark:bg-healthcare-critical-dark',
  warning: 'bg-healthcare-warning dark:bg-healthcare-warning-dark',
  optimal: 'bg-healthcare-success dark:bg-healthcare-success-dark',
  info: 'bg-healthcare-info dark:bg-healthcare-info-dark',
};

const STATUS_ICON = {
  critical: 'heroicons:exclamation-triangle',
  warning: 'heroicons:exclamation-circle',
  optimal: 'heroicons:check-circle',
  info: 'heroicons:information-circle',
};

const STATUS_LABEL = {
  critical: 'Understaffed',
  warning: 'Tight',
  optimal: 'Covered',
};

const PRIORITY_BADGE = {
  critical: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/15 dark:text-healthcare-critical-dark',
  warning: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/15 dark:text-healthcare-warning-dark',
  info: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/15 dark:text-healthcare-info-dark',
};

const PRIORITY_LABEL = { critical: 'Critical', warning: 'Watch', info: 'Steady' };

// ESI badge palette — data-driven (clinical triage scale), not status color.
const ESI_BADGE = {
  1: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/15 dark:text-healthcare-critical-dark',
  2: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/15 dark:text-healthcare-warning-dark',
  3: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/15 dark:text-healthcare-info-dark',
  4: 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/15 dark:text-healthcare-success-dark',
  5: 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/15 dark:text-healthcare-success-dark',
};

const cssVar = (name, fallback) => {
  if (typeof window === 'undefined') return fallback;
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  return v ? `rgb(${v})` : fallback;
};

// Per-resource view config for the required-vs-available chart toggle.
const RESOURCE_VIEWS = {
  nurses: {
    label: 'Nurses',
    icon: 'heroicons:user-group',
    requiredKey: 'requiredNurses',
    availableKey: 'availableNurses',
  },
  providers: {
    label: 'Providers',
    icon: 'heroicons:identification',
    requiredKey: 'requiredProviders',
    availableKey: 'availableProviders',
  },
  beds: {
    label: 'Beds',
    icon: 'heroicons:rectangle-stack',
    requiredKey: 'requiredBeds',
    availableKey: 'availableBeds',
  },
};

export default function Resources({
  kpis,
  forecast,
  available,
  recommendations,
  acuityMix,
  generatedAt = null,
}) {
  const k = kpis ?? DEMO_KPIS;
  const rows = forecast && forecast.length > 0 ? forecast : DEMO_FORECAST;
  const avail = available ?? DEMO_AVAILABLE;
  const recs = recommendations && recommendations.length > 0 ? recommendations : DEMO_RECOMMENDATIONS;
  const acuity = acuityMix && acuityMix.length > 0 ? acuityMix : DEMO_ACUITY_MIX;

  const [resourceView, setResourceView] = useState('nurses');
  const view = RESOURCE_VIEWS[resourceView];

  // Required-vs-available grouped bars for the selected resource.
  const chartData = useMemo(
    () => ({
      labels: rows.map((r) => r.hour),
      datasets: [
        {
          label: `Required ${view.label.toLowerCase()}`,
          data: rows.map((r) => r[view.requiredKey]),
          backgroundColor: cssVar('--color-healthcare-warning', '#F59E0B'),
          borderRadius: 4,
          maxBarThickness: 22,
        },
        {
          label: `Available ${view.label.toLowerCase()}`,
          data: rows.map((r) => r[view.availableKey]),
          backgroundColor: cssVar('--color-healthcare-info', '#0EA5E9'),
          borderRadius: 4,
          maxBarThickness: 22,
        },
      ],
    }),
    [rows, view],
  );

  const chartOptions = {
    plugins: {
      legend: {
        display: true,
        position: 'top',
        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true },
      },
    },
  };

  const hasData = rows.length > 0;
  const hoursAtRisk = rows.filter((r) => r.status !== 'optimal').length;
  const totalPredicted = rows.reduce((acc, r) => acc + (r.predictedArrivals || 0), 0);

  // KPI wall — pressure headline numbers. Status pairs the gap/pressure trend
  // with tone; the label + value carry the meaning, never colour alone.
  const kpiMetrics = [
    metric({
      key: 'peak-arrivals', label: 'Peak predicted arrivals', value: Number(k.peakArrivals.value),
      status: 'info', caption: `Busiest hour at ${k.peakArrivals.hour}`,
      definition: 'Highest predicted arrivals in any single hour of the horizon.',
    }),
    metric({
      key: 'nurse-gap', label: 'Max nurse gap', value: Number(k.nurseGap.value),
      status: k.nurseGap.value > 0 ? 'critical' : 'success', goodWhenDown: true,
      caption: k.nurseGap.value > 0 ? `Shortfall at ${k.nurseGap.hour}` : 'Nursing fully covered',
      definition: 'Largest shortfall of required vs available nurses across the horizon.',
    }),
    metric({
      key: 'bed-pressure', label: 'Peak bed pressure', value: Number(k.bedPressure.value), unit: '%',
      status: k.bedPressure.value >= 100 ? 'critical' : k.bedPressure.value >= 80 ? 'warning' : 'success',
      target: 100, goodWhenDown: true, caption: 'Required vs available beds',
      definition: 'Peak required beds as a share of available beds (100% = at capacity).',
    }),
    metric({
      key: 'high-acuity-share', label: 'High-acuity share', value: Number(k.highAcuityShare.value), unit: '%',
      status: k.highAcuityShare.value >= 25 ? 'warning' : 'success',
      caption: 'ESI 1-2 of predicted mix',
      definition: 'Predicted share of incoming patients triaged ESI 1-2.',
    }),
  ];

  // Loading / unpopulated guard — render the skeleton if nothing resolved at all.
  if (!hasData && !forecast) {
    return (
      <DashboardLayout>
        <Head title="Resource Optimization - Emergency" />
        <PageContentLayout
          title="Resource Optimization"
          subtitle="Optimize resource allocation from predicted arrivals"
        >
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
              {[0, 1, 2, 3].map((i) => (
                <div
                  key={i}
                  className="h-28 animate-pulse rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm"
                />
              ))}
            </div>
            <div className="h-80 animate-pulse rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm" />
          </div>
        </PageContentLayout>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout>
      <Head title="Resource Optimization - Emergency" />
      <PageContentLayout
        title="Resource Optimization"
        subtitle={`Recommended staffing & bed allocation for the next ${formatDurationHours(rows.length)}, from predicted arrivals and acuity`}
        headerContent={
          <div className="flex items-center gap-2 rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark px-3 py-1.5 shadow-sm">
            <Icon
              icon="heroicons:cpu-chip"
              className="h-4 w-4 text-healthcare-info dark:text-healthcare-info-dark"
              aria-hidden="true"
            />
            <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {hoursAtRisk === 0
                ? 'Roster covers all forecast hours'
                : `${formatDurationHours(hoursAtRisk)} of ${formatDurationHours(rows.length)} under pressure`}
            </span>
          </div>
        }
      >
        <div className="flex flex-col gap-5">
          {/* KPI wall */}
          <Section
            title="Resource pressure"
            icon="heroicons:cpu-chip"
            summary={`${totalPredicted} predicted arrivals across the next ${formatDurationHours(rows.length)}`}
          >
            <MetricGrid metrics={kpiMetrics} />
          </Section>

          {/* Required vs available chart — resource toggle lives in actions */}
          <Section
            title={`Required vs available — ${view.label}`}
            icon="heroicons:chart-bar"
            summary={`${totalPredicted} predicted arrivals across the next ${formatDurationHours(rows.length)}`}
            actions={
              <div
                className="inline-flex rounded-md bg-healthcare-background dark:bg-healthcare-background-dark p-0.5"
                role="tablist"
                aria-label="Resource type"
              >
                {Object.entries(RESOURCE_VIEWS).map(([key, cfg]) => {
                  const activeTab = key === resourceView;
                  return (
                    <button
                      key={key}
                      type="button"
                      role="tab"
                      aria-selected={activeTab}
                      onClick={() => setResourceView(key)}
                      className={`inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-healthcare-warning ${
                        activeTab
                          ? 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark shadow-sm'
                          : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'
                      }`}
                    >
                      <Icon icon={cfg.icon} className="h-4 w-4" aria-hidden="true" />
                      {cfg.label}
                    </button>
                  );
                })}
              </div>
            }
          >
            <Panel className="p-4">
              {hasData ? (
                <div className="h-80">
                  <BarChart data={chartData} options={chartOptions} />
                </div>
              ) : (
                <EmptyState
                  icon="heroicons:chart-bar"
                  message="No arrival forecast available for this horizon"
                />
              )}
            </Panel>
          </Section>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {/* Hourly allocation board */}
            <Section
              className="lg:col-span-2"
              title="Hourly allocation plan"
              icon="heroicons:table-cells"
              summary="Predicted demand and the roster needed to cover it, hour by hour"
            >
              <Panel className="p-0">
                {hasData ? (
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark text-left">
                        <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Hour
                        </th>
                        <th className="px-4 py-3 text-right font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Arrivals
                        </th>
                        <th className="px-4 py-3 text-right font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Nurses
                        </th>
                        <th className="px-4 py-3 text-right font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Providers
                        </th>
                        <th className="px-4 py-3 text-right font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Beds
                        </th>
                        <th className="px-4 py-3 font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          Status
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.map((r) => (
                        <tr
                          key={r.hour}
                          className="border-b border-healthcare-border dark:border-healthcare-border-dark last:border-0 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200"
                        >
                          <td className="px-4 py-3 font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {r.hour}
                          </td>
                          <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {r.predictedArrivals}
                          </td>
                          <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            <span className={r.requiredNurses > r.availableNurses ? STATUS_TEXT.critical : ''}>
                              {r.requiredNurses}
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              {' '}/ {r.availableNurses}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            <span className={r.requiredProviders > r.availableProviders ? STATUS_TEXT.critical : ''}>
                              {r.requiredProviders}
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              {' '}/ {r.availableProviders}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            <span className={r.requiredBeds > r.availableBeds ? STATUS_TEXT.critical : ''}>
                              {r.requiredBeds}
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              {' '}/ {r.availableBeds}
                            </span>
                          </td>
                          <td className="px-4 py-3">
                            <span className="inline-flex items-center gap-1.5">
                              <span
                                className={`h-2 w-2 rounded-full ${STATUS_DOT[r.status] ?? STATUS_DOT.info}`}
                                aria-hidden="true"
                              />
                              <Icon
                                icon={STATUS_ICON[r.status] ?? STATUS_ICON.info}
                                className={`h-4 w-4 ${STATUS_TEXT[r.status] ?? STATUS_TEXT.info}`}
                                aria-hidden="true"
                              />
                              <span className={`text-xs font-medium ${STATUS_TEXT[r.status] ?? STATUS_TEXT.info}`}>
                                {STATUS_LABEL[r.status] ?? 'Covered'}
                              </span>
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="p-4">
                    <EmptyState
                      icon="heroicons:table-cells"
                      message="No hourly allocation plan available"
                    />
                  </div>
                )}
              </Panel>
            </Section>

            {/* Right rail: recommendations + acuity mix */}
            <div className="flex flex-col gap-5">
              <Section
                title="Recommendations"
                icon="heroicons:clipboard-document-list"
                summary="Prioritized by severity"
              >
                <Panel className="space-y-3 p-4">
                  {recs.map((rec) => (
                    <div
                      key={rec.id}
                      className="flex items-start gap-3 rounded-md border border-healthcare-border dark:border-healthcare-border-dark p-3"
                    >
                      <Icon
                        icon={STATUS_ICON[rec.priority] ?? STATUS_ICON.info}
                        className={`mt-0.5 h-5 w-5 flex-shrink-0 ${STATUS_TEXT[rec.priority] ?? STATUS_TEXT.info}`}
                        aria-hidden="true"
                      />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                          <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {rec.resource}
                          </span>
                          <span
                            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${PRIORITY_BADGE[rec.priority] ?? PRIORITY_BADGE.info}`}
                          >
                            {PRIORITY_LABEL[rec.priority] ?? 'Steady'}
                          </span>
                        </div>
                        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {rec.detail}
                        </p>
                        <p className="mt-1 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {formatRecommendationTime(rec.hour)}
                        </p>
                      </div>
                    </div>
                  ))}
                </Panel>
              </Section>

              <Section
                title="Predicted acuity mix"
                icon="heroicons:chart-pie"
                summary="Drives the staffing weight per arrival"
              >
                <Panel className="space-y-3 p-4">
                  {acuity.map((a) => (
                    <div key={a.esi} className="flex items-center gap-3">
                      <span
                        className={`inline-flex h-6 w-8 items-center justify-center rounded text-xs font-semibold tabular-nums ${ESI_BADGE[a.esi] ?? ESI_BADGE[3]}`}
                      >
                        {a.esi}
                      </span>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {a.label}
                          </span>
                          <span className="text-sm font-medium tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {a.pct}%
                          </span>
                        </div>
                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                          <div
                            className={`h-full rounded-full ${STATUS_DOT[a.esi <= 2 ? (a.esi === 1 ? 'critical' : 'warning') : 'info']}`}
                            style={{ width: `${Math.min(100, a.pct)}%` }}
                          />
                        </div>
                      </div>
                    </div>
                  ))}
                </Panel>
              </Section>
            </div>
          </div>

          {generatedAt && (
            <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Forecast generated {new Date(generatedAt).toLocaleString()}
            </p>
          )}
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
