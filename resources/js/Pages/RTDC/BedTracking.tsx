import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, UnitHeatStrip, metric } from '@/Components/system';
import type { KpiMetric, UnitCensus } from '@/Components/system';

// Gold-standard rebuild of Bed Tracking on the shared design system: a dense
// instrument wall (KpiTiles with status + target + sparkline) grouped into
// Sections, plus a per-unit census heat strip — replacing the old sparse
// MetricsCard grid and the "bed map coming soon" placeholder. Values are
// representative demo data until the live census feed is wired through.

const TOTAL = 500;
const OCCUPIED = 425;

const capacity = [
  metric({ key: 'total-beds', label: 'Total staffed beds', value: TOTAL, status: 'neutral',
    trajectory: [500, 500, 498, 500, 500, 500],
    definition: 'Staffed, licensed beds available house-wide right now.', drillHref: '/rtdc/analytics/utilization' }),
  metric({ key: 'occupied', label: 'Occupied', value: OCCUPIED, status: 'warning',
    trajectory: [402, 410, 418, 415, 422, 425], target: 400, goodWhenDown: true,
    definition: 'Beds with a current inpatient. Above target signals tightening capacity.' }),
  metric({ key: 'available', label: 'Available now', value: 75, status: 'critical', goodWhenDown: false,
    trajectory: [98, 90, 84, 85, 78, 75], target: 100,
    definition: 'Clean, staffed, immediately assignable beds. Below 100 is a capacity alert.' }),
  metric({ key: 'occupancy', label: 'Occupancy', value: 85, unit: '%', status: 'warning',
    trajectory: [80, 82, 84, 83, 84, 85], target: 80, goodWhenDown: true,
    definition: 'Occupied ÷ staffed beds. The headline capacity number; >85% strains flow.' }),
  metric({ key: 'turnover', label: 'In turnover', value: 12, status: 'info',
    trajectory: [8, 10, 14, 11, 13, 12],
    definition: 'Beds being cleaned between patients (EVS in progress).' }),
  metric({ key: 'blocked', label: 'Blocked', value: 6, status: 'warning', goodWhenDown: true,
    trajectory: [3, 4, 5, 7, 6, 6],
    definition: 'Beds unavailable for staffing, isolation, or maintenance reasons.' }),
];

const flow = [
  metric({ key: 'pend-admit', label: 'Pending admissions', value: 15, status: 'warning', goodWhenDown: true,
    trajectory: [9, 11, 13, 12, 16, 15], target: 8,
    definition: 'Patients with an admit order awaiting a bed assignment.', drillHref: '/rtdc/bed-placement' }),
  metric({ key: 'pend-discharge', label: 'Pending discharges', value: 20, status: 'success',
    trajectory: [12, 14, 17, 16, 19, 20], target: 24,
    definition: 'Discharge orders in process — capacity that will free up today.' }),
  metric({ key: 'pend-transfer', label: 'Pending transfers', value: 8, status: 'info',
    trajectory: [5, 6, 7, 6, 9, 8],
    definition: 'Internal unit-to-unit moves awaiting a destination bed.' }),
  metric({ key: 'ed-boarding', label: 'ED boarding', value: 9, status: 'critical', goodWhenDown: true,
    trajectory: [3, 5, 6, 8, 9, 9], target: 2,
    definition: 'Admitted patients held in the ED for >2h waiting on an inpatient bed.', drillHref: '/dashboard?drill=ed' }),
  metric({ key: 'net-position', label: 'Net bed position · 4h', value: 5, status: 'success',
    display: '+5', trajectory: [-2, 0, 1, 3, 4, 5],
    definition: 'Projected discharges minus admissions over the next 4 hours.', drillHref: '/rtdc/predictions/demand' }),
];

// Fallback only — overridden by live unitCensus from BedTrackingService when present.
// Units are the real Summit Regional roster (see config/hospital/hospital-1.php).
const UNITS: UnitCensus[] = [
  { unitId: 1, name: 'MICU', type: 'Critical', staffed: 24, occupied: 22, blocked: 1, available: 1, occupancyPct: 92, acuityAdjustedPct: 95, status: 'critical' },
  { unitId: 2, name: 'SICU', type: 'Critical', staffed: 24, occupied: 21, blocked: 0, available: 3, occupancyPct: 88, acuityAdjustedPct: 91, status: 'warning' },
  { unitId: 3, name: 'CVICU', type: 'Critical', staffed: 20, occupied: 18, blocked: 0, available: 2, occupancyPct: 90, acuityAdjustedPct: 93, status: 'critical' },
  { unitId: 4, name: '7E', type: 'Telemetry', staffed: 32, occupied: 27, blocked: 1, available: 4, occupancyPct: 84, acuityAdjustedPct: 87, status: 'warning' },
  { unitId: 5, name: '5E', type: 'Med/Surg', staffed: 28, occupied: 24, blocked: 0, available: 4, occupancyPct: 86, acuityAdjustedPct: 88, status: 'warning' },
  { unitId: 6, name: '6E', type: 'Med/Surg', staffed: 24, occupied: 19, blocked: 1, available: 4, occupancyPct: 79, acuityAdjustedPct: 82, status: 'success' },
  { unitId: 7, name: 'BHU', type: 'Behavioral', staffed: 24, occupied: 22, blocked: 0, available: 2, occupancyPct: 92, acuityAdjustedPct: 92, status: 'critical' },
  { unitId: 8, name: 'ONC', type: 'Oncology', staffed: 24, occupied: 22, blocked: 1, available: 1, occupancyPct: 92, acuityAdjustedPct: 94, status: 'critical' },
  { unitId: 9, name: 'AIR', type: 'Rehab', staffed: 20, occupied: 15, blocked: 0, available: 5, occupancyPct: 75, acuityAdjustedPct: 77, status: 'success' },
  { unitId: 10, name: 'PED', type: 'Pediatrics', staffed: 24, occupied: 18, blocked: 0, available: 6, occupancyPct: 75, acuityAdjustedPct: 78, status: 'success' },
];

interface BedTrackingProps {
  /** Live metric values keyed by metric key (from BedTrackingService). */
  bedMetrics?: Record<string, number>;
  /** Live per-unit census (from BedTrackingService). */
  unitCensus?: UnitCensus[];
}

// Swap the authored demo value for the live value (when present), keeping every
// authored presentation detail — status, target, sparkline trajectory,
// definition, drill link — so the page looks identical while reading real data.
function withLive(metrics: KpiMetric[], live?: Record<string, number>): KpiMetric[] {
  if (!live) return metrics;
  return metrics.map((m) => {
    const v = live[m.key];
    if (v == null) return m;
    const isPct = m.unit === '%';
    const display = isPct
      ? `${v}%`
      : m.key === 'net-position'
        ? `${v > 0 ? '+' : ''}${v.toLocaleString('en-US')}`
        : v.toLocaleString('en-US');
    return { ...m, value: v, display };
  });
}

export default function BedTracking({ bedMetrics, unitCensus }: BedTrackingProps) {
  const liveCapacity = withLive(capacity, bedMetrics);
  const liveFlow = withLive(flow, bedMetrics);
  const units = unitCensus && unitCensus.length > 0 ? unitCensus : UNITS;

  const totalBeds = bedMetrics?.['total-beds'] ?? TOTAL;
  const occupiedBeds = bedMetrics?.['occupied'] ?? OCCUPIED;
  const openBeds = units.reduce((n, u) => n + u.available, 0);
  return (
    <RTDCPageLayout title="Bed Tracking" subtitle="Real-time bed status and capacity management">
      <div className="flex flex-col gap-5">
        <Section
          title="Capacity"
          icon="heroicons:building-office-2"
          summary={`${occupiedBeds}/${totalBeds} occupied · ${openBeds} open across ${units.length} units`}
          drillHref="/rtdc/analytics/utilization"
          drillLabel="Utilization"
        >
          <MetricGrid metrics={liveCapacity} />
        </Section>

        <Section
          title="Pending flow"
          icon="heroicons:arrows-right-left"
          summary="Admissions, discharges & transfers in motion"
          drillHref="/rtdc/bed-placement"
          drillLabel="Bed placement"
        >
          <MetricGrid metrics={liveFlow} />
        </Section>

        <Section
          title="Census by unit"
          icon="heroicons:squares-2x2"
          summary="Occupancy and open beds per unit — acuity-adjusted"
          drillHref="/rtdc/analytics/resources"
          drillLabel="Resources"
        >
          <UnitHeatStrip units={units} />
        </Section>
      </div>
    </RTDCPageLayout>
  );
}
