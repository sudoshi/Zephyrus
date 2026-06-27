import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, UnitHeatStrip, Panel, EmptyState, STATUS_VAR } from '@/Components/system';

// Live RTDC Utilization & Capacity — house + per-unit occupancy/capacity built
// on the shared design system (Section / MetricGrid / KpiTile / UnitHeatStrip),
// fed by UtilizationAnalyticsService from prod.census_snapshots. Replaces the
// "This page displays…" stub. KPI wall + per-unit occupancy bar + real
// house-wide occupancy trend, with defensible loading / empty states.

// Map a unit/occupancy status string to the four-color CSS var.
function statusColor(status) {
    return STATUS_VAR[status] ?? STATUS_VAR.neutral;
}

// Horizontal per-unit occupancy bar. Token-colored fill per status; raw
// occupied/staffed and available shown inline. No raw tailwind palette — the
// bar color comes from the status CSS vars (var(--critical) etc.).
function OccupancyByUnit({ units }) {
    if (!units || units.length === 0) {
        return <EmptyState message="No units reporting occupancy" />;
    }
    return (
        <Panel className="flex flex-col gap-3 p-4">
            {units.map((u) => {
                const color = statusColor(u.status);
                const pct = Math.max(0, Math.min(100, u.occupancyPct));
                return (
                    <div key={u.unitId} className="flex items-center gap-3">
                        <div className="flex w-28 shrink-0 flex-col leading-tight">
                            <span className="truncate text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {u.name}
                            </span>
                            <span className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {u.type}
                            </span>
                        </div>
                        <div className="relative h-5 flex-1 overflow-hidden rounded-full bg-healthcare-border dark:bg-healthcare-border-dark">
                            <div
                                className="h-full rounded-full transition-[width] duration-500 ease-out"
                                style={{ width: `${pct}%`, background: color }}
                            />
                        </div>
                        <div className="flex w-32 shrink-0 items-baseline justify-end gap-2">
                            <span className="text-sm font-semibold tabular-nums" style={{ color }}>
                                {u.occupancyPct}%
                            </span>
                            <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {u.occupied}/{u.staffed} {'·'} {u.available} open
                            </span>
                        </div>
                    </div>
                );
            })}
        </Panel>
    );
}

// Real house-wide occupancy trend rendered as a token-styled SVG line + area.
// Built from census snapshots; uses healthcare tokens / CSS vars only (no
// recharts hex). Degrades to an empty state when too few points to draw.
function OccupancyTrend({ points }) {
    if (!points || points.length < 2) {
        return <EmptyState message="Not enough history to chart occupancy" />;
    }

    const W = 720;
    const H = 200;
    const padX = 8;
    const padY = 16;
    const values = points.map((p) => p.occupancy);
    const min = Math.max(0, Math.min(...values) - 5);
    const max = Math.min(100, Math.max(...values) + 5);
    const span = max - min || 1;

    const x = (i) => padX + (i / (points.length - 1)) * (W - padX * 2);
    const y = (v) => padY + (1 - (v - min) / span) * (H - padY * 2);

    const linePath = points
        .map((p, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)} ${y(p.occupancy).toFixed(1)}`)
        .join(' ');
    const areaPath =
        `${linePath} L ${x(points.length - 1).toFixed(1)} ${(H - padY).toFixed(1)}` +
        ` L ${x(0).toFixed(1)} ${(H - padY).toFixed(1)} Z`;

    const latest = points[points.length - 1].occupancy;
    const stroke = 'rgb(var(--color-healthcare-primary))';

    return (
        <Panel className="flex flex-col gap-2 p-4">
            <div className="flex items-baseline justify-between">
                <span className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    House occupancy
                </span>
                <span className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {latest}%
                </span>
            </div>
            <svg
                viewBox={`0 0 ${W} ${H}`}
                preserveAspectRatio="none"
                className="h-44 w-full"
                role="img"
                aria-label="House-wide occupancy trend"
            >
                <defs>
                    <linearGradient id="util-occ-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={stroke} stopOpacity="0.22" />
                        <stop offset="100%" stopColor={stroke} stopOpacity="0" />
                    </linearGradient>
                </defs>
                <path d={areaPath} fill="url(#util-occ-fill)" />
                <path d={linePath} fill="none" stroke={stroke} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                {points.map((p, i) => (
                    <circle key={p.label} cx={x(i)} cy={y(p.occupancy)} r="2.5" fill={stroke} />
                ))}
            </svg>
            <div className="flex justify-between">
                {points.map((p) => (
                    <span
                        key={p.label}
                        className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                    >
                        {p.label}
                    </span>
                ))}
            </div>
        </Panel>
    );
}

export default function Utilization({ houseSummary, kpis, unitCensus, occupancyByUnit, occupancyTrend }) {
    const summary = houseSummary ?? {};
    const metrics = kpis ?? [];
    const units = unitCensus ?? [];
    const byUnit = occupancyByUnit ?? [];
    const trend = occupancyTrend ?? [];

    const occupied = summary.occupied ?? 0;
    const staffed = summary.staffed ?? 0;
    const available = summary.available ?? 0;
    const unitCount = summary.unitCount ?? units.length;

    const hasData = metrics.length > 0 || units.length > 0;

    return (
        <RTDCPageLayout title="Utilization & Capacity" subtitle="House and per-unit occupancy, capacity and load">
            {!hasData ? (
                <EmptyState message="No census reporting — utilization data is unavailable" />
            ) : (
                <div className="flex flex-col gap-5">
                    <Section
                        title="House capacity"
                        icon="heroicons:building-office-2"
                        summary={`${occupied}/${staffed} occupied · ${available} open across ${unitCount} units`}
                        drillHref="/rtdc/bed-tracking"
                        drillLabel="Bed tracking"
                    >
                        <MetricGrid metrics={metrics} />
                    </Section>

                    <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
                        <Section
                            title="Occupancy by unit"
                            icon="heroicons:chart-bar"
                            summary="Per-unit occupancy — hottest first"
                            drillHref="/rtdc/analytics/resources"
                            drillLabel="Resources"
                        >
                            <OccupancyByUnit units={byUnit} />
                        </Section>

                        <Section
                            title="Occupancy trend"
                            icon="heroicons:presentation-chart-line"
                            summary="House-wide occupancy over recent census"
                        >
                            <OccupancyTrend points={trend} />
                        </Section>
                    </div>

                    <Section
                        title="Census by unit"
                        icon="heroicons:squares-2x2"
                        summary="Occupancy and open beds per unit — acuity-adjusted"
                        drillHref="/rtdc/bed-tracking"
                        drillLabel="Bed tracking"
                    >
                        <UnitHeatStrip units={units} />
                    </Section>
                </div>
            )}
        </RTDCPageLayout>
    );
}
