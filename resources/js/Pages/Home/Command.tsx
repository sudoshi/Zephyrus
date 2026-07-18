import HomePageLayout from '@/Components/Home/HomePageLayout';
import { Section } from '@/Components/system';
import { Sparkline } from '@/Components/cockpit/Sparkline';
import { ProvenanceBadge } from '@/Components/cockpit/ProvenanceBadge';

// Virtual Ward Command — the Home Hospital flagship (ACUM-PRD-HAH-001 §4.2).
// A grid of episode tiles sorted by escalation risk (breach → HEWS → acuity).
// Earned urgency: the resting tile is grey; ONLY an unacknowledged critical
// vital (breach=true) earns the coral treatment. HEWS is operational triage,
// not diagnosis — the chip carries the band label, never a diagnosis claim.

interface VitalSeries {
  loinc: string;
  display: string;
  unit: string | null;
  latest: number;
  series: number[];
}

interface EpisodeAlertItem {
  alertUuid: string;
  ruleKey: string;
  severity: 'watch' | 'warning' | 'critical';
  status: string;
  display: string | null;
  value: number | null;
  unit: string | null;
  openedAt: string | null;
}

interface EpisodeTile {
  episodeUuid: string;
  patientRef: string;
  slotLabel: string | null;
  program: string | null;
  conditionLabel: string | null;
  acuityTier: number | null;
  serviceZone: string | null;
  dayOfStay: number | null;
  targetLosDays: number | null;
  expectedDischargeDate: string | null;
  hews: { score: number; band: 'low' | 'medium' | 'high'; components: Record<string, number> } | null;
  vitals: VitalSeries[];
  alerts: { open: number; acknowledged: number; critical: number; items: EpisodeAlertItem[] };
  nextVisit: { type: string; scheduledStart: string | null; isWaiverRequired: boolean } | null;
  device: { kitCode: string; connectivity: string | null; batteryPct: number | null; lastSeenAt: string | null; online: boolean } | null;
  breach: boolean;
  provenance: string | null;
}

interface CommandProps {
  ward: { name: string; abbreviation: string } | null;
  episodes: EpisodeTile[];
  summary: { active: number; breaches: number; highRisk: number; openAlerts: number };
}

const HEWS_STYLE: Record<'low' | 'medium' | 'high', { label: string; dotClass: string }> = {
  low: { label: 'Low', dotClass: 'bg-healthcare-success dark:bg-healthcare-success-dark' },
  medium: { label: 'Medium', dotClass: 'bg-healthcare-warning dark:bg-healthcare-warning-dark' },
  high: { label: 'High', dotClass: 'bg-healthcare-critical dark:bg-healthcare-critical-dark' },
};

const VISIT_LABELS: Record<string, string> = {
  rn: 'RN visit',
  community_paramedic: 'Paramedic visit',
  md_np_tele: 'MD/NP tele',
  md_np_in_person: 'MD/NP in person',
  lab_draw: 'Lab draw',
  delivery: 'Delivery',
  other: 'Visit',
};

function visitCountdown(iso: string | null): { text: string; overdue: boolean } {
  if (!iso) return { text: '—', overdue: false };
  const deltaMin = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
  if (deltaMin >= 0) {
    const h = Math.floor(deltaMin / 60);
    return { text: h > 0 ? `in ${h}h ${deltaMin % 60}m` : `in ${deltaMin}m`, overdue: false };
  }
  const late = Math.abs(deltaMin);
  const h = Math.floor(late / 60);
  return { text: h > 0 ? `overdue ${h}h ${late % 60}m` : `overdue ${late}m`, overdue: true };
}

function EpisodeCard({ tile }: { tile: EpisodeTile }) {
  const hews = tile.hews ? HEWS_STYLE[tile.hews.band] : null;
  const countdown = visitCountdown(tile.nextVisit?.scheduledStart ?? null);

  return (
    <div
      className={`rounded-lg border bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm p-3 flex flex-col gap-2.5 ${
        tile.breach
          ? 'border-healthcare-critical dark:border-healthcare-critical-dark'
          : 'border-healthcare-border dark:border-healthcare-border-dark'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex flex-col">
          <span className="flex items-center gap-2">
            <span className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {tile.patientRef}
            </span>
            {tile.provenance === 'demo' && <ProvenanceBadge />}
          </span>
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {tile.conditionLabel ?? '—'}
            {tile.slotLabel && <span className="tabular-nums"> · {tile.slotLabel}</span>}
          </span>
        </div>
        {hews && tile.hews && (
          <span className="flex shrink-0 items-center gap-1.5 rounded border border-healthcare-border dark:border-healthcare-border-dark px-1.5 py-0.5 text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            <span aria-hidden className={`h-1.5 w-1.5 rounded-full ${hews.dotClass}`} />
            HEWS {tile.hews.score} · {hews.label}
          </span>
        )}
      </div>

      {tile.breach && (
        <p className="text-xs font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
          ◆ Unacknowledged critical vital
        </p>
      )}

      <div className="flex items-center justify-between gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span className="tabular-nums">
          Day {tile.dayOfStay ?? '—'}
          {tile.targetLosDays != null && ` of ${tile.targetLosDays}`}
        </span>
        {tile.serviceZone && <span>{tile.serviceZone}</span>}
        <span className="tabular-nums">
          {tile.alerts.open + tile.alerts.acknowledged} alert{tile.alerts.open + tile.alerts.acknowledged === 1 ? '' : 's'}
        </span>
      </div>

      {tile.vitals.length > 0 && (
        <div className="grid grid-cols-2 gap-2">
          {tile.vitals.map((vital) => (
            <div key={vital.loinc} className="flex flex-col gap-0.5">
              <div className="flex items-baseline justify-between gap-1">
                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark truncate">
                  {vital.display}
                </span>
                <span className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {vital.latest}
                  {vital.unit && <span className="text-xs font-normal"> {vital.unit}</span>}
                </span>
              </div>
              <Sparkline
                data={vital.series}
                status="neutral"
                id={`${tile.episodeUuid}-${vital.loinc}`}
                className="h-8 w-full"
              />
            </div>
          ))}
        </div>
      )}

      <div className="mt-auto flex items-center justify-between gap-2 border-t border-healthcare-border dark:border-healthcare-border-dark pt-2 text-xs">
        <span
          className={
            countdown.overdue
              ? 'font-medium text-healthcare-warning dark:text-healthcare-warning-dark'
              : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
          }
        >
          {tile.nextVisit
            ? `${VISIT_LABELS[tile.nextVisit.type] ?? 'Visit'} ${countdown.text}${tile.nextVisit.isWaiverRequired ? ' · waiver' : ''}`
            : 'No visit scheduled'}
        </span>
        {tile.device && (
          <span className="flex shrink-0 items-center gap-1.5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span
              aria-hidden
              className={`h-1.5 w-1.5 rounded-full ${
                tile.device.online
                  ? 'bg-healthcare-success dark:bg-healthcare-success-dark'
                  : 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
              }`}
            />
            <span className="tabular-nums">
              {tile.device.kitCode} · {tile.device.online ? 'online' : 'offline'}
            </span>
          </span>
        )}
      </div>
    </div>
  );
}

export default function Command({ ward, episodes, summary }: CommandProps) {
  return (
    <HomePageLayout
      title="Virtual Ward Command"
      subtitle={
        ward
          ? `${ward.name} · ${summary.active} active episodes · ${summary.openAlerts} open alerts`
          : 'Virtual ward not provisioned'
      }
    >
      <Section
        title="Active episodes"
        icon="heroicons:heart"
        summary={`${summary.breaches} breach${summary.breaches === 1 ? '' : 'es'} · ${summary.highRisk} high-risk · sorted by escalation risk`}
      >
        {episodes.length > 0 ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-3">
            {episodes.map((tile) => (
              <EpisodeCard key={tile.episodeUuid} tile={tile} />
            ))}
          </div>
        ) : (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No active home episodes. Activate a referral from the funnel, or run the demo seed.
          </p>
        )}
      </Section>
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        HEWS is operational triage support, not a diagnostic device. Escalation decisions rest with the clinical team.
      </p>
    </HomePageLayout>
  );
}
