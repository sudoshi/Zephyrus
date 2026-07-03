package net.acumenus.hummingbird.ui.transport

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.ApiException
import net.acumenus.hummingbird.data.TransportQueue

class TransportViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var queue by mutableStateOf<TransportQueue?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingJobId by mutableStateOf<Int?>(null); private set

    fun load(bearer: String) {
        loading = true
        viewModelScope.launch {
            try {
                queue = api.transportQueue(bearer)
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

    fun advance(bearer: String, id: Int, status: String) {
        mutateJob(id) {
            api.transportStatus(bearer, id, status)
            queue = api.transportQueue(bearer)
        }
    }

    fun claim(bearer: String, id: Int) {
        mutateJob(id) {
            api.transportStatus(bearer, id, "assigned")
            queue = api.transportQueue(bearer)
        }
    }

    fun handoff(bearer: String, id: Int, handoffTo: String, summary: String?) {
        mutateJob(id) {
            api.transportHandoff(bearer, id, handoffTo, summary)
            queue = api.transportQueue(bearer)
        }
    }

    private fun mutateJob(id: Int, block: suspend () -> Unit) {
        workingJobId = id
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
            workingJobId = null
        }
    }
}
