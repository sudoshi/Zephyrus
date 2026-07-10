package net.acumenus.hummingbird.ui.staffing

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.StaffingCandidate
import net.acumenus.hummingbird.data.StaffingOverview
import net.acumenus.hummingbird.data.StaffingReq

class StaffingViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var overview by mutableStateOf<StaffingOverview?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingRequestIds by mutableStateOf<Set<Int>>(emptySet()); private set
    var candidateLoadingIds by mutableStateOf<Set<Int>>(emptySet()); private set
    var candidatesByRequest by mutableStateOf<Map<Int, List<StaffingCandidate>>>(emptyMap()); private set
    var selectedCandidateByRequest by mutableStateOf<Map<Int, Int>>(emptyMap()); private set
    var sourceByRequest by mutableStateOf<Map<Int, String>>(emptyMap()); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                overview = api.staffingOverview(bearer)
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

    fun loadCandidates(bearer: String, request: StaffingReq) {
        candidateLoadingIds = candidateLoadingIds + request.staffingRequestId
        viewModelScope.launch {
            try {
                val candidates = api.staffingCandidates(bearer, request.staffingRequestId)
                candidatesByRequest = candidatesByRequest + (request.staffingRequestId to candidates)
                val firstEligible = candidates.firstOrNull { it.eligible }
                selectedCandidateByRequest = if (firstEligible == null) {
                    selectedCandidateByRequest - request.staffingRequestId
                } else {
                    selectedCandidateByRequest + (request.staffingRequestId to firstEligible.staffMemberId)
                }
                sourceByRequest = sourceByRequest + (request.staffingRequestId to (sourceByRequest[request.staffingRequestId] ?: "float_pool"))
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            candidateLoadingIds = candidateLoadingIds - request.staffingRequestId
        }
    }

    fun selectCandidate(requestId: Int, staffMemberId: Int) {
        selectedCandidateByRequest = selectedCandidateByRequest + (requestId to staffMemberId)
    }

    fun fillSelected(bearer: String, request: StaffingReq, onDone: () -> Unit = {}) {
        val staffMemberId = selectedCandidateByRequest[request.staffingRequestId] ?: run {
            error = "Select a qualified, available staff member."
            return
        }
        workingRequestIds = workingRequestIds + request.staffingRequestId
        viewModelScope.launch {
            try {
                api.fillStaffingRequest(
                    bearer,
                    request.staffingRequestId,
                    staffMemberId,
                    sourceByRequest[request.staffingRequestId] ?: "float_pool",
                )
                overview = api.staffingOverview(bearer)
                candidatesByRequest = candidatesByRequest - request.staffingRequestId
                selectedCandidateByRequest = selectedCandidateByRequest - request.staffingRequestId
                error = null
                onDone()
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            workingRequestIds = workingRequestIds - request.staffingRequestId
        }
    }
}
