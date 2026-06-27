import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Dashboard/Card';
import { ShieldAlert, TrendingUp, Activity, Users } from 'lucide-react';

/**
 * RTDC › Predictions › Risk Assessment
 *
 * Stratifies the active inpatient census by a transparent, deterministic 30-day
 * discharge / readmission risk proxy (LOS-vs-GMLOS overage, acuity tier, and
 * expected-discharge pressure), surfaced as risk-tier tallies, a high-risk
 * watchlist, and a contributing-factor drivers panel. Data comes from
 * RiskAssessmentService (prod.encounters × prod.gmlos_references). Decision
 * support only — never an automated discharge action.
 *
 * Status is always paired with an icon + label (never color alone), per the
 * design non-negotiables. When no live props are present the page keeps its
 * on-brand empty states so the route stays navigable.
 */

type Tone = 'critical' | 'warning' | 'success';
type TierKey = 'high' | 'moderate' | 'low';

interface RiskTier {
  readonly key: TierKey;
  readonly label: string;
  readonly tone: Tone;
  readonly blurb: string;
}

interface WatchlistRow {
  readonly id: string;
  readonly patient: string;
  readonly unit: string;
  readonly unitType: string;
  readonly acuityTier: number;
  readonly losDays: number;
  readonly gmlosDays: number;
  readonly overageDays: number;
  readonly daysToEdd: number | null;
  readonly risk: number;
  readonly tier: TierKey;
  readonly signals: readonly string[];
}

interface DriverRow {
  readonly key: string;
  readonly label: string;
  readonly detail: string;
  readonly count: number;
  readonly prevalence: number;
  readonly avgContribution: number;
}

interface RiskAssessmentProps {
  readonly tiers?: Record<TierKey, number>;
  readonly total?: number;
  readonly averageRisk?: number;
  readonly watchlist?: readonly WatchlistRow[];
  readonly drivers?: readonly DriverRow[];
  readonly generatedAt?: string;
}

const RISK_TIERS: readonly RiskTier[] = [
  { key: 'high', label: 'High readmission risk', tone: 'critical', blurb: 'Composite risk ≥ 0.40 — prioritise discharge planning' },
  { key: 'moderate', label: 'Moderate risk', tone: 'warning', blurb: 'Risk 0.20–0.39 — monitor discharge readiness' },
  { key: 'low', label: 'Low risk', tone: 'success', blurb: 'Risk < 0.20 — standard discharge pathway' },
];

const TONE_CLASS: Record<Tone, string> = {
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  success: 'text-healthcare-success dark:text-healthcare-success-dark',
};

const TIER_TONE: Record<TierKey, Tone> = {
  high: 'critical',
  moderate: 'warning',
  low: 'success',
};

const TIER_LABEL: Record<TierKey, string> = {
  high: 'High',
  moderate: 'Moderate',
  low: 'Low',
};

function EmptyState({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-10 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{icon}</div>
      <p className="text-sm">{children}</p>
    </div>
  );
}

function RiskBadge({ tier, risk }: { tier: TierKey; risk: number }) {
  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium tabular-nums ${TONE_CLASS[TIER_TONE[tier]]}`}>
      <Activity className="h-3.5 w-3.5" aria-hidden="true" />
      {TIER_LABEL[tier]} · {risk.toFixed(2)}
    </span>
  );
}

export default function RiskAssessment({
  tiers,
  total,
  averageRisk,
  watchlist,
  drivers,
  generatedAt,
}: RiskAssessmentProps = {}) {
  const hasCensus = typeof total === 'number' && total > 0;
  const hasWatchlist = Array.isArray(watchlist) && watchlist.length > 0;
  const hasDrivers = Array.isArray(drivers) && drivers.length > 0;
  const generatedLabel = generatedAt
    ? new Date(generatedAt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    : null;

  return (
    <AuthenticatedLayout
      header={
        <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark leading-tight">
          Risk Assessment
        </h2>
      }
    >
      <Head title="Risk Assessment" />

      <div className="p-4 flex flex-col gap-4">
        <Card className="p-4">
          <div className="flex items-start gap-3">
            <ShieldAlert className="h-5 w-5 mt-0.5 text-healthcare-primary dark:text-healthcare-primary-dark" />
            <div className="flex-1">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h1 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Discharge &amp; Readmission Risk
                </h1>
                {hasCensus && (
                  <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">
                    {total} active patients scored
                    {typeof averageRisk === 'number' ? ` · mean risk ${averageRisk.toFixed(2)}` : ''}
                    {generatedLabel ? ` · as of ${generatedLabel}` : ''}
                  </span>
                )}
              </div>
              <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Stratifies the active census by a transparent composite of length-of-stay overage,
                acuity, and expected-discharge pressure so the huddle can prioritise discharge
                planning and post-acute follow-up. Decision support only — never an automated
                discharge action.
              </p>
            </div>
          </div>
        </Card>

        {/* Risk-tier tallies */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {RISK_TIERS.map((tier) => {
            const count = tiers?.[tier.key];
            const share = hasCensus && typeof count === 'number' && total ? Math.round((count / total) * 100) : null;
            return (
              <Card key={tier.key} className="p-4 shadow-sm">
                <div className="flex items-center gap-2">
                  <Activity className={`h-4 w-4 ${TONE_CLASS[tier.tone]}`} aria-hidden="true" />
                  <span className={`text-sm font-semibold ${TONE_CLASS[tier.tone]}`}>{tier.label}</span>
                </div>
                <div className="mt-2 flex items-baseline gap-2">
                  <span className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {typeof count === 'number' ? count : '—'}
                  </span>
                  {share !== null && (
                    <span className="text-sm font-medium tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {share}%
                    </span>
                  )}
                </div>
                <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {tier.blurb}
                </p>
              </Card>
            );
          })}
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* High-risk watchlist */}
          <Card className="p-4 shadow-sm">
            <div className="flex items-center gap-2 mb-2">
              <Users className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
              <h3 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                High-risk watchlist
              </h3>
            </div>
            {hasWatchlist ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-b border-healthcare-border dark:border-healthcare-border-dark">
                      <th className="py-2 pr-3 font-medium">Patient</th>
                      <th className="py-2 pr-3 font-medium">Unit</th>
                      <th className="py-2 pr-3 font-medium text-right">LOS / GMLOS</th>
                      <th className="py-2 pl-3 font-medium text-right">Risk</th>
                    </tr>
                  </thead>
                  <tbody>
                    {watchlist!.map((row) => (
                      <tr
                        key={row.id}
                        className="border-b border-healthcare-border dark:border-healthcare-border-dark last:border-0"
                      >
                        <td className="py-2 pr-3 align-top">
                          <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {row.patient}
                          </div>
                          {row.signals.length > 0 && (
                            <div className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              {row.signals.join(' · ')}
                            </div>
                          )}
                        </td>
                        <td className="py-2 pr-3 align-top text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.unit}</div>
                          <div className="text-xs">{row.unitType}</div>
                        </td>
                        <td className="py-2 pr-3 align-top text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {row.losDays.toFixed(1)} / {row.gmlosDays.toFixed(1)}d
                        </td>
                        <td className="py-2 pl-3 align-top text-right">
                          <RiskBadge tier={row.tier} risk={row.risk} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <EmptyState icon={<Users className="h-6 w-6" />}>
                Patient-level risk scores will appear here once the active census is populated.
              </EmptyState>
            )}
          </Card>

          {/* Top risk drivers */}
          <Card className="p-4 shadow-sm">
            <div className="flex items-center gap-2 mb-2">
              <TrendingUp className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
              <h3 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Top risk drivers
              </h3>
            </div>
            {hasDrivers ? (
              <ul className="flex flex-col gap-3">
                {drivers!.map((driver) => (
                  <li key={driver.key}>
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {driver.label}
                      </span>
                      <span className="text-sm font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {driver.count}
                        <span className="ml-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          ({driver.prevalence}%)
                        </span>
                      </span>
                    </div>
                    <div className="mt-1 h-1.5 w-full rounded-full bg-healthcare-border dark:bg-healthcare-border-dark">
                      <div
                        className="h-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark"
                        style={{ width: `${Math.min(100, driver.prevalence)}%` }}
                      />
                    </div>
                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {driver.detail} · avg contribution {driver.avgContribution.toFixed(2)}
                    </p>
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState icon={<TrendingUp className="h-6 w-6" />}>
                Contributing factors (LOS overage, acuity, discharge pressure) will be summarised here.
              </EmptyState>
            )}
          </Card>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
