package net.acumenus.hummingbird.data

import java.time.OffsetDateTime

/**
 * Flow Window models — GET /api/mobile/v1/flow/window + /flow/floors.
 * The 48h time model: [now−24h, now+24h]; past = snapshots + events (solid),
 * future = projections (ghost rendering, confidence-mapped).
 * Interim hand-parsed DTOs in the existing ApiClient style (pre-P1.4 codegen).
 */

/** ISO-8601 offset timestamp → epoch millis (null when absent/unparseable). */
fun flowIsoToEpochMs(iso: String?): Long? =
    if (iso.isNullOrBlank()) null
    else runCatching { OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrNull()

data class FlowWindowRange(
    val from: String,
    val to: String,
    val now: String,
)

data class FlowLens(
    val roleId: String,
    val scopesAllowed: List<String>,
    val layers: List<String>,
    val eventKinds: List<String>,
    val projectionKinds: List<String>,
    val patientDots: String,
    val actions: List<String>,
    val defaultZoomHours: Int,
)

data class FlowScope(
    val type: String,
    val floor: Int?,
    val unitId: Int?,
    val patientContextRef: String?,
    val label: String,
)

data class FlowUnitRollup(
    val unitId: Int,
    val abbr: String,
    val name: String,
    val staffed: Int,
    val occupied: Int,
    val available: Int,
    val blocked: Int,
    val occupancyPct: Int,
)

data class FlowFloorRollup(
    val floor: Int,
    val label: String,
    val staffed: Int,
    val occupied: Int,
    val occupancyPct: Int,
    val units: List<FlowUnitRollup>,
)

data class FlowSnapshot(
    val t: String,
    val unitId: Int,
    val staffed: Int,
    val occupied: Int,
    val available: Int,
    val blocked: Int,
) {
    val tMs: Long by lazy { flowIsoToEpochMs(t) ?: 0L }
}

data class FlowTimelineEvent(
    val t: String,
    val kind: String,
    val label: String,
    val tier: String,
    val unitId: Int?,
    val fromSpace: String?,
    val toSpace: String?,
    val patientContextRef: String?,
    val provenanceSource: String,
) {
    val tMs: Long by lazy { flowIsoToEpochMs(t) ?: 0L }
}

data class FlowProjection(
    val t: String,
    val kind: String,
    val confidence: String,
    val label: String,
    val unitId: Int?,
    val bedId: Int?,
    val value: Int?,
    val bandLower: Int?,
    val bandUpper: Int?,
    val endsAt: String?,
    val derived: Boolean,
    val patientContextRef: String?,
    val provenanceService: String,
    val provenanceReliability: Double?,
) {
    val tMs: Long by lazy { flowIsoToEpochMs(t) ?: 0L }

    /** Ghost grammar: definite 0.8, probable 0.5, possible 0.3 — never solid. */
    val confidenceAlpha: Float
        get() = when (confidence) {
            "definite" -> 0.8f
            "probable" -> 0.5f
            else -> 0.3f
        }
}

data class FlowWindowData(
    val window: FlowWindowRange,
    val lens: FlowLens,
    val scope: FlowScope,
    val spacesFloors: List<FlowFloorRollup>,
    val snapshots: List<FlowSnapshot>,
    val events: List<FlowTimelineEvent>,
    val projections: List<FlowProjection>,
) {
    val fromMs: Long by lazy { flowIsoToEpochMs(window.from) ?: 0L }
    val toMs: Long by lazy { flowIsoToEpochMs(window.to) ?: 0L }
    val nowMs: Long by lazy { flowIsoToEpochMs(window.now) ?: 0L }
}

/** One drawable plate on a floor: unit/zone, room/bay, bed, corridor, vertical_transport. */
data class FlowPlate(
    val id: Int,
    val code: String,
    val category: String,
    val label: String,
    /** [x, y, w, h] in plan-view feet, top-left origin. */
    val rect: List<Double>,
    val unitId: Int?,
    val bedId: Int?,
)

data class FlowFloor(
    val floor: Int,
    val label: String,
    /** [x, y, w, h] envelope of the floor in plan-view feet. */
    val bounds: List<Double>,
    val spaces: List<FlowPlate>,
)

data class FlowFloorsDocument(
    val version: String,
    val floors: List<FlowFloor>,
)
