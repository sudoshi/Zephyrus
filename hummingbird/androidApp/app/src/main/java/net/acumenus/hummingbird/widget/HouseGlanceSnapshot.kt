package net.acumenus.hummingbird.widget

import net.acumenus.hummingbird.data.AltitudeHome
import net.acumenus.hummingbird.data.FlowWindowData
import org.json.JSONObject
import kotlin.math.roundToInt

/**
 * The compact house-glance the app writes on every altitude-home / flow-window load and the
 * Glance widget reads back. Every metric is nullable so a partial load only updates what it
 * knows; [merge] overlays fresh non-null fields onto the last snapshot. Pure + JSON-only so
 * the derivation is testable without Android.
 */
data class HouseGlanceSnapshot(
    val occupancyPct: Int? = null,
    val netBedNeed: Int? = null,
    val forYouCount: Int? = null,
    val next4hGhostCount: Int? = null,
    val updatedAtMs: Long = 0L,
) {
    val isEmpty: Boolean
        get() = occupancyPct == null && netBedNeed == null && forYouCount == null && next4hGhostCount == null

    fun toJson(): String = JSONObject().apply {
        occupancyPct?.let { put("occupancy_pct", it) }
        netBedNeed?.let { put("net_bed_need", it) }
        forYouCount?.let { put("for_you_count", it) }
        next4hGhostCount?.let { put("next_4h_ghost_count", it) }
        put("updated_at_ms", updatedAtMs)
    }.toString()

    companion object {
        private const val FOUR_HOURS_MS = 4 * 60 * 60 * 1000L

        fun fromJson(text: String): HouseGlanceSnapshot? = runCatching {
            val o = JSONObject(text)
            HouseGlanceSnapshot(
                occupancyPct = o.intOrNull("occupancy_pct"),
                netBedNeed = o.intOrNull("net_bed_need"),
                forYouCount = o.intOrNull("for_you_count"),
                next4hGhostCount = o.intOrNull("next_4h_ghost_count"),
                updatedAtMs = o.optLong("updated_at_ms", 0L),
            )
        }.getOrNull()

        private fun JSONObject.intOrNull(key: String): Int? = if (has(key) && !isNull(key)) optInt(key) else null

        /** Overlay [partial]'s known fields onto [base]; stamps [nowMs] as the update time. */
        fun merge(base: HouseGlanceSnapshot?, partial: HouseGlanceSnapshot, nowMs: Long): HouseGlanceSnapshot =
            HouseGlanceSnapshot(
                occupancyPct = partial.occupancyPct ?: base?.occupancyPct,
                netBedNeed = partial.netBedNeed ?: base?.netBedNeed,
                forYouCount = partial.forYouCount ?: base?.forYouCount,
                next4hGhostCount = partial.next4hGhostCount ?: base?.next4hGhostCount,
                updatedAtMs = nowMs,
            )

        /**
         * Flow-window derived slice: occupancy from the floor rollups and the count of
         * projection ghosts landing in the next 4h. Net bed need isn't in the window rollup,
         * so it is left for a home load to supply.
         */
        fun fromFlow(window: FlowWindowData): HouseGlanceSnapshot {
            val staffed = window.spacesFloors.sumOf { it.staffed }
            val occupied = window.spacesFloors.sumOf { it.occupied }
            val occupancy = if (staffed > 0) (occupied * 100.0 / staffed).roundToInt() else null
            val horizon = window.nowMs + FOUR_HOURS_MS
            val next4h = window.projections.count { it.tMs in window.nowMs..horizon }
            return HouseGlanceSnapshot(occupancyPct = occupancy, next4hGhostCount = next4h)
        }

        /**
         * Altitude-home derived slice: For You head count, plus occupancy / net bed need
         * scraped from any matching glance tile value.
         */
        fun fromHome(home: AltitudeHome): HouseGlanceSnapshot = HouseGlanceSnapshot(
            occupancyPct = tileValue(home, "occup"),
            netBedNeed = tileValue(home, "bed_need") ?: tileValue(home, "net_bed"),
            forYouCount = home.forYouHead.size,
        )

        private fun tileValue(home: AltitudeHome, keyFragment: String): Int? =
            home.tiles.firstOrNull { it.key.contains(keyFragment, ignoreCase = true) }
                ?.let { firstSignedInt(it.value) }

        /** First signed integer embedded in a tile value like "84%" or "+3 beds". */
        private fun firstSignedInt(value: String): Int? =
            Regex("-?\\d+").find(value)?.value?.toIntOrNull()
    }
}
