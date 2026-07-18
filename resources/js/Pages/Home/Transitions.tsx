import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import HomePageLayout from '@/Components/Home/HomePageLayout';
import { Section } from '@/Components/system';

// Transitions of Care Board (ACUM-PRD-HAH-001 §4.2/§7): inbound activation
// checklists, outbound governed handoffs (care_transition transport request +
// regional decision with opportunity-cost scoring for SNF), and the 30-day
// post-discharge monitoring cohort (billable under the 2026 RPM codes).

interface Transition {
  transitionUuid: string;
  patientRef: string;
  conditionLabel: string | null;
  direction: 'inbound' | 'outbound';
  status: string;
  checklist: Record<string, string>;
  handoffOwner: string | null;
  receivingEntityType: string | null;
  barriers: string[];
}

interface CohortMember {
  episodeUuid: string;
  patientRef: string;
  conditionLabel: string | null;
  dayOfCohort: number | null;
  windowDays: number;
  endsOn: string | null;
}

interface ActiveEpisode {
  episodeUuid: string;
  patientRef: string;
  conditionLabel: string | null;
}

interface TransitionsProps {
  inbound: Transition[];
  outbound: Transition[];
  postDischargeCohort: CohortMember[];
  activeEpisodes: ActiveEpisode[];
}

const CHECKLIST_LABELS: Record<string, string> = {
  consent: 'Consent',
  home_safety_check: 'Home safety check',
  kit_delivery: 'Kit delivery',
  first_visit: 'First visit',
  discharge_readiness: 'Discharge readiness',
  med_reconciliation: 'Med reconciliation',
  handoff_report: 'Handoff report',
  equipment_return: 'Equipment return',
};

const RECEIVING_LABELS: Record<string, string> = {
  pcp: 'Primary care',
  home_health: 'Home health',
  snf: 'Skilled nursing facility',
  other: 'Other',
};

const surface =
  'rounded-lg border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm';

function TransitionCard({ transition }: { transition: Transition }) {
  const [busy, setBusy] = useState<string | null>(null);

  const complete = async (item: string) => {
    setBusy(item);
    try {
      await axios.post(`/api/home/transitions/${transition.transitionUuid}/checklist`, { item });
      router.reload();
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className={`${surface} p-3 flex flex-col gap-2`}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {transition.patientRef}
        </span>
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {transition.direction === 'outbound'
            ? (RECEIVING_LABELS[transition.receivingEntityType ?? 'other'] ?? 'Handoff')
            : (transition.conditionLabel ?? 'Activation')}
        </span>
      </div>
      <div className="flex flex-col gap-1">
        {Object.entries(transition.checklist).map(([item, state]) => (
          <div key={item} className="flex items-center justify-between gap-2">
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {state === 'complete' ? '● ' : '○ '}
              {CHECKLIST_LABELS[item] ?? item}
            </span>
            {state !== 'complete' && (
              <button
                type="button"
                disabled={busy === item}
                onClick={() => complete(item)}
                className="rounded border border-healthcare-border dark:border-healthcare-border-dark px-1.5 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:opacity-80 disabled:opacity-60"
              >
                Done
              </button>
            )}
          </div>
        ))}
      </div>
      {transition.handoffOwner && (
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Owner: {transition.handoffOwner}
        </p>
      )}
    </div>
  );
}

export default function Transitions({ inbound, outbound, postDischargeCohort, activeEpisodes }: TransitionsProps) {
  const [episodeUuid, setEpisodeUuid] = useState('');
  const [receiving, setReceiving] = useState<'pcp' | 'home_health' | 'snf' | 'other'>('home_health');
  const [busy, setBusy] = useState(false);

  const startHandoff = async () => {
    if (!episodeUuid) return;
    setBusy(true);
    try {
      await axios.post(`/api/home/episodes/${episodeUuid}/handoff`, { receiving_entity_type: receiving });
      router.reload();
    } finally {
      setBusy(false);
    }
  };

  return (
    <HomePageLayout
      title="Transitions of Care"
      subtitle={`${inbound.length} activating · ${outbound.length} handing off · ${postDischargeCohort.length} in the 30-day cohort`}
    >
      <div className="flex flex-col gap-5">
        <Section
          title="Inbound activations"
          icon="heroicons:arrow-down-tray"
          summary="Consent, home-safety, kit delivery, first visit — the activation floor"
        >
          {inbound.length > 0 ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
              {inbound.map((t) => (
                <TransitionCard key={t.transitionUuid} transition={t} />
              ))}
            </div>
          ) : (
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              No activations in progress — new activations open here automatically.
            </p>
          )}
        </Section>

        <Section
          title="Outbound handoffs"
          icon="heroicons:arrow-up-tray"
          summary="Governed handoffs — SNF destinations score candidates with opportunity cost"
        >
          <div className="flex flex-col gap-3">
            <div className={`${surface} p-3 flex flex-wrap items-center gap-2`}>
              <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Start handoff:
              </span>
              <select
                value={episodeUuid}
                onChange={(e) => setEpisodeUuid(e.target.value)}
                className="rounded border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark px-2 py-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              >
                <option value="">Select episode…</option>
                {activeEpisodes.map((e) => (
                  <option key={e.episodeUuid} value={e.episodeUuid}>
                    {e.patientRef} — {e.conditionLabel ?? '—'}
                  </option>
                ))}
              </select>
              <select
                value={receiving}
                onChange={(e) => setReceiving(e.target.value as typeof receiving)}
                className="rounded border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark px-2 py-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
              >
                {Object.entries(RECEIVING_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
              <button
                type="button"
                disabled={busy || !episodeUuid}
                onClick={startHandoff}
                className="rounded bg-healthcare-primary px-2.5 py-1 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
              >
                Open handoff
              </button>
            </div>
            {outbound.length > 0 && (
              <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                {outbound.map((t) => (
                  <TransitionCard key={t.transitionUuid} transition={t} />
                ))}
              </div>
            )}
          </div>
        </Section>

        <Section
          title="30-day post-discharge cohort"
          icon="heroicons:calendar-days"
          summary="Step-down monitoring cadence after routine discharge — readmission watch"
        >
          {postDischargeCohort.length > 0 ? (
            <div
              className={`${surface} divide-y divide-healthcare-border dark:divide-healthcare-border-dark`}
            >
              {postDischargeCohort.map((m) => (
                <div key={m.episodeUuid} className="flex items-center justify-between gap-2 px-4 py-2">
                  <span className="text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {m.patientRef}
                  </span>
                  <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {m.conditionLabel ?? '—'}
                  </span>
                  <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Day {m.dayOfCohort ?? '—'} of {m.windowDays} · ends {m.endsOn ?? '—'}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Cohort is empty — routine discharges enroll here automatically.
            </p>
          )}
        </Section>
      </div>
    </HomePageLayout>
  );
}
