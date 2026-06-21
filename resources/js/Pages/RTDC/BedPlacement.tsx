import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { RecommendationCard } from '@/Components/RTDC/RecommendationCard';
import { fetchPendingRequests, fetchRecommendations, postDecision } from '@/features/rtdc/bedPlacement';

export default function BedPlacement() {
  const qc = useQueryClient();
  const [selected, setSelected] = useState<number | null>(null);

  const { data: requests } = useQuery({ queryKey: ['rtdc', 'bed-requests'], queryFn: fetchPendingRequests });
  const { data: recs } = useQuery({
    queryKey: ['rtdc', 'recommendations', selected],
    queryFn: () => fetchRecommendations(selected as number),
    enabled: selected !== null,
  });

  const accept = useMutation({
    mutationFn: ({ id, bedId }: { id: number; bedId: number }) => postDecision(id, { action: 'accepted', chosen_bed_id: bedId }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['rtdc', 'bed-requests'] });
      qc.invalidateQueries({ queryKey: ['rtdc', 'units'] });
      setSelected(null);
    },
  });

  return (
    <RTDCPageLayout title="Bed Placement" subtitle="Prescriptive bed-assignment recommendations">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-[var(--space-6)]">
        <section>
          <h3 className="text-panel-title">Pending requests</h3>
          <ul className="flex flex-col gap-[var(--space-2)]">
            {(requests ?? []).map((r) => (
              <li key={r.bed_request_id}>
                <button
                  onClick={() => setSelected(r.bed_request_id)}
                  className={`w-full text-left rounded-[var(--radius-sm)] p-[var(--space-3)] ${selected === r.bed_request_id ? 'bg-[var(--surface-raised)]' : 'bg-[var(--surface-overlay)]'}`}
                >
                  <span className="text-[var(--text-primary)]">{r.patient_ref}</span>
                  <span className="text-caption ml-[var(--space-2)]">{r.source} · tier {r.acuity_tier} · {r.required_unit_type}{r.isolation_required !== 'none' ? ` · ${r.isolation_required}` : ''}</span>
                </button>
              </li>
            ))}
            {(requests ?? []).length === 0 && <li className="text-caption">No pending requests.</li>}
          </ul>
        </section>

        <section className="lg:col-span-2 flex flex-col gap-[var(--space-4)]">
          <h3 className="text-panel-title">Recommendations</h3>
          {selected === null && <div className="text-caption">Select a pending request to see recommendations.</div>}
          {recs && recs.recommendations.length === 0 && (
            <div className="rounded-[var(--radius-lg)] bg-[var(--critical-bg)] p-[var(--space-5)] text-[var(--critical)]">
              No safe bed available — every candidate failed a hard clinical/safety constraint. ({recs.excluded.length} excluded)
            </div>
          )}
          {recs?.recommendations.map((rec, i) => (
            <RecommendationCard
              key={rec.bed_id}
              rec={rec}
              isTop={i === 0}
              runnerUpDelta={recs.runner_up_delta}
              onAccept={(bedId) => selected !== null && accept.mutate({ id: selected, bedId })}
            />
          ))}
        </section>
      </div>
    </RTDCPageLayout>
  );
}
