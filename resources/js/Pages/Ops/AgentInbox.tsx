import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { MetricTile, normalizeTone, Panel, ToneBadge } from './components';
import { useAgentDefinitions, useAgentInbox, useRunAgent } from '@/features/ops/hooks';
import type { AgentDefinition } from '@/features/ops/types';
import { Head, Link } from '@inertiajs/react';
import { Bot, CheckCircle2, ClipboardList, Inbox, Play, ShieldCheck } from 'lucide-react';

const RUNNABLE = new Set(['capacity_commander', 'data_quality_agent', 'executive_briefing_agent']);

function AgentCard({ definition }: { definition: AgentDefinition }) {
  const run = useRunAgent();
  const result = run.data;
  const showResult = result && result.agentKey === definition.key;

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <Bot className="size-4 text-healthcare-primary" />
            <span className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{definition.label}</span>
            {definition.readOnly && <ToneBadge tone="info">read-only</ToneBadge>}
          </div>
          <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{definition.description}</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {definition.toolAllowlist.map((tool) => (
              <span key={tool} className="rounded-md bg-healthcare-background px-1.5 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
                {tool}
              </span>
            ))}
          </div>
        </div>
        {RUNNABLE.has(definition.key) && (
          <button
            type="button"
            onClick={() => run.mutate(definition.key)}
            disabled={run.isPending}
            aria-label={`${run.isPending ? 'Running' : 'Run'} ${definition.label} agent`}
            className="inline-flex items-center gap-1 rounded-md bg-healthcare-primary px-2.5 py-1 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-60"
          >
            <Play className="size-3.5" /> {run.isPending ? 'Running' : 'Run'}
          </button>
        )}
      </div>

      {showResult && (
        <div className="mt-3 space-y-2 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
          <div className="flex items-center gap-2">
            <ToneBadge tone={normalizeTone(result.status === 'completed' ? (result.output.status ?? 'success') : 'critical')}>
              {result.status}
            </ToneBadge>
            {result.output.headline && (
              <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{result.output.headline}</span>
            )}
          </div>
          <div className="flex flex-wrap gap-1.5">
            {result.evaluations.map((evaluation) => (
              <ToneBadge key={evaluation.evaluationKey} tone={evaluation.status === 'pass' ? 'success' : 'critical'}>
                {evaluation.evaluationKey}: {evaluation.status}
              </ToneBadge>
            ))}
          </div>
          {result.safetyEvents.length > 0 && (
            <div className="flex flex-wrap gap-1.5">
              {result.safetyEvents.map((event, idx) => (
                <ToneBadge key={`${event.eventType}-${idx}`} tone="critical">
                  {event.eventType}: {event.status}
                </ToneBadge>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function AgentInbox() {
  const inbox = useAgentInbox();
  const definitions = useAgentDefinitions();

  const summary = inbox.data?.summary;
  // Unknown must never render as zero: while the queue is loading or failed,
  // tiles show an explicit non-number so "0 pending" always means a verified 0.
  const summaryUnknown = inbox.isPending || inbox.isError;
  const summaryValue = (value: number | undefined) => (summaryUnknown ? '—' : value ?? 0);

  return (
    <DashboardLayout>
      <Head title="Agent Inbox" />
      <PageContentLayout
        title="Agent Inbox"
        subtitle="Governed agent roster, run traces with evaluations and safety events, and the approval-gated action queue"
        headerContent={
          <Link href="/ops/executive-brief" className="inline-flex items-center gap-1.5 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-semibold text-healthcare-text-secondary hover:bg-healthcare-background dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-white/5">
            <ClipboardList className="size-4" /> Executive brief
          </Link>
        }
      >
        <div className="space-y-4">
          {inbox.isError && (
            <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 px-3 py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              <span>The approval queue could not be loaded — counts below are unavailable, not zero.</span>
              <button
                type="button"
                onClick={() => inbox.refetch()}
                className="rounded-md border border-healthcare-border px-2.5 py-1 text-xs font-semibold text-healthcare-text-primary hover:bg-healthcare-background dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
              >
                Retry
              </button>
            </div>
          )}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <MetricTile label="Pending approvals" value={summaryValue(summary?.pendingApprovals)} tone={(summary?.pendingApprovals ?? 0) > 0 ? 'warning' : 'neutral'} />
            <MetricTile label="Active actions" value={summaryValue(summary?.activeActions)} />
            <MetricTile label="Assigned" value={summaryValue(summary?.assignedActions)} />
            <MetricTile label="Overdue" value={summaryValue(summary?.overdueActions)} tone={(summary?.overdueActions ?? 0) > 0 ? 'critical' : 'neutral'} />
          </div>

          <Panel title="Governed agents" icon={<Bot className="size-5 text-healthcare-primary" />}>
            {definitions.isLoading ? (
              <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Loading agents...</div>
            ) : (
              <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                {(definitions.data ?? []).map((definition) => (
                  <AgentCard key={definition.key} definition={definition} />
                ))}
              </div>
            )}
          </Panel>

          <Panel title="Pending approvals" icon={<ShieldCheck className="size-5 text-healthcare-primary" />}>
            {summaryUnknown ? (
              <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {inbox.isPending ? 'Loading approvals...' : 'Approvals unavailable — retry above.'}
              </div>
            ) : (inbox.data?.approvals.length ?? 0) === 0 ? (
              <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                No pending approvals. Review and approve in the{' '}
                <Link href="/analytics/opportunities" className="font-medium text-healthcare-primary">Opportunity Portfolio</Link>.
              </div>
            ) : (
              <ul className="space-y-2">
                {(inbox.data?.approvals ?? []).map((approval) => (
                  <li key={approval.approvalId} className="flex items-center justify-between gap-2 rounded-md border border-healthcare-border p-2.5 dark:border-healthcare-border-dark">
                    <div>
                      <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {approval.action?.recommendation?.title ?? approval.action?.type ?? 'Action'}
                      </span>
                      {approval.action?.ownerName && (
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Owner: {approval.action.ownerName}</p>
                      )}
                    </div>
                    <ToneBadge tone={normalizeTone(approval.action?.recommendation?.riskLevel)}>
                      {approval.action?.recommendation?.riskLevel ?? 'pending'}
                    </ToneBadge>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="Active actions" icon={<Inbox className="size-5 text-healthcare-primary" />}>
            {summaryUnknown ? (
              <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {inbox.isPending ? 'Loading actions...' : 'Actions unavailable — retry above.'}
              </div>
            ) : (inbox.data?.actions.length ?? 0) === 0 ? (
              <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No active actions.</div>
            ) : (
              <ul className="space-y-2">
                {(inbox.data?.actions ?? []).map((action) => (
                  <li key={action.actionId} className="flex items-center justify-between gap-2 rounded-md border border-healthcare-border p-2.5 dark:border-healthcare-border-dark">
                    <div className="flex items-center gap-2">
                      {action.status === 'completed' && <CheckCircle2 className="size-4 text-healthcare-success dark:text-healthcare-success-dark" />}
                      <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {action.recommendation?.title ?? action.type}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      {action.isOverdue && <ToneBadge tone="critical">overdue</ToneBadge>}
                      <ToneBadge tone="neutral">{action.status}</ToneBadge>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
