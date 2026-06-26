import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Dashboard/Card';
import { ShieldAlert, TrendingUp, Activity, Users } from 'lucide-react';

/**
 * RTDC › Predictions › Risk Assessment
 *
 * Detailed stub for the discharge / readmission risk workspace (route
 * `rtdc.predictions.risk` → `/rtdc/predictions/risk`). Renders the intended
 * information architecture as empty states so the route is navigable and
 * on-brand while the data layer is wired. Status is always paired with an
 * icon + label (never color alone), per the design non-negotiables.
 */

interface RiskTier {
  readonly label: string;
  readonly tone: 'critical' | 'warning' | 'success';
  readonly blurb: string;
}

const RISK_TIERS: readonly RiskTier[] = [
  { label: 'High readmission risk', tone: 'critical', blurb: 'Predicted 30-day readmission probability ≥ 0.40' },
  { label: 'Moderate risk', tone: 'warning', blurb: 'Probability 0.20–0.39 — monitor discharge readiness' },
  { label: 'Low risk', tone: 'success', blurb: 'Probability < 0.20 — standard discharge pathway' },
];

const TONE_CLASS: Record<RiskTier['tone'], string> = {
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  success: 'text-healthcare-success dark:text-healthcare-success-dark',
};

function EmptyState({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-10 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{icon}</div>
      <p className="text-sm">{children}</p>
    </div>
  );
}

export default function RiskAssessment() {
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
            <div>
              <h1 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Discharge &amp; Readmission Risk
              </h1>
              <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Stratifies the active census by predicted 30-day readmission risk so the huddle can
                prioritise discharge planning and post-acute follow-up. Decision support only — never
                an automated discharge action.
              </p>
            </div>
          </div>
        </Card>

        {/* Risk-tier legend */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {RISK_TIERS.map((tier) => (
            <Card key={tier.label} className="p-4">
              <div className="flex items-center gap-2">
                <Activity className={`h-4 w-4 ${TONE_CLASS[tier.tone]}`} />
                <span className={`text-sm font-semibold ${TONE_CLASS[tier.tone]}`}>{tier.label}</span>
              </div>
              <div className="mt-2 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                —
              </div>
              <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {tier.blurb}
              </p>
            </Card>
          ))}
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Card className="p-4">
            <div className="flex items-center gap-2 mb-2">
              <Users className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
              <h3 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                High-risk watchlist
              </h3>
            </div>
            <EmptyState icon={<Users className="h-6 w-6" />}>
              Patient-level risk scores will appear here once the readmission model is connected.
            </EmptyState>
          </Card>

          <Card className="p-4">
            <div className="flex items-center gap-2 mb-2">
              <TrendingUp className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
              <h3 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Top risk drivers
              </h3>
            </div>
            <EmptyState icon={<TrendingUp className="h-6 w-6" />}>
              Contributing factors (LACE+, prior utilisation, social determinants) will be summarised here.
            </EmptyState>
          </Card>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
