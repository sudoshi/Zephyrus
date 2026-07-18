import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import HomePageLayout from '@/Components/Home/HomePageLayout';
import { Section } from '@/Components/system';

// Eligibility & Referral Funnel (ACUM-PRD-HAH-001 §4.2). The worklists are
// the decant valve made operational: home-eligible ED patients (boarders
// first) and step-down inpatients, each one click from the funnel. Declines
// always carry a coded reason — the §11 selection-bias guardrail.

interface FunnelReferral {
  referralUuid: string;
  patientRef: string;
  program: string | null;
  source: string;
  status: string;
  payerClass: string | null;
  serviceZone: string | null;
  declineReason: string | null;
  referredAt: string | null;
}

interface EdCandidate {
  patientRef: string;
  esiLevel: number;
  isBoarding: boolean;
  losMinutes: number;
  source: string;
}

interface StepDownCandidate {
  patientRef: string;
  unit: string | null;
  acuityTier: number | null;
  expectedDischargeDate: string | null;
  encounterId: number;
  source: string;
}

interface ReferralsProps {
  funnel: Record<string, FunnelReferral[]>;
  counts: Record<string, number>;
  edCandidates: EdCandidate[];
  stepDownCandidates: StepDownCandidate[];
  freeSlots: number;
}

const STAGES = ['referred', 'screened', 'eligible', 'consented', 'activated', 'declined'] as const;

const STAGE_LABELS: Record<(typeof STAGES)[number], string> = {
  referred: 'Referred',
  screened: 'Screened',
  eligible: 'Eligible',
  consented: 'Consented',
  activated: 'Activated',
  declined: 'Declined',
};

const DECLINE_REASONS = [
  'patient_preference',
  'out_of_service_zone',
  'clinical_ineligibility',
  'payer_not_covered',
  'home_safety_failed',
  'connectivity_failed',
] as const;

const surface =
  'rounded-lg border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm';

function reload() {
  router.reload();
}

function ReferralCard({ referral }: { referral: FunnelReferral }) {
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState<string>(DECLINE_REASONS[0]);
  const [busy, setBusy] = useState(false);
  const terminal = referral.status === 'activated' || referral.status === 'declined';

  const advance = async () => {
    setBusy(true);
    try {
      await axios.post(`/api/home/referrals/${referral.referralUuid}/advance`);
      reload();
    } finally {
      setBusy(false);
    }
  };

  const decline = async () => {
    setBusy(true);
    try {
      await axios.post(`/api/home/referrals/${referral.referralUuid}/decline`, { reason });
      reload();
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className={`${surface} p-2.5 flex flex-col gap-1.5`}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {referral.patientRef}
        </span>
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {referral.source === 'ed_diversion' ? 'ED' : referral.source === 'inpatient_stepdown' ? 'Step-down' : referral.source}
        </span>
      </div>
      <div className="flex items-center justify-between gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span>{referral.payerClass ?? '—'}</span>
        <span>{referral.serviceZone ?? '—'}</span>
      </div>
      {referral.declineReason && (
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Declined: {referral.declineReason.replaceAll('_', ' ')}
        </p>
      )}
      {!terminal && !declining && (
        <div className="flex items-center gap-2 pt-0.5">
          <button
            type="button"
            disabled={busy}
            onClick={advance}
            className="rounded bg-healthcare-primary px-2 py-1 text-xs font-medium text-white hover:opacity-90 disabled:opacity-60"
          >
            {referral.status === 'consented' ? 'Activate' : 'Advance'}
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => setDeclining(true)}
            className="rounded border border-healthcare-border dark:border-healthcare-border-dark px-2 py-1 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:opacity-80"
          >
            Decline
          </button>
        </div>
      )}
      {!terminal && declining && (
        <div className="flex items-center gap-2 pt-0.5">
          <select
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="rounded border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark px-1.5 py-1 text-xs text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
          >
            {DECLINE_REASONS.map((r) => (
              <option key={r} value={r}>
                {r.replaceAll('_', ' ')}
              </option>
            ))}
          </select>
          <button
            type="button"
            disabled={busy}
            onClick={decline}
            className="rounded bg-healthcare-primary px-2 py-1 text-xs font-medium text-white hover:opacity-90 disabled:opacity-60"
          >
            Confirm
          </button>
        </div>
      )}
    </div>
  );
}

export default function Referrals({ funnel, counts, edCandidates, stepDownCandidates, freeSlots }: ReferralsProps) {
  const [busyRef, setBusyRef] = useState<string | null>(null);

  const refer = async (candidate: { patientRef: string; source: string; encounterId?: number }) => {
    setBusyRef(candidate.patientRef);
    try {
      await axios.post('/api/home/referrals', {
        patient_ref: candidate.patientRef,
        source: candidate.source,
        encounter_id: candidate.encounterId ?? null,
      });
      reload();
    } finally {
      setBusyRef(null);
    }
  };

  return (
    <HomePageLayout
      title="Referrals & Eligibility"
      subtitle={`${freeSlots} free home slot${freeSlots === 1 ? '' : 's'} tonight · ${counts['activated'] ?? 0} activated`}
    >
      <div className="flex flex-col gap-5">
        <Section
          title="Eligibility worklists"
          icon="heroicons:magnifying-glass"
          summary={`${edCandidates.length} ED candidates · ${stepDownCandidates.length} step-down candidates — screened over the live census`}
        >
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <div className={`${surface} p-3`}>
              <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                ED candidates
              </h3>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Stable (ESI 3–5), boarders first — each activation is boarding relieved.
              </p>
              <div className="mt-2 flex flex-col divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {edCandidates.length === 0 && (
                  <p className="py-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    No home-eligible ED patients right now.
                  </p>
                )}
                {edCandidates.map((c) => (
                  <div key={c.patientRef} className="flex items-center justify-between gap-2 py-1.5">
                    <span className="text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {c.patientRef}
                    </span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      ESI {c.esiLevel}
                      {c.isBoarding && ' · ▲ boarding'}
                      {` · ${Math.floor(c.losMinutes / 60)}h in ED`}
                    </span>
                    <button
                      type="button"
                      disabled={busyRef === c.patientRef}
                      onClick={() => refer(c)}
                      className="rounded bg-healthcare-primary px-2 py-1 text-xs font-medium text-white hover:opacity-90 disabled:opacity-60"
                    >
                      Refer
                    </button>
                  </div>
                ))}
              </div>
            </div>
            <div className={`${surface} p-3`}>
              <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Inpatient step-down candidates
              </h3>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                At or near expected LOS — decanting frees a physical bed early.
              </p>
              <div className="mt-2 flex flex-col divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {stepDownCandidates.length === 0 && (
                  <p className="py-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    No step-down candidates inside the 48-hour window.
                  </p>
                )}
                {stepDownCandidates.map((c) => (
                  <div key={c.patientRef} className="flex items-center justify-between gap-2 py-1.5">
                    <span className="text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {c.patientRef}
                    </span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {c.unit ?? '—'} · EDD {c.expectedDischargeDate ?? '—'}
                    </span>
                    <button
                      type="button"
                      disabled={busyRef === c.patientRef}
                      onClick={() => refer(c)}
                      className="rounded bg-healthcare-primary px-2 py-1 text-xs font-medium text-white hover:opacity-90 disabled:opacity-60"
                    >
                      Refer
                    </button>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </Section>

        <Section
          title="Referral funnel"
          icon="heroicons:funnel"
          summary="referred → screened → eligible → consented → activated, declines always coded"
        >
          <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            {STAGES.map((stage) => (
              <div key={stage} className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {STAGE_LABELS[stage]}
                  </span>
                  <span className="text-xs font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {counts[stage] ?? 0}
                  </span>
                </div>
                {(funnel[stage] ?? []).map((referral) => (
                  <ReferralCard key={referral.referralUuid} referral={referral} />
                ))}
              </div>
            ))}
          </div>
        </Section>
      </div>
    </HomePageLayout>
  );
}
