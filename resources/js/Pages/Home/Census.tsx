import HomePageLayout from '@/Components/Home/HomePageLayout';
import { Section, MetricGrid, metric } from '@/Components/system';
import { ProvenanceBadge } from '@/Components/cockpit/ProvenanceBadge';

// Virtual Bed Board — Phase 0 flagship of the Home Hospital workspace
// (ACUM-PRD-HAH-001 §4.2). The virtual ward is one more census-spine unit, so
// this board reads the same engine as the house-wide huddle. Earned urgency:
// slot states are informational (grey/teal/amber baseline); coral is reserved
// for later-phase true breaches (unacked critical vitals, blown response SLAs).

interface SlotEpisode {
  patientRef: string;
  program: string | null;
  conditionLabel: string | null;
  acuityTier: number | null;
  serviceZone: string | null;
  dayOfStay: number | null;
  targetLosDays: number | null;
  expectedDischargeDate: string | null;
  provenance: string | null;
}

interface Slot {
  bedId: number;
  label: string;
  status: 'available' | 'occupied' | 'pending_setup' | 'blocked';
  episode: SlotEpisode | null;
}

interface CensusProps {
  unit: { name: string; abbreviation: string; slotCount: number } | null;
  slots: Slot[];
  occupancy: { occupied: number; capacity: number; pct: number | null };
  pipeline: {
    counts: Record<string, number>;
    declines: Record<string, number>;
  };
  projectedDischarges: { next24h: number; next48h: number };
}

const SLOT_STATUS: Record<
  Slot['status'],
  { label: string; dotClass: string }
> = {
  available: {
    label: 'Available',
    dotClass: 'bg-healthcare-success dark:bg-healthcare-success-dark',
  },
  occupied: {
    label: 'Occupied',
    dotClass: 'bg-healthcare-info dark:bg-healthcare-info-dark',
  },
  pending_setup: {
    label: 'Pending setup',
    dotClass: 'bg-healthcare-warning dark:bg-healthcare-warning-dark',
  },
  blocked: {
    label: 'Blocked',
    dotClass: 'bg-healthcare-border dark:bg-healthcare-border-dark',
  },
};

const FUNNEL_ORDER = ['referred', 'screened', 'eligible', 'consented', 'activated', 'declined'] as const;

const FUNNEL_LABELS: Record<(typeof FUNNEL_ORDER)[number], string> = {
  referred: 'Referred',
  screened: 'Screened',
  eligible: 'Eligible',
  consented: 'Consented',
  activated: 'Activated',
  declined: 'Declined',
};

function declineLabel(reason: string): string {
  return reason.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
}

function SlotCard({ slot }: { slot: Slot }) {
  const status = SLOT_STATUS[slot.status];
  const episode = slot.episode;

  return (
    <div
      className="rounded-lg border border-healthcare-border dark:border-healthcare-border-dark
                 bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm p-3
                 flex flex-col gap-2"
    >
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {slot.label}
        </span>
        <span className="flex items-center gap-1.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <span aria-hidden className={`h-1.5 w-1.5 rounded-full ${status.dotClass}`} />
          {status.label}
        </span>
      </div>

      {episode ? (
        <div className="flex flex-col gap-1">
          <div className="flex items-center justify-between gap-2">
            <span className="text-sm font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {episode.patientRef}
            </span>
            {episode.provenance === 'demo' && <ProvenanceBadge />}
          </div>
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {episode.conditionLabel ?? '—'}
          </span>
          <div className="flex items-center justify-between gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span className="tabular-nums">
              Day {episode.dayOfStay ?? '—'}
              {episode.targetLosDays != null && ` of ${episode.targetLosDays}`}
            </span>
            {episode.serviceZone && <span>{episode.serviceZone}</span>}
          </div>
        </div>
      ) : (
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {slot.status === 'pending_setup'
            ? 'Kit delivery & setup in progress'
            : slot.status === 'blocked'
              ? 'Held — not accepting enrollment'
              : 'Open for enrollment'}
        </span>
      )}
    </div>
  );
}

export default function Census({ unit, slots, occupancy, pipeline, projectedDischarges }: CensusProps) {
  const available = slots.filter((s) => s.status === 'available').length;
  const pendingSetup = slots.filter((s) => s.status === 'pending_setup').length;
  const blocked = slots.filter((s) => s.status === 'blocked').length;

  const capacityMetrics = [
    metric({
      key: 'home-occupancy',
      label: 'Ward occupancy',
      value: occupancy.pct ?? 0,
      unit: '%',
      status: 'neutral',
      definition: 'Occupied program slots ÷ total slots on the virtual ward.',
    }),
    metric({
      key: 'home-occupied',
      label: 'Occupied slots',
      value: occupancy.occupied,
      status: 'neutral',
      definition: 'Active home episodes holding a program slot right now.',
    }),
    metric({
      key: 'home-available',
      label: 'Free slots tonight',
      value: available,
      status: available === 0 ? 'warning' : 'success',
      definition: 'Slots open for enrollment — the decant capacity next to ED boarding.',
    }),
    metric({
      key: 'home-pending',
      label: 'Pending setup',
      value: pendingSetup,
      status: 'neutral',
      definition: 'Slots awaiting kit delivery, connectivity check, or home-safety sign-off.',
    }),
    metric({
      key: 'home-blocked',
      label: 'Blocked',
      value: blocked,
      status: 'neutral',
      definition: 'Slots held back from enrollment (staffing, zone coverage, or safety hold).',
    }),
    metric({
      key: 'home-discharge-24',
      label: 'Projected discharges · 24h',
      value: projectedDischarges.next24h,
      status: 'info',
      definition: 'Episodes at expected discharge within 24 hours — slots freeing up.',
    }),
    metric({
      key: 'home-discharge-48',
      label: 'Projected discharges · 48h',
      value: projectedDischarges.next48h,
      status: 'info',
      definition: 'Episodes at expected discharge within 48 hours.',
    }),
  ];

  const funnelTotal = FUNNEL_ORDER.reduce((n, k) => n + (pipeline.counts[k] ?? 0), 0);

  return (
    <HomePageLayout
      title="Virtual Bed Board"
      subtitle={unit ? `${unit.name} · ${occupancy.occupied}/${occupancy.capacity} slots occupied` : 'Virtual ward not provisioned'}
    >
      <div className="flex flex-col gap-5">
        <Section
          title="Program capacity"
          icon="heroicons:home-modern"
          summary={
            unit
              ? `${available} free · ${pendingSetup} pending setup · ${blocked} blocked`
              : 'Seed the virtual ward to activate this board'
          }
        >
          <MetricGrid metrics={capacityMetrics} />
        </Section>

        <Section
          title="Ward slots"
          icon="heroicons:squares-2x2"
          summary={unit ? `${occupancy.capacity} program slots on ${unit.abbreviation}` : 'No virtual_home unit found'}
        >
          {slots.length > 0 ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
              {slots.map((slot) => (
                <SlotCard key={slot.bedId} slot={slot} />
              ))}
            </div>
          ) : (
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              No program slots yet — run the Home Hospital demo seed or provision the ward.
            </p>
          )}
        </Section>

        <Section
          title="Enrollment pipeline"
          icon="heroicons:funnel"
          summary={`${funnelTotal} referrals in the funnel`}
        >
          <div
            className="rounded-lg border border-healthcare-border dark:border-healthcare-border-dark
                       bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm
                       divide-y divide-healthcare-border dark:divide-healthcare-border-dark"
          >
            {FUNNEL_ORDER.map((stage) => (
              <div key={stage} className="flex items-center justify-between px-4 py-2">
                <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {FUNNEL_LABELS[stage]}
                </span>
                <span className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {pipeline.counts[stage] ?? 0}
                </span>
              </div>
            ))}
            {Object.keys(pipeline.declines).length > 0 && (
              <div className="px-4 py-2">
                <p className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Decline reasons
                </p>
                <div className="mt-1 flex flex-col gap-1">
                  {Object.entries(pipeline.declines).map(([reason, count]) => (
                    <div key={reason} className="flex items-center justify-between">
                      <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {declineLabel(reason)}
                      </span>
                      <span className="text-xs font-medium tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {count}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </Section>
      </div>
    </HomePageLayout>
  );
}
