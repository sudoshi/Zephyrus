package net.acumenus.hummingbird.widget

import android.content.Context
import androidx.glance.appwidget.updateAll
import net.acumenus.hummingbird.data.AltitudeHome
import net.acumenus.hummingbird.data.FlowWindowData
import java.io.File
import java.nio.file.Files
import java.nio.file.StandardCopyOption

/**
 * Persists the house-glance snapshot (a small file under filesDir/glance) and pushes it to
 * the widget. App-driven only: writes happen on a foreground altitude-home / flow-window
 * load, and each write ends with GlanceAppWidget.updateAll — there is NO background /
 * WorkManager networking behind the widget.
 */
object HouseGlanceStore {
    private const val DIR = "glance"
    private const val FILE = "house.json"

    private fun file(context: Context): File = File(File(context.filesDir, DIR), FILE)

    fun read(context: Context): HouseGlanceSnapshot? =
        runCatching { file(context).readText() }.getOrNull()?.let(HouseGlanceSnapshot::fromJson)

    suspend fun updateFromFlow(context: Context, window: FlowWindowData) =
        write(context, HouseGlanceSnapshot.fromFlow(window))

    suspend fun updateFromHome(context: Context, home: AltitudeHome) =
        write(context, HouseGlanceSnapshot.fromHome(home))

    /** Wipe the snapshot on logout and reset the widget to its placeholder. */
    suspend fun clear(context: Context) {
        runCatching { file(context).delete() }
        HouseGlanceWidget().updateAll(context)
    }

    private suspend fun write(context: Context, partial: HouseGlanceSnapshot) {
        val merged = HouseGlanceSnapshot.merge(read(context), partial, System.currentTimeMillis())
        atomicWrite(file(context), merged.toJson())
        HouseGlanceWidget().updateAll(context)
    }

    private fun atomicWrite(target: File, text: String) {
        target.parentFile?.mkdirs()
        val tmp = File(target.parentFile, "${target.name}.tmp")
        tmp.writeText(text)
        runCatching { Files.move(tmp.toPath(), target.toPath(), StandardCopyOption.ATOMIC_MOVE) }
            .onFailure { Files.move(tmp.toPath(), target.toPath(), StandardCopyOption.REPLACE_EXISTING) }
    }
}
