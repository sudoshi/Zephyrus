package net.acumenus.hummingbird.data

import android.app.Application
import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch

class AltitudeViewModel(app: Application) : AndroidViewModel(app) {
    private val api = ApiClient()
    private val prefs = app.getSharedPreferences("hb", Context.MODE_PRIVATE)

    var selectedRole by mutableStateOf(MobileRoleCatalog.default); private set
    var selectedDomain by mutableStateOf(MobileRoleCatalog.default.defaultDomain); private set
    var confirmedProfile by mutableStateOf(ConfirmedProfile()); private set
    var profileUnits by mutableStateOf<List<CensusUnit>>(emptyList()); private set
    var loadingProfileUnits by mutableStateOf(false); private set

    var home by mutableStateOf<AltitudeHome?>(null); private set
    var workspace by mutableStateOf<AltitudeWorkspace?>(null); private set
    var drill by mutableStateOf<DrillDetail?>(null); private set
    var patientContext by mutableStateOf<PatientOperationalContext?>(null); private set
    var activity by mutableStateOf(ActivityFeed(emptyList(), null)); private set
    var eddyContext by mutableStateOf<EddyContext?>(null); private set

    var loading by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    var needsReauth by mutableStateOf(false); private set

    fun loadProfileForUser(me: MeData?) {
        val userId = me?.id ?: return
        val roleId = prefs.getString(profileKey("role", userId), null)
        val unitId = prefs.getInt(profileKey("unit", userId), 0).takeIf { it != 0 }
        val unitName = prefs.getString(profileKey("unitName", userId), null)
        val preselectedRole = roleId
            ?.let(MobileRoleCatalog::byId)
            ?: MobileRoleCatalog.matchingServerRoles(me.roles)
            ?: MobileRoleCatalog.default

        confirmedProfile = ConfirmedProfile(roleId = roleId, unitId = unitId, unitName = unitName)
        selectRole(preselectedRole)
    }

    fun confirmProfile(userId: Int, role: MobileRole, unit: CensusUnit?) {
        val editor = prefs.edit().putString(profileKey("role", userId), role.id)
        if (unit == null) {
            editor.remove(profileKey("unit", userId))
            editor.putString(profileKey("unitName", userId), "House-wide")
        } else {
            editor.putInt(profileKey("unit", userId), unit.unitId)
            editor.putString(profileKey("unitName", userId), unit.name)
        }
        editor.apply()

        confirmedProfile = ConfirmedProfile(
            roleId = role.id,
            unitId = unit?.unitId,
            unitName = unit?.name ?: "House-wide",
        )
        selectRole(role)
    }

    fun loadProfileUnits(bearer: String) {
        if (profileUnits.isNotEmpty() || loadingProfileUnits) return
        loadingProfileUnits = true
        viewModelScope.launch {
            try {
                profileUnits = api.census(bearer).units
            } catch (e: ApiException) {
                if (e.statusCode == 401) needsReauth = true
                error = e.message
            } catch (e: Exception) {
                error = e.message
            }
            loadingProfileUnits = false
        }
    }

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

    private fun profileKey(key: String, userId: Int): String = "hb.$key.$userId"
}
