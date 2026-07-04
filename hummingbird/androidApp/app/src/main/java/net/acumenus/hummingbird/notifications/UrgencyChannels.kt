package net.acumenus.hummingbird.notifications

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build

/**
 * The four urgency-tier notification channels from role-catalog.v1.json (`urgency_tiers`:
 * T1 Critical, T2 High, T3 Awareness, T4 Digest), registered at app start so a future push
 * can post into the right lane. Importance mapping: T1 high (with sound), T2 default,
 * T3 low, T4 min.
 *
 * NOTE: registration only. Actual FCM sends remain blocked on server-side credentials
 * (no push key provisioned yet) — see docs/hummingbird/DESIGN-ELEVATION-TODO.md Wave 3.
 */
object UrgencyChannels {

    /** Tier id → channel id, using the catalog tier ids so payloads can map 1:1. */
    private data class Tier(val id: String, val name: String, val importance: Int, val sound: Boolean)

    private val tiers = listOf(
        Tier("T1", "Critical", NotificationManager.IMPORTANCE_HIGH, sound = true),
        Tier("T2", "High", NotificationManager.IMPORTANCE_DEFAULT, sound = false),
        Tier("T3", "Awareness", NotificationManager.IMPORTANCE_LOW, sound = false),
        Tier("T4", "Digest", NotificationManager.IMPORTANCE_MIN, sound = false),
    )

    fun channelId(tierId: String): String = "hb_urgency_${tierId.lowercase()}"

    fun register(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        tiers.forEach { tier ->
            val channel = NotificationChannel(channelId(tier.id), tier.name, tier.importance).apply {
                description = "Urgency tier ${tier.id} — ${tier.name}"
                if (!tier.sound) setSound(null, null)
            }
            manager.createNotificationChannel(channel)
        }
    }
}
