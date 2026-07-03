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
import net.acumenus.hummingbird.data.StaffingOverview
import net.acumenus.hummingbird.data.StaffingReq

class StaffingViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var overview by mutableStateOf<StaffingOverview?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingRequestIds by mutableStateOf<Set<Int>>(emptySet()); private set

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

    fun fillFromFloatPool(bearer: String, request: StaffingReq, onDone: () -> Unit = {}) {
        workingRequestIds = workingRequestIds + request.staffingRequestId
        viewModelScope.launch {
            try {
                api.fillStaffingRequest(bearer, request.staffingRequestId, "Float Pool")
                overview = api.staffingOverview(bearer)
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
