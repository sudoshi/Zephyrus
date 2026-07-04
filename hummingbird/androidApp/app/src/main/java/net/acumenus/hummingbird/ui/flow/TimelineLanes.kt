package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowTimelineEvent
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.abs

/** One persona-filtered lane: which event kinds it replays, which projection kinds it ghosts. */
private data class FlowLaneSpec(
    val label: String,
    val eventKinds: Set<String>,
    val projectionKinds: Set<String>,
)

/**
 * Charge-nurse lanes per §8 P3: Admits+Transfers / Discharges (+ expected_discharge
 * ghosts) / Barriers / Turns+Trips. Bed manager / house supervisor (§8 P5) adds
 * the Placements lane — where demand lands over time.
 */
private fun lanesFor(personaId: String): List<FlowLaneSpec> {
    val base = listOf(
        FlowLaneSpec(
            "Admits · Transfers",
            setOf("admit", "transfer", "ed_arrival", "ed_admit_decision"),
            setOf("predicted_arrivals"),
        ),
        FlowLaneSpec(
            "Discharges",
            setOf("discharge"),
            setOf("expected_discharge"),
        ),
        FlowLaneSpec(
            "Barriers",
            setOf("barrier_opened", "barrier_resolved"),
            emptySet(),
        ),
        FlowLaneSpec(
            "Turns · Trips",
            setOf("evs_status", "transport_status"),
            setOf("evs_due", "transport_due"),
        ),
    )
    return if (personaId == "bed_manager" || personaId == "house_supervisor") {
        // Events only — predicted_census/surge are curve material, not lane
        // dots (288 census steps would drown the lane), matching iOS.
        base + FlowLaneSpec(
            "Placements",
            setOf("bed_request", "placement"),
            emptySet(),
        )
    } else {
        base
    }
}

private fun eventDotColor(tier: String): Color = when (tier) {
    "critical" -> Z.statusCritical // red only for real breaches
    "warning" -> Z.statusWarning // amber is earned
    else -> Z.primary
}

/**
 * Horizontal lanes aligned to the Chronobar's time range. Past events are solid
 * dots; projections are dashed outlined rings at confidence alpha — the ghost
 * grammar, never solid, never a status color.
 */
@Composable
fun TimelineLanes(
    fromMs: Long,
    toMs: Long,
    nowMs: Long,
    events: List<FlowTimelineEvent>,
    projections: List<FlowProjection>,
    personaId: String,
    selection: FlowSelection?,
    onSelect: (FlowSelection?) -> Unit,
    modifier: Modifier = Modifier,
) {
    val span = (toMs - fromMs).coerceAtLeast(1L)
    val lanes = lanesFor(personaId)
    val density = LocalDensity.current
    val tapSlopPx = with(density) { 22.dp.toPx() }
    val select by rememberUpdatedState(onSelect)

    Column(modifier = modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        lanes.forEach { lane ->
            val laneEvents = events.filter { it.kind in lane.eventKinds }
            val laneGhosts = projections.filter { it.kind in lane.projectionKinds }

            Row(Modifier.fillMaxWidth().height(26.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    lane.label,
                    color = Z.inkMuted,
                    fontSize = 9.sp,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.width(96.dp),
                )
                Canvas(
                    Modifier
                        .weight(1f)
                        .fillMaxSize()
                        .pointerInput(fromMs, span, laneEvents, laneGhosts) {
                            detectTapGestures { pos ->
                                fun xOf(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * size.width
                                val eventHit = laneEvents.minByOrNull { abs(xOf(it.tMs) - pos.x) }
                                val ghostHit = laneGhosts.minByOrNull { abs(xOf(it.tMs) - pos.x) }
                                val eventDist = eventHit?.let { abs(xOf(it.tMs) - pos.x) } ?: Float.MAX_VALUE
                                val ghostDist = ghostHit?.let { abs(xOf(it.tMs) - pos.x) } ?: Float.MAX_VALUE
                                select(
                                    when {
                                        eventDist <= ghostDist && eventDist <= tapSlopPx ->
                                            eventHit?.let { FlowSelection.Event(it) }
                                        ghostDist <= tapSlopPx -> ghostHit?.let { FlowSelection.Ghost(it) }
                                        else -> null
                                    },
                                )
                            }
                        },
                ) {
                    val cy = size.height / 2f
                    fun x(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * size.width

                    // Lane baseline + now tick.
                    drawLine(
                        color = Z.border.copy(alpha = 0.5f),
                        start = Offset(0f, cy),
                        end = Offset(size.width, cy),
                        strokeWidth = 1.dp.toPx(),
                    )
                    val xNow = x(nowMs)
                    drawLine(
                        color = Z.border,
                        start = Offset(xNow, 2.dp.toPx()),
                        end = Offset(xNow, size.height - 2.dp.toPx()),
                        strokeWidth = 1.dp.toPx(),
                    )

                    // Past events: solid dots, tier-colored (amber earned, red only critical).
                    laneEvents.forEach { event ->
                        val center = Offset(x(event.tMs), cy)
                        drawCircle(color = eventDotColor(event.tier), radius = 4.dp.toPx(), center = center)
                        if (isSelectedEvent(selection, event)) {
                            drawCircle(
                                color = Z.gold,
                                radius = 7.dp.toPx(),
                                center = center,
                                style = Stroke(width = 1.5.dp.toPx()),
                            )
                        }
                    }

                    // Projections: dashed outlined rings, ink at confidence alpha.
                    val ghostDash = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 2.5.dp.toPx()))
                    laneGhosts.forEach { ghost ->
                        val center = Offset(x(ghost.tMs), cy)
                        drawCircle(
                            color = Z.ink.copy(alpha = ghost.confidenceAlpha),
                            radius = 4.5.dp.toPx(),
                            center = center,
                            style = Stroke(width = 1.5.dp.toPx(), pathEffect = ghostDash),
                        )
                        if (isSelectedGhost(selection, ghost)) {
                            drawCircle(
                                color = Z.gold,
                                radius = 7.5.dp.toPx(),
                                center = center,
                                style = Stroke(width = 1.5.dp.toPx()),
                            )
                        }
                    }
                }
            }
        }
    }
}

private fun isSelectedEvent(selection: FlowSelection?, event: FlowTimelineEvent): Boolean =
    selection is FlowSelection.Event && selection.event == event

private fun isSelectedGhost(selection: FlowSelection?, ghost: FlowProjection): Boolean =
    selection is FlowSelection.Ghost && selection.projection == ghost
