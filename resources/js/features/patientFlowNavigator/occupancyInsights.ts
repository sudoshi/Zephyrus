import type {
  FlowLens,
  OccupancyInsight,
  OccupancyServiceLineSummary,
  OccupancySummary,
  OccupancyTimer,
  OccupancyTimerStatus,
  PatientFlowLocations,
  PatientVisibleState,
  ProjectionItem,
} from './types';

const LONG_STAY_WARN_MINUTES = 8 * 60;
const LONG_STAY_DELAY_MINUTES = 18 * 60;
const READY_MOVE_WINDOW_MINUTES = 30;
const OVERDUE_TIMER_WINDOW_MINUTES = 4 * 60;

const TIMER_LABELS: Record<string, string> = {
  expected_discharge: 'Discharge',
  transport_due: 'Transport',
  evs_due: 'EVS turn',
  scheduled_or_case: 'OR',
};

function minutesBetween(fromMs: number, toIso: string): number | null {
  const target = Date.parse(toIso);
  if (!Number.isFinite(target)) return null;
  return Math.round((target - fromMs) / 1_000) / 60;
}

function timerStatus(minutesRemaining: number | null): OccupancyTimerStatus {
  if (minutesRemaining === null) return 'ok';
  if (minutesRemaining < 0) return 'delayed';
  if (minutesRemaining <= READY_MOVE_WINDOW_MINUTES) return 'watch';
  return 'ok';
}

function statusRank(status: OccupancyTimerStatus): number {
  if (status === 'delayed') return 2;
  if (status === 'watch') return 1;
  return 0;
}

function strongestStatus(statuses: OccupancyTimerStatus[]): OccupancyTimerStatus {
  return statuses.reduce(
    (best, status) => (statusRank(status) > statusRank(best) ? status : best),
    'ok' as OccupancyTimerStatus,
  );
}

function humanServiceLine(value?: string | null): string {
  return value ? value.replaceAll('_', ' ') : 'Unassigned';
}

function projectionMatchesState(item: ProjectionItem, state: PatientVisibleState): boolean {
  return projectionMatchRank(item, state) > 0;
}

function projectionMatchRank(item: ProjectionItem, state: PatientVisibleState): number {
  if (item._patient_ref && item._patient_ref === state.event.patient_id) return 5;
  const entityRef = item.entity?.ref ? String(item.entity.ref) : null;
  if (item.patient_context_ref && state.event.metadata?.patient_context_ref === item.patient_context_ref) return 5;
  if (entityRef && [state.event.encounter_id, state.event.patient_id, state.event.patient_display_id].includes(entityRef)) return 4;
  if (item.bed_id !== null && state.event.bed && String(item.bed_id) === String(state.event.bed)) return 3;
  if (item.room && state.event.room && item.room.toLowerCase() === state.event.room.toLowerCase()) return 2;
  return 0;
}

function nearbyProjections(
  state: PatientVisibleState,
  projections: ProjectionItem[],
  timeMs: number,
): ProjectionItem[] {
  const currentUnit = state.event.unit_code;
  const eventLocation = state.event.to_location;
  const future = projections
    .filter((item) => {
      const t = Date.parse(item.t);
      if (!Number.isFinite(t) || t < timeMs - OVERDUE_TIMER_WINDOW_MINUTES * 60_000) return false;
      if (projectionMatchesState(item, state)) return true;
      if (item.room && [state.event.room, eventLocation].filter(Boolean).some((value) => item.room?.toLowerCase() === String(value).toLowerCase())) return true;
      if (item.unit_id !== null && currentUnit && item.label.toLowerCase().includes(currentUnit.toLowerCase())) return true;
      return false;
    })
    .map((item) => ({ item, rank: projectionMatchRank(item, state) }));
  const exact = future.filter(({ rank }) => rank >= 4);

  return (exact.length > 0 ? exact : future)
    .sort((a, b) => b.rank - a.rank || Date.parse(a.item.t) - Date.parse(b.item.t))
    .slice(0, 4)
    .map(({ item }) => item);
}

function eventTimer(state: PatientVisibleState, timeMs: number): OccupancyTimer | null {
  const next = state.nextEvent;
  if (!next?.occurred_at) return null;
  const minutesRemaining = minutesBetween(timeMs, next.occurred_at);
  if (minutesRemaining === null) return null;
  const movement = next.event_category === 'movement';
  const label = movement && next.to_location
    ? `Next ${next.to_location}`
    : next.event_type.replaceAll('_', ' ');
  const metadata = next.metadata ?? {};

  return {
    kind: movement ? 'next_transport' : 'readiness',
    label,
    dueAt: next.occurred_at,
    minutesRemaining,
    status: timerStatus(minutesRemaining),
    source: movement ? 'movement' : next.event_category,
    reason: typeof metadata.reason === 'string' ? metadata.reason : typeof metadata.barrier_reason === 'string' ? metadata.barrier_reason : typeof metadata.delay_reason === 'string' ? metadata.delay_reason : null,
    barrierCode: typeof metadata.barrier_code === 'string' ? metadata.barrier_code : null,
    ownerRole: typeof metadata.owner_role === 'string' ? metadata.owner_role : null,
    blocks: typeof metadata.blocks === 'string' ? metadata.blocks : null,
    impact: typeof metadata.impact === 'string' ? metadata.impact : null,
  };
}

function projectionTimer(item: ProjectionItem, timeMs: number): OccupancyTimer {
  const minutesRemaining = minutesBetween(timeMs, item.t);
  const source = item.derived ? `${item.provenance.service} · derived` : item.provenance.service;
  const explicitKind = item.timer_kind && ['stay', 'arrival_transport', 'next_transport', 'evs', 'readiness'].includes(item.timer_kind)
    ? item.timer_kind
    : null;

  return {
    kind: explicitKind ?? (item.kind === 'evs_due' ? 'evs' : item.kind === 'transport_due' ? 'next_transport' : 'readiness'),
    label: item.label || TIMER_LABELS[item.kind] || item.kind.replaceAll('_', ' '),
    dueAt: item.t,
    minutesRemaining,
    status: timerStatus(minutesRemaining),
    source,
    reason: item.reason ?? null,
    barrierCode: item.barrier_code ?? null,
    ownerRole: item.owner_role ?? null,
    blocks: item.blocks ?? null,
    impact: item.impact ?? null,
  };
}

function stayTimer(stayMinutes: number): OccupancyTimer {
  const status: OccupancyTimerStatus = stayMinutes >= LONG_STAY_DELAY_MINUTES
    ? 'delayed'
    : stayMinutes >= LONG_STAY_WARN_MINUTES ? 'watch' : 'ok';

  return {
    kind: 'stay',
    label: 'Stay',
    dueAt: null,
    minutesRemaining: null,
    status,
    source: 'elapsed occupancy',
    reason: status === 'ok' ? null : 'Elapsed occupancy has crossed the RTDC stay-duration threshold.',
    barrierCode: null,
    barrierLabel: null,
    riskCode: status === 'ok' ? null : 'long_stay_capacity_risk',
    riskLabel: status === 'ok' ? null : 'Long-stay capacity risk',
    ownerRole: status === 'ok' ? null : 'bed_manager',
    blocks: status === 'ok' ? null : 'Capacity release',
    impact: status === 'ok' ? null : 'Long-stay occupancy compounds bed availability risk.',
    classification: 'duration_risk',
    verified: false,
    verification: {
      status: 'inferred',
      assertion: 'elapsed_duration_threshold',
      matchedBy: 'occupancy_elapsed_time',
    },
  };
}

function blockerLabels(timers: OccupancyTimer[]): string[] {
  return timers
    .filter((timer) => timer.status !== 'ok' && Boolean(timer.barrierCode))
    .map((timer) => timer.label)
    .filter((value, index, values) => values.indexOf(value) === index);
}

function summarize(insights: OccupancyInsight[]): OccupancySummary {
  const serviceMap = new Map<string, { occupied: number; delayed: number; watch: number; stay: number }>();
  let delayed = 0;
  let watch = 0;
  let transportDelays = 0;
  let evsDelays = 0;
  let readyToMove = 0;
  let stayTotal = 0;
  const barrierMap = new Map<string, {
    barrierCode: string | null;
    label: string;
    reason: string | null;
    ownerRole: string | null;
    count: number;
    serviceLines: Set<string>;
  }>();

  for (const insight of insights) {
    stayTotal += insight.stayMinutes;
    if (insight.primaryStatus === 'delayed') delayed += 1;
    if (insight.primaryStatus === 'watch') watch += 1;
    if (insight.timers.some((timer) => ['arrival_transport', 'next_transport'].includes(timer.kind) && timer.status !== 'ok')) transportDelays += 1;
    if (insight.timers.some((timer) => timer.kind === 'evs' && timer.status !== 'ok')) evsDelays += 1;
    if (insight.timers.some((timer) => timer.minutesRemaining !== null && timer.minutesRemaining >= 0 && timer.minutesRemaining <= READY_MOVE_WINDOW_MINUTES)) readyToMove += 1;

    const serviceLine = humanServiceLine(insight.serviceLine);
    const entry = serviceMap.get(serviceLine) ?? { occupied: 0, delayed: 0, watch: 0, stay: 0 };
    entry.occupied += 1;
    entry.stay += insight.stayMinutes;
    if (insight.primaryStatus === 'delayed') entry.delayed += 1;
    if (insight.primaryStatus === 'watch') entry.watch += 1;
    serviceMap.set(serviceLine, entry);

    for (const timer of insight.timers) {
      if (timer.status === 'ok') continue;
      if (timer.kind === 'stay') continue;
      if (!timer.barrierCode) continue;
      const key = timer.barrierCode ?? `${timer.label}|${timer.reason ?? ''}|${timer.ownerRole ?? ''}`;
      const barrier = barrierMap.get(key) ?? {
        barrierCode: timer.barrierCode ?? null,
        label: timer.barrierLabel ?? timer.label,
        reason: timer.reason ?? null,
        ownerRole: timer.ownerRole ?? null,
        count: 0,
        serviceLines: new Set<string>(),
      };
      barrier.count += 1;
      barrier.serviceLines.add(serviceLine);
      barrierMap.set(key, barrier);
    }
  }

  const serviceLines: OccupancyServiceLineSummary[] = [...serviceMap.entries()]
    .map(([serviceLine, entry]) => ({
      serviceLine,
      occupied: entry.occupied,
      delayed: entry.delayed,
      watch: entry.watch,
      avgStayMinutes: entry.occupied > 0 ? entry.stay / entry.occupied : 0,
    }))
    .sort((a, b) => (b.delayed + b.watch) - (a.delayed + a.watch) || b.occupied - a.occupied)
    .slice(0, 4);

  return {
    active: insights.length,
    delayed,
    watch,
    transportDelays,
    evsDelays,
    readyToMove,
    durationRisks: insights.filter((insight) => insight.timers.some((timer) => timer.classification === 'duration_risk' && timer.status !== 'ok')).length,
    verifiedBarriers: insights.reduce((count, insight) => count + insight.timers.filter((timer) => timer.verified && timer.status !== 'ok').length, 0),
    avgStayMinutes: insights.length > 0 ? stayTotal / insights.length : 0,
    serviceLines,
    persona: {
      transport: transportDelays,
      evs: evsDelays,
      bedManager: readyToMove + delayed,
      capacity: delayed + watch,
    },
    topBarriers: [...barrierMap.values()]
      .map((item) => ({
        barrierCode: item.barrierCode,
        label: item.label,
        reason: item.reason,
        ownerRole: item.ownerRole,
        count: item.count,
        serviceLines: [...item.serviceLines],
      }))
      .sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
      .slice(0, 5),
  };
}

export function buildOccupancyInsights(
  states: PatientVisibleState[],
  locations: PatientFlowLocations,
  projections: ProjectionItem[],
  timeMs: number,
  lens: FlowLens | null | undefined,
): { insights: OccupancyInsight[]; summary: OccupancySummary } {
  const identityVisible = !lens || lens.patient_dots === 'full';

  const insights = states.map((state): OccupancyInsight => {
    const stayMinutes = Math.max(0, Math.round((timeMs - Date.parse(state.arrivedAt)) / 1_000) / 60);
    const projectionTimers = nearbyProjections(state, projections, timeMs).map((item) => projectionTimer(item, timeMs));
    const knownNext = eventTimer(state, timeMs);
    const timers = [
      stayTimer(stayMinutes),
      ...(knownNext ? [knownNext] : []),
      ...projectionTimers,
    ].sort((a, b) => statusRank(b.status) - statusRank(a.status));
    const location = state.event.to_location ?? 'unknown';
    const loc = locations[location];
    const blockers = blockerLabels(timers);
    const blockingTimers = timers.filter((timer) => timer.status !== 'ok');
    const barrierTimers = blockingTimers.filter((timer) => Boolean(timer.barrierCode));
    const statuses = timers.map((timer) => timer.status);

    return {
      key: `${state.patientId}:${location}`,
      location,
      locationName: state.event.location_name ?? loc?.name ?? null,
      unitCode: state.event.unit_code ?? loc?.unit_code ?? null,
      serviceLine: state.event.service_line ?? state.event.location_service_line ?? loc?.service_line ?? null,
      ...(identityVisible
        ? {
            patientDisplayId: state.event.patient_display_id,
            patientId: state.event.patient_id,
            encounterId: state.event.encounter_id,
          }
        : {}),
      position: state.position,
      stayMinutes,
      arrivedAt: state.arrivedAt,
      cameFrom: state.cameFrom ?? state.event.from_location ?? null,
      nextMove: state.nextEvent?.to_location ?? projectionTimers[0]?.label ?? null,
      nextMoveAt: state.nextEvent?.occurred_at ?? projectionTimers[0]?.dueAt ?? null,
      primaryStatus: strongestStatus(statuses),
      timers,
      blockers,
      barrierReasons: [...new Set(barrierTimers.map((timer) => timer.reason).filter((value): value is string => Boolean(value)))],
      barrierCodes: [...new Set(barrierTimers.map((timer) => timer.barrierCode).filter((value): value is string => Boolean(value)))],
      barrierLabels: [...new Set(barrierTimers.map((timer) => timer.barrierLabel).filter((value): value is string => Boolean(value)))],
      ownerRoles: [...new Set(barrierTimers.map((timer) => timer.ownerRole).filter((value): value is string => Boolean(value)))],
      delayImpacts: [...new Set(barrierTimers.map((timer) => timer.impact).filter((value): value is string => Boolean(value)))],
      rtdcMetrics: [...new Set(barrierTimers.flatMap((timer) => timer.rtdcMetrics ?? []))],
      eddySummaries: [...new Set(barrierTimers.map((timer) => timer.eddySummary).filter((value): value is string => Boolean(value)))],
      barrierOwnerMap: Object.fromEntries(
        barrierTimers
          .filter((timer) => timer.barrierCode)
          .map((timer) => [
            timer.barrierCode as string,
            { label: timer.barrierLabel ?? timer.label, ownerRole: timer.ownerRole ?? null },
          ]),
      ),
    };
  });

  return { insights, summary: summarize(insights) };
}
