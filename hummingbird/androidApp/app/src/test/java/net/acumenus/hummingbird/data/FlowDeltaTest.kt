package net.acumenus.hummingbird.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Delta-merge contract: append+dedupe events (by t, kind, entity ref, label) and snapshots
 * (by t, unit_id); REPLACE projections, bed_statuses and spaces wholesale; take the fresh
 * window frame. Plus the `?since=` cursor derivation.
 */
class FlowDeltaTest {

    private val t1 = "2026-07-04T10:00:00+00:00"
    private val t2 = "2026-07-04T11:00:00+00:00"
    private val t3 = "2026-07-04T12:00:00+00:00"

    private fun range(from: String, to: String, now: String, since: String? = null) =
        FlowWindowRange(from = from, to = to, now = now, since = since)

    private val lens = FlowLens("bed_manager", emptyList(), emptyList(), emptyList(), emptyList(), "full", emptyList(), 48)
    private val scope = FlowScope("house", null, null, null, "House")

    private fun event(t: String, kind: String, label: String, entityRef: String? = null) = FlowTimelineEvent(
        t = t, kind = kind, label = label, tier = "info",
        unitId = 1, fromSpace = null, toSpace = null, patientContextRef = entityRef,
        provenanceSource = "prod.operational_events", entityRef = entityRef,
    )

    private fun snapshot(t: String, unitId: Int) =
        FlowSnapshot(t = t, unitId = unitId, staffed = 10, occupied = 5, available = 5, blocked = 0)

    private fun projection(kind: String) = FlowProjection(
        t = t3, kind = kind, confidence = "probable", label = kind, unitId = 1, bedId = null, room = null,
        value = null, bandLower = null, bandUpper = null, endsAt = null, derived = false,
        patientContextRef = null, provenanceService = "svc", provenanceReliability = 0.8,
    )

    private fun bedStatus(bedId: Int, status: String) = FlowBedStatus(bedId, 1, "MICU-0$bedId", status)

    private fun floor(n: Int) = FlowFloorRollup(n, "Floor $n", 20, 10, 50, emptyList())

    private fun window(
        range: FlowWindowRange,
        events: List<FlowTimelineEvent> = emptyList(),
        snapshots: List<FlowSnapshot> = emptyList(),
        projections: List<FlowProjection> = emptyList(),
        bedStatuses: List<FlowBedStatus> = emptyList(),
        floors: List<FlowFloorRollup> = emptyList(),
        webLink: String? = null,
    ) = FlowWindowData(range, lens, scope, floors, snapshots, events, projections, bedStatuses, webLink)

    @Test
    fun appendsNewEventsAndDedupesByKey() {
        val current = window(
            range(t1, t3, t2),
            events = listOf(event(t1, "admit", "Admitted to MICU"), event(t2, "discharge", "Discharged")),
        )
        val delta = window(
            range(t1, t3, t3, since = t2),
            // e2 duplicate (same t, kind, ref, label) + a genuinely new event.
            events = listOf(event(t2, "discharge", "Discharged"), event(t3, "transfer", "Transfer")),
        )

        val merged = mergeFlowWindow(current, delta)

        assertEquals(3, merged.events.size)
        assertEquals(listOf("admit", "discharge", "transfer"), merged.events.map { it.kind })
    }

    @Test
    fun keepsEventsThatShareTimeKindLabelButDifferInEntityRef() {
        val current = window(range(t1, t3, t2), events = listOf(event(t2, "bed_request", "Bed request", entityRef = "ref_a")))
        val delta = window(
            range(t1, t3, t3, since = t2),
            events = listOf(event(t2, "bed_request", "Bed request", entityRef = "ref_b")),
        )

        val merged = mergeFlowWindow(current, delta)

        assertEquals(2, merged.events.size)
        assertEquals(setOf("ref_a", "ref_b"), merged.events.mapNotNull { it.entityRef }.toSet())
    }

    @Test
    fun appendsSnapshotsAndDedupesByTimeAndUnit() {
        val current = window(range(t1, t3, t2), snapshots = listOf(snapshot(t1, 1), snapshot(t1, 2)))
        val delta = window(
            range(t1, t3, t3, since = t2),
            snapshots = listOf(snapshot(t1, 1), snapshot(t2, 1)), // dup (t1,unit1) + new (t2,unit1)
        )

        val merged = mergeFlowWindow(current, delta)

        assertEquals(3, merged.snapshots.size)
    }

    @Test
    fun replacesProjectionsBedStatusesAndSpacesWholesale() {
        val current = window(
            range(t1, t3, t2),
            projections = listOf(projection("surge_probability"), projection("predicted_census")),
            bedStatuses = listOf(bedStatus(1, "dirty"), bedStatus(2, "occupied")),
            floors = listOf(floor(1), floor(2)),
        )
        val delta = window(
            range(t1, t3, t3, since = t2),
            projections = listOf(projection("expected_discharge")),
            bedStatuses = listOf(bedStatus(3, "available")),
            floors = listOf(floor(9)),
        )

        val merged = mergeFlowWindow(current, delta)

        assertEquals(listOf("expected_discharge"), merged.projections.map { it.kind })
        assertEquals(listOf(3), merged.bedStatuses.map { it.bedId })
        assertEquals(listOf(9), merged.spacesFloors.map { it.floor })
    }

    @Test
    fun takesTheFreshWindowFrame() {
        val current = window(range(t1, t3, t2))
        val delta = window(range(t2, t3, t3, since = t2))

        val merged = mergeFlowWindow(current, delta)

        assertEquals(t2, merged.window.from)
        assertEquals(t3, merged.window.now)
        assertEquals(t2, merged.window.since)
    }

    @Test
    fun sinceCursorIsNewestLoadedEventOrSnapshot() {
        val win = window(
            range(t1, t3, t3),
            events = listOf(event(t1, "admit", "a")),
            snapshots = listOf(snapshot(t2, 1)),
        )
        // Newest of {t1 event, t2 snapshot} is t2.
        assertEquals(flowEpochMsToIso(flowIsoToEpochMs(t2)!!), newestLoadedSinceIso(win))
    }

    @Test
    fun sinceCursorIsNullWhenNothingLoaded() {
        assertNull(newestLoadedSinceIso(window(range(t1, t3, t3))))
    }

    @Test
    fun mergeDoesNotMutateProjectionCountUpward() {
        // Guards the "projections always full ⇒ replace, never accumulate" rule under repeat.
        val current = window(range(t1, t3, t2), projections = listOf(projection("surge_probability")))
        val delta = window(range(t1, t3, t3, since = t2), projections = listOf(projection("surge_probability")))
        val once = mergeFlowWindow(current, delta)
        val twice = mergeFlowWindow(once, delta)
        assertEquals(1, twice.projections.size)
        assertFalse(twice.projections.size > 1)
        assertTrue(twice.events.isEmpty())
    }
}
