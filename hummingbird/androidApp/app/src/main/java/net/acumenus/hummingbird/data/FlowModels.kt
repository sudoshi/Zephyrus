package net.acumenus.hummingbird.data

import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter

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

/** Epoch millis → ISO-8601 UTC offset string (the `?since=` param the delta path sends). */
fun flowEpochMsToIso(ms: Long): String =
    DateTimeFormatter.ISO_OFFSET_DATE_TIME.format(
        OffsetDateTime.ofInstant(Instant.ofEpochMilli(ms), ZoneOffset.UTC),
    )

data class FlowWindowRange(
    val from: String,
    val to: String,
    val now: String,
    /** `window.since` — the server-echoed parsed delta cursor; null on a full load. */
    val since: String? = null,
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
    /** `entity.ref` (ptok or non-patient ref) — part of the delta dedupe key. */
    val entityRef: String? = null,
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
    /** Room name — populated only on `scheduled_or_case` (e.g. "OR 3"); null elsewhere. */
    val room: String?,
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
    val endsAtMs: Long? by lazy { flowIsoToEpochMs(endsAt) }

    /** Ghost grammar: definite 0.8, probable 0.5, possible 0.3 — never solid. */
    val confidenceAlpha: Float
        get() = when (confidence) {
            "definite" -> 0.8f
            "probable" -> 0.5f
            else -> 0.3f
        }
}

/**
 * Strictly-current bed state for the EVS turn map. Present only when the
 * scope is floor/unit AND the lens allows bed_status events; absent otherwise.
 */
data class FlowBedStatus(
    val bedId: Int,
    val unitId: Int?,
    val label: String,
    /** available | occupied | blocked | dirty */
    val status: String,
)

data class FlowWindowData(
    val window: FlowWindowRange,
    val lens: FlowLens,
    val scope: FlowScope,
    val spacesFloors: List<FlowFloorRollup>,
    val snapshots: List<FlowSnapshot>,
    val events: List<FlowTimelineEvent>,
    val projections: List<FlowProjection>,
    val bedStatuses: List<FlowBedStatus> = emptyList(),
    /** `links.web` — the web Navigator deep link for this scope/t (PI clip target). */
    val webLink: String? = null,
) {
    val fromMs: Long by lazy { flowIsoToEpochMs(window.from) ?: 0L }
    val toMs: Long by lazy { flowIsoToEpochMs(window.to) ?: 0L }
    val nowMs: Long by lazy { flowIsoToEpochMs(window.now) ?: 0L }
}

/** Parsed window plus the raw envelope text, so a FULL load can be cached verbatim. */
data class FlowWindowFetch(
    val data: FlowWindowData,
    val raw: String,
)

data class FlowDemoScenario(
    val key: String,
    val label: String,
    val enabled: Boolean,
    val historySupported: Boolean,
    val sourceMode: String?,
)

data class FlowHistoryWindow(
    val from: String,
    val to: String,
    val limit: Int,
)

data class FlowHistoryLens(
    val roleId: String,
    val patientDots: String,
    val projectionKinds: List<String>,
)

data class FlowHistorySnapshot(
    val snapshotAt: String?,
    val activePatientCount: Int,
    val occupancyDetailCount: Int,
    val timerStatusCounts: Map<String, Int>,
)

data class FlowHistorySummary(
    val snapshots: Int,
    val activePatientCount: Int,
    val sourceMode: String,
    val redacted: Boolean,
)

data class FlowOccupancyHistory(
    val window: FlowHistoryWindow,
    val lens: FlowHistoryLens,
    val scenario: String?,
    val history: List<FlowHistorySnapshot>,
    val summary: FlowHistorySummary,
)

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
