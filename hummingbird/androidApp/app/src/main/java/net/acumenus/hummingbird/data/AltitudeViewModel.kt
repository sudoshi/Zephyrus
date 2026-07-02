package net.acumenus.hummingbird.data

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch

class AltitudeViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()

    var selectedRole by mutableStateOf(MobileRoleCatalog.default); private set
    var selectedDomain by mutableStateOf(MobileRoleCatalog.default.defaultDomain); private set

    var home by mutableStateOf<AltitudeHome?>(null); private set
    var workspace by mutableStateOf<AltitudeWorkspace?>(null); private set
    var drill by mutableStateOf<DrillDetail?>(null); private set
    var patientContext by mutableStateOf<PatientOperationalContext?>(null); private set
    var activity by mutableStateOf(ActivityFeed(emptyList(), null)); private set
    var eddyContext by mutableStateOf<EddyContext?>(null); private set

    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    fun selectRole(role: MobileRole) {
        selectedRole = role
        selectedDomain = role.defaultDomain
        drill = null
        patientContext = null
        eddyContext = null
        error = null
    }

    fun selectDomain(domain: String) {
        selectedDomain = domain
        workspace = null
        error = null
    }

    fun loadHome(bearer: String) = request {
        home = api.altitudeHome(bearer, selectedRole.id)
    }

    fun loadWorkspace(bearer: String) = request {
        workspace = api.altitudeWorkspace(bearer, selectedDomain, selectedRole.id)
    }

    fun loadDrill(bearer: String, itemUuid: String) = request {
        drill = api.drill(bearer, itemUuid, selectedRole.id)
    }

    fun loadPatientContext(bearer: String, contextRef: String) = request {
        patientContext = api.patientOperationalContext(bearer, contextRef, selectedRole.id)
    }

    fun loadActivity(bearer: String, cursor: String? = null) = request {
        val next = api.activity(bearer, selectedRole.id, cursor)
        activity = if (cursor == null) next else ActivityFeed(activity.events + next.events, next.nextCursor)
    }

    fun acknowledgeActivity(bearer: String, eventUuid: String) = request {
        api.ackActivity(bearer, eventUuid, selectedRole.id)
        activity = ActivityFeed(activity.events.filterNot { it.eventUuid == eventUuid }, activity.nextCursor)
    }

    fun loadEddyContext(bearer: String, scopeRef: String) = request {
        eddyContext = api.eddyContext(bearer, scopeRef, selectedRole.id)
    }

    private fun request(block: suspend () -> Unit) {
        loading = true
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
            loading = false
        }
    }
}
