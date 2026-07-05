package net.acumenus.hummingbird.widget

import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver

/** Home-screen receiver that hosts the [HouseGlanceWidget]. Registered in the manifest. */
class HouseGlanceReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = HouseGlanceWidget()
}
