import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, UnitHeatStrip, metric } from '@/Components/system';
import type { UnitCensus } from '@/Components/system';

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
    definition: 'Admitted patients held in the ED for >2h waiting on an inpatient bed.', drillHref: '/dashboard/emergency' }),
  metric({ key: 'net-position', label: 'Net bed position · 4h', value: 5, status: 'success',
    display: '+5', trajectory: [-2, 0, 1, 3, 4, 5],
    definition: 'Projected discharges minus admissions over the next 4 hours.', drillHref: '/rtdc/predictions/demand' }),
];

const UNITS: UnitCensus[] = [
  { unitId: 1, name: '4 West', type: 'Med/Surg', staffed: 32, occupied: 30, blocked: 1, available: 1, occupancyPct: 94, acuityAdjustedPct: 96, status: 'critical' },
  { unitId: 2, name: '5 East', type: 'Med/Surg', staffed: 28, occupied: 24, blocked: 0, available: 4, occupancyPct: 86, acuityAdjustedPct: 88, status: 'warning' },
  { unitId: 3, name: '6 North', type: 'Telemetry', staffed: 24, occupied: 22, blocked: 1, available: 1, occupancyPct: 92, acuityAdjustedPct: 95, status: 'critical' },
  { unitId: 4, name: 'ICU', type: 'Critical', staffed: 20, occupied: 17, blocked: 0, available: 3, occupancyPct: 85, acuityAdjustedPct: 90, status: 'warning' },
  { unitId: 5, name: 'PCU', type: 'Step-down', staffed: 18, occupied: 13, blocked: 1, available: 4, occupancyPct: 72, acuityAdjustedPct: 75, status: 'success' },
  { unitId: 6, name: '3 South', type: 'Med/Surg', staffed: 30, occupied: 21, blocked: 0, available: 9, occupancyPct: 70, acuityAdjustedPct: 72, status: 'success' },
  { unitId: 7, name: 'Ortho', type: 'Surgical', staffed: 22, occupied: 19, blocked: 0, available: 3, occupancyPct: 86, acuityAdjustedPct: 84, status: 'warning' },
  { unitId: 8, name: 'Oncology', type: 'Med/Surg', staffed: 16, occupied: 12, blocked: 1, available: 3, occupancyPct: 75, acuityAdjustedPct: 80, status: 'success' },
  { unitId: 9, name: 'Neuro', type: 'Specialty', staffed: 18, occupied: 17, blocked: 0, available: 1, occupancyPct: 94, acuityAdjustedPct: 97, status: 'critical' },
  { unitId: 10, name: 'CVICU', type: 'Critical', staffed: 14, occupied: 11, blocked: 0, available: 3, occupancyPct: 79, acuityAdjustedPct: 86, status: 'warning' },
];

export default function BedTracking() {
  const openBeds = UNITS.reduce((n, u) => n + u.available, 0);
  return (
    <RTDCPageLayout title="Bed Tracking" subtitle="Real-time bed status and capacity management">
      <div className="flex flex-col gap-5">
        <Section
          title="Capacity"
          icon="heroicons:building-office-2"
          summary={`${OCCUPIED}/${TOTAL} occupied · ${openBeds} open across ${UNITS.length} units`}
          drillHref="/rtdc/analytics/utilization"
          drillLabel="Utilization"
        >
          <MetricGrid metrics={capacity} />
        </Section>

        <Section
          title="Pending flow"
          icon="heroicons:arrows-right-left"
          summary="Admissions, discharges & transfers in motion"
          drillHref="/rtdc/bed-placement"
          drillLabel="Bed placement"
        >
          <MetricGrid metrics={flow} />
        </Section>

        <Section
          title="Census by unit"
          icon="heroicons:squares-2x2"
          summary="Occupancy and open beds per unit — acuity-adjusted"
          drillHref="/rtdc/analytics/resources"
          drillLabel="Resources"
        >
          <UnitHeatStrip units={UNITS} />
        </Section>
      </div>
    </RTDCPageLayout>
  );
}
