package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.clickable
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowFloor
import net.acumenus.hummingbird.data.FlowFloorsDocument
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin

/** A trip endpoint resolved to the map: a floor plus an optional plan-feet anchor rect. */
data class FlowSpacePoint(val floor: Int, val rect: List<Double>?)

/**
 * One transport trip drawable on the map: a past transport_status event (solid
 * arc) or a transport_due projection still ahead of the scrub time (dashed
 * ghost). Ghost trips from derived discharges have no destination — they render
 * as departure stubs from the origin bed's unit.
 */
data class TransportTrip(
    val selection: FlowSelection,
    val label: String,
    val fromRaw: String?,
    val toRaw: String?,
    val from: FlowSpacePoint?,
    val to: FlowSpacePoint?,
    val ghost: Boolean,
    val alpha: Float,
    /** STAT/at-risk tier → warning tint; NEVER the critical color for trips. */
    val warning: Boolean,
)

/**
 * Resolves the transport from_space/to_space vocabulary against the floors
 * payload: unit abbreviation ("ED") → full unit name ("3 West — Medical ICU
 * (MICU)") → bed label ("MICU-01" = {ABBR}-{NN}: resolve the bed's unit and use
 * that unit's plate centroid). Anything else (free-text destinations like
 * "Main Lobby Discharge") is unresolvable → the off-map gutter.
 */
class FlowSpaceResolver(window: FlowWindowData, floors: FlowFloorsDocument?) {
    private data class UnitRef(val unitId: Int, val floor: Int)

    private val unitsByAbbr: Map<String, UnitRef>
    private val unitsByName: Map<String, UnitRef>
    private val unitPlateRect = mutableMapOf<Int, Pair<Int, List<Double>>>()
    private val bedPlateRect = mutableMapOf<Int, Pair<Int, List<Double>>>()
    private val bedUnit: Map<String, Int> // bed label → unit_id (from bed_statuses when present)

    init {
        val byAbbr = mutableMapOf<String, UnitRef>()
        val byName = mutableMapOf<String, UnitRef>()
        window.spacesFloors.forEach { floor ->
            floor.units.forEach { unit ->
                val ref = UnitRef(unit.unitId, floor.floor)
                if (unit.abbr.isNotBlank()) byAbbr.putIfAbsent(unit.abbr.lowercase(), ref)
                if (unit.name.isNotBlank()) byName.putIfAbsent(unit.name.lowercase(), ref)
            }
        }
        unitsByAbbr = byAbbr
        unitsByName = byName
        floors?.floors?.forEach { floor ->
            floor.spaces.forEach { plate ->
                when {
                    (plate.category == "unit" || plate.category == "zone") && plate.unitId != null ->
                        unitPlateRect.putIfAbsent(plate.unitId, floor.floor to plate.rect)
                    plate.category == "bed" && plate.bedId != null ->
                        bedPlateRect.putIfAbsent(plate.bedId, floor.floor to plate.rect)
                }
            }
        }
        bedUnit = window.bedStatuses
            .filter { it.unitId != null }
            .associate { it.label.lowercase() to it.unitId!! }
    }

    fun resolveSpace(raw: String?): FlowSpacePoint? {
        val s = raw?.trim()?.lowercase().orEmpty()
        if (s.isEmpty()) return null
        unitsByAbbr[s]?.let { return unitPoint(it) }
        unitsByName[s]?.let { return unitPoint(it) }
        bedUnit[s]?.let { unitId -> return resolveUnit(unitId) }
        // Bed label pattern {ABBR}-{NN}: the bed's unit owns the endpoint.
        val match = Regex("^(.+)-(\\d+)$").find(s)
        if (match != null) {
            unitsByAbbr[match.groupValues[1]]?.let { return unitPoint(it) }
        }
        return null
    }

    fun resolveUnit(unitId: Int?): FlowSpacePoint? {
        if (unitId == null) return null
        unitPlateRect[unitId]?.let { (floor, rect) -> return FlowSpacePoint(floor, rect) }
        val ref = unitsByAbbr.values.firstOrNull { it.unitId == unitId }
            ?: unitsByName.values.firstOrNull { it.unitId == unitId }
        return ref?.let { FlowSpacePoint(it.floor, null) }
    }

    fun resolveBed(bedId: Int?): FlowSpacePoint? {
        if (bedId == null) return null
        bedPlateRect[bedId]?.let { (floor, rect) -> return FlowSpacePoint(floor, rect) }
        return null
    }

    private fun unitPoint(ref: UnitRef): FlowSpacePoint {
        unitPlateRect[ref.unitId]?.let { (floor, rect) -> return FlowSpacePoint(floor, rect) }
        return FlowSpacePoint(ref.floor, null)
    }
}

/**
 * The transporter's trips at scrub time t: transport_status events at/before t
 * (the replayed record) plus transport_due projections still ahead of t (ghost
 * trips — dashed, confidence-mapped, provenance on tap).
 */
fun transportTrips(window: FlowWindowData, resolver: FlowSpaceResolver, scrubT: Long): List<TransportTrip> {
    val past = window.events
        .filter { it.kind == "transport_status" && it.tMs <= scrubT }
        .map { event ->
            TransportTrip(
                selection = FlowSelection.Event(event),
                label = event.label,
                fromRaw = event.fromSpace,
                toRaw = event.toSpace,
                from = resolver.resolveSpace(event.fromSpace),
                to = resolver.resolveSpace(event.toSpace),
                ghost = false,
                alpha = 0.85f,
                warning = event.tier == "warning" || event.tier == "critical",
            )
        }
    val ahead = window.projections
        .filter { it.kind == "transport_due" && it.tMs > scrubT }
        .map { ghost ->
            TransportTrip(
                selection = FlowSelection.Ghost(ghost),
                label = ghost.label,
                fromRaw = null,
                toRaw = null,
                from = resolver.resolveBed(ghost.bedId) ?: resolver.resolveUnit(ghost.unitId),
                to = null,
                ghost = true,
                alpha = ghost.confidenceAlpha,
                warning = false,
            )
        }
    return past + ahead
}

/** Trips with a free-text endpoint the map can't place — surfaced in the gutter. */
fun offMapTrips(trips: List<TransportTrip>): List<TransportTrip> =
    trips.filter {
        (it.from == null && !it.fromRaw.isNullOrBlank()) ||
            (it.to == null && !it.toRaw.isNullOrBlank())
    }

private fun tripColor(trip: TransportTrip): Color = when {
    trip.ghost -> Z.ink
    trip.warning -> Z.statusWarning
    else -> Z.primary
}

private fun DrawScope.drawArrowHead(tip: Offset, towards: Offset, color: Color, alpha: Float) {
    val angle = atan2(tip.y - towards.y, tip.x - towards.x)
    val len = 5.dp.toPx()
    listOf(angle + 0.5f, angle - 0.5f).forEach { a ->
        drawLine(
            color = color.copy(alpha = alpha),
            start = tip,
            end = Offset(tip.x - len * cos(a), tip.y - len * sin(a)),
            strokeWidth = 1.5.dp.toPx(),
        )
    }
}

private fun DrawScope.drawTripArc(start: Offset, end: Offset, trip: TransportTrip) {
    val color = tripColor(trip)
    val mid = Offset((start.x + end.x) / 2f, (start.y + end.y) / 2f)
    val lift = 18.dp.toPx() + (end - start).getDistance() * 0.12f
    val control = Offset(mid.x, mid.y - lift)
    val path = Path().apply {
        moveTo(start.x, start.y)
        quadraticBezierTo(control.x, control.y, end.x, end.y)
    }
    val dash = if (trip.ghost) PathEffect.dashPathEffect(floatArrayOf(5.dp.toPx(), 4.dp.toPx())) else null
    drawPath(
        path,
        color = color.copy(alpha = trip.alpha),
        style = Stroke(width = 1.5.dp.toPx(), pathEffect = dash),
    )
    drawCircle(color = color.copy(alpha = trip.alpha), radius = 2.5.dp.toPx(), center = start)
    drawArrowHead(end, control, color, trip.alpha)
}

/** Short curved stub — a trip leaving (or entering) the displayed floor. */
private fun DrawScope.drawTripStub(anchor: Offset, trip: TransportTrip, departing: Boolean) {
    val color = tripColor(trip)
    val dx = 22.dp.toPx()
    val far = Offset(anchor.x + dx, anchor.y - dx)
    val control = Offset(anchor.x + dx * 0.2f, anchor.y - dx)
    val (start, end) = if (departing) anchor to far else far to anchor
    val path = Path().apply {
        moveTo(start.x, start.y)
        quadraticBezierTo(control.x, control.y, end.x, end.y)
    }
    val dash = if (trip.ghost) PathEffect.dashPathEffect(floatArrayOf(5.dp.toPx(), 4.dp.toPx())) else null
    drawPath(
        path,
        color = color.copy(alpha = trip.alpha),
        style = Stroke(width = 1.5.dp.toPx(), pathEffect = dash),
    )
    drawCircle(color = color.copy(alpha = trip.alpha), radius = 2.5.dp.toPx(), center = start)
    drawArrowHead(end, control, color, trip.alpha)
}

/**
 * Same-floor trip arcs drawn over the FloorPlateCanvas (no pointer input — taps
 * fall through to the plates). Trips with one endpoint on this floor render as
 * stubs; ghost trips (no destination) as departure stubs at confidence alpha.
 */
@Composable
fun FloorTripArcsOverlay(
    floor: FlowFloor,
    trips: List<TransportTrip>,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current
    val padPx = with(density) { floorPlatePad.toPx() }
    Canvas(modifier.fillMaxSize()) {
        val transform = FlowPlanTransform(floor.bounds, size, padPx)
        fun anchor(point: FlowSpacePoint?): Offset? =
            point?.takeIf { it.floor == floor.floor }?.rect?.let(transform::center)

        trips.forEach { trip ->
            val start = anchor(trip.from)
            val end = anchor(trip.to)
            when {
                start != null && end != null -> drawTripArc(start, end, trip)
                start != null -> drawTripStub(start, trip, departing = true)
                end != null -> drawTripStub(end, trip, departing = false)
            }
        }
    }
}

/** A trip reduced to floors for the house stack; fromFloor == toFloor renders as a stub. */
data class HouseTripArc(
    val fromFloor: Int,
    val toFloor: Int,
    val color: Color,
    val dashed: Boolean,
    val alpha: Float,
)

/** House-stack arcs: cross-floor trips, plus same-/single-floor trips as stubs. */
fun houseTripArcs(trips: List<TransportTrip>): List<HouseTripArc> =
    trips.mapNotNull { trip ->
        val from = trip.from?.floor
        val to = trip.to?.floor
        val a = from ?: to ?: return@mapNotNull null
        HouseTripArc(
            fromFloor = a,
            toFloor = to ?: a,
            color = tripColor(trip),
            dashed = trip.ghost,
            alpha = trip.alpha,
        )
    }

/**
 * The "Off-map" gutter: trips whose endpoint is free text the map can't place
 * ("Main Lobby Discharge", SNFs, home health) listed as tappable chips.
 */
@Composable
fun OffMapTripGutter(
    trips: List<TransportTrip>,
    onSelect: (FlowSelection) -> Unit,
    modifier: Modifier = Modifier,
) {
    if (trips.isEmpty()) return
    Row(
        modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp)
            .horizontalScroll(rememberScrollState()),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("Off-map", color = Z.inkMuted, fontSize = 10.sp)
        trips.forEach { trip ->
            val text = when {
                trip.from == null && !trip.fromRaw.isNullOrBlank() && trip.to == null && !trip.toRaw.isNullOrBlank() ->
                    "${trip.fromRaw} → ${trip.toRaw}"
                trip.to == null && !trip.toRaw.isNullOrBlank() -> "→ ${trip.toRaw}"
                else -> "${trip.fromRaw} →"
            }
            Box(
                Modifier
                    .heightIn(min = 48.dp)
                    .clickable { onSelect(trip.selection) },
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    text,
                    color = Z.inkMuted,
                    fontSize = 11.sp,
                    maxLines = 1,
                    modifier = Modifier
                        .panel(corner = 8)
                        .padding(horizontal = 8.dp, vertical = 5.dp),
                )
            }
        }
    }
}
