package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowFloorRollup
import net.acumenus.hummingbird.ui.theme.Z

private val slabHeight = 44.dp
private val slabGap = 4.dp

/**
 * A per-floor tint override (staffing lens: floors tinted by their worst
 * shift gap). The label names the state — status never by color alone.
 */
data class FloorAccent(
    val color: Color,
    val alpha: Float,
    val label: String,
)

/**
 * Exploded axonometric house stack: each floor is a sheared parallelogram slab,
 * stacked bottom-up with neutral occupancy heat. Tap a floor to descend into
 * its floor plate. Optional trip arcs (transport lens) bow along the left
 * margin between floor slabs; single-floor trips render as short stubs.
 * Optional accents replace the occupancy heat with a labeled lens tint.
 */
@Composable
fun HouseStack(
    floors: List<FlowFloorRollup>,
    onSelectFloor: (Int) -> Unit,
    modifier: Modifier = Modifier,
    arcs: List<HouseTripArc> = emptyList(),
    accents: Map<Int, FloorAccent> = emptyMap(),
) {
    // Top floor first — the building reads bottom-up.
    val ordered = floors.sortedByDescending { it.floor }
    val slabIndex = ordered.mapIndexed { index, floor -> floor.floor to index }.toMap()

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 8.dp),
    ) {
        Box {
            Column(verticalArrangement = Arrangement.spacedBy(slabGap)) {
                ordered.forEach { floor ->
                    FloorSlab(
                        floor = floor,
                        accent = accents[floor.floor],
                        onClick = { onSelectFloor(floor.floor) },
                    )
                }
            }
            if (arcs.isNotEmpty()) {
                // Purely visual overlay — no pointer input, slab taps fall through.
                Canvas(Modifier.matchParentSize()) {
                    val step = (slabHeight + slabGap).toPx()
                    val half = slabHeight.toPx() / 2f
                    fun centerY(floor: Int): Float? = slabIndex[floor]?.let { it * step + half }

                    arcs.forEachIndexed { index, arc ->
                        val y0 = centerY(arc.fromFloor) ?: return@forEachIndexed
                        val y1 = centerY(arc.toFloor) ?: return@forEachIndexed
                        val x = 10.dp.toPx()
                        val bow = 20.dp.toPx() + (index % 3) * 8.dp.toPx()
                        val dash = if (arc.dashed) {
                            PathEffect.dashPathEffect(floatArrayOf(5.dp.toPx(), 4.dp.toPx()))
                        } else {
                            null
                        }
                        if (arc.fromFloor == arc.toFloor) {
                            // Trip contained on (or leaving) one floor: a short stub.
                            drawLine(
                                color = arc.color.copy(alpha = arc.alpha),
                                start = Offset(x - 6.dp.toPx(), y0),
                                end = Offset(x + 8.dp.toPx(), y0),
                                strokeWidth = 1.5.dp.toPx(),
                                pathEffect = dash,
                            )
                            drawCircle(
                                color = arc.color.copy(alpha = arc.alpha),
                                radius = 2.5.dp.toPx(),
                                center = Offset(x + 8.dp.toPx(), y0),
                            )
                        } else {
                            val path = Path().apply {
                                moveTo(x, y0)
                                quadraticBezierTo(x - bow, (y0 + y1) / 2f, x, y1)
                            }
                            drawPath(
                                path,
                                color = arc.color.copy(alpha = arc.alpha),
                                style = Stroke(width = 1.5.dp.toPx(), pathEffect = dash),
                            )
                            drawCircle(
                                color = arc.color.copy(alpha = arc.alpha),
                                radius = 2.5.dp.toPx(),
                                center = Offset(x, y0),
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun FloorSlab(floor: FlowFloorRollup, accent: FloorAccent?, onClick: () -> Unit) {
    Box(
        Modifier
            .fillMaxWidth()
            .height(slabHeight)
            .clickable(onClick = onClick),
    ) {
        Canvas(Modifier.fillMaxSize()) {
            val shear = 16.dp.toPx()
            val w = size.width
            val h = size.height
            // Sheared parallelogram slab: identical shear per slab reads as a
            // coherent prism once the slabs stack with small vertical gaps.
            val slab = Path().apply {
                moveTo(shear, 0f)
                lineTo(w, 0f)
                lineTo(w - shear, h)
                lineTo(0f, h)
                close()
            }
            drawPath(slab, color = Z.surface)
            if (accent != null) {
                drawPath(slab, color = accent.color.copy(alpha = accent.alpha))
            } else {
                drawPath(slab, color = Z.primary.copy(alpha = occupancyAlpha(floor.occupancyPct)))
            }
            drawPath(slab, color = Z.border, style = Stroke(width = 1.dp.toPx()))
        }
        Row(
            Modifier
                .fillMaxSize()
                .padding(horizontal = 28.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                floor.label,
                color = Z.ink,
                fontSize = 13.sp,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.weight(1f),
            )
            Text(
                floor.units.joinToString(" · ") { it.abbr }.ifBlank { "—" },
                color = Z.inkMuted,
                fontSize = 10.sp,
                modifier = Modifier.weight(1f),
                maxLines = 1,
            )
            // The accent names its state — tint is never the only signal.
            accent?.let {
                Text(
                    it.label,
                    color = it.color,
                    fontSize = 10.sp,
                    fontWeight = FontWeight.SemiBold,
                    style = flowTabularNums,
                    modifier = Modifier.padding(end = 8.dp),
                )
            }
            Text(
                "${floor.occupied}/${floor.staffed}",
                color = Z.ink,
                fontSize = 12.sp,
                fontWeight = FontWeight.Medium,
                style = flowTabularNums,
            )
        }
    }
}
