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
import net.acumenus.hummingbird.data.TransportJob
import net.acumenus.hummingbird.data.TransportQueue

class TransportViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var queue by mutableStateOf<TransportQueue?>(null); private set
    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set
    var workingJobId by mutableStateOf<Int?>(null); private set
    var loadingMore by mutableStateOf(false); private set

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

    fun loadMore(bearer: String) {
        val current = queue ?: return
        val cursor = current.nextCursor ?: return
        if (loadingMore || !current.hasMore) return

        loadingMore = true
        viewModelScope.launch {
            try {
                val page = api.transportQueue(bearer, cursor)
                val known = current.jobs.mapTo(mutableSetOf()) { it.id }
                queue = page.copy(jobs = current.jobs + page.jobs.filter { known.add(it.id) })
                error = null
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            loadingMore = false
        }
    }

    fun advance(
        bearer: String,
        id: Int,
        status: String,
        lifecycleVersion: Int,
        onSuccess: (TransportJob) -> Unit = {},
    ) {
        mutateJob(id) {
            val updated = api.transportStatus(bearer, id, status, lifecycleVersion)
            queue = api.transportQueue(bearer)
            onSuccess(updated)
        }
    }

    fun claim(bearer: String, id: Int, lifecycleVersion: Int) {
        mutateJob(id) {
            api.transportStatus(bearer, id, "assigned", lifecycleVersion)
            queue = api.transportQueue(bearer)
        }
    }

    fun handoff(
        bearer: String,
        id: Int,
        handoffTo: String,
        receiverRole: String,
        acceptanceStatus: String,
        outstandingRisk: String?,
        summary: String?,
        lifecycleVersion: Int,
        onSuccess: (TransportJob) -> Unit = {},
    ) {
        mutateJob(id) {
            val updated = api.transportHandoff(
                bearer,
                id,
                handoffTo,
                receiverRole,
                acceptanceStatus,
                outstandingRisk,
                summary,
                lifecycleVersion,
            )
            queue = api.transportQueue(bearer)
            onSuccess(updated)
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
