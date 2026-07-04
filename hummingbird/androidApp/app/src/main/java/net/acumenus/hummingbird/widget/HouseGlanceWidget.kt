package net.acumenus.hummingbird.widget

import android.content.Context
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.appWidgetBackground
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.background
import androidx.glance.layout.Alignment
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.width
import androidx.glance.text.FontFamily
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * Glance colors mirror the Zephyrus `Z` palette (System A blue/slate + the rationed status
 * ramp). Glance can't reach the Compose `Z` object's ColorProviders, so the hex values are
 * duplicated here — keep them in sync. Projections read as warning (amber), never critical.
 */
private object GlanceZ {
    val bg = ColorProvider(Color(0xFF1E293B))       // Z.surface
    val ink = ColorProvider(Color(0xFFF8FAFC))      // Z.ink
    val inkMuted = ColorProvider(Color(0xFF94A3B8)) // Z.inkMuted
    val primary = ColorProvider(Color(0xFF60A5FA))  // Z.statusInfo (interactive blue on dark)
    val warning = ColorProvider(Color(0xFFE5A84B))  // Z.statusWarning
}

// monospace ⇒ tabular alignment for the metrics
private val metricStyle = TextStyle(
    color = GlanceZ.ink,
    fontSize = 22.sp,
    fontWeight = FontWeight.Medium,
    fontFamily = FontFamily.Monospace,
)

private val labelStyle = TextStyle(color = GlanceZ.inkMuted, fontSize = 11.sp)
private val kickerStyle = TextStyle(color = GlanceZ.primary, fontSize = 12.sp, fontWeight = FontWeight.Medium)

/**
 * Small house-glance widget: occupancy %, net bed need, For You count, next-4h ghost count,
 * and an updated-at stamp. Fed by [HouseGlanceStore]; renders a tasteful placeholder until
 * the app has synced once.
 */
class HouseGlanceWidget : GlanceAppWidget() {
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val snapshot = HouseGlanceStore.read(context)
        provideContent { Content(snapshot) }
    }
}

@Composable
private fun Content(snapshot: HouseGlanceSnapshot?) {
    val root = GlanceModifier
        .fillMaxSize()
        .appWidgetBackground()
        .background(GlanceZ.bg)
        .cornerRadius(16.dp)
        .padding(12.dp)

    if (snapshot == null || snapshot.isEmpty) {
        Column(modifier = root, verticalAlignment = Alignment.CenterVertically) {
            Text("Hummingbird", style = kickerStyle)
            Spacer(GlanceModifier.height(4.dp))
            Text("Open Hummingbird to sync", style = labelStyle)
        }
        return
    }

    Column(modifier = root) {
        Row(modifier = GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("House", style = kickerStyle)
            Spacer(GlanceModifier.width(6.dp))
            Text(updatedLabel(snapshot.updatedAtMs), style = labelStyle)
        }
        Spacer(GlanceModifier.height(6.dp))
        Row(modifier = GlanceModifier.fillMaxWidth()) {
            Metric(label = "Occupied", value = snapshot.occupancyPct?.let { "$it%" } ?: DASH)
            Spacer(GlanceModifier.width(12.dp))
            Metric(label = "Bed need", value = snapshot.netBedNeed?.let(::signed) ?: DASH)
        }
        Spacer(GlanceModifier.height(6.dp))
        Row(modifier = GlanceModifier.fillMaxWidth()) {
            Metric(label = "For You", value = snapshot.forYouCount?.toString() ?: DASH)
            Spacer(GlanceModifier.width(12.dp))
            Metric(
                label = "Next 4h",
                value = snapshot.next4hGhostCount?.toString() ?: DASH,
                valueColor = GlanceZ.warning, // projections render as warning, never critical
            )
        }
    }
}

@Composable
private fun Metric(label: String, value: String, valueColor: ColorProvider = GlanceZ.ink) {
    Column {
        Text(value, style = metricStyle.copy(color = valueColor))
        Text(label, style = labelStyle)
    }
}

private const val DASH = "—"

private fun signed(v: Int): String = if (v > 0) "+$v" else v.toString()

private fun updatedLabel(ms: Long): String {
    if (ms <= 0L) return ""
    val clock = DateTimeFormatter.ofPattern("HH:mm")
        .format(Instant.ofEpochMilli(ms).atZone(ZoneId.systemDefault()))
    return "Updated $clock"
}
