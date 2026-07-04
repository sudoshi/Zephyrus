package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.drawText
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.abs

/**
 * One point on the 48h census/occupancy curve. Past points come from snapshots
 * (no band); future points come from `predicted_census` projections (band when
 * the service provides one).
 */
data class FlowCurvePoint(
    val tMs: Long,
    val value: Int,
    val bandLower: Int? = null,
    val bandUpper: Int? = null,
)

/**
 * Past occupancy series from hourly snapshots. `unitIds == null` = whole scope
 * (sum every unit per checkpoint); otherwise the client-side filter the
 * capacity lens uses when a floor/unit is tapped.
 */
fun occupancySeries(window: FlowWindowData, unitIds: Set<Int>? = null): List<FlowCurvePoint> =
    window.snapshots
        .asSequence()
        .filter { unitIds == null || it.unitId in unitIds }
        .groupBy { it.tMs }
        .map { (t, rows) -> FlowCurvePoint(t, rows.sumOf { it.occupied }) }
        .sortedBy { it.tMs }

/**
 * Future census series from `predicted_census` projections (2h steps).
 * House scope prefers house-level rows (unit_id == null); when the payload is
 * per-unit only, unit rows are summed per step (bands summed when present).
 */
fun predictedSeries(window: FlowWindowData, unitIds: Set<Int>? = null): List<FlowCurvePoint> {
    val census = window.projections.filter { it.kind == "predicted_census" && it.value != null }
    val rows = if (unitIds == null) {
        census.filter { it.unitId == null }.ifEmpty { census }
    } else {
        census.filter { it.unitId != null && it.unitId in unitIds }
    }
    return rows
        .groupBy { it.tMs }
        .map { (t, group) ->
            FlowCurvePoint(
                tMs = t,
                value = group.sumOf { it.value ?: 0 },
                bandLower = if (group.any { it.bandLower != null }) group.sumOf { it.bandLower ?: it.value ?: 0 } else null,
                bandUpper = if (group.any { it.bandUpper != null }) group.sumOf { it.bandUpper ?: it.value ?: 0 } else null,
            )
        }
        .sortedBy { it.tMs }
}

/** Staffed capacity for the scope — the reference line the curve is judged against. */
fun staffedCapacity(window: FlowWindowData, unitIds: Set<Int>? = null): Int =
    window.spacesFloors
        .asSequence()
        .flatMap { it.units }
        .filter { unitIds == null || it.unitId in unitIds }
        .sumOf { it.staffed }

/** "212/240 occupied" readout at t — snapshots behind now, predicted steps ahead. */
fun curveReadout(window: FlowWindowData, unitIds: Set<Int>?, t: Long): String {
    val staffed = staffedCapacity(window, unitIds)
    if (t > window.nowMs) {
        val step = predictedSeries(window, unitIds).lastOrNull { it.tMs <= t }
        if (step != null) {
            val band = if (step.bandLower != null && step.bandUpper != null) " (${step.bandLower}–${step.bandUpper})" else ""
            return "≈${step.value}$band / $staffed staffed"
        }
    }
    val past = occupancySeries(window, unitIds).lastOrNull { it.tMs <= t }
    if (past != null) return "${past.value} / $staffed staffed"
    // No checkpoints yet — fall back to the now-state rollups.
    val occupiedNow = window.spacesFloors
        .asSequence()
        .flatMap { it.units }
        .filter { unitIds == null || it.unitId in unitIds }
        .sumOf { it.occupied }
    return "$occupiedNow / $staffed staffed"
}

/**
 * The shared 48h census/occupancy curve, aligned to the Chronobar window
 * (§8 P6/P9/P10). Past = solid line from snapshots; future = dashed line +
 * soft band ribbon from `predicted_census` (ghost grammar — dashed, never a
 * status color); staffed capacity as a reference line; now marker; optional
 * 07:00/19:00 detents; optional `staffing_shift_gap` step markers and a
 * `surge_probability` marker, both tappable for provenance.
 */
@Composable
fun FlowCurve(
    window: FlowWindowData,
    unitIds: Set<Int>? = null,
    scopeLabel: String,
    scrubT: Long,
    gapMarkers: List<FlowProjection> = emptyList(),
    surgeMarker: FlowProjection? = null,
    showShiftDetents: Boolean = true,
    emphasizeNextShift: Boolean = false,
    onSelectProjection: ((FlowProjection) -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    val fromMs = window.fromMs
    val toMs = window.toMs
    val nowMs = window.nowMs
    val span = (toMs - fromMs).coerceAtLeast(1L)

    val past = remember(window, unitIds) { occupancySeries(window, unitIds) }
    val future = remember(window, unitIds) { predictedSeries(window, unitIds) }
    val staffed = remember(window, unitIds) { staffedCapacity(window, unitIds) }
    val detents = remember(fromMs, toMs) { shiftDetentsMs(fromMs, toMs) }
    // Gap markers aggregated per shift boundary: one step, summed headcount.
    val gapSteps = remember(gapMarkers) {
        gapMarkers.groupBy { it.tMs }.entries.sortedBy { it.key }
    }

    val textMeasurer = rememberTextMeasurer()
    val labelStyle = TextStyle(color = Z.inkMuted, fontSize = 9.sp, fontFeatureSettings = "tnum")
    val select by rememberUpdatedState(onSelectProjection)

    Column(modifier = modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                scopeLabel,
                color = Z.inkMuted,
                fontSize = 11.sp,
                fontWeight = FontWeight.SemiBold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f),
            )
            Text(
                "${flowClock(scrubT)} · ${curveReadout(window, unitIds, scrubT)}",
                color = Z.ink,
                fontSize = 11.sp,
                fontWeight = FontWeight.Medium,
                style = flowTabularNums,
            )
        }

        Canvas(
            Modifier
                .fillMaxWidth()
                .weight(1f, fill = true)
                .heightIn(min = 120.dp)
                .pointerInput(gapSteps, surgeMarker, fromMs, span) {
                    detectTapGestures { pos ->
                        val handler = select ?: return@detectTapGestures
                        fun xOf(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * size.width
                        val slop = 22.dp.toPx()
                        // Nearest tappable projected value: a gap step or the surge marker.
                        val candidates = buildList {
                            gapSteps.forEach { (t, rows) ->
                                rows.maxByOrNull { it.value ?: 0 }?.let { add(it to abs(xOf(t) - pos.x)) }
                            }
                            surgeMarker?.let { add(it to abs(xOf(it.tMs) - pos.x)) }
                        }
                        candidates.minByOrNull { it.second }
                            ?.takeIf { it.second <= slop }
                            ?.let { handler(it.first) }
                    }
                },
        ) {
            val w = size.width
            val h = size.height
            val topPad = 12.dp.toPx()
            val bottomPad = 12.dp.toPx()
            val plotH = (h - topPad - bottomPad).coerceAtLeast(1f)
            val maxValue = maxOf(
                staffed,
                past.maxOfOrNull { it.value } ?: 0,
                future.maxOfOrNull { it.bandUpper ?: it.value } ?: 0,
                1,
            ) * 1.08f

            fun x(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * w
            fun y(value: Float): Float = topPad + plotH * (1f - (value / maxValue).coerceIn(0f, 1f))

            // Staffed-capacity reference line.
            if (staffed > 0) {
                val yStaffed = y(staffed.toFloat())
                drawLine(
                    color = Z.inkMuted.copy(alpha = 0.7f),
                    start = Offset(0f, yStaffed),
                    end = Offset(w, yStaffed),
                    strokeWidth = 1.dp.toPx(),
                )
                drawText(
                    textMeasurer = textMeasurer,
                    text = "staffed $staffed",
                    style = labelStyle,
                    topLeft = Offset(4.dp.toPx(), (yStaffed - 12.dp.toPx()).coerceAtLeast(0f)),
                )
            }

            // Shift-boundary detents (07:00/19:00); the next one after now can be emphasized.
            if (showShiftDetents) {
                val nextShift = detents.firstOrNull { it > nowMs }
                detents.forEach { detent ->
                    val emphasized = emphasizeNextShift && detent == nextShift
                    val dx = x(detent)
                    drawLine(
                        color = if (emphasized) Z.ink else Z.inkMuted.copy(alpha = 0.6f),
                        start = Offset(dx, h - bottomPad),
                        end = Offset(dx, h - bottomPad + (if (emphasized) 9.dp else 5.dp).toPx()),
                        strokeWidth = (if (emphasized) 2.dp else 1.dp).toPx(),
                        cap = StrokeCap.Round,
                    )
                    if (emphasized) {
                        drawText(
                            textMeasurer = textMeasurer,
                            text = flowClock(detent),
                            style = labelStyle.copy(color = Z.ink),
                            topLeft = Offset((dx - 14.dp.toPx()).coerceAtLeast(0f), h - 11.dp.toPx()),
                        )
                    }
                }
            }

            // Band ribbon ahead — a soft fill, never a status color.
            val banded = future.filter { it.bandLower != null && it.bandUpper != null }
            if (banded.size >= 2) {
                val ribbon = Path().apply {
                    banded.forEachIndexed { i, p ->
                        val px = x(p.tMs)
                        val py = y(p.bandUpper!!.toFloat())
                        if (i == 0) moveTo(px, py) else lineTo(px, py)
                    }
                    banded.asReversed().forEach { p ->
                        lineTo(x(p.tMs), y(p.bandLower!!.toFloat()))
                    }
                    close()
                }
                drawPath(ribbon, color = Z.primary.copy(alpha = 0.10f))
            }

            // Past: solid — the reviewable record.
            if (past.size >= 2) {
                val path = Path().apply {
                    past.forEachIndexed { i, p ->
                        val px = x(p.tMs)
                        val py = y(p.value.toFloat())
                        if (i == 0) moveTo(px, py) else lineTo(px, py)
                    }
                }
                drawPath(path, color = Z.primary, style = Stroke(width = 2.dp.toPx(), cap = StrokeCap.Round))
            } else {
                past.firstOrNull()?.let { p ->
                    drawCircle(Z.primary, radius = 3.dp.toPx(), center = Offset(x(p.tMs), y(p.value.toFloat())))
                }
            }

            // Future: dashed ghost line, anchored to the last observed point when present.
            val futureAnchor = past.lastOrNull()?.let { listOf(it) } ?: emptyList()
            val futureLine = futureAnchor + future
            if (futureLine.size >= 2) {
                val dash = PathEffect.dashPathEffect(floatArrayOf(6.dp.toPx(), 5.dp.toPx()))
                val path = Path().apply {
                    futureLine.forEachIndexed { i, p ->
                        val px = x(p.tMs)
                        val py = y(p.value.toFloat())
                        if (i == 0) moveTo(px, py) else lineTo(px, py)
                    }
                }
                drawPath(
                    path,
                    color = Z.primary.copy(alpha = 0.55f),
                    style = Stroke(width = 1.5.dp.toPx(), cap = StrokeCap.Round, pathEffect = dash),
                )
            }

            // `now` marker.
            val xNow = x(nowMs)
            drawLine(
                color = Z.ink,
                start = Offset(xNow, topPad - 6.dp.toPx()),
                end = Offset(xNow, h - bottomPad),
                strokeWidth = 1.5.dp.toPx(),
            )

            // Scrub position (only when it isn't sitting at now).
            if (abs(scrubT - nowMs) > 60_000L) {
                drawLine(
                    color = Z.inkMuted.copy(alpha = 0.8f),
                    start = Offset(x(scrubT), topPad),
                    end = Offset(x(scrubT), h - bottomPad),
                    strokeWidth = 1.dp.toPx(),
                    pathEffect = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 3.dp.toPx())),
                )
            }

            // Staffing gap steps: dashed ghost step at each shift boundary,
            // headcount label — warning tint only when the gap is real (> 0).
            gapSteps.forEach { (t, rows) ->
                val gap = rows.sumOf { (it.value ?: 0).coerceAtLeast(0) }
                val color = if (gap > 0) Z.statusWarning else Z.inkMuted
                val gx = x(t)
                drawLine(
                    color = color.copy(alpha = 0.8f),
                    start = Offset(gx, h - bottomPad),
                    end = Offset(gx, h - bottomPad - 14.dp.toPx()),
                    strokeWidth = 2.dp.toPx(),
                    cap = StrokeCap.Round,
                    pathEffect = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 2.5.dp.toPx())),
                )
                drawText(
                    textMeasurer = textMeasurer,
                    text = if (gap > 0) "gap $gap" else "gap 0",
                    style = labelStyle.copy(color = color),
                    topLeft = Offset(
                        (gx - 10.dp.toPx()).coerceIn(0f, w - 30.dp.toPx()),
                        (h - bottomPad - 26.dp.toPx()).coerceAtLeast(0f),
                    ),
                )
            }

            // Surge probability marker at its own t — dashed ring, confidence alpha.
            surgeMarker?.let { surge ->
                val sx = x(surge.tMs)
                val sy = topPad + 6.dp.toPx()
                drawCircle(
                    color = Z.ink.copy(alpha = surge.confidenceAlpha),
                    radius = 5.dp.toPx(),
                    center = Offset(sx, sy),
                    style = Stroke(
                        width = 1.5.dp.toPx(),
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(3.dp.toPx(), 2.5.dp.toPx())),
                    ),
                )
                surge.value?.let { v ->
                    drawText(
                        textMeasurer = textMeasurer,
                        text = "surge $v%",
                        style = labelStyle,
                        topLeft = Offset((sx + 8.dp.toPx()).coerceAtMost(w - 44.dp.toPx()), sy - 6.dp.toPx()),
                    )
                }
            }
        }

        // Encoding legend — the line style names the meaning, never color alone.
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            CurveLegendSwatch(solid = true, label = "occupied")
            CurveLegendSwatch(solid = false, label = "predicted · band")
            Text("— staffed", color = Z.inkMuted, fontSize = 9.sp)
        }
    }
}

@Composable
private fun CurveLegendSwatch(solid: Boolean, label: String) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        Canvas(Modifier.size(width = 14.dp, height = 8.dp)) {
            drawLine(
                color = if (solid) Z.primary else Z.primary.copy(alpha = 0.55f),
                start = Offset(0f, size.height / 2f),
                end = Offset(size.width, size.height / 2f),
                strokeWidth = 2.dp.toPx(),
                pathEffect = if (solid) null else PathEffect.dashPathEffect(floatArrayOf(4f, 4f)),
            )
        }
        Text(label, color = Z.inkMuted, fontSize = 9.sp)
    }
}
