package net.acumenus.hummingbird.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Public
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.CensusUnit
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.Z
import kotlin.math.roundToInt

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun UnitDetailScreen(unit: CensusUnit, webLink: String?, onBack: () -> Unit) {
    val status = unit.capacity
    val context = LocalContext.current
    val fraction = if (unit.safeCapacity > 0) unit.occupied.toFloat() / unit.safeCapacity else 0f
    val pct = (fraction * 100).roundToInt()
    val animated by animateFloatAsState(targetValue = fraction.coerceIn(0f, 1f), label = "gauge")

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text(unit.name, fontWeight = FontWeight.SemiBold) },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg, titleContentColor = Z.ink, navigationIconContentColor = Z.ink,
                ),
            )
        },
    ) { inner ->
        Column(
            modifier = Modifier.padding(inner).fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            Box(Modifier.size(190.dp), contentAlignment = Alignment.Center) {
                Canvas(Modifier.fillMaxSize()) {
                    val stroke = 14.dp.toPx()
                    drawArc(color = Z.border, startAngle = -90f, sweepAngle = 360f, useCenter = false, style = Stroke(stroke, cap = StrokeCap.Round))
                    drawArc(color = status.color, startAngle = -90f, sweepAngle = 360f * animated, useCenter = false, style = Stroke(stroke, cap = StrokeCap.Round))
                }
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("$pct%", color = Z.ink, fontSize = 44.sp, fontWeight = FontWeight.SemiBold)
                    Text("${unit.occupied} / ${unit.safeCapacity} safe", color = Z.inkMuted, fontSize = 13.sp)
                }
            }

            StatusChip(status)

            Column(
                Modifier.fillMaxWidth().panel().padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                detailRow("Occupied", "${unit.occupied}")
                HorizontalDivider(color = Z.border)
                detailRow("Available", "${unit.available}")
                HorizontalDivider(color = Z.border)
                detailRow("Blocked / dirty", "${unit.blocked}")
                HorizontalDivider(color = Z.border)
                detailRow("Safe capacity", "${unit.safeCapacity}")
                HorizontalDivider(color = Z.border)
                detailRow("Staffed beds", "${unit.staffedBedCount}")
                if (unit.bedNeed > 0) {
                    HorizontalDivider(color = Z.border)
                    detailRow("Over safe capacity", "${unit.bedNeed}", emphasize = true)
                }
            }

            if (webLink != null) {
                OutlinedButton(
                    onClick = { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(webLink))) },
                    modifier = Modifier.fillMaxWidth(),
                    border = BorderStroke(1.dp, Z.border),
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = Z.primary),
                ) {
                    Icon(Icons.Filled.Public, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                    Text("View bed tracking on web", fontWeight = FontWeight.SemiBold)
                }
            }

            Text(
                "Safe capacity is acuity-adjusted. Bed need = occupied − safe capacity.",
                color = Z.inkMuted, fontSize = 11.sp, textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun detailRow(label: String, value: String, emphasize: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = Z.inkMuted, fontSize = 14.sp)
        Spacer(Modifier.weight(1f))
        Text(value, color = if (emphasize) Z.statusCritical else Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
    }
}
