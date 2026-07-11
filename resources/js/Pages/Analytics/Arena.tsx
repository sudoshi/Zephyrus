// resources/js/Pages/Analytics/Arena.tsx
//
// Zephyrus 2.0 Part X — the Patient-Flow Arena. This page is now a thin shell
// over two modes: the default "48-Hour Review" (flow → barriers → corrective
// actions after each 48h evaluation) and the free-exploration "Explore" Study
// (the original object-centric surface, extracted to ArenaStudy). Deep-linkable
// via ?mode=explore. Gated upstream by ARENA_ENABLED (EnsureArenaEnabled).
import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import { ArenaStudy } from '@/Components/arena/ArenaStudy';
import { FlowReviewMovement } from '@/Components/arena/review/FlowReviewMovement';
import { ModeToggle, type ReviewMode } from '@/Components/arena/review/ModeToggle';

function initialMode(): ReviewMode {
  if (typeof window === 'undefined') return 'review';
  return new URLSearchParams(window.location.search).get('mode') === 'explore' ? 'explore' : 'review';
}

export default function Arena() {
  const page = usePage<PageProps>();
  const aiEnabled = page.props.arena?.ai_enabled ?? false;
  const isAdmin = page.props.auth?.is_admin ?? false;
  // ops:approve is enforced server-side (the Eddy gate); this only decides which
  // tooltip the (currently disabled) Approve button shows in the scaffold.
  const canApprove = isAdmin;

  const [mode, setMode] = useState<ReviewMode>(initialMode);

  return (
    <AnalyticsLayout title="Patient-Flow Arena" headerButtons={<ModeToggle mode={mode} onChange={setMode} />}>
      <Head title="Patient-Flow Arena" />
      {mode === 'review' ? (
        <FlowReviewMovement aiEnabled={aiEnabled} canApprove={canApprove} />
      ) : (
        <ArenaStudy aiEnabled={aiEnabled} />
      )}
    </AnalyticsLayout>
  );
}
