package net.acumenus.hummingbird.ui.flow

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.FlowFloorsDocument
import net.acumenus.hummingbird.data.FlowPlate
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowSnapshot
import net.acumenus.hummingbird.data.FlowTimelineEvent
import net.acumenus.hummingbird.data.FlowWindowData
import java.time.Instant
import java.time.ZoneId

/** What the detail strip is describing: a past event, a future ghost, or a tapped plate. */
sealed interface FlowSelection {
    data class Event(val event: FlowTimelineEvent) : FlowSelection
    data class Ghost(val projection: FlowProjection) : FlowSelection
    data class Plate(val plate: FlowPlate) : FlowSelection
}

/**
 * State for the Flow Window (48h scrub-in-time map). Past accumulates events;
 * scrubbing forward accumulates ghosts symmetrically. Window refreshes shift the
 * frame — they never reset the user's scrub position.
 */
class FlowViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var window by mutableStateOf<FlowWindowData?>(null); private set
    var floors by mutableStateOf<FlowFloorsDocument?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    /** Scrub time t, epoch millis, clamped to [window.from, window.to]. */
    var scrubT by mutableStateOf(0L); private set
    var selectedFloor by mutableStateOf<Int?>(null); private set
    var playing by mutableStateOf(false); private set
    var selection by mutableStateOf<FlowSelection?>(null)

    private var playbackJob: Job? = null
    private var shiftReplayDone = false

    fun load(bearer: String, persona: String, scope: String? = null) {
        loading = true
        viewModelScope.launch {
            try {
                val win = api.flowWindow(bearer, persona, scope)
                if (floors == null) floors = api.flowFloors(bearer)
                val firstLoad = window == null
                window = win
                scrubT = if (firstLoad || scrubT == 0L) {
                    win.nowMs
                } else {
                    scrubT.coerceIn(win.fromMs, win.toMs)
                }
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            loading = false
        }
    }

    // MARK: time model helpers

    /** Past state at t: all events with time ≤ t (the replay accumulator). */
    fun eventsUpTo(t: Long): List<FlowTimelineEvent> =
        window?.events?.filter { it.tMs <= t } ?: emptyList()

    /**
     * Ghosts at t: projections with time ≤ t while t sits in the future half —
     * scrubbing forward accumulates ghosts the same way the past accumulates events.
     */
    fun ghostsUpTo(t: Long): List<FlowProjection> {
        val win = window ?: return emptyList()
        if (t < win.nowMs) return emptyList()
        return win.projections.filter { it.tMs <= t }
    }

    /** Unit census at t = nearest snapshot at or before t (unit rollups come from checkpoints). */
    fun censusAt(t: Long, unitId: Int): FlowSnapshot? =
        window?.snapshots
            ?.filter { it.unitId == unitId && it.tMs <= t }
            ?.maxByOrNull { it.tMs }

    // MARK: scrub + playback

    fun scrubTo(t: Long) {
        pause()
        val win = window ?: return
        scrubT = t.coerceIn(win.fromMs, win.toMs)
    }

    fun selectFloor(floor: Int?) {
        selectedFloor = floor
        if (selection is FlowSelection.Plate) selection = null
    }

    fun togglePlayback() {
        if (playing) pause() else play()
    }

    /** Replay the past half: animate t from `fromMs` (or current past position) to now. */
    fun play(fromMs: Long? = null, durationMs: Long = 12_000L) {
        val win = window ?: return
        playbackJob?.cancel()
        val start = (fromMs ?: if (scrubT in win.fromMs until win.nowMs) scrubT else win.fromMs)
            .coerceIn(win.fromMs, win.nowMs)
        val span = (win.nowMs - start).coerceAtLeast(1L)
        playing = true
        scrubT = start
        playbackJob = viewModelScope.launch {
            val stepMs = 50L
            var elapsed = 0L
            while (elapsed < durationMs && isActive) {
                delay(stepMs)
                elapsed += stepMs
                scrubT = start + span * elapsed / durationMs
            }
            scrubT = win.nowMs
            playing = false
        }
    }

    fun pause() {
        playbackJob?.cancel()
        playbackJob = null
        playing = false
    }

    /**
     * The start-of-shift moment: for charge nurses, open at the last shift boundary
     * (07:00/19:00) and auto-replay the unit's story up to now. Runs once per session.
     */
    fun startOfShiftReplayIfNeeded(persona: String) {
        if (shiftReplayDone) return
        val win = window ?: return
        shiftReplayDone = true
        if (persona != "charge_nurse") return
        val boundary = lastShiftBoundaryMs(win.nowMs).coerceAtLeast(win.fromMs)
        play(fromMs = boundary)
    }

    override fun onCleared() {
        playbackJob?.cancel()
        super.onCleared()
    }
}

/** Most recent 07:00/19:00 shift boundary at or before `nowMs`, in the device zone. */
fun lastShiftBoundaryMs(nowMs: Long, zone: ZoneId = ZoneId.systemDefault()): Long {
    val now = Instant.ofEpochMilli(nowMs).atZone(zone)
    val today = now.toLocalDate()
    val seven = today.atTime(7, 0).atZone(zone)
    val nineteen = today.atTime(19, 0).atZone(zone)
    val boundary = when {
        !now.isBefore(nineteen) -> nineteen
        !now.isBefore(seven) -> seven
        else -> today.minusDays(1).atTime(19, 0).atZone(zone)
    }
    return boundary.toInstant().toEpochMilli()
}

/** Every 07:00/19:00 detent inside [fromMs, toMs], ascending — the Chronobar's shift ticks. */
fun shiftDetentsMs(fromMs: Long, toMs: Long, zone: ZoneId = ZoneId.systemDefault()): List<Long> {
    if (toMs <= fromMs) return emptyList()
    val out = mutableListOf<Long>()
    var day = Instant.ofEpochMilli(fromMs).atZone(zone).toLocalDate().minusDays(1)
    val lastDay = Instant.ofEpochMilli(toMs).atZone(zone).toLocalDate().plusDays(1)
    while (!day.isAfter(lastDay)) {
        for (hour in intArrayOf(7, 19)) {
            val ms = day.atTime(hour, 0).atZone(zone).toInstant().toEpochMilli()
            if (ms in fromMs..toMs) out += ms
        }
        day = day.plusDays(1)
    }
    return out.sorted()
}
