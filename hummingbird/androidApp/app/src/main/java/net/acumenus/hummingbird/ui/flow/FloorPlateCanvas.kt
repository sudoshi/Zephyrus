package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.data.FlowFloor
import net.acumenus.hummingbird.data.FlowFloorRollup
import net.acumenus.hummingbird.data.FlowPlate
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.max
import kotlin.math.min

/**
 * Neutral occupancy heat: Z primary from 0.10 to 0.45 alpha by occupancy —
 * NEVER red; urgency is earned by real breaches, not by fullness.
 */
internal fun occupancyAlpha(occupancyPct: Int): Float =
    0.10f + 0.35f * (occupancyPct.coerceIn(0, 100) / 100f)

/** Maps plan-view feet → canvas px for one floor (uniform scale, centered). */
private class PlateTransform(bounds: List<Double>, canvas: Size, padPx: Float) {
    private val bx = bounds.getOrElse(0) { 0.0 }.toFloat()
    private val by = bounds.getOrElse(1) { 0.0 }.toFloat()
    private val bw = max(bounds.getOrElse(2) { 1.0 }.toFloat(), 0.001f)
    private val bh = max(bounds.getOrElse(3) { 1.0 }.toFloat(), 0.001f)
    private val scale = min((canvas.width - 2 * padPx) / bw, (canvas.height - 2 * padPx) / bh)
    private val ox = (canvas.width - bw * scale) / 2f - bx * scale
    private val oy = (canvas.height - bh * scale) / 2f - by * scale

    fun rect(r: List<Double>): Rect {
        val x = r.getOrElse(0) { 0.0 }.toFloat()
        val y = r.getOrElse(1) { 0.0 }.toFloat()
        val w = r.getOrElse(2) { 0.0 }.toFloat()
        val h = r.getOrElse(3) { 0.0 }.toFloat()
        return Rect(ox + x * scale, oy + y * scale, ox + (x + w) * scale, oy + (y + h) * scale)
    }
}

/** Inflate a plate's rect to at least the minimum touch target, centered. */
private fun Rect.expandedToTarget(minPx: Float): Rect {
    val dw = max(0f, minPx - width) / 2f
    val dh = max(0f, minPx - height) / 2f
    return Rect(left - dw, top - dh, right + dw, bottom + dh)
}

/** Smallest plate whose ≥44dp effective target contains the tap — beds win over rooms over units. */
private fun hitTestPlate(pos: Offset, plates: List<FlowPlate>, transform: PlateTransform, minTargetPx: Float): FlowPlate? =
    plates
        .filter { transform.rect(it.rect).expandedToTarget(minTargetPx).contains(pos) }
        .minByOrNull { transform.rect(it.rect).let { r -> r.width * r.height } }

/**
 * One floor's plates on a Compose Canvas: unit/zone plates carry the neutral
 * occupancy heat, rooms/bays are quiet outlines, beds are small outlined rects.
 * Projections with a bedId on this floor render as ghosts — dashed 1.5dp ink
 * outlines at confidence alpha. Never a solid fill, never a status color.
 */
@Composable
fun FloorPlateCanvas(
    floor: FlowFloor,
    rollup: FlowFloorRollup?,
    ghosts: List<FlowProjection>,
    selectedPlateId: Int?,
    onSelectPlate: (FlowPlate?) -> Unit,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current
    val minTargetPx = with(density) { 44.dp.toPx() }
    val padPx = with(density) { 12.dp.toPx() }
    val occupancyByUnit = rollup?.units?.associate { it.unitId to it.occupancyPct } ?: emptyMap()
    val plates by rememberUpdatedState(floor.spaces)
    val bounds by rememberUpdatedState(floor.bounds)
    val select by rememberUpdatedState(onSelectPlate)

    Canvas(
        modifier
            .fillMaxSize()
            .pointerInput(floor.floor) {
                detectTapGestures { pos ->
                    val transform = PlateTransform(bounds, Size(size.width.toFloat(), size.height.toFloat()), padPx)
                    select(hitTestPlate(pos, plates, transform, minTargetPx))
                }
            },
    ) {
        val transform = PlateTransform(floor.bounds, size, padPx)
        val corner = CornerRadius(3.dp.toPx())
        val hairline = Stroke(width = 1.dp.toPx())

        // 1. Unit/zone plates — neutral occupancy heat.
        floor.spaces.filter { it.category == "unit" || it.category == "zone" }.forEach { plate ->
            val r = transform.rect(plate.rect)
            val pct = plate.unitId?.let { occupancyByUnit[it] } ?: 0
            drawRoundRect(
                color = Z.primary.copy(alpha = occupancyAlpha(pct)),
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = corner,
            )
            drawRoundRect(
                color = Z.border,
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = corner,
                style = hairline,
            )
        }

        // 2. Corridors + vertical transport — whisper-quiet circulation.
        floor.spaces.filter { it.category == "corridor" || it.category == "vertical_transport" }.forEach { plate ->
            val r = transform.rect(plate.rect)
            drawRoundRect(
                color = Z.inkMuted.copy(alpha = 0.08f),
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = corner,
            )
            drawRoundRect(
                color = Z.border.copy(alpha = 0.5f),
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = corner,
                style = hairline,
            )
        }

        // 3. Rooms/bays — subtle outlines only.
        floor.spaces.filter { it.category == "room" || it.category == "bay" }.forEach { plate ->
            val r = transform.rect(plate.rect)
            drawRoundRect(
                color = Z.border.copy(alpha = 0.8f),
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = corner,
                style = hairline,
            )
        }

        // 4. Beds — small outlined rects.
        floor.spaces.filter { it.category == "bed" }.forEach { plate ->
            val r = transform.rect(plate.rect)
            drawRoundRect(
                color = Z.inkMuted,
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = CornerRadius(2.dp.toPx()),
                style = hairline,
            )
        }

        // 5. Ghost overlays: dashed 1.5dp ink outlines on the target bed,
        //    alpha by confidence (definite 0.8 / probable 0.5 / possible 0.3).
        val bedPlates = floor.spaces.filter { it.category == "bed" && it.bedId != null }
        val ghostDash = PathEffect.dashPathEffect(floatArrayOf(5.dp.toPx(), 4.dp.toPx()))
        ghosts.filter { it.bedId != null }.forEach { ghost ->
            val bed = bedPlates.firstOrNull { it.bedId == ghost.bedId } ?: return@forEach
            val r = transform.rect(bed.rect).let {
                Rect(it.left - 3.dp.toPx(), it.top - 3.dp.toPx(), it.right + 3.dp.toPx(), it.bottom + 3.dp.toPx())
            }
            drawRoundRect(
                color = Z.ink.copy(alpha = ghost.confidenceAlpha),
                topLeft = r.topLeft,
                size = r.size,
                cornerRadius = CornerRadius(4.dp.toPx()),
                style = Stroke(width = 1.5.dp.toPx(), pathEffect = ghostDash),
            )
        }

        // 6. Selection — gold focus ring (System B focus layer, never a fill).
        selectedPlateId?.let { id ->
            floor.spaces.firstOrNull { it.id == id }?.let { plate ->
                val r = transform.rect(plate.rect).let {
                    Rect(it.left - 2.dp.toPx(), it.top - 2.dp.toPx(), it.right + 2.dp.toPx(), it.bottom + 2.dp.toPx())
                }
                drawRoundRect(
                    color = Z.gold,
                    topLeft = r.topLeft,
                    size = r.size,
                    cornerRadius = CornerRadius(4.dp.toPx()),
                    style = Stroke(width = 2.dp.toPx()),
                )
            }
        }
    }
}
