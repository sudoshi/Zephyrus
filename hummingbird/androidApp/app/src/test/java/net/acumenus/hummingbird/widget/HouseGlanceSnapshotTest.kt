package net.acumenus.hummingbird.widget

import net.acumenus.hummingbird.data.FlowFloorRollup
import net.acumenus.hummingbird.data.FlowLens
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowScope
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.data.FlowWindowRange
import net.acumenus.hummingbird.data.flowIsoToEpochMs
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Widget-feed derivation: occupancy + next-4h ghost count from the window, and field-preserving merge. */
class HouseGlanceSnapshotTest {

    private val now = "2026-07-04T12:00:00+00:00"
    private fun plus(hours: Long) = "2026-07-04T${(12 + hours).toString().padStart(2, '0')}:00:00+00:00"

    private fun projection(t: String) = FlowProjection(
        t = t, kind = "predicted_arrivals", confidence = "probable", label = "arrivals",
        unitId = 1, bedId = null, room = null, value = null, bandLower = null, bandUpper = null,
        endsAt = null, derived = false, patientContextRef = null, provenanceService = "svc", provenanceReliability = null,
    )

    private fun window(floors: List<FlowFloorRollup>, projections: List<FlowProjection>) = FlowWindowData(
        window = FlowWindowRange(from = "2026-07-03T12:00:00+00:00", to = "2026-07-05T12:00:00+00:00", now = now),
        lens = FlowLens("bed_manager", emptyList(), emptyList(), emptyList(), emptyList(), "full", emptyList(), 48),
        scope = FlowScope("house", null, null, null, "House"),
        spacesFloors = floors,
        snapshots = emptyList(),
        events = emptyList(),
        projections = projections,
        bedStatuses = emptyList(),
    )

    @Test
    fun fromFlowComputesOccupancyAndNext4hGhostCount() {
        val win = window(
            floors = listOf(
                FlowFloorRollup(1, "F1", staffed = 10, occupied = 8, occupancyPct = 80, units = emptyList()),
                FlowFloorRollup(2, "F2", staffed = 10, occupied = 4, occupancyPct = 40, units = emptyList()),
            ),
            projections = listOf(
                projection(plus(1)),   // within 4h
                projection(plus(3)),   // within 4h
                projection(plus(6)),   // beyond 4h
                projection(now),       // exactly now — inclusive
            ),
        )

        val snap = HouseGlanceSnapshot.fromFlow(win)

        assertEquals(60, snap.occupancyPct) // (8+4)/(10+10) = 60%
        assertEquals(3, snap.next4hGhostCount)
    }

    @Test
    fun fromFlowLeavesOccupancyNullWithoutStaffedBeds() {
        val snap = HouseGlanceSnapshot.fromFlow(window(emptyList(), emptyList()))
        assertNull(snap.occupancyPct)
        assertEquals(0, snap.next4hGhostCount)
    }

    @Test
    fun mergePreservesKnownFieldsAcrossPartialWrites() {
        val fromHome = HouseGlanceSnapshot(forYouCount = 5, netBedNeed = 3)
        val base = HouseGlanceSnapshot.merge(null, fromHome, nowMs = 1000L)
        // A later flow-only write updates occupancy + next-4h but must keep For You / bed need.
        val fromFlow = HouseGlanceSnapshot(occupancyPct = 84, next4hGhostCount = 7)
        val merged = HouseGlanceSnapshot.merge(base, fromFlow, nowMs = 2000L)

        assertEquals(84, merged.occupancyPct)
        assertEquals(7, merged.next4hGhostCount)
        assertEquals(5, merged.forYouCount)
        assertEquals(3, merged.netBedNeed)
        assertEquals(2000L, merged.updatedAtMs)
    }

    @Test
    fun jsonRoundTrips() {
        val snap = HouseGlanceSnapshot(occupancyPct = 71, netBedNeed = -2, forYouCount = 9, next4hGhostCount = 4, updatedAtMs = 42L)
        val back = HouseGlanceSnapshot.fromJson(snap.toJson())
        assertEquals(snap, back)
        assertTrue(flowIsoToEpochMs(now)!! > 0)
    }
}
