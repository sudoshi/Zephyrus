package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.drawText
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowBedStatus
import net.acumenus.hummingbird.data.FlowFloor
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.abs

/**
 * Bed-state tint fade: bed_statuses are strictly "now", so the tint layer fades
 * as the user scrubs away from now (full within ~15 min, down to 25% at ±4h).
 */
fun bedStateFade(scrubT: Long, nowMs: Long): Float {
    val hoursAway = abs(scrubT - nowMs) / 3_600_000f
    return 1f - 0.75f * (hoursAway / 4f).coerceAtMost(1f)
}

/** True when the scrub position is far enough from now to caveat the tint layer. */
fun bedStateIsStale(scrubT: Long, nowMs: Long): Boolean =
    abs(scrubT - nowMs) >= 15 * 60_000L

/**
 * The turn map's bed treatment: dirty = warning tint, blocked = muted outline,
 * occupied = neutral fill, available = faint success tint. Status is never by
 * color alone — the tap detail names the state.
 */
fun evsBedPaints(statuses: List<FlowBedStatus>, fade: Float): Map<Int, FlowBedPaint> =
    statuses.associate { bed ->
        bed.bedId to when (bed.status) {
            "dirty" -> FlowBedPaint(
                fill = Z.statusWarning.copy(alpha = 0.35f * fade),
                outline = Z.statusWarning.copy(alpha = (0.9f * fade).coerceAtLeast(0.3f)),
            )
            "blocked" -> FlowBedPaint(
                fill = null,
                outline = Z.inkMuted.copy(alpha = (0.55f * fade).coerceAtLeast(0.3f)),
            )
            "available" -> FlowBedPaint(
                fill = Z.statusSuccess.copy(alpha = 0.16f * fade),
                outline = null,
            )
            else -> FlowBedPaint( // occupied — neutral
                fill = Z.inkMuted.copy(alpha = 0.18f * fade),
                outline = null,
            )
        }
    }

private data class EvsTurnMark(
    val bedRect: List<Double>,
    val tMs: Long,
    val alpha: Float,
    val warning: Boolean,
    val isolation: Boolean,
)

/**
 * Turn markers over the floor plate (no pointer input — bed taps fall through):
 * past evs_status events as solid ticks on their bed, future evs_due
 * projections as dashed ghost outlines on the target bed with a time chip.
 */
@Composable
fun EvsTurnOverlay(
    floor: FlowFloor,
    window: FlowWindowData,
    scrubT: Long,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current
    val padPx = with(density) { floorPlatePad.toPx() }
    val textMeasurer = rememberTextMeasurer()

    val marks = remember(floor, window, scrubT) {
        val bedRectById = floor.spaces
            .filter { it.category == "bed" && it.bedId != null }
            .associate { it.bedId!! to it.rect }
        val bedIdByLabel = window.bedStatuses.associate { it.label.lowercase() to it.bedId }

        val past = window.events
            .filter { it.kind == "evs_status" && it.tMs <= scrubT }
            .mapNotNull { event ->
                val bedId = event.toSpace?.trim()?.lowercase()?.let(bedIdByLabel::get) ?: return@mapNotNull null
                val rect = bedRectById[bedId] ?: return@mapNotNull null
                EvsTurnMark(
                    bedRect = rect,
                    tMs = event.tMs,
                    alpha = 1f,
                    warning = event.tier == "warning" || event.tier == "critical",
                    isolation = event.label.contains("isolation", ignoreCase = true),
                )
            }
        val ahead = window.projections
            .filter { it.kind == "evs_due" && it.tMs > scrubT }
            .mapNotNull { ghost ->
                val rect = ghost.bedId?.let(bedRectById::get) ?: return@mapNotNull null
                EvsTurnMark(
                    bedRect = rect,
                    tMs = ghost.tMs,
                    alpha = ghost.confidenceAlpha,
                    warning = false,
                    isolation = ghost.label.contains("isolation", ignoreCase = true),
                )
            }
        past to ahead
    }

    Canvas(modifier.fillMaxSize()) {
        val transform = FlowPlanTransform(floor.bounds, size, padPx)
        val ghostDash = PathEffect.dashPathEffect(floatArrayOf(5.dp.toPx(), 4.dp.toPx()))

        // Past turns: solid ticks on the bed (amber only when the turn ran hot).
        marks.first.forEach { mark ->
            val r = transform.rect(mark.bedRect)
            val color = if (mark.warning) Z.statusWarning else Z.primary
            drawLine(
                color = color,
                start = Offset(r.center.x - 3.dp.toPx(), r.center.y + 1.dp.toPx()),
                end = Offset(r.center.x - 0.5.dp.toPx(), r.center.y + 3.5.dp.toPx()),
                strokeWidth = 2.dp.toPx(),
            )
            drawLine(
                color = color,
                start = Offset(r.center.x - 0.5.dp.toPx(), r.center.y + 3.5.dp.toPx()),
                end = Offset(r.center.x + 4.dp.toPx(), r.center.y - 3.dp.toPx()),
                strokeWidth = 2.dp.toPx(),
            )
        }

        // Upcoming turns: dashed ghost outline + a time chip so techs can pre-position.
        marks.second.forEach { mark ->
            val r = transform.rect(mark.bedRect).let {
                Rect(it.left - 3.dp.toPx(), it.top - 3.dp.toPx(), it.right + 3.dp.toPx(), it.bottom + 3.dp.toPx())
            }
            drawRoundRect(
                color = Z.ink.copy(alpha = mark.alpha),
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = androidx.compose.ui.geometry.CornerRadius(4.dp.toPx()),
                style = Stroke(width = 1.5.dp.toPx(), pathEffect = ghostDash),
            )
            val chip = flowClock(mark.tMs) + if (mark.isolation) " · ISO" else ""
            drawText(
                textMeasurer = textMeasurer,
                text = chip,
                topLeft = Offset(r.right + 3.dp.toPx(), r.top - 4.dp.toPx()),
                style = TextStyle(
                    color = (if (mark.isolation) Z.statusWarning else Z.ink).copy(alpha = mark.alpha),
                    fontSize = 8.sp,
                    fontFeatureSettings = "tnum",
                ),
            )
        }
    }
}
