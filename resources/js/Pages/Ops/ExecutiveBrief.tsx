import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { MetricTile, normalizeTone, Panel, ToneBadge } from './components';
import { useRunAgent } from '@/features/ops/hooks';
import type { AgentRun } from '@/features/ops/types';
import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { BadgeCheck, FileText, GitBranch, Layers, RefreshCcw, ShieldCheck, TrendingUp } from 'lucide-react';

export default function ExecutiveBrief() {
  const run = useRunAgent();

  useEffect(() => {
    run.mutate('executive_briefing_agent');
    // Run once on mount; refresh is manual.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const data: AgentRun | undefined = run.data;
  const output = data?.output;

  return (
    <DashboardLayout>
      <Head title="Executive Brief" />
      <PageContentLayout
        title="Executive Operations Brief"
        subtitle="Governed, read-only synthesis of situation, plan, measured impact, and source lineage — composed by the Executive Briefing Agent"
        headerContent={
          <button
            type="button"
            onClick={() => run.mutate('executive_briefing_agent')}
            disabled={run.isPending}
            className="inline-flex items-center gap-1.5 rounded-md bg-healthcare-primary px-3 py-1.5 text-sm/[18px] font-semibold text-white hover:opacity-90 disabled:opacity-60"
          >
            <RefreshCcw className={`size-4 ${run.isPending ? 'animate-spin' : ''}`} /> {run.isPending ? 'Composing...' : 'Refresh brief'}
          </button>
        }
      >
        {run.isPending && !data ? (
          <div className="rounded-md border border-healthcare-border p-6 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            Composing executive brief...
          </div>
        ) : !output ? (
          <div className="rounded-md border border-healthcare-border p-6 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            No brief available.
          </div>
        ) : data?.status === 'blocked' ? (
          <div className="rounded-md border border-healthcare-critical/20 bg-healthcare-critical/10 p-4 text-sm/[18px] text-healthcare-critical dark:border-healthcare-critical-dark/40 dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark">
            {output.blockedReason ?? 'The briefing request was blocked by agent guardrails.'}
          </div>
        ) : (
          <div className="space-y-4">
            <div className={`rounded-md border p-4 ${normalizeTone(output.status) === 'critical' ? 'border-healthcare-critical/20 bg-healthcare-critical/10 dark:border-healthcare-critical-dark/40 dark:bg-healthcare-critical-dark/20' : normalizeTone(output.status) === 'warning' ? 'border-healthcare-warning/20 bg-healthcare-warning/10 dark:border-healthcare-warning-dark/40 dark:bg-healthcare-warning-dark/20' : 'border-healthcare-success/20 bg-healthcare-success/10 dark:border-healthcare-success-dark/40 dark:bg-healthcare-success-dark/20'}`}>
              <div className="flex items-center gap-2">
                <FileText className="size-5 text-healthcare-primary" />
                <ToneBadge tone={normalizeTone(output.status)}>{output.status ?? 'unknown'}</ToneBadge>
              </div>
              <p className="mt-2 text-base/[21px] font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {output.headline}
              </p>
            </div>

            {output.situation && output.situation.length > 0 && (
              <Panel title="Situation — root causes" icon={<Layers className="size-5 text-healthcare-primary" />}>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  {output.situation.map((item) => (
                    <div key={item.domain} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                      <div className="flex items-center justify-between">
                        <span className="text-sm/[18px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.domain}</span>
                        <ToneBadge tone={normalizeTone(item.status)}>{item.status}</ToneBadge>
                      </div>
                      <p className="mt-1 text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.detail}</p>
                    </div>
                  ))}
                </div>
              </Panel>
            )}

            {output.recommendedPlan && (
              <Panel title="Recommended plan — governed" icon={<GitBranch className="size-5 text-healthcare-primary" />}>
                <div className="grid grid-cols-3 gap-3">
                  <MetricTile label="Pending approvals" value={output.recommendedPlan.pendingApprovals} tone={output.recommendedPlan.pendingApprovals > 0 ? 'warning' : 'neutral'} />
                  <MetricTile label="Draft actions" value={output.recommendedPlan.draftActions} />
                  <MetricTile label="Open recommendations" value={output.recommendedPlan.openRecommendations} />
                </div>
                {output.recommendedPlan.topRecommendations.length > 0 && (
                  <ul className="space-y-2">
                    {output.recommendedPlan.topRecommendations.map((rec) => (
                      <li key={`${rec.type}-${rec.title}`} className="flex items-center justify-between gap-2 rounded-md border border-healthcare-border p-2.5 dark:border-healthcare-border-dark">
                        <span className="text-sm/[18px] text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{rec.title}</span>
                        <ToneBadge tone={normalizeTone(rec.riskLevel)}>{rec.riskLevel}</ToneBadge>
                      </li>
                    ))}
                  </ul>
                )}
              </Panel>
            )}

            {output.measuredImpact && (
              <Panel title="Measured impact" icon={<TrendingUp className="size-5 text-healthcare-primary" />}>
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                  <MetricTile label="Interventions" value={output.measuredImpact.totalInterventions} />
                  <MetricTile label="Net bed gain" value={output.measuredImpact.estimatedNetBedGain} tone={output.measuredImpact.estimatedNetBedGain > 0 ? 'success' : 'neutral'} />
                  <MetricTile label="Primary improved" value={`${output.measuredImpact.primaryOutcomesImproved}/${output.measuredImpact.primaryOutcomeCount}`} />
                  <MetricTile label="Confidence" value={output.measuredImpact.confidenceLevel} />
                </div>
                <p className="text-xs/[16px] italic text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {output.measuredImpact.confidenceLanguage}
                </p>
              </Panel>
            )}

            {output.sourceLineage && output.sourceLineage.length > 0 && (
              <Panel title="Source lineage & trust" icon={<ShieldCheck className="size-5 text-healthcare-primary" />}>
                <ul className="space-y-2">
                  {output.sourceLineage.map((item) => (
                    <li key={item.domain} className="flex items-start justify-between gap-3 rounded-md border border-healthcare-border p-2.5 dark:border-healthcare-border-dark">
                      <div>
                        <span className="text-sm/[18px] font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.domain}</span>
                        {item.detail && <p className="text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.detail}</p>}
                      </div>
                      <ToneBadge tone={normalizeTone(item.status)}>{item.status}</ToneBadge>
                    </li>
                  ))}
                </ul>
              </Panel>
            )}

            {data && (
              <Panel title="Governance trace" icon={<BadgeCheck className="size-5 text-healthcare-primary" />}>
                <div className="flex flex-wrap gap-2">
                  {data.toolCalls.map((call) => (
                    <span key={call.agentToolCallId} className="rounded-md bg-healthcare-background px-2 py-1 text-xs/[15px] font-medium text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
                      {call.toolKey} · {call.status} {call.readOnly ? '· read-only' : ''}
                    </span>
                  ))}
                </div>
                <div className="flex flex-wrap gap-2">
                  {data.evaluations.map((evaluation) => (
                    <ToneBadge key={evaluation.evaluationKey} tone={evaluation.status === 'pass' ? 'success' : 'critical'}>
                      {evaluation.evaluationKey}: {evaluation.status}
                    </ToneBadge>
                  ))}
                </div>
              </Panel>
            )}

            {output.confidenceStatement && (
              <p className="text-xs/[16px] italic text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {output.confidenceStatement}
              </p>
            )}
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
