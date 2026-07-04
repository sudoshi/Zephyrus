package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.ui.theme.Z
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.math.abs

private val hourFmt = DateTimeFormatter.ofPattern("HH:mm")

internal fun flowClock(ms: Long): String =
    hourFmt.format(Instant.ofEpochMilli(ms).atZone(ZoneId.systemDefault()))

/** "−4h 12m" / "+6h" style offset from now — review vs prediction at a glance. */
internal fun flowOffsetLabel(t: Long, nowMs: Long): String {
    val deltaMin = (t - nowMs) / 60_000L
    if (deltaMin == 0L) return "now"
    val sign = if (deltaMin < 0) "−" else "+"
    val absMin = abs(deltaMin)
    val h = absMin / 60
    val m = absMin % 60
    return when {
        h == 0L -> "$sign${m}m"
        m == 0L -> "$sign${h}h"
        else -> "$sign${h}h ${m}m"
    }
}

/** Tabular digits so time readouts don't jitter while scrubbing. */
internal val flowTabularNums = TextStyle(fontFeatureSettings = "tnum")

/**
 * The 48h scrubber (plan D5): past half solid, future half dashed, `now` tick,
 * shift-boundary detents at 07:00/19:00 with haptic feedback on crossing,
 * and a play button that replays the past half.
 */
@Composable
fun Chronobar(
    fromMs: Long,
    toMs: Long,
    nowMs: Long,
    t: Long,
    playing: Boolean,
    onScrub: (Long) -> Unit,
    onPlayPause: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val haptics = LocalHapticFeedback.current
    val span = (toMs - fromMs).coerceAtLeast(1L)
    val detents = remember(fromMs, toMs) { shiftDetentsMs(fromMs, toMs) }
    val currentT by rememberUpdatedState(t)
    val scrub by rememberUpdatedState(onScrub)

    Column(modifier = modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onPlayPause) {
                Icon(
                    if (playing) Icons.Filled.Pause else Icons.Filled.PlayArrow,
                    contentDescription = if (playing) "Pause replay" else "Replay the past 24h",
                    tint = Z.primary,
                )
            }
            Canvas(
                Modifier
                    .weight(1f)
                    .height(44.dp)
                    .pointerInput(fromMs, span, detents) {
                        var last = 0L
                        detectHorizontalDragGestures(
                            onDragStart = { last = currentT },
                        ) { change, _ ->
                            change.consume()
                            val fraction = (change.position.x / size.width).coerceIn(0f, 1f)
                            val newT = fromMs + (span * fraction).toLong()
                            val lo = minOf(last, newT)
                            val hi = maxOf(last, newT)
                            if (newT != last && detents.any { it in lo..hi }) {
                                haptics.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                            }
                            last = newT
                            scrub(newT)
                        }
                    }
                    .pointerInput(fromMs, span) {
                        detectTapGestures { offset ->
                            val fraction = (offset.x / size.width).coerceIn(0f, 1f)
                            scrub(fromMs + (span * fraction).toLong())
                        }
                    },
            ) {
                val w = size.width
                val h = size.height
                val cy = h / 2f
                fun x(ms: Long): Float = ((ms - fromMs).toFloat() / span.toFloat()) * w

                val xNow = x(nowMs)
                // Past half: solid primary — the reviewable record.
                drawLine(
                    color = Z.primary,
                    start = Offset(0f, cy),
                    end = Offset(xNow, cy),
                    strokeWidth = 3.dp.toPx(),
                    cap = StrokeCap.Round,
                )
                // Future half: dashed, quieter — prediction, not fact.
                drawLine(
                    color = Z.primary.copy(alpha = 0.45f),
                    start = Offset(xNow, cy),
                    end = Offset(w, cy),
                    strokeWidth = 3.dp.toPx(),
                    cap = StrokeCap.Round,
                    pathEffect = PathEffect.dashPathEffect(floatArrayOf(6.dp.toPx(), 5.dp.toPx())),
                )
                // Shift-boundary detents (07:00 / 19:00).
                detents.forEach { detent ->
                    val dx = x(detent)
                    drawLine(
                        color = Z.inkMuted,
                        start = Offset(dx, cy - 7.dp.toPx()),
                        end = Offset(dx, cy + 7.dp.toPx()),
                        strokeWidth = 2.dp.toPx(),
                        cap = StrokeCap.Round,
                    )
                }
                // `now` tick — taller, ink.
                drawLine(
                    color = Z.ink,
                    start = Offset(xNow, cy - 11.dp.toPx()),
                    end = Offset(xNow, cy + 11.dp.toPx()),
                    strokeWidth = 2.dp.toPx(),
                    cap = StrokeCap.Round,
                )
                // Scrub thumb.
                val xT = x(currentT.coerceIn(fromMs, toMs))
                drawCircle(color = Z.bg, radius = 9.dp.toPx(), center = Offset(xT, cy))
                drawCircle(color = Z.primary, radius = 7.dp.toPx(), center = Offset(xT, cy))
                drawCircle(
                    color = Z.ink,
                    radius = 7.dp.toPx(),
                    center = Offset(xT, cy),
                    style = Stroke(width = 1.5.dp.toPx()),
                )
            }
        }
        Row(
            Modifier
                .fillMaxWidth()
                .padding(start = 48.dp, end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(flowClock(fromMs), color = Z.inkMuted, fontSize = 10.sp, style = flowTabularNums)
            Box(Modifier.weight(1f), contentAlignment = Alignment.Center) {
                Text(
                    "${flowClock(t)} · ${flowOffsetLabel(t, nowMs)}",
                    color = Z.ink,
                    fontSize = 11.sp,
                    fontWeight = FontWeight.SemiBold,
                    style = flowTabularNums,
                )
            }
            Text(flowClock(toMs), color = Z.inkMuted, fontSize = 10.sp, style = flowTabularNums)
        }
    }
}
