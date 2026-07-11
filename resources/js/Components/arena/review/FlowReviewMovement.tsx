// resources/js/Components/arena/review/FlowReviewMovement.tsx
//
// Orchestrator for the 48-Hour Flow Review. Owns the ONE selection atom the
// whole movement shares, the Run-review mutation, and the boundary parse. Reads
// one persisted artifact (arenaReviewResponseSchema) — degrading to an in-place
// card, never a white screen — exactly like the Study.
import { useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { runArenaReview } from '@/features/arena/api';
import { useArenaReview } from '@/features/arena/hooks';
import { REVIEW_FIXTURE } from '@/features/arena/reviewFixture';
import { arenaReviewResponseSchema, type ArenaReviewResponse, type RankedBarrier } from '@/features/arena/reviewSchema';
import { SEVERITY_RANK } from './format';
import { BarrierRail } from './BarrierRail';
import { CorrectiveActionPanel } from './CorrectiveActionPanel';
import { ReviewChronobar } from './ReviewChronobar';
import { ReviewFlowMap } from './ReviewFlowMap';
import { ReviewHeader, type Freshness } from './ReviewHeader';
import { ReviewSummaryStrip } from './ReviewSummaryStrip';

// The backend loop (FlowReviewService + GET /api/arena/review) has shipped, so a
// live review is preferred whenever the endpoint returns one (see the useMemo
// below — parsed data always wins). This stays true as the OFFLINE fallback: when
// ARENA is disabled the route 404s and the movement still demos end to end
// against the fixture. Set to false to force the "no review yet" empty state.
const USE_REVIEW_FIXTURE = true;

function InfoCard({ title, body }: { title: string; body: string }) {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h2 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
      <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{body}</p>
    </div>
  );
}

interface Props {
  aiEnabled: boolean;
  canApprove: boolean;
  lensLabel?: string;
}

export function FlowReviewMovement({ aiEnabled, canApprove, lensLabel }: Props) {
  const [selectedBarrierId, setSelectedBarrierId] = useState<string | null>(null);
  const reviewQuery = useArenaReview();
  const queryClient = useQueryClient();

  const runMutation = useMutation({
    mutationFn: runArenaReview,
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['arena', 'review'] }),
  });

  const review = useMemo<ArenaReviewResponse | null>(() => {
    if (reviewQuery.data !== undefined) {
      const parsed = arenaReviewResponseSchema.safeParse(reviewQuery.data);
      if (parsed.success) return parsed.data;
    }
    return USE_REVIEW_FIXTURE ? REVIEW_FIXTURE : null;
  }, [reviewQuery.data]);

  // Worst-first ranking, shared by the rail and the default selection.
  const barriers = useMemo<RankedBarrier[]>(() => {
    if (!review || !review.available) return [];
    return [...review.barriers].sort((a, b) => {
      const bySeverity = SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity];
      if (bySeverity !== 0) return bySeverity;
      return Math.abs(b.metric.delta_pct ?? 0) - Math.abs(a.metric.delta_pct ?? 0);
    });
  }, [review]);

  if (!review) {
    if (reviewQuery.isLoading) {
      return <InfoCard title="Loading the review…" body="Fetching the latest 48-hour Flow Review artifact." />;
    }
    return (
      <InfoCard
        title="No 48-hour review yet"
        body="No review has been generated for this window. Run one to discover the flow, rank barriers, and draft corrective actions."
      />
    );
  }

  if (!review.available) {
    return (
      <InfoCard
        title="Review unavailable"
        body={`The Flow Review could not load: ${review.reason}. Confirm ARENA_ENABLED and the OCPM sidecar are up, then Run review.`}
      />
    );
  }

  // Default selection is the worst barrier; the atom is the only cross-component state.
  const effectiveSelectedId = selectedBarrierId ?? barriers[0]?.id ?? null;
  const selectedBarrier: RankedBarrier | null = barriers.find((barrier) => barrier.id === effectiveSelectedId) ?? null;
  const freshness: Freshness = review.stale ? 'stale' : review.cached ? 'cached' : 'fresh';

  return (
    <div className="space-y-4">
      <ReviewHeader
        windowLabel={review.window.label}
        priorLabel={review.prior_window_label}
        freshness={freshness}
        generatedAt={review.generated_at}
        lensLabel={lensLabel}
        onRun={() => runMutation.mutate()}
        running={runMutation.isPending}
      />
      <ReviewChronobar window={review.window} barriers={barriers} selectedId={effectiveSelectedId} onSelect={setSelectedBarrierId} />
      <ReviewSummaryStrip stats={review.stats} />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr,24rem]">
        <ReviewFlowMap
          map={review.map}
          performanceIndex={review.performance_index}
          barriers={barriers}
          selectedBarrier={selectedBarrier}
          onSelect={setSelectedBarrierId}
        />
        <BarrierRail barriers={barriers} selectedId={effectiveSelectedId} onSelect={setSelectedBarrierId} />
      </div>
      {/* key remounts the panel per barrier so its approve/draft mutation state
          (and the deviant-cases toggle) never leaks onto the next selection. */}
      <CorrectiveActionPanel key={effectiveSelectedId ?? 'none'} barrier={selectedBarrier} aiEnabled={aiEnabled} canApprove={canApprove} />
    </div>
  );
}
