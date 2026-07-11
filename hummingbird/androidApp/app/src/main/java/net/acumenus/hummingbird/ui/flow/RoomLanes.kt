package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.drawText
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowTimelineEvent
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.components.formatOperationalMinutes
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.abs

private const val PACU_ROOM = "PACU"
private const val DEFAULT_CASE_MS = 60 * 60_000L

/** One scheduled case on a room lane, cascade-adjusted when the room runs long. */
data class RoomLaneCase(
    val projection: FlowProjection,
    val scheduledStartMs: Long,
    /** Cascade-adjusted start: max(scheduled, previous case's end). */
    val startMs: Long,
    val endMs: Long,
    val driftMin: Long,
)

data class RoomLane(
    val room: String,
    val cases: List<RoomLaneCase>,
    val milestones: List<FlowTimelineEvent>,
)

/** "OR 2" before "OR 10": compare by prefix, then trailing number. */
private val roomOrder = compareBy<String>(
    { it.replace(Regex("\\d+$"), "").trim() },
    { Regex("(\\d+)$").find(it)?.groupValues?.get(1)?.toIntOrNull() ?: 0 },
)

/**
 * Room lanes for the OR lenses: scheduled_or_case grouped by `room`,
 * or_milestone events by `to_space`; PACU milestones collect into a bottom row.
 * Within a room, a case overlapping its predecessor's end is pushed to start
 * at that end — the delay cascade — and carries the drift in minutes.
 */
fun buildRoomLanes(window: FlowWindowData): List<RoomLane> {
    val casesByRoom = window.projections
        .filter { it.kind == "scheduled_or_case" && !it.room.isNullOrBlank() }
        .groupBy { it.room!!.trim() }
    val milestonesByRoom = window.events
        .filter { it.kind == "or_milestone" && !it.toSpace.isNullOrBlank() }
        .groupBy { it.toSpace!!.trim() }

    val rooms = (casesByRoom.keys + milestonesByRoom.keys)
        .filterNot { it.equals(PACU_ROOM, ignoreCase = true) }
        .distinct()
        .sortedWith(roomOrder)

    val lanes = rooms.map { room ->
        val scheduled = (casesByRoom[room] ?: emptyList()).sortedBy { it.tMs }
        val cases = mutableListOf<RoomLaneCase>()
        var previousEnd = Long.MIN_VALUE
        scheduled.forEach { case ->
            val duration = (case.endsAtMs?.minus(case.tMs))?.takeIf { it > 0 } ?: DEFAULT_CASE_MS
            val start = maxOf(case.tMs, previousEnd)
            val end = start + duration
            cases += RoomLaneCase(
                projection = case,
                scheduledStartMs = case.tMs,
                startMs = start,
                endMs = end,
                driftMin = ((start - case.tMs) / 60_000L).coerceAtLeast(0L),
            )
            previousEnd = end
        }
        RoomLane(room, cases, (milestonesByRoom[room] ?: emptyList()).sortedBy { it.tMs })
    }

    val pacuMilestones = milestonesByRoom.entries
        .firstOrNull { it.key.equals(PACU_ROOM, ignoreCase = true) }
        ?.value.orEmpty().sortedBy { it.tMs }
    return if (pacuMilestones.isEmpty()) lanes else lanes + RoomLane(PACU_ROOM, emptyList(), pacuMilestones)
}

/**
 * The OR lenses' primary layer: one lane per room aligned to the Chronobar's
 * 48h domain. Case bars are solid once started, dashed ghosts when still ahead;
 * milestone ticks carry their labels via the tap → selection strip; drift shows
 * as a "+Xm" chip at the pushed case's start.
 */
@Composable
fun RoomLanes(
    window: FlowWindowData,
    lanes: List<RoomLane>,
    highlightRoom: String?,
    selection: FlowSelection?,
    onSelect: (FlowSelection?) -> Unit,
    modifier: Modifier = Modifier,
) {
    val fromMs = window.fromMs
    val span = (window.toMs - window.fromMs).coerceAtLeast(1L)
    val nowMs = window.nowMs
    val density = LocalDensity.current
    val tapSlopPx = with(density) { 22.dp.toPx() }
    val textMeasurer = rememberTextMeasurer()
    val select by rememberUpdatedState(onSelect)

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        if (lanes.isEmpty()) {
            Text(
                "No cases or milestones in this window yet.",
                color = Z.inkMuted,
                fontSize = 12.sp,
            )
            return@Column
        }
        lanes.forEach { lane ->
            val highlighted = highlightRoom != null && lane.room.equals(highlightRoom, ignoreCase = true)
            val dimmed = highlightRoom != null && !highlighted
            Row(
                Modifier
                    .fillMaxWidth()
                    .height(48.dp)
                    .background(
                        if (highlighted) Z.primary.copy(alpha = 0.10f) else Z.bg.copy(alpha = 0f),
                        RoundedCornerShape(6.dp),
                    ),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    lane.room,
                    color = if (highlighted) Z.ink else Z.inkMuted,
                    fontSize = 11.sp,
                    fontWeight = if (highlighted) FontWeight.SemiBold else FontWeight.Medium,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.width(56.dp),
                    style = flowTabularNums,
                )
                Canvas(
                    Modifier
                        .weight(1f)
                        .fillMaxSize()
                        .pointerInput(fromMs, span, lane) {
                            detectTapGestures { pos ->
                                fun xOf(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * size.width
                                val tickHit = lane.milestones.minByOrNull { abs(xOf(it.tMs) - pos.x) }
                                val tickDist = tickHit?.let { abs(xOf(it.tMs) - pos.x) } ?: Float.MAX_VALUE
                                val barHit = lane.cases.firstOrNull { case ->
                                    pos.x in (xOf(case.startMs) - tapSlopPx / 2)..(xOf(case.endMs) + tapSlopPx / 2)
                                }
                                select(
                                    when {
                                        tickDist <= tapSlopPx -> tickHit?.let { FlowSelection.Event(it) }
                                        barHit != null -> FlowSelection.Ghost(barHit.projection)
                                        else -> null
                                    },
                                )
                            }
                        },
                ) {
                    val laneAlpha = if (dimmed) 0.35f else 1f
                    val cy = size.height / 2f
                    fun x(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * size.width

                    drawLine(
                        color = Z.border.copy(alpha = 0.5f * laneAlpha),
                        start = Offset(0f, cy),
                        end = Offset(size.width, cy),
                        strokeWidth = 1.dp.toPx(),
                    )
                    val xNow = x(nowMs)
                    drawLine(
                        color = Z.border.copy(alpha = laneAlpha),
                        start = Offset(xNow, 3.dp.toPx()),
                        end = Offset(xNow, size.height - 3.dp.toPx()),
                        strokeWidth = 1.dp.toPx(),
                    )

                    val barHeight = 16.dp.toPx()
                    val corner = CornerRadius(4.dp.toPx())
                    val ghostDash = PathEffect.dashPathEffect(floatArrayOf(5.dp.toPx(), 4.dp.toPx()))
                    lane.cases.forEach { case ->
                        val left = x(case.startMs)
                        val right = x(case.endMs).coerceAtLeast(left + 6.dp.toPx())
                        val top = cy - barHeight / 2f
                        val barSize = Size(right - left, barHeight)
                        if (case.startMs <= nowMs) {
                            // Started (or should have): the record half — solid.
                            drawRoundRect(
                                color = Z.primary.copy(alpha = 0.30f * laneAlpha),
                                topLeft = Offset(left, top),
                                size = barSize,
                                cornerRadius = corner,
                            )
                            drawRoundRect(
                                color = Z.primary.copy(alpha = 0.9f * laneAlpha),
                                topLeft = Offset(left, top),
                                size = barSize,
                                cornerRadius = corner,
                                style = Stroke(width = 1.dp.toPx()),
                            )
                        } else {
                            // Still ahead: ghost grammar — dashed outline, never a solid fill.
                            drawRoundRect(
                                color = Z.ink.copy(alpha = case.projection.confidenceAlpha * laneAlpha),
                                topLeft = Offset(left, top),
                                size = barSize,
                                cornerRadius = corner,
                                style = Stroke(width = 1.5.dp.toPx(), pathEffect = ghostDash),
                            )
                        }
                        if (case.driftMin > 0) {
                            drawText(
                                textMeasurer = textMeasurer,
                                text = "+${formatOperationalMinutes(case.driftMin, compact = true)}",
                                topLeft = Offset(left, top - 11.dp.toPx()),
                                style = TextStyle(
                                    color = Z.statusWarning.copy(alpha = laneAlpha),
                                    fontSize = 8.sp,
                                    fontWeight = FontWeight.Medium,
                                    fontFeatureSettings = "tnum",
                                ),
                            )
                        }
                        if (selection is FlowSelection.Ghost && selection.projection == case.projection) {
                            drawRoundRect(
                                color = Z.gold,
                                topLeft = Offset(left - 2.dp.toPx(), top - 2.dp.toPx()),
                                size = Size(barSize.width + 4.dp.toPx(), barSize.height + 4.dp.toPx()),
                                cornerRadius = CornerRadius(6.dp.toPx()),
                                style = Stroke(width = 1.5.dp.toPx()),
                            )
                        }
                    }

                    lane.milestones.forEach { milestone ->
                        val mx = x(milestone.tMs)
                        drawLine(
                            color = Z.primary.copy(alpha = laneAlpha),
                            start = Offset(mx, cy - 7.dp.toPx()),
                            end = Offset(mx, cy + 7.dp.toPx()),
                            strokeWidth = 2.dp.toPx(),
                        )
                        if (selection is FlowSelection.Event && selection.event == milestone) {
                            drawCircle(
                                color = Z.gold,
                                radius = 8.dp.toPx(),
                                center = Offset(mx, cy),
                                style = Stroke(width = 1.5.dp.toPx()),
                            )
                        }
                    }
                }
            }
        }
    }
}
