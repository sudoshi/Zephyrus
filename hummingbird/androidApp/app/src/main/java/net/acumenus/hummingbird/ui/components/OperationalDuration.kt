package net.acumenus.hummingbird.ui.components

import java.time.Duration
import java.time.Instant
import kotlin.math.abs
import kotlin.math.roundToLong

internal fun formatOperationalSeconds(value: Double, compact: Boolean = false): String {
    if (!value.isFinite()) return "--"

    val totalSeconds = abs(value).roundToLong()
    val negative = value < 0 && totalSeconds > 0
    val hours = totalSeconds / 3_600
    val minutes = (totalSeconds % 3_600) / 60
    val seconds = totalSeconds % 60
    val parts = buildList {
        if (hours > 0) add(if (compact) "${hours}h" else "$hours hr")
        if (hours > 0 || minutes > 0) add(if (compact) "${minutes}m" else "$minutes min")
        add(if (compact) "${seconds}s" else "$seconds sec")
    }

    return (if (negative) "-" else "") + parts.joinToString(" ")
}

internal fun formatOperationalMinutes(value: Number, compact: Boolean = false): String =
    formatOperationalSeconds(value.toDouble() * 60, compact)

internal fun formatOperationalAge(instant: Instant, now: Instant = Instant.now()): String {
    val elapsedMillis = Duration.between(instant, now).toMillis()
    if (elapsedMillis < -500) return "scheduled"

    return "${formatOperationalSeconds(elapsedMillis.coerceAtLeast(0).toDouble() / 1_000)} ago"
}

internal fun formatOperationalOffset(deltaMillis: Long): String {
    val totalSeconds = abs(deltaMillis.toDouble() / 1_000).roundToLong()
    if (totalSeconds == 0L) return "now"

    val sign = if (deltaMillis < 0) "-" else "+"
    return sign + formatOperationalSeconds(totalSeconds.toDouble(), compact = true)
}
