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
    private var noCacheEpoch = 0L
    private var patientCommunicationAccessAllowed = false

    var items by mutableStateOf<List<ForYouItem>>(emptyList()); private set
    var unitsByName by mutableStateOf<Map<String, CensusUnit>>(emptyMap()); private set
    var webLink by mutableStateOf<String?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingItemIds by mutableStateOf<Set<String>>(emptySet()); private set

    fun load(
        bearer: String,
        role: MobileRole,
        canViewPatientCommunications: Boolean = false,
    ) {
        updatePatientCommunicationAccess(canViewPatientCommunications)
        loading = true
        val requestEpoch = noCacheEpoch
        viewModelScope.launch {
            val refreshedItems = try {
                api.forYou(bearer, role.id)
            } catch (e: Exception) {
                if (requestEpoch == noCacheEpoch) handleQueueLoadFailure(e)
                return@launch
            }
            if (requestEpoch != noCacheEpoch) return@launch
            acceptLoadedQueue(refreshedItems)
            error = null

            runCatching { api.census(bearer) }
                .onSuccess { census ->
                    if (requestEpoch == noCacheEpoch) {
                        acceptCensusLookup(census)
                    }
                }
                .onFailure { e ->
                    if (requestEpoch == noCacheEpoch) {
                        unitsByName = emptyMap()
                        webLink = null
                        if (e is ApiException && e.statusCode == 401) needsReauth = true
                    }
                }
            if (requestEpoch == noCacheEpoch) loading = false
        }
    }

    fun unitFor(item: ForYouItem): CensusUnit? = item.unit?.let(unitsByName::get)

    fun filteredItems(
        role: MobileRole,
        unitName: String?,
        canViewPatientCommunications: Boolean = false,
    ): List<ForYouItem> =
        items.filter { item ->
            ForYouQueuePolicy.keepVisible(
                canViewPatientCommunications = canViewPatientCommunications,
                filter = role.queueFilter,
                item = item,
                unitName = unitName,
                unitsByName = unitsByName,
            )
        }

    /**
     * Applies the canonical `/me.can.view_patient_communications` capability and immediately
     * purges communication identifiers if access is absent or revoked.
     */
    fun updatePatientCommunicationAccess(allowed: Boolean) {
        patientCommunicationAccessAllowed = allowed
        if (!allowed) {
            val restrictedIds = items
                .filter { PatientCommunicationForYou.isType(it.type) }
                .mapTo(mutableSetOf(), ForYouItem::id)
            items = items.filterNot { PatientCommunicationForYou.isType(it.type) }
            workingItemIds = workingItemIds - restrictedIds
        }
    }

    /** Clears the in-memory-only queue and every lookup/action derivative. */
    fun clearNoCacheState() {
        noCacheEpoch += 1
        items = emptyList()
        unitsByName = emptyMap()
        webLink = null
        workingItemIds = emptySet()
        error = null
        loading = false
    }

    internal fun acceptLoadedQueue(loaded: List<ForYouItem>) {
        items = if (patientCommunicationAccessAllowed) {
            loaded
        } else {
            loaded.filterNot { PatientCommunicationForYou.isType(it.type) }
        }
    }

    internal fun acceptCensusLookup(census: CensusResult) {
        unitsByName = census.units.associateBy { it.name }
        webLink = census.webLink
    }

    internal fun beginAction(itemId: String) {
        workingItemIds = workingItemIds + itemId
    }

    fun resolveBarrier(bearer: String, item: ForYouItem, role: MobileRole) {
        val id = refId(item.id, "barrier-") ?: return
        mutate(item.id) {
            api.resolveBarrier(bearer, id)
            api.forYou(bearer, role.id)
        }
    }

    fun claimTransport(bearer: String, item: ForYouItem, role: MobileRole) {
        val id = refId(item.id, "transport-") ?: return
        mutate(item.id) {
            api.transportStatus(bearer, id, "assigned")
            api.forYou(bearer, role.id)
        }
    }

    fun claimEvsTurn(bearer: String, item: ForYouItem, role: MobileRole) {
        val id = refId(item.id, "evs-") ?: return
        mutate(item.id) {
            api.evsStatus(bearer, id, "assigned")
            api.forYou(bearer, role.id)
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
            api.forYou(bearer, role.id)
        }
    }

    private fun mutate(itemId: String, block: suspend () -> List<ForYouItem>) {
        beginAction(itemId)
        val requestEpoch = noCacheEpoch
        viewModelScope.launch {
            try {
                val refreshedItems = block()
                if (requestEpoch == noCacheEpoch) {
                    acceptLoadedQueue(refreshedItems)
                    error = null
                }
            } catch (e: Exception) {
                if (requestEpoch == noCacheEpoch) handleQueueLoadFailure(e)
            } finally {
                if (requestEpoch == noCacheEpoch) {
                    workingItemIds = workingItemIds - itemId
                }
            }
        }
    }

    internal fun handleQueueLoadFailure(failure: Exception) {
        clearNoCacheState()
        if (failure is ApiException && failure.statusCode == 401) needsReauth = true
        error = failure.message ?: "Can't load your queue right now."
    }

    private fun refId(composite: String, prefix: String): Int? =
        composite.takeIf { it.startsWith(prefix) }?.drop(prefix.length)?.toIntOrNull()

    private fun refString(composite: String, prefix: String): String? =
        composite.takeIf { it.startsWith(prefix) }?.drop(prefix.length)?.takeIf { it.isNotBlank() }

}

/** Pure queue policy so every server-authorized communication attention item survives role filters. */
internal object ForYouQueuePolicy {
    private val criticalUnitTypes = setOf("icu", "step_down")

    fun keepVisible(
        canViewPatientCommunications: Boolean,
        filter: QueueFilter,
        item: ForYouItem,
        unitName: String?,
        unitsByName: Map<String, CensusUnit>,
    ): Boolean =
        (canViewPatientCommunications || !PatientCommunicationForYou.isType(item.type)) &&
            keep(filter, item, unitName, unitsByName)

    fun keep(
        filter: QueueFilter,
        item: ForYouItem,
        unitName: String?,
        unitsByName: Map<String, CensusUnit>,
    ): Boolean {
        if (PatientCommunicationForYou.isType(item.type)) return true

        return when (filter) {
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
    }
}
