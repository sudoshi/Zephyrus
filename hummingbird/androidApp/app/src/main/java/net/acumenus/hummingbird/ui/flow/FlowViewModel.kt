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
import net.acumenus.hummingbird.data.FlowWindowCache
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.data.mergeFlowWindow
import net.acumenus.hummingbird.data.newestLoadedSinceIso
import net.acumenus.hummingbird.widget.HouseGlanceStore
import org.json.JSONObject
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
    private val cache = FlowWindowCache(app)

    var window by mutableStateOf<FlowWindowData?>(null); private set
    var floors by mutableStateOf<FlowFloorsDocument?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    /** When non-null, the shown window came from the offline cache; value = when it was cached. */
    var offlineAsOfMs by mutableStateOf<Long?>(null); private set

    /** Scrub time t, epoch millis, clamped to [window.from, window.to]. */
    var scrubT by mutableStateOf(0L); private set
    var selectedFloor by mutableStateOf<Int?>(null); private set
    var playing by mutableStateOf(false); private set
    var selection by mutableStateOf<FlowSelection?>(null)

    private var playbackJob: Job? = null
    private var shiftReplayDone = false

    /** The (persona, scope) the current window belongs to — gates whether a refresh can delta. */
    private var loadedKey: String? = null
    private var floorsRaw: String? = null

    /**
     * Load or refresh the window. When a window is already loaded for the same persona+scope
     * (the 20s poll / re-snapshot / foreground-return path), sends `since=<newest loaded
     * event/snapshot t>` and merges the delta per the contract; falls back to a full load on
     * HTTP 422 (invalid_since) or a parse failure. On a hard load failure, serves the offline
     * cache for this user with a staleness caption. [userId] keys the cache; null ⇒ no caching.
     */
    fun load(bearer: String, persona: String, scope: String? = null, userId: Int? = null) {
        loading = true
        viewModelScope.launch {
            val key = "$persona|${scope ?: ""}"
            val current = window
            val canDelta = current != null && loadedKey == key
            val since = if (canDelta) newestLoadedSinceIso(current!!) else null
            try {
                if (floors == null) {
                    val (doc, raw) = api.flowFloorsRaw(bearer)
                    floors = doc
                    floorsRaw = raw
                }
                val fetch = try {
                    api.flowWindowRaw(bearer, persona, scope, since)
                } catch (e: ApiException) {
                    // Delta rejected (stale cursor) ⇒ retry once as a full load.
                    if (since != null && (e.statusCode == 422 || e.errorCode == "invalid_since")) {
                        api.flowWindowRaw(bearer, persona, scope, null)
                    } else {
                        throw e
                    }
                }
                val fresh = fetch.data
                val isDelta = since != null && fresh.window.since != null
                val firstLoad = window == null
                window = if (isDelta && current != null) mergeFlowWindow(current, fresh) else fresh
                loadedKey = key
                offlineAsOfMs = null
                // Cache only FULL (non-delta) payloads, keyed by the authenticated user.
                if (!isDelta && userId != null) {
                    cache.putWindow(userId, persona, scope, fetch.raw, floorsRaw)
                }
                val win = window!!
                scrubT = if (firstLoad || scrubT == 0L) win.nowMs else scrubT.coerceIn(win.fromMs, win.toMs)
                error = null
                writeGlance(win)
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                if (!serveFromCache(persona, scope, userId)) error = e.message
            } catch (e: Exception) {
                if (!serveFromCache(persona, scope, userId)) error = e.message
            }
            loading = false
        }
    }

    /** Present the last cached FULL window for this user; returns true on a cache hit. */
    private fun serveFromCache(persona: String, scope: String?, userId: Int?): Boolean {
        if (window != null) return true // keep what's already shown rather than downgrade
        if (userId == null) return false
        val cached = cache.readWindow(userId, persona, scope) ?: return false
        val doc = runCatching { api.parseFlowWindow(JSONObject(cached.windowRaw)) }.getOrNull() ?: return false
        if (floors == null) {
            cached.floorsRaw?.let { raw -> runCatching { api.parseFlowFloors(JSONObject(raw)) }.getOrNull()?.let { floors = it } }
        }
        window = doc
        loadedKey = "$persona|${scope ?: ""}"
        offlineAsOfMs = cached.updatedAtMs
        scrubT = if (scrubT == 0L) doc.nowMs else scrubT.coerceIn(doc.fromMs, doc.toMs)
        error = null
        return true
    }

    private fun writeGlance(win: FlowWindowData) {
        viewModelScope.launch { runCatching { HouseGlanceStore.updateFromFlow(getApplication<android.app.Application>(), win) } }
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
