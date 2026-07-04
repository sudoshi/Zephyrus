package net.acumenus.hummingbird.ui.flow

import net.acumenus.hummingbird.data.FlowFloorRollup
import net.acumenus.hummingbird.data.FlowLens
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowScope
import net.acumenus.hummingbird.data.FlowSnapshot
import net.acumenus.hummingbird.data.FlowTimelineEvent
import net.acumenus.hummingbird.data.FlowUnitRollup
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.data.FlowWindowRange
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.ZoneId

/**
 * Phase 3 aggregate-lens pure logic: curve series, snapshot replay rollups,
 * staffing gap tinting, discharge-leverage ranking, and the PI clip payload.
 */
class AggregateLensLogicTest {

    // Window: from 2026-07-03T12:00Z, now 2026-07-04T12:00Z, to 2026-07-05T12:00Z.
    private val fromIso = "2026-07-03T12:00:00+00:00"
    private val nowIso = "2026-07-04T12:00:00+00:00"
    private val toIso = "2026-07-05T12:00:00+00:00"

    private fun ms(iso: String): Long = net.acumenus.hummingbird.data.flowIsoToEpochMs(iso)!!

    private fun unit(id: Int, abbr: String, staffed: Int, occupied: Int) = FlowUnitRollup(
        unitId = id,
        abbr = abbr,
        name = abbr,
        staffed = staffed,
        occupied = occupied,
        available = staffed - occupied,
        blocked = 0,
        occupancyPct = if (staffed > 0) occupied * 100 / staffed else 0,
    )

    private fun snapshot(iso: String, unitId: Int, occupied: Int, staffed: Int = 10) = FlowSnapshot(
        t = iso,
        unitId = unitId,
        staffed = staffed,
        occupied = occupied,
        available = staffed - occupied,
        blocked = 0,
    )

    private fun projection(
        iso: String,
        kind: String,
        unitId: Int? = null,
        value: Int? = null,
        bandLower: Int? = null,
        bandUpper: Int? = null,
        confidence: String = "probable",
        label: String = kind,
        patientContextRef: String? = null,
    ) = FlowProjection(
        t = iso,
        kind = kind,
        confidence = confidence,
        label = label,
        unitId = unitId,
        bedId = null,
        room = null,
        value = value,
        bandLower = bandLower,
        bandUpper = bandUpper,
        endsAt = null,
        derived = false,
        patientContextRef = patientContextRef,
        provenanceService = "demand_forecast",
        provenanceReliability = 0.86,
    )

    private fun event(iso: String, kind: String) = FlowTimelineEvent(
        t = iso,
        kind = kind,
        label = kind,
        tier = "info",
        unitId = 1,
        fromSpace = null,
        toSpace = null,
        patientContextRef = null,
        provenanceSource = "prod.operational_events",
    )

    private fun window(
        snapshots: List<FlowSnapshot> = emptyList(),
        events: List<FlowTimelineEvent> = emptyList(),
        projections: List<FlowProjection> = emptyList(),
        webLink: String? = null,
    ) = FlowWindowData(
        window = FlowWindowRange(from = fromIso, to = toIso, now = nowIso),
        lens = FlowLens(
            roleId = "capacity_lead",
            scopesAllowed = listOf("house"),
            layers = listOf("snapshots", "projections", "spaces"),
            eventKinds = emptyList(),
            projectionKinds = emptyList(),
            patientDots = "none",
            actions = emptyList(),
            defaultZoomHours = 48,
        ),
        scope = FlowScope(type = "house", floor = null, unitId = null, patientContextRef = null, label = "House"),
        spacesFloors = listOf(
            FlowFloorRollup(
                floor = 3,
                label = "Floor 3",
                staffed = 30,
                occupied = 24,
                occupancyPct = 80,
                units = listOf(unit(1, "MICU", 10, 9), unit(2, "TEL3", 20, 15)),
            ),
            FlowFloorRollup(
                floor = 4,
                label = "Floor 4",
                staffed = 20,
                occupied = 12,
                occupancyPct = 60,
                units = listOf(unit(3, "MED4", 20, 12)),
            ),
        ),
        snapshots = snapshots,
        events = events,
        projections = projections,
        webLink = webLink,
    )

    // MARK: FlowCurve series

    @Test
    fun occupancySeriesSumsUnitsForHouseAndFiltersForUnit() {
        val win = window(
            snapshots = listOf(
                snapshot("2026-07-04T08:00:00+00:00", unitId = 1, occupied = 7),
                snapshot("2026-07-04T08:00:00+00:00", unitId = 2, occupied = 12),
                snapshot("2026-07-04T09:00:00+00:00", unitId = 1, occupied = 8),
            ),
        )

        val house = occupancySeries(win)
        assertEquals(2, house.size)
        assertEquals(19, house.first().value) // 7 + 12 at 08:00
        assertEquals(8, house.last().value) // only unit 1 checkpointed at 09:00

        val unitOnly = occupancySeries(win, setOf(1))
        assertEquals(listOf(7, 8), unitOnly.map { it.value })
    }

    @Test
    fun predictedSeriesPrefersHouseRowsAndSumsPerUnitFallback() {
        val houseRow = projection("2026-07-04T14:00:00+00:00", "predicted_census", unitId = null, value = 40, bandLower = 37, bandUpper = 43)
        val unitRows = listOf(
            projection("2026-07-04T14:00:00+00:00", "predicted_census", unitId = 1, value = 9, bandLower = 8, bandUpper = 10),
            projection("2026-07-04T14:00:00+00:00", "predicted_census", unitId = 2, value = 16, bandLower = 15, bandUpper = 18),
        )

        // House-level rows win at house scope.
        val withHouse = predictedSeries(window(projections = listOf(houseRow) + unitRows))
        assertEquals(1, withHouse.size)
        assertEquals(40, withHouse.first().value)

        // Per-unit-only payload: units are summed per 2h step.
        val summed = predictedSeries(window(projections = unitRows))
        assertEquals(25, summed.first().value)
        assertEquals(23, summed.first().bandLower)
        assertEquals(28, summed.first().bandUpper)

        // Unit filter keeps the client-side scope selection honest.
        val filtered = predictedSeries(window(projections = listOf(houseRow) + unitRows), setOf(2))
        assertEquals(listOf(16), filtered.map { it.value })
    }

    @Test
    fun staffedCapacityRespectsUnitFilter() {
        val win = window()
        assertEquals(50, staffedCapacity(win))
        assertEquals(20, staffedCapacity(win, setOf(2)))
    }

    // MARK: executive / PI replay

    @Test
    fun floorsAtRebuildsFloorHeatFromSnapshots() {
        val win = window(
            snapshots = listOf(
                snapshot("2026-07-04T02:00:00+00:00", unitId = 1, occupied = 2, staffed = 10),
                snapshot("2026-07-04T06:00:00+00:00", unitId = 1, occupied = 5, staffed = 10),
            ),
        )
        val t = ms("2026-07-04T04:00:00+00:00")

        val floors = floorsAt(win, t)
        val floor3 = floors.first { it.floor == 3 }
        val micu = floor3.units.first { it.unitId == 1 }
        assertEquals(2, micu.occupied) // 02:00 checkpoint, not the 06:00 one
        // Units without a checkpoint at t keep their live rollup.
        assertEquals(15, floor3.units.first { it.unitId == 2 }.occupied)
        assertEquals(17, floor3.occupied)

        // At/after now the live rollups win untouched.
        assertEquals(win.spacesFloors, floorsAt(win, win.nowMs))
    }

    @Test
    fun arrivalsNext24hTotalOnlyCountsTheFutureHalf() {
        val win = window(
            projections = listOf(
                projection("2026-07-04T10:00:00+00:00", "predicted_arrivals", unitId = 24, value = 9), // past — excluded
                projection("2026-07-04T13:00:00+00:00", "predicted_arrivals", unitId = 24, value = 2),
                projection("2026-07-04T14:00:00+00:00", "predicted_arrivals", unitId = 24, value = 3),
            ),
        )
        assertEquals(5, arrivalsNext24hTotal(win))
    }

    // MARK: staffing lens

    @Test
    fun worstGapByFloorMapsUnitsThroughTheFloorsPayload() {
        val win = window(
            projections = listOf(
                projection("2026-07-04T19:00:00+00:00", "staffing_shift_gap", unitId = 1, value = 2),
                projection("2026-07-04T19:00:00+00:00", "staffing_shift_gap", unitId = 2, value = 3),
                projection("2026-07-05T07:00:00+00:00", "staffing_shift_gap", unitId = 3, value = 0), // covered — no tint
                projection("2026-07-05T07:00:00+00:00", "staffing_shift_gap", unitId = 99, value = 4), // unknown unit — dropped
            ),
        )
        val worst = worstGapByFloor(win)
        assertEquals(mapOf(3 to 3), worst)
        assertFalse(worst.containsKey(4))
    }

    // MARK: discharge leverage

    @Test
    fun dischargeLeverageRanksConfidenceThenTime() {
        val possibleEarly = projection("2026-07-04T13:00:00+00:00", "expected_discharge", confidence = "possible", label = "A")
        val definiteLate = projection("2026-07-04T18:00:00+00:00", "expected_discharge", confidence = "definite", label = "B", patientContextRef = "ptok_b")
        val probableMid = projection("2026-07-04T15:00:00+00:00", "expected_discharge", confidence = "probable", label = "C")
        val definiteEarly = projection("2026-07-04T14:00:00+00:00", "expected_discharge", confidence = "definite", label = "D", patientContextRef = "ptok_d")
        val notADischarge = projection("2026-07-04T13:30:00+00:00", "transport_due", confidence = "definite", label = "E")

        val ranked = dischargeLeverageRows(listOf(possibleEarly, definiteLate, probableMid, definiteEarly, notADischarge))
        assertEquals(listOf("D", "B", "C", "A"), ranked.map { it.label })
    }

    // MARK: PI clip

    @Test
    fun clipSummaryCarriesScopeRangeOccupancyDeltaAndEventCounts() {
        val win = window(
            snapshots = listOf(
                snapshot("2026-07-03T12:00:00+00:00", unitId = 1, occupied = 4),
                snapshot("2026-07-04T10:00:00+00:00", unitId = 1, occupied = 9),
            ),
            events = listOf(
                event("2026-07-03T15:00:00+00:00", "admit"),
                event("2026-07-03T16:00:00+00:00", "admit"),
                event("2026-07-04T01:00:00+00:00", "evs_status"),
                event("2026-07-04T13:00:00+00:00", "admit"), // after the clip range
            ),
            webLink = "http://localhost/rtdc/patient-flow-navigator?persona=pi_lead",
        )

        val summary = clipSummary(win, win.fromMs, ms("2026-07-04T11:00:00+00:00"), zone = ZoneId.of("UTC"))
        assertTrue(summary.contains("House"))
        assertTrue(summary.contains("Occupancy 4 → 9 (+5)"))
        assertTrue(summary.contains("admit 2"))
        assertTrue(summary.contains("evs_status 1"))
        assertFalse(summary.contains("admit 3"))
        assertTrue(summary.contains("&from=2026-07-03T12%3A00"))
        assertTrue(summary.contains("&to=2026-07-04T11%3A00"))
    }

    @Test
    fun clipWebLinkAppendsRangeWithCorrectJoiner() {
        val bare = window(webLink = "http://localhost/nav")
        val linked = clipWebLink(bare, bare.fromMs, bare.nowMs)!!
        assertTrue(linked.startsWith("http://localhost/nav?from="))
        assertTrue(linked.contains("&to="))

        val withQuery = window(webLink = "http://localhost/nav?persona=pi_lead")
        assertTrue(clipWebLink(withQuery, withQuery.fromMs, withQuery.nowMs)!!.contains("?persona=pi_lead&from="))

        assertNull(clipWebLink(window(webLink = null), 0L, 1L))
    }

    @Test
    fun piReplayDurationIsFourHoursPerSecond() {
        val win = window()
        assertEquals(6_000L, piReplayDurationMs(win.fromMs, win.nowMs)) // 24h at 4h/s
    }
}
