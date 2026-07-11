package net.acumenus.hummingbird.data

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch

class ForYouViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var items by mutableStateOf<List<ForYouItem>>(emptyList()); private set
    var unitsByName by mutableStateOf<Map<String, CensusUnit>>(emptyMap()); private set
    var webLink by mutableStateOf<String?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingItemIds by mutableStateOf<Set<String>>(emptySet()); private set

    fun load(bearer: String, role: MobileRole) {
        loading = true
        viewModelScope.launch {
            try {
                items = api.forYou(bearer, role.id)
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            runCatching { api.census(bearer) }
                .onSuccess { census ->
                    unitsByName = census.units.associateBy { it.name }
                    webLink = census.webLink
                }
                .onFailure { e ->
                    if (e is ApiException && e.statusCode == 401) needsReauth = true
                }
            loading = false
        }
    }

    fun unitFor(item: ForYouItem): CensusUnit? = item.unit?.let(unitsByName::get)

    fun filteredItems(role: MobileRole, unitName: String?): List<ForYouItem> =
        items.filter { item -> keep(role.queueFilter, item, unitName) }

    fun resolveBarrier(bearer: String, item: ForYouItem, role: MobileRole) {
        val id = refId(item.id, "barrier-") ?: return
        mutate(item.id) {
            api.resolveBarrier(bearer, id)
            items = api.forYou(bearer, role.id)
        }
    }

    fun claimTransport(bearer: String, item: ForYouItem, role: MobileRole) {
        val id = refId(item.id, "transport-") ?: return
        mutate(item.id) {
            api.transportStatus(bearer, id, "assigned")
            items = api.forYou(bearer, role.id)
        }
    }

    fun claimEvsTurn(bearer: String, item: ForYouItem, role: MobileRole) {
        val id = refId(item.id, "evs-") ?: return
        mutate(item.id) {
            api.evsStatus(bearer, id, "assigned")
            items = api.forYou(bearer, role.id)
        }
    }

    fun approveOpsAction(bearer: String, item: ForYouItem, role: MobileRole) {
        decideOpsAction(bearer, item, role, "approved")
    }

    fun rejectOpsAction(bearer: String, item: ForYouItem, role: MobileRole) {
        decideOpsAction(bearer, item, role, "rejected")
    }

    private fun decideOpsAction(bearer: String, item: ForYouItem, role: MobileRole, decision: String) {
        val approvalUuid = refString(item.id, "ops-approval-") ?: return
        mutate(item.id) {
            api.opsDecision(bearer, approvalUuid, decision)
            items = api.forYou(bearer, role.id)
        }
    }

    private fun mutate(itemId: String, block: suspend () -> Unit) {
        workingItemIds = workingItemIds + itemId
        viewModelScope.launch {
            try {
                block()
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            workingItemIds = workingItemIds - itemId
        }
    }

    private fun keep(filter: QueueFilter, item: ForYouItem, unitName: String?): Boolean = when (filter) {
        QueueFilter.All -> true
        QueueFilter.None -> false
        QueueFilter.Placements -> item.type == "bed_request" || item.type == "capacity"
        QueueFilter.Escalations -> item.type == "barrier" || item.type == "capacity"
        QueueFilter.Turns -> item.type == "capacity"
        QueueFilter.MyUnit -> unitName == null || item.unit == unitName || item.type == "bed_request"
        QueueFilter.CriticalCare -> {
            if (item.type == "bed_request") {
                val subtitle = item.subtitle.lowercase()
                subtitle.contains("icu") || subtitle.contains("step")
            } else {
                item.unit?.let(unitsByName::get)?.type?.let { it in criticalUnitTypes } ?: false
            }
        }
    }

    private fun refId(composite: String, prefix: String): Int? =
        composite.takeIf { it.startsWith(prefix) }?.drop(prefix.length)?.toIntOrNull()

    private fun refString(composite: String, prefix: String): String? =
        composite.takeIf { it.startsWith(prefix) }?.drop(prefix.length)?.takeIf { it.isNotBlank() }

    companion object {
        private val criticalUnitTypes = setOf("icu", "step_down")
    }
}
