package net.acumenus.hummingbird.data

import android.app.Application
import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import net.acumenus.hummingbird.widget.HouseGlanceStore
import java.util.UUID

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
    var eddyChatTurns by mutableStateOf<List<EddyChatTurn>>(emptyList()); private set
    var eddyChatLoading by mutableStateOf(false); private set
    var eddyChatError by mutableStateOf<String?>(null); private set
    var eddyConversationHistory by mutableStateOf<List<EddyConversationSummary>>(emptyList()); private set
    var eddyConversationDetail by mutableStateOf<EddyConversationDetail?>(null); private set
    var eddyHistoryLoading by mutableStateOf(false); private set
    var eddyHistoryError by mutableStateOf<String?>(null); private set
    var eddyApprovals by mutableStateOf<List<EddyApprovalSummary>>(emptyList()); private set
    var eddyApprovalPreview by mutableStateOf<EddyApprovalPreview?>(null); private set
    var eddyApprovalsLoading by mutableStateOf(false); private set
    var eddyApprovalsError by mutableStateOf<String?>(null); private set
    var eddyApprovalWorking by mutableStateOf(false); private set
    var eddyApprovalDecision by mutableStateOf<EddyApprovalDecisionResult?>(null); private set
    private val eddyApprovalIdempotencyKeys = mutableMapOf<String, String>()
    private var eddyConversationId: String? = null
    private var eddyScopeRef: String? = null

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
        resetEddyChat()
        resetEddyHistory()
        resetEddyApprovals()
        error = null
    }

    fun selectDomain(domain: String) {
        selectedDomain = domain
        workspace = null
        error = null
    }

    fun loadHome(bearer: String) = request {
        home = api.altitudeHome(bearer, selectedRole.id)
        // Feed the house-glance widget on every home load (app-driven, no background work).
        home?.let { runCatching { HouseGlanceStore.updateFromHome(getApplication<android.app.Application>(), it) } }
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

    fun loadEddyConversationHistory(bearer: String) {
        if (eddyHistoryLoading) return
        val roleId = selectedRole.id
        eddyHistoryLoading = true
        eddyHistoryError = null
        viewModelScope.launch {
            try {
                val history = api.eddyConversations(bearer, roleId)
                    .filter { it.id.isNotBlank() }
                if (selectedRole.id == roleId) eddyConversationHistory = history
            } catch (e: ApiException) {
                if (selectedRole.id != roleId) return@launch
                if (e.statusCode == 401) needsReauth = true
                eddyHistoryError = e.message ?: "Eddy conversation history is unavailable right now."
            } catch (_: Exception) {
                if (selectedRole.id != roleId) return@launch
                eddyHistoryError = "Eddy conversation history is unavailable right now."
            } finally {
                if (selectedRole.id == roleId) eddyHistoryLoading = false
            }
        }
    }

    fun loadEddyConversation(bearer: String, conversationId: String) {
        if (conversationId.isBlank() || eddyHistoryLoading) return
        val roleId = selectedRole.id
        eddyHistoryLoading = true
        eddyHistoryError = null
        eddyConversationDetail = null
        viewModelScope.launch {
            try {
                val conversation = api.eddyConversation(bearer, conversationId, roleId)
                if (selectedRole.id == roleId) eddyConversationDetail = conversation
            } catch (e: ApiException) {
                if (selectedRole.id != roleId) return@launch
                if (e.statusCode == 401) needsReauth = true
                eddyHistoryError = e.message ?: "This Eddy conversation is unavailable right now."
            } catch (_: Exception) {
                if (selectedRole.id != roleId) return@launch
                eddyHistoryError = "This Eddy conversation is unavailable right now."
            } finally {
                if (selectedRole.id == roleId) eddyHistoryLoading = false
            }
        }
    }

    /** Read the current user's pending Eddy proposals from the server, never from a device cache. */
    fun loadEddyApprovals(bearer: String) {
        if (eddyApprovalsLoading) return
        val roleId = selectedRole.id
        eddyApprovalsLoading = true
        eddyApprovalsError = null
        eddyApprovalDecision = null
        viewModelScope.launch {
            try {
                val approvals = api.eddyApprovals(bearer, roleId)
                    .filter { it.approvalUuid.isNotBlank() }
                if (selectedRole.id == roleId) eddyApprovals = approvals
            } catch (e: ApiException) {
                if (selectedRole.id != roleId) return@launch
                if (e.statusCode == 401) needsReauth = true
                eddyApprovalsError = e.message ?: "Eddy approvals are unavailable right now."
            } catch (_: Exception) {
                if (selectedRole.id != roleId) return@launch
                eddyApprovalsError = "Eddy approvals are unavailable right now."
            } finally {
                if (selectedRole.id == roleId) eddyApprovalsLoading = false
            }
        }
    }

    /** Fetch the no-store dry run immediately before the user may confirm a decision. */
    fun loadEddyApproval(bearer: String, approvalId: String) {
        if (approvalId.isBlank() || eddyApprovalsLoading) return
        val roleId = selectedRole.id
        eddyApprovalsLoading = true
        eddyApprovalsError = null
        eddyApprovalPreview = null
        eddyApprovalDecision = null
        viewModelScope.launch {
            try {
                val preview = api.eddyApproval(bearer, approvalId, roleId)
                if (selectedRole.id == roleId) eddyApprovalPreview = preview
            } catch (e: ApiException) {
                if (selectedRole.id != roleId) return@launch
                if (e.statusCode == 401) needsReauth = true
                eddyApprovalsError = e.message ?: "This Eddy approval is unavailable right now."
            } catch (_: Exception) {
                if (selectedRole.id != roleId) return@launch
                eddyApprovalsError = "This Eddy approval is unavailable right now."
            } finally {
                if (selectedRole.id == roleId) eddyApprovalsLoading = false
            }
        }
    }

    /**
     * Send a consciously selected human decision online. The exact idempotency key remains
     * only in this ViewModel while an explicit retry is possible; it is never persisted or
     * automatically replayed after an authorization failure.
     */
    fun decideEddyApproval(bearer: String, approvalId: String, decision: String) {
        if (approvalId.isBlank() || decision !in setOf("approved", "rejected") || eddyApprovalWorking) return
        val roleId = selectedRole.id
        val keyRef = "$approvalId:$decision"
        val idempotencyKey = eddyApprovalIdempotencyKeys.getOrPut(keyRef) { UUID.randomUUID().toString() }
        eddyApprovalWorking = true
        eddyApprovalsError = null
        viewModelScope.launch {
            try {
                val result = api.decideEddyApproval(
                    bearer = bearer,
                    approvalId = approvalId,
                    persona = roleId,
                    decision = decision,
                    idempotencyKey = idempotencyKey,
                )
                if (selectedRole.id == roleId) {
                    eddyApprovalDecision = result
                    eddyApprovals = eddyApprovals.filterNot { it.approvalUuid == approvalId }
                    eddyApprovalIdempotencyKeys.remove(keyRef)
                }
            } catch (e: ApiException) {
                if (selectedRole.id != roleId) return@launch
                if (e.statusCode == 401) needsReauth = true
                eddyApprovalsError = e.message
                    ?: "The Eddy decision was not recorded. Check live connectivity and review server status before intentionally trying again."
            } catch (_: Exception) {
                if (selectedRole.id != roleId) return@launch
                eddyApprovalsError = "The Eddy decision was not recorded. Check live connectivity and review server status before intentionally trying again."
            } finally {
                if (selectedRole.id == roleId) eddyApprovalWorking = false
            }
        }
    }

    /**
     * Start an in-memory Eddy transcript for a single authorized operational scope.
     * Switching scope or persona intentionally drops the prior transcript rather than
     * persisting potentially sensitive prompts outside the server conversation store.
     */
    fun beginEddyChat(scopeRef: String) {
        if (eddyScopeRef == scopeRef) return
        resetEddyChat()
        eddyScopeRef = scopeRef
    }

    fun sendEddyMessage(bearer: String, scopeRef: String, message: String) {
        val trimmed = message.trim()
        if (trimmed.isEmpty() || eddyChatLoading) return
        if (trimmed.length > EDDY_MESSAGE_MAX_LENGTH) {
            eddyChatError = "Messages are limited to 8,000 characters."
            return
        }

        beginEddyChat(scopeRef)
        val conversationId = eddyConversationId
        val roleId = selectedRole.id
        eddyChatTurns = eddyChatTurns + EddyChatTurn(EddyChatRole.USER, trimmed) +
            EddyChatTurn(EddyChatRole.ASSISTANT, "", pending = true)
        eddyChatLoading = true
        eddyChatError = null

        viewModelScope.launch {
            try {
                val reply = api.eddyChat(
                    bearer = bearer,
                    message = trimmed,
                    conversationId = conversationId,
                    persona = roleId,
                    pageContext = "eddy_context",
                    pageComponent = "Eddy context",
                    pageData = mapOf(
                        "screen" to "Eddy context",
                        "persona" to roleId,
                        "scope_ref" to scopeRef,
                    ),
                )
                if (eddyScopeRef != scopeRef) return@launch
                eddyConversationId = reply.conversationId ?: eddyConversationId
                resolvePendingEddyTurn(reply.message.content, reply.message.provider)
            } catch (e: ApiException) {
                if (eddyScopeRef != scopeRef) return@launch
                if (e.statusCode == 401) needsReauth = true
                val message = e.message ?: "Eddy is unavailable right now. Please try again shortly."
                eddyChatError = message
                resolvePendingEddyTurn(message, null)
            } catch (_: Exception) {
                if (eddyScopeRef != scopeRef) return@launch
                val message = "Eddy is unavailable right now. Please try again shortly."
                eddyChatError = message
                resolvePendingEddyTurn(message, null)
            } finally {
                if (eddyScopeRef == scopeRef) eddyChatLoading = false
            }
        }
    }

    private fun resolvePendingEddyTurn(message: String, provider: String?) {
        val index = eddyChatTurns.indexOfLast { it.pending }
        val resolved = EddyChatTurn(
            role = EddyChatRole.ASSISTANT,
            text = message.ifBlank { "…" },
            provider = provider,
        )
        eddyChatTurns = if (index >= 0) {
            eddyChatTurns.toMutableList().also { it[index] = resolved }
        } else {
            eddyChatTurns + resolved
        }
    }

    private fun resetEddyChat() {
        eddyChatTurns = emptyList()
        eddyChatLoading = false
        eddyChatError = null
        eddyConversationId = null
        eddyScopeRef = null
    }

    private fun resetEddyHistory() {
        eddyConversationHistory = emptyList()
        eddyConversationDetail = null
        eddyHistoryLoading = false
        eddyHistoryError = null
    }

    private fun resetEddyApprovals() {
        eddyApprovals = emptyList()
        eddyApprovalPreview = null
        eddyApprovalsLoading = false
        eddyApprovalsError = null
        eddyApprovalWorking = false
        eddyApprovalDecision = null
        eddyApprovalIdempotencyKeys.clear()
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

    private companion object {
        const val EDDY_MESSAGE_MAX_LENGTH = 8_000
    }
}
