package net.acumenus.hummingbird.ui.capacity

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.HouseBrief
import net.acumenus.hummingbird.data.OpsApproval

class CapacityDemandViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var brief by mutableStateOf<HouseBrief?>(null); private set
    var approvals by mutableStateOf<List<OpsApproval>>(emptyList()); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingApprovalIds by mutableStateOf<Set<String>>(emptySet()); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            runCatching { api.commandHouse(bearer) }
                .onSuccess { brief = it }
                .onFailure { e -> if (e is ApiException && e.statusCode == 401) needsReauth = true }

            try {
                approvals = api.opsInbox(bearer)
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

    fun decide(bearer: String, approval: OpsApproval, decision: String, onDone: () -> Unit = {}) {
        workingApprovalIds = workingApprovalIds + approval.approvalUuid
        viewModelScope.launch {
            try {
                api.opsDecision(bearer, approval.approvalUuid, decision)
                approvals = api.opsInbox(bearer)
                error = null
                onDone()
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            workingApprovalIds = workingApprovalIds - approval.approvalUuid
        }
    }
}
