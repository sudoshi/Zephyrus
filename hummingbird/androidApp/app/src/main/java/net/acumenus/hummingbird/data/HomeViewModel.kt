package net.acumenus.hummingbird.data

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.math.roundToInt

class HomeViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var units by mutableStateOf<List<CensusUnit>>(emptyList()); private set
    var asOf by mutableStateOf<String?>(null); private set
    var webLink by mutableStateOf<String?>(null); private set
    var stale by mutableStateOf(false); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                val r = api.census(bearer)
                units = r.units; asOf = r.asOf; stale = r.stale; webLink = r.webLink; error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message; stale = true
            } catch (e: Exception) {
                error = e.message; stale = true
            }
            loading = false
        }
    }

    val totalOccupied: Int get() = units.sumOf { it.occupied }
    val totalSafe: Int get() = units.sumOf { it.safeCapacity }
    val occupancyPercent: Int get() = if (totalSafe > 0) (totalOccupied * 100.0 / totalSafe).roundToInt() else 0
    val worstStatus: CapacityStatus get() = units.map { it.capacity }.maxByOrNull { it.severity } ?: CapacityStatus.INFO
    val pressuredCount: Int get() = units.count { it.capacity == CapacityStatus.WARNING || it.capacity == CapacityStatus.CRITICAL }

    val asOfDisplay: String
        get() = asOf?.let {
            runCatching {
                DateTimeFormatter.ofPattern("h:mm a").withZone(ZoneId.systemDefault()).format(Instant.parse(it))
            }.getOrDefault("—")
        } ?: "—"
}
