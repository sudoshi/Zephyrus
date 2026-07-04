// resources/js/Components/cockpit/CockpitOverview.tsx
//
// The A0 Glance surface (Zephyrus 2.0 P2): one screen assembling the cockpit
// grammar — CommandBar, AlertTicker, CensusStrip, the 8-domain panel grid,
// the OKR scorecard, and the status legend. Pure presentation: everything
// arrives parsed (cockpitSnapshotSectionsSchema) from the page.
//
// Role behavior preserves the pre-2.0 HeroWall↔OKR swap: the executive
// persona reads Outcomes-first (OKR scorecard above the domain grid); command
// reads operations-first. Same information either way — arrangement, not
// content, changes (PRODUCT.md: density with clarity).
import type { ReactNode } from 'react';
import type { CockpitAlert, CockpitDrillDomain, CockpitSnapshotSections } from '@/types/cockpit';
import type { CommandRole } from '@/stores/commandCenterStore';
import { Icon } from '@iconify/react';
import { statusLevels } from '@/types/commandCenter';
import { statusStyle } from './statusStyle';
import { CommandBar } from './CommandBar';
import { AlertTicker } from './AlertTicker';
import { CensusStrip } from './CensusStrip';
import { DomainGrid } from './DomainGrid';
import { OkrScorecard } from './OkrScorecard';

interface CockpitOverviewProps {
  sections: CockpitSnapshotSections;
  role: CommandRole;
  updatedLabel: string;
  refreshing: boolean;
  aging: boolean;
  stale: boolean;
  onRefresh: () => void;
  /** D2: held drill state — P3 opens the matching DrillModal from this. */
  activeDrill: CockpitDrillDomain | null;
  onDrillChange: (domain: CockpitDrillDomain | null) => void;
  /** D2: ?display=wall — P2 wires the flag; P8 builds full wall mode on it. */
  wall?: boolean;
  /** P6 WS-4: ticker → EddyDock hand-off (wired by the page when Eddy is on). */
  onAlertEngage?: (alert: CockpitAlert) => void;
  /** P6 WS-5: CommandBar affordance for the in-cockpit action inbox. */
  onOpenInbox?: () => void;
  inboxCount?: number;
  /** P6 WS-5: executive-role brief panel, slotted below the OKR scorecard. */
  briefPanel?: ReactNode;
}

function Legend({ asOf }: { asOf: string }) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      {statusLevels.map((level) => {
        const s = statusStyle(level);
        return (
          <span key={level} className="inline-flex items-center gap-1">
            <span aria-hidden="true" className="leading-none" style={{ color: s.color }}>{s.glyph}</span>
            {s.label}
          </span>
        );
      })}
      <span className="inline-flex items-center gap-1">
        <span className="rounded border border-healthcare-border dark:border-healthcare-border-dark px-1 py-px font-medium uppercase tracking-wide">
          demo
        </span>
        seeded demonstration data
      </span>
      <span className="ml-auto tabular-nums">As of {new Date(asOf).toLocaleTimeString([], { hour12: false })}</span>
    </div>
  );
}

export function CockpitOverview({
  sections,
  role,
  updatedLabel,
  refreshing,
  aging,
  stale,
  onRefresh,
  activeDrill,
  onDrillChange,
  wall = false,
  onAlertEngage,
  onOpenInbox,
  inboxCount,
  briefPanel,
}: CockpitOverviewProps) {
  const okrsFirst = role === 'executive';
  const scorecard = <OkrScorecard okrs={sections.okrs} onDrill={onDrillChange} />;
  const grid = <DomainGrid domains={sections.domains} onDrill={onDrillChange} />;

  return (
    <div
      className="flex flex-col gap-3"
      data-testid="cockpit-overview"
      data-display={wall ? 'wall' : undefined}
      data-drill={activeDrill ?? undefined}
    >
      <CommandBar
        facility={sections.facility}
        capacityStatus={sections.capacityStatus}
        updatedLabel={updatedLabel}
        refreshing={refreshing}
        aging={aging}
        stale={stale}
        onRefresh={onRefresh}
        onOpenInbox={onOpenInbox}
        inboxCount={inboxCount}
      />

      {/* Stale signal: same loud contract as the classic view — when the
          payload stops advancing, stop implying the numbers are live. */}
      {stale && (
        <div role="status" aria-live="polite" aria-label="Stale data warning"
             className="flex items-center gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/20
                        px-3 py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          <Icon icon="heroicons:exclamation-triangle" aria-hidden="true"
                className="h-4 w-4 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark" />
          <span className="min-w-0">
            Live updates interrupted — showing last good data from {updatedLabel}.{' '}
            <button type="button" onClick={onRefresh}
                    className="font-medium underline underline-offset-2 hover:no-underline">
              Retry now
            </button>
          </span>
        </div>
      )}

      <AlertTicker alerts={sections.alerts} onEngage={onAlertEngage} />
      <CensusStrip census={sections.census} />

      {okrsFirst ? scorecard : grid}
      {/* P6 WS-5: the executive persona reads the narrative brief right after
          its Outcomes scorecard; other roles never mount the panel. */}
      {okrsFirst && briefPanel}
      {okrsFirst ? grid : scorecard}

      <Legend asOf={sections.asOf} />
    </div>
  );
}
