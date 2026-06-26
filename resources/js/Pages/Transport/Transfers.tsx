import WorklistPage from './WorklistPage';
import { MetricTile } from './components';
import {
  useCreateRegionalTransferAgentDraft,
  useCreateRegionalTransferDecision,
  useRegionalTransferSummary,
  useRunRegionalRouteSimulation,
} from '@/features/transport/hooks';
import type {
  RegionalComparisonRow,
  RegionalRouteScenario,
  RegionalTransferAgentDraftRecommendation,
  RegionalTransferCandidate,
} from '@/features/transport/types';
import { Bot, Building2, CheckCircle2, Clock3, GitBranch, Layers, PlayCircle, RefreshCcw, Route, ShieldAlert } from 'lucide-react';

export default function Transfers() {
  return (
    <WorklistPage
      title="Interfacility Transfers"
      subtitle="Track transfer acceptance, bed dependency, transport mode, vendor assignment, and receiving handoff"
      current="/transport/transfers"
      requestType="transfer"
    >
      <RegionalTransferPanel />
    </WorklistPage>
  );
}

function RegionalTransferPanel() {
  const summary = useRegionalTransferSummary();
  const decision = useCreateRegionalTransferDecision();
  const simulation = useRunRegionalRouteSimulation();
  const agentDraft = useCreateRegionalTransferAgentDraft();
  const data = summary.data;

  function decide(transportRequestId: number, candidate: RegionalTransferCandidate, decisionStatus: 'accepted' | 'deferred') {
    decision.mutate({
      transportRequestId,
      input: {
        selected_facility_code: candidate.facilityCode,
        decision_status: decisionStatus,
        note: `${decisionStatus} from regional transfer panel with score ${candidate.score}`,
      },
    });
  }

  function recordSimulation() {
    simulation.mutate({ model_version_key: data?.routeSimulation.modelVersionKey ?? 'phase8-network-v1' });
  }

  function draftWithAgent(transportRequestId: number) {
    agentDraft.mutate(transportRequestId);
  }

  return (
    <section className="space-y-4 rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <GitBranch className="size-5 text-healthcare-primary" />
            <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Regional Transfer Optimization
            </h2>
          </div>
          <p className="mt-1 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Candidate destinations are scored with capability, capacity, transport, and opportunity-cost evidence.
          </p>
        </div>
        <button
          type="button"
          onClick={() => summary.refetch()}
          className="inline-flex items-center gap-2 rounded-md border border-healthcare-border px-3 py-2 text-[13px]/[18px] font-semibold text-healthcare-text-primary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
        >
          <RefreshCcw className="size-4" />
          Refresh
        </button>
      </div>

      {summary.isError ? (
        <div className="rounded-md border border-red-200 bg-red-50 p-3 text-[13px]/[18px] text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
          Regional transfer recommendations are unavailable.
        </div>
      ) : null}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-8">
        <MetricTile label="Facilities" value={data?.counts.networkFacilities ?? 0} />
        <MetricTile label="Internal" value={data?.counts.internalFacilities ?? 0} />
        <MetricTile label="External" value={data?.counts.externalFacilities ?? 0} />
        <MetricTile label="Open Beds" value={data?.counts.availableBeds ?? 0} />
        <MetricTile label="Open ICU" value={data?.counts.icuAvailableBeds ?? 0} />
        <MetricTile label="Transfers" value={data?.counts.activeTransfers ?? 0} />
        <MetricTile label="Decisions" value={data?.counts.pendingDecisions ?? 0} tone={(data?.counts.pendingDecisions ?? 0) > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="Models" value={data?.counts.modelVersions ?? 0} tone="good" />
      </div>

      {data ? (
        <div className="grid gap-4 2xl:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
          <RegionalComparisonDashboard rows={data.comparison} />
          <RegionalRouteSimulationPanel
            scenarios={data.routeSimulation.scenarios}
            modelVersionKey={data.routeSimulation.modelVersionKey}
            onRecord={recordSimulation}
            isRecording={simulation.isPending}
          />
        </div>
      ) : null}

      {data ? (
        <TransferCenterAgentPanel
          drafts={data.transferCenterAgent.draftRecommendations}
          onDraft={draftWithAgent}
          isDrafting={agentDraft.isPending}
        />
      ) : null}

      {(data?.recommendations ?? []).length === 0 ? (
        <div className="rounded-md border border-dashed border-healthcare-border p-4 text-[13px]/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          No active transfer requests need regional scoring.
        </div>
      ) : (
        <div className="space-y-3">
          {data?.recommendations.map((recommendation) => (
            <div key={recommendation.transportRequestId} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="text-[14px]/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {recommendation.patientRef}
                  </div>
                  <div className="mt-1 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {recommendation.origin} to {recommendation.destination}
                  </div>
                </div>
                <span className="rounded bg-amber-100 px-2 py-0.5 text-[12px]/[16px] font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                  {recommendation.priority.toUpperCase()}
                </span>
              </div>
              <div className="mt-3 grid gap-3 xl:grid-cols-3">
                {recommendation.candidates.slice(0, 3).map((candidate) => (
                  <div key={candidate.facilityCode} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex items-center gap-2 font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          <Building2 className="size-4 shrink-0 text-healthcare-primary" />
                          <span className="truncate">{candidate.facilityName}</span>
                        </div>
                        <div className="mt-1 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {candidate.facilityType.replaceAll('_', ' ')}
                        </div>
                      </div>
                      <span className={`rounded px-2 py-0.5 text-[12px]/[16px] font-semibold ${
                        candidate.recommendation === 'accept'
                          ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                          : candidate.recommendation === 'conditional'
                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                            : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'
                      }`}>
                        {candidate.score}
                      </span>
                    </div>
                    <div className="mt-3 grid grid-cols-3 gap-2 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span>{candidate.availableBeds} beds</span>
                      <span>{candidate.icuAvailableBeds} ICU</span>
                      <span>{candidate.transportMinutes} min</span>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-1">
                      {candidate.rationale.required_capabilities.map((capability) => (
                        <span key={capability} className={`rounded px-2 py-0.5 text-[12px]/[16px] ${
                          candidate.constraints.missing_capabilities.includes(capability)
                            ? 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-200'
                            : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                        }`}>
                          {capability.replaceAll('_', ' ')}
                        </span>
                      ))}
                    </div>
                    <div className="mt-3 flex items-center gap-2 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <Clock3 className="size-4" />
                      <span>After acceptance: {candidate.opportunityCost.available_beds_after_acceptance} beds, {candidate.opportunityCost.icu_beds_after_acceptance} ICU</span>
                    </div>
                    {candidate.constraints.missing_capabilities.length > 0 ? (
                      <div className="mt-2 flex items-center gap-2 text-[12px]/[16px] text-red-700 dark:text-red-300">
                        <ShieldAlert className="size-4" />
                        <span>Missing {candidate.constraints.missing_capabilities.join(', ')}</span>
                      </div>
                    ) : null}
                    <div className="mt-3 flex flex-wrap gap-2">
                      <button
                        type="button"
                        disabled={decision.isPending}
                        onClick={() => decide(recommendation.transportRequestId, candidate, 'accepted')}
                        className="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-[12px]/[16px] font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                      >
                        <CheckCircle2 className="size-4" />
                        Accept
                      </button>
                      <button
                        type="button"
                        disabled={decision.isPending}
                        onClick={() => decide(recommendation.transportRequestId, candidate, 'deferred')}
                        className="rounded-md border border-healthcare-border px-3 py-1.5 text-[12px]/[16px] font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                      >
                        Defer
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function RegionalComparisonDashboard({ rows }: { rows: RegionalComparisonRow[] }) {
  return (
    <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Layers className="size-4 text-healthcare-primary" />
          <h3 className="text-[14px]/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Regional Comparison
          </h3>
        </div>
        <span className="text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {rows.length} scopes
        </span>
      </div>
      <div className="mt-3 overflow-x-auto">
        <table className="min-w-[720px] w-full text-left text-[12px]/[16px]">
          <thead className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
              <th className="py-2 pr-3 font-semibold">Scope</th>
              <th className="py-2 pr-3 font-semibold">Beds</th>
              <th className="py-2 pr-3 font-semibold">ICU</th>
              <th className="py-2 pr-3 font-semibold">Boarders</th>
              <th className="py-2 pr-3 font-semibold">Transit</th>
              <th className="py-2 pr-3 font-semibold">Top</th>
              <th className="py-2 pr-3 font-semibold">Pressure</th>
            </tr>
          </thead>
          <tbody>
            {rows.slice(0, 6).map((row) => (
              <tr key={row.scopeKey} className="border-b border-healthcare-border/70 last:border-0 dark:border-healthcare-border-dark/70">
                <td className="py-2 pr-3">
                  <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {row.scopeLabel}
                  </div>
                  <div className="mt-0.5 flex flex-wrap gap-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <span>{row.campusKey ?? 'regional'}</span>
                    <span>{row.buildingKey ?? 'facility'}</span>
                    {row.isExternal ? <span className="font-semibold text-amber-700 dark:text-amber-300">external</span> : null}
                  </div>
                </td>
                <td className="py-2 pr-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.availableBeds}/{row.staffedBeds}</td>
                <td className="py-2 pr-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.icuAvailableBeds}</td>
                <td className="py-2 pr-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.edBoarders}</td>
                <td className="py-2 pr-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.transportMinutes}m</td>
                <td className="py-2 pr-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.topChoiceCount}</td>
                <td className="py-2 pr-3">
                  <span className={`rounded px-2 py-0.5 font-semibold ${
                    row.status === 'open'
                      ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                      : row.status === 'constrained'
                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                        : 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-200'
                  }`}>
                    {row.pressureScore}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function RegionalRouteSimulationPanel({
  scenarios,
  modelVersionKey,
  onRecord,
  isRecording,
}: {
  scenarios: RegionalRouteScenario[];
  modelVersionKey: string;
  onRecord: () => void;
  isRecording: boolean;
}) {
  return (
    <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Route className="size-4 text-healthcare-primary" />
          <h3 className="text-[14px]/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Route Simulation
          </h3>
        </div>
        <button
          type="button"
          onClick={onRecord}
          disabled={isRecording}
          className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-[12px]/[16px] font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
        >
          <PlayCircle className="size-4" />
          Record
        </button>
      </div>
      <div className="mt-1 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {modelVersionKey}
      </div>
      <div className="mt-3 grid gap-2 md:grid-cols-2">
        {scenarios.map((scenario) => (
          <ScenarioCard key={scenario.scenarioKey} scenario={scenario} />
        ))}
      </div>
    </div>
  );
}

function ScenarioCard({ scenario }: { scenario: RegionalRouteScenario }) {
  const tone = scenario.routeRiskScore >= 70
    ? 'border-red-200 bg-red-50 dark:border-red-900/60 dark:bg-red-950/20'
    : scenario.routeRiskScore >= 45
      ? 'border-amber-200 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/20'
      : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/20';

  return (
    <div className={`rounded-md border p-3 ${tone}`}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="text-[13px]/[18px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {scenario.label}
          </div>
          <div className="mt-0.5 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {scenario.modelVersionKey}
          </div>
        </div>
        <span className="rounded bg-white/70 px-2 py-0.5 text-[12px]/[16px] font-semibold text-healthcare-text-primary dark:bg-black/20 dark:text-healthcare-text-primary-dark">
          Risk {scenario.routeRiskScore}
        </span>
      </div>
      <div className="mt-3 grid grid-cols-4 gap-2 text-[12px]/[16px] text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        <span>{scenario.acceptedTransfers} accept</span>
        <span>{scenario.deferredTransfers} defer</span>
        <span>{scenario.netAvailableBeds} beds</span>
        <span>{scenario.totalTransportMinutes}m</span>
      </div>
    </div>
  );
}

function TransferCenterAgentPanel({
  drafts,
  onDraft,
  isDrafting,
}: {
  drafts: RegionalTransferAgentDraftRecommendation[];
  onDraft: (transportRequestId: number) => void;
  isDrafting: boolean;
}) {
  return (
    <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
      <div className="flex items-center gap-2">
        <Bot className="size-4 text-healthcare-primary" />
        <h3 className="text-[14px]/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Transfer Center Agent
        </h3>
      </div>
      {drafts.length === 0 ? (
        <div className="mt-3 rounded-md border border-dashed border-healthcare-border p-3 text-[13px]/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          No transfer-center drafts are pending.
        </div>
      ) : (
        <div className="mt-3 grid gap-2 lg:grid-cols-2">
          {drafts.map((draft) => (
            <div key={draft.transportRequestId} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="text-[13px]/[18px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {draft.patientRef}
                  </div>
                  <div className="mt-0.5 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {draft.selectedFacilityName ?? 'No candidate'} · confidence {Math.round(draft.confidence * 100)}%
                  </div>
                </div>
                <span className={`rounded px-2 py-0.5 text-[12px]/[16px] font-semibold ${
                  draft.recommendedDecision === 'accepted'
                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                    : draft.recommendedDecision === 'redirected'
                      ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                      : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'
                }`}>
                  {draft.recommendedDecision}
                </span>
              </div>
              <button
                type="button"
                disabled={isDrafting}
                onClick={() => onDraft(draft.transportRequestId)}
                className="mt-3 inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-[12px]/[16px] font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
              >
                <Bot className="size-4" />
                Draft
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
