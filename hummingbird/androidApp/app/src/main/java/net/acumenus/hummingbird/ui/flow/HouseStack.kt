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
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowFloorRollup
import net.acumenus.hummingbird.ui.theme.Z

/**
 * Exploded axonometric house stack: each floor is a sheared parallelogram slab,
 * stacked bottom-up with neutral occupancy heat. Tap a floor to descend into
 * its floor plate.
 */
@Composable
fun HouseStack(
    floors: List<FlowFloorRollup>,
    onSelectFloor: (Int) -> Unit,
    modifier: Modifier = Modifier,
) {
    // Top floor first — the building reads bottom-up.
    val ordered = floors.sortedByDescending { it.floor }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 8.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        ordered.forEach { floor ->
            FloorSlab(floor = floor, onClick = { onSelectFloor(floor.floor) })
        }
    }
}

@Composable
private fun FloorSlab(floor: FlowFloorRollup, onClick: () -> Unit) {
    Box(
        Modifier
            .fillMaxWidth()
            .height(44.dp)
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
            drawPath(slab, color = Z.primary.copy(alpha = occupancyAlpha(floor.occupancyPct)))
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
