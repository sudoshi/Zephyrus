package net.acumenus.hummingbird.data

/**
 * Delta merge for the Flow Window (`?since=` refreshes).
 *
 * Contract: with `since` present the server trims `events` and `snapshots` to items with
 * t > since; `projections`, `spaces` and `bed_statuses` come back FULL every time. So the
 * client APPENDS new events/snapshots (deduped) and REPLACES projections/bed_statuses/spaces
 * wholesale. The window frame (from/to/now), lens and scope always take the fresh values.
 *
 * Pure function — no Android deps — so the merge semantics are unit-testable in isolation.
 */
fun mergeFlowWindow(current: FlowWindowData, delta: FlowWindowData): FlowWindowData =
    current.copy(
        window = delta.window,
        lens = delta.lens,
        scope = delta.scope,
        // spaces/projections/bed_statuses are always full in a delta response → replace.
        spacesFloors = delta.spacesFloors,
        projections = delta.projections,
        bedStatuses = delta.bedStatuses,
        // events/snapshots are trimmed to t > since → append the new ones, deduped.
        events = appendEvents(current.events, delta.events),
        snapshots = appendSnapshots(current.snapshots, delta.snapshots),
        webLink = delta.webLink ?: current.webLink,
    )

/** Dedupe key for a timeline event: (t, kind, entity ref, label). */
private fun eventKey(e: FlowTimelineEvent): String =
    "${e.tMs}|${e.kind}|${e.entityRef ?: ""}|${e.label}"

/** Dedupe key for a snapshot: (t, unit_id). */
private fun snapshotKey(s: FlowSnapshot): String = "${s.tMs}|${s.unitId}"

internal fun appendEvents(
    existing: List<FlowTimelineEvent>,
    incoming: List<FlowTimelineEvent>,
): List<FlowTimelineEvent> {
    if (incoming.isEmpty()) return existing
    val seen = existing.mapTo(HashSet()) { eventKey(it) }
    val merged = existing.toMutableList()
    for (e in incoming) if (seen.add(eventKey(e))) merged += e
    return merged.sortedBy { it.tMs }
}

internal fun appendSnapshots(
    existing: List<FlowSnapshot>,
    incoming: List<FlowSnapshot>,
): List<FlowSnapshot> {
    if (incoming.isEmpty()) return existing
    val seen = existing.mapTo(HashSet()) { snapshotKey(it) }
    val merged = existing.toMutableList()
    for (s in incoming) if (seen.add(snapshotKey(s))) merged += s
    return merged.sortedBy { it.tMs }
}

/**
 * The `?since=` cursor for the next delta: the newest loaded event/snapshot time, ISO8601.
 * Null when nothing is loaded yet (⇒ caller does a full load). The value is always ≤ now,
 * so it lies inside [from, to); a window that has drifted far enough for it to fall out of
 * range is handled by the server's 422 → full-load fallback.
 */
fun newestLoadedSinceIso(window: FlowWindowData): String? {
    val newest = (window.events.map { it.tMs } + window.snapshots.map { it.tMs })
        .filter { it > 0L }
        .maxOrNull() ?: return null
    return flowEpochMsToIso(newest)
}
