package net.acumenus.hummingbird.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.CensusUnit
import net.acumenus.hummingbird.ui.theme.Z

/** Per-unit census tile: status stripe + name + chip + occupied/safe metric + occupancy bar. */
@Composable
fun KpiTile(unit: CensusUnit) {
    val status = unit.capacity
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel(),
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(status.color))
        Column(
            Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text(unit.name, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                    Text(
                        unit.type.replace("_", " ").uppercase(),
                        color = Z.inkMuted, fontSize = 10.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.5.sp,
                    )
                }
                StatusChip(status)
            }

            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("${unit.occupied}", color = Z.ink, fontSize = 34.sp, fontWeight = FontWeight.SemiBold)
                Text("/ ${unit.safeCapacity} safe beds", color = Z.inkMuted, fontSize = 13.sp, modifier = Modifier.padding(bottom = 5.dp))
                Spacer(Modifier.weight(1f))
                if (unit.bedNeed > 0) {
                    Text("${unit.bedNeed} over", color = Z.statusCritical, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 5.dp))
                }
            }

            val ratio = if (unit.safeCapacity > 0) (unit.occupied.toFloat() / unit.safeCapacity).coerceIn(0f, 1f) else 0f
            Box(
                Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(50)).background(Z.border),
            ) {
                Box(Modifier.fillMaxWidth(ratio).height(6.dp).clip(RoundedCornerShape(50)).background(status.color))
            }

            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Metric("${unit.available}", "available")
                Metric("${unit.blocked}", "blocked/dirty")
            }
        }
    }
}

@Composable
private fun Metric(value: String, label: String) {
    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(value, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
        Text(label, color = Z.inkMuted, fontSize = 12.sp)
    }
}
