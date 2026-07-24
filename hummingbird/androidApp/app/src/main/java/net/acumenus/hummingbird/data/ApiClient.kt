package net.acumenus.hummingbird.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.withContext
import net.acumenus.hummingbird.BuildConfig
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.MessageDigest

data class StaffTokenSession(
    val accessToken: String,
    val refreshToken: String,
    val accessExpiresAtEpochMs: Long,
)

interface StaffTokenStore {
    fun load(): StaffTokenSession?
    fun save(session: StaffTokenSession): Boolean
    fun clear()
}

private class VolatileStaffTokenStore : StaffTokenStore {
    private var session: StaffTokenSession? = null
    override fun load(): StaffTokenSession? = session
    override fun save(session: StaffTokenSession): Boolean {
        this.session = session
        return true
    }
    override fun clear() {
        session = null
    }
}

/**
 * One process-wide refresh coordinator shared by every ApiClient instance. Network
 * rotation happens while holding [lock], so simultaneous reads cannot present the
 * same one-time refresh credential twice.
 */
class StaffTokenCoordinator(
    private var store: StaffTokenStore = VolatileStaffTokenStore(),
) {
    private data class BearerOutcome(
        val bearer: String,
        val replacement: StaffTokenSession? = null,
    )

    private val lock = Any()
    private var session: StaffTokenSession? = store.load()
    @Volatile private var listener: ((StaffTokenSession?) -> Unit)? = null

    fun configure(persistentStore: StaffTokenStore) {
        synchronized(lock) {
            store = persistentStore
            session = store.load()
        }
        notifyCurrent()
    }

    fun setSessionListener(listener: ((StaffTokenSession?) -> Unit)?) {
        this.listener = listener
        listener?.invoke(snapshot())
    }

    fun snapshot(): StaffTokenSession? = synchronized(lock) { session }

    fun install(result: TokenResult, nowEpochMs: Long = System.currentTimeMillis()): StaffTokenSession {
        val access = result.accessToken?.takeIf(String::isNotBlank)
            ?: throw ApiException("The server returned an incomplete staff session.")
        val refresh = result.refreshToken?.takeIf(String::isNotBlank)
            ?: throw ApiException("The server returned an incomplete staff session.")
        val expiresIn = result.expiresIn?.takeIf { it > 0 }
            ?: throw ApiException("The server returned an incomplete staff session.")
        return install(
            StaffTokenSession(
                accessToken = access,
                refreshToken = refresh,
                accessExpiresAtEpochMs = nowEpochMs + expiresIn * 1_000L,
            ),
        )
    }

    fun install(replacement: StaffTokenSession): StaffTokenSession {
        val installed = try {
            synchronized(lock) {
                require(replacement.accessToken.isNotBlank() && replacement.refreshToken.isNotBlank()) {
                    "A complete staff token pair is required."
                }
                if (!store.save(replacement)) {
                    throw ApiException(
                        "Secure credential storage is unavailable. Please sign in again.",
                        401,
                    )
                }
                session = replacement
                replacement
            }
        } catch (error: Exception) {
            if (isTerminal(error)) {
                clear()
            }
            throw error
        }
        notifyCurrent()
        return installed
    }

    fun clear() {
        synchronized(lock) { clearLocked() }
        notifyCurrent()
    }

    /**
     * A freshly rotated bearer rejected on the one permitted GET replay is terminal
     * for that generation. A newer concurrently installed pair is never cleared.
     */
    fun invalidateAfterRejectedReplay(bearer: String) {
        val cleared = synchronized(lock) {
            if (session?.accessToken != bearer) return@synchronized false
            clearLocked()
            true
        }
        if (cleared) notifyCurrent()
    }

    fun bearerBeforeRequest(
        presentedBearer: String,
        nowEpochMs: Long = System.currentTimeMillis(),
        refresh: (String) -> StaffTokenSession,
    ): String {
        val outcome = try {
            synchronized(lock) {
                val current = session ?: return@synchronized BearerOutcome(presentedBearer)
                if (current.accessToken != presentedBearer) {
                    return@synchronized BearerOutcome(current.accessToken)
                }
                if (current.accessExpiresAtEpochMs > nowEpochMs + REFRESH_LEAD_TIME_MS) {
                    return@synchronized BearerOutcome(current.accessToken)
                }

                try {
                    val replacement = rotateLocked(current, refresh)
                    BearerOutcome(replacement.accessToken, replacement)
                } catch (error: Exception) {
                    if (current.accessExpiresAtEpochMs > nowEpochMs && !isTerminal(error)) {
                        BearerOutcome(current.accessToken)
                    } else {
                        throw error
                    }
                }
            }
        } catch (error: Exception) {
            if (isTerminal(error)) {
                clear()
                throw ApiException("Your session has expired. Please sign in again.", 401)
            }
            throw error
        }
        if (outcome.replacement != null) notifyCurrent()
        return outcome.bearer
    }

    fun bearerAfterUnauthorized(
        failedBearer: String,
        refresh: (String) -> StaffTokenSession,
    ): String {
        val outcome = try {
            synchronized(lock) {
                val current = session
                    ?: throw ApiException("Your session has expired. Please sign in again.", 401)
                if (current.accessToken != failedBearer) {
                    return@synchronized BearerOutcome(current.accessToken)
                }
                val replacement = rotateLocked(current, refresh)
                BearerOutcome(replacement.accessToken, replacement)
            }
        } catch (error: Exception) {
            if (isTerminal(error)) {
                clear()
                throw ApiException("Your session has expired. Please sign in again.", 401)
            }
            throw error
        }
        if (outcome.replacement != null) notifyCurrent()
        return outcome.bearer
    }

    private fun rotateLocked(
        current: StaffTokenSession,
        refresh: (String) -> StaffTokenSession,
    ): StaffTokenSession {
        val replacement = refresh(current.refreshToken)
        if (
            replacement.accessToken == current.accessToken ||
            replacement.refreshToken == current.refreshToken ||
            replacement.accessExpiresAtEpochMs <= System.currentTimeMillis()
        ) {
            throw ApiException("Your session has expired. Please sign in again.", 401)
        }
        if (!store.save(replacement)) {
            throw ApiException(
                "Secure credential storage is unavailable. Please sign in again.",
                401,
            )
        }
        session = replacement
        return replacement
    }

    private fun clearLocked() {
        session = null
        store.clear()
    }

    private fun notifyCurrent() {
        listener?.invoke(snapshot())
    }

    private fun isTerminal(error: Exception): Boolean =
        error is ApiException && error.statusCode in setOf(401, 403)

    companion object {
        private const val REFRESH_LEAD_TIME_MS = 120_000L
        val shared = StaffTokenCoordinator()
    }
}

/**
 * Thin coroutine API client for the Hummingbird BFF. The Android emulator reaches the Mac
 * host via 10.0.2.2, so the Dockerized `php artisan serve` on :8001 is at 10.0.2.2:8001.
 * This is the seam the KMP shared `data` module (Ktor) will replace later.
 */
class ApiClient(
    private val baseUrl: String = BASE_URL,
    private val tokenCoordinator: StaffTokenCoordinator = StaffTokenCoordinator.shared,
) {

    companion object {
        val BASE_URL: String = BuildConfig.ZEPHYRUS_BASE_URL
        val REVERB_SCHEME: String = BuildConfig.ZEPHYRUS_REVERB_SCHEME
        val REVERB_HOST: String = BuildConfig.ZEPHYRUS_REVERB_HOST
        val REVERB_PORT: Int = BuildConfig.ZEPHYRUS_REVERB_PORT
        val REVERB_KEY: String = BuildConfig.ZEPHYRUS_REVERB_KEY
    }


    suspend fun token(
        username: String,
        password: String,
        device: StaffAuthDevice? = null,
    ): TokenResult = withContext(Dispatchers.IO) {
        val body = staffTokenBody(username, password, device)
        val (code, text) = send("POST", "/api/auth/token", body.toString(), null)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseTokenResult(JSONObject(text))
    }

    internal fun staffTokenBody(
        username: String,
        password: String,
        device: StaffAuthDevice?,
    ): JSONObject = JSONObject()
        .put("username", username)
        .put("password", password)
        .apply { device?.let { put("device", it.toJson()) } }

    internal fun parseTokenResult(json: JSONObject): TokenResult =
        TokenResult(
            accessToken = json.optStringOrNull("access_token"),
            refreshToken = json.optStringOrNull("refresh_token"),
            expiresIn = json.optInt("expires_in").takeIf { json.has("expires_in") && it > 0 },
            abilities = json.optJSONArray("abilities")?.let { arr -> List(arr.length()) { arr.getString(it) } } ?: emptyList(),
            passwordChangeRequired = json.optBoolean("password_change_required", false),
            changeToken = json.optStringOrNull("change_token"),
        )

    suspend fun changePassword(
        currentPassword: String,
        newPassword: String,
        bearer: String,
        device: StaffAuthDevice? = null,
    ): TokenResult = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("current_password", currentPassword)
            .put("new_password", newPassword)
            .put("new_password_confirmation", newPassword)
            .apply { device?.let { put("device", it.toJson()) } }
        val (code, text) = send("POST", "/api/auth/change-password", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code, errorCode(text))
        parseTokenResult(JSONObject(text))
    }

    suspend fun me(bearer: String): MeData = withContext(Dispatchers.IO) {
        parseMeData(getData("/api/mobile/v1/me", bearer))
    }

    suspend fun staffSessions(bearer: String): List<StaffSession> = withContext(Dispatchers.IO) {
        parseStaffSessions(getData("/api/mobile/v1/me/sessions", bearer))
    }

    internal fun parseStaffSessions(data: JSONObject): List<StaffSession> {
        val sessions = data.getJSONArray("sessions")
        if (sessions.length() !in 1..100) {
            throw org.json.JSONException("Staff session inventory size is invalid.")
        }
        val parsed = List(sessions.length()) { index ->
            parseStaffSession(sessions.getJSONObject(index))
        }
        if (
            parsed.map(StaffSession::sessionUuid).toSet().size != parsed.size ||
            parsed.count(StaffSession::current) != 1
        ) {
            throw org.json.JSONException("Staff session inventory invariants are invalid.")
        }
        return parsed
    }

    suspend fun revokeStaffSession(
        bearer: String,
        sessionUuid: String,
    ): StaffSessionRevocation = withContext(Dispatchers.IO) {
        val canonicalUuid = canonicalStaffSessionUuid(sessionUuid)

        val (code, text) = send(
            "DELETE",
            "/api/mobile/v1/me/sessions/${urlPart(canonicalUuid)}",
            null,
            bearer,
        )
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code, errorCode(text))
        parseStaffSessionRevocation(
            JSONObject(text).getJSONObject("data"),
            expectedSessionUuid = canonicalUuid,
        )
    }

    internal fun canonicalStaffSessionUuid(value: String): String {
        if (!isCanonicalStaffSessionUuid(value)) {
            throw ApiException("The selected session identifier is invalid.")
        }
        return value
    }

    internal fun isCanonicalStaffSessionUuid(value: String): Boolean {
        val pattern =
            Regex("^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
        val canonical = runCatching { java.util.UUID.fromString(value).toString().lowercase() }
            .getOrNull()
        return pattern.matches(value) && canonical == value
    }

    internal fun parseStaffSession(data: JSONObject): StaffSession {
        val device = data.getJSONObject("device")
        for (required in listOf("platform", "name", "app_version", "os_version")) {
            if (!device.has(required)) {
                throw org.json.JSONException("Required staff device metadata key is absent.")
            }
        }
        val sessionUuid = data.getString("session_uuid")
        if (!isCanonicalStaffSessionUuid(sessionUuid)) {
            throw org.json.JSONException("Staff session UUID is not canonical.")
        }
        val status = data.getString("status")
        if (status != "active") {
            throw org.json.JSONException("Only active staff sessions may be projected.")
        }
        val platform = device.optStringOrNull("platform")
        if (platform != null && platform !in setOf("ios", "android")) {
            throw org.json.JSONException("Staff session platform is invalid.")
        }
        val name = device.optStringOrNull("name")
        val appVersion = device.optStringOrNull("app_version")
        val osVersion = device.optStringOrNull("os_version")
        for ((value, maximum) in listOf(
            name to 120,
            appVersion to 80,
            osVersion to 80,
        )) {
            if (value != null && value.codePointCount(0, value.length) > maximum) {
                throw org.json.JSONException("Staff session device metadata exceeds its contract bound.")
            }
        }
        val environment = data.getString("environment")
        if (
            environment.isEmpty() ||
            environment.codePointCount(0, environment.length) > 40
        ) {
            throw org.json.JSONException("Staff session environment is invalid.")
        }
        val lastSeenAt = data.getString("last_seen_at")
        val expiresAt = data.getString("expires_at")
        val createdAt = data.getString("created_at")
        for (timestamp in listOf(lastSeenAt, expiresAt, createdAt)) {
            if (!timestamp.endsWith("Z")) {
                throw org.json.JSONException("Staff session timestamp is invalid.")
            }
            runCatching { java.time.OffsetDateTime.parse(timestamp) }
                .getOrElse {
                    throw org.json.JSONException("Staff session timestamp is invalid.")
                }
        }
        return StaffSession(
            sessionUuid = sessionUuid,
            current = data.getBoolean("current"),
            status = status,
            device = StaffSessionDevice(
                platform = platform,
                name = name,
                appVersion = appVersion,
                osVersion = osVersion,
            ),
            environment = environment,
            lastSeenAt = lastSeenAt,
            expiresAt = expiresAt,
            createdAt = createdAt,
        )
    }

    internal fun parseStaffSessionRevocation(
        data: JSONObject,
        expectedSessionUuid: String,
    ): StaffSessionRevocation {
        val sessionUuid = data.getString("session_uuid")
        if (
            !isCanonicalStaffSessionUuid(sessionUuid) ||
            sessionUuid != expectedSessionUuid
        ) {
            throw org.json.JSONException("Revoked staff session does not match the requested resource.")
        }
        val revoked = data.getBoolean("revoked")
        if (!revoked) {
            throw org.json.JSONException("Staff session revocation was not confirmed.")
        }
        return StaffSessionRevocation(
            sessionUuid = sessionUuid,
            revoked = true,
            alreadyRevoked = data.getBoolean("already_revoked"),
            current = data.getBoolean("current"),
        )
    }

    internal fun parseMeData(data: JSONObject): MeData {
        val can = data.optJSONObject("can")
        return MeData(
            id = data.optInt("id"),
            name = data.optString("name"),
            username = data.optString("username"),
            roles = data.optJSONArray("roles").strings(),
            workflowPreference = data.optStringOrNull("workflow_preference"),
            isAdmin = data.optBoolean("is_admin", false),
            canViewPatientCommunications = can?.optBoolean("view_patient_communications", false) ?: false,
            canRespondPatientCommunications = can?.optBoolean("respond_patient_communications", false) ?: false,
        )
    }

    suspend fun patientCommunicationsInbox(bearer: String): PatientCommunicationInbox =
        withContext(Dispatchers.IO) {
            val data = getData("/api/mobile/v1/patient-communications/inbox", bearer)
            val items = data.optJSONArray("items").objects().map(::parsePatientCommunicationWorkItem)
            PatientCommunicationInbox(
                items = items,
                count = data.optInt("count", items.size),
            )
        }

    suspend fun patientCommunicationThread(
        bearer: String,
        workItemUuid: String,
    ): PatientCommunicationWorkItem = withContext(Dispatchers.IO) {
        parsePatientCommunicationWorkItem(
            getData(
                "/api/mobile/v1/patient-communications/threads/${urlPart(workItemUuid)}",
                bearer,
            ),
        )
    }

    suspend fun claimPatientCommunication(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult = withContext(Dispatchers.IO) {
        patientCommunicationMutation(
            bearer = bearer,
            path = "/api/mobile/v1/patient-communications/threads/${urlPart(workItemUuid)}/claim",
            body = patientCommunicationClaimBody(workItemVersion, threadVersion),
            idempotencyKey = idempotencyKey,
        )
    }

    suspend fun replyToPatientCommunication(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUuid: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult = withContext(Dispatchers.IO) {
        patientCommunicationMutation(
            bearer = bearer,
            path = "/api/mobile/v1/patient-communications/threads/${urlPart(workItemUuid)}/reply",
            body = patientCommunicationReplyBody(
                workItemVersion = workItemVersion,
                threadVersion = threadVersion,
                message = message,
                clientMessageUuid = clientMessageUuid,
            ),
            idempotencyKey = idempotencyKey,
        )
    }

    suspend fun closePatientCommunication(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reason: PatientCommunicationCloseReason,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult = withContext(Dispatchers.IO) {
        patientCommunicationMutation(
            bearer = bearer,
            path = "/api/mobile/v1/patient-communications/threads/${urlPart(workItemUuid)}/close",
            body = patientCommunicationCloseBody(workItemVersion, threadVersion, reason),
            idempotencyKey = idempotencyKey,
        )
    }

    suspend fun patientCommunicationRouteCandidates(
        bearer: String,
        workItemUuid: String,
    ): PatientCommunicationRouteCandidates = withContext(Dispatchers.IO) {
        val exactWorkItemUuid = PatientCommunicationCommandIds.requireUuid(workItemUuid)
        parsePatientCommunicationRouteCandidates(
            data = getData(
                "/api/mobile/v1/patient-communications/threads/${urlPart(exactWorkItemUuid)}/route-candidates",
                bearer,
            ),
            expectedWorkItemUuid = exactWorkItemUuid,
        )
    }

    suspend fun releasePatientCommunication(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult = withContext(Dispatchers.IO) {
        val exactWorkItemUuid = PatientCommunicationCommandIds.requireUuid(workItemUuid)
        patientCommunicationMutation(
            bearer = bearer,
            path = "/api/mobile/v1/patient-communications/threads/${urlPart(exactWorkItemUuid)}/release",
            body = patientCommunicationReleaseBody(workItemVersion, threadVersion, reasonCode),
            idempotencyKey = idempotencyKey,
        )
    }

    suspend fun reassignPatientCommunication(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult = withContext(Dispatchers.IO) {
        val exactWorkItemUuid = PatientCommunicationCommandIds.requireUuid(workItemUuid)
        patientCommunicationMutation(
            bearer = bearer,
            path = "/api/mobile/v1/patient-communications/threads/${urlPart(exactWorkItemUuid)}/reassign",
            body = patientCommunicationReassignBody(
                workItemVersion = workItemVersion,
                threadVersion = threadVersion,
                targetMembershipUuid = targetMembershipUuid,
                reasonCode = reasonCode,
            ),
            idempotencyKey = idempotencyKey,
        )
    }

    suspend fun reroutePatientCommunication(
        bearer: String,
        workItemUuid: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUuid: String,
        reasonCode: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult = withContext(Dispatchers.IO) {
        val exactWorkItemUuid = PatientCommunicationCommandIds.requireUuid(workItemUuid)
        patientCommunicationMutation(
            bearer = bearer,
            path = "/api/mobile/v1/patient-communications/threads/${urlPart(exactWorkItemUuid)}/reroute",
            body = patientCommunicationRerouteBody(
                workItemVersion = workItemVersion,
                threadVersion = threadVersion,
                targetPoolUuid = targetPoolUuid,
                reasonCode = reasonCode,
            ),
            idempotencyKey = idempotencyKey,
        )
    }

    suspend fun census(bearer: String): CensusResult = withContext(Dispatchers.IO) {
        val (code, text) = send("GET", "/api/mobile/v1/rtdc/census", null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val root = JSONObject(text)
        val arr = root.getJSONArray("data")
        val units = List(arr.length()) { i -> parseCensusUnit(arr.getJSONObject(i)) }
        val meta = root.optJSONObject("meta")
        val web = root.optJSONObject("links")?.optStringOrNull("web")
        CensusResult(units, meta?.optStringOrNull("as_of"), meta?.optBoolean("stale", false) ?: false, web)
    }

    suspend fun forYou(bearer: String, persona: String? = null): List<ForYouItem> = withContext(Dispatchers.IO) {
        val (code, text) = send("GET", withPersona("/api/mobile/v1/for-you", persona), null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val arr = JSONObject(text).getJSONArray("data")
        arr.objects().map(::parseForYouItem)
    }

    suspend fun resolveBarrier(bearer: String, id: Int): Boolean = withContext(Dispatchers.IO) {
        val (code, text) = send("POST", "/api/mobile/v1/rtdc/barriers/$id/resolve", "{}", bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).optJSONObject("data")?.optBoolean("resolved", true) ?: true
    }

    suspend fun transportQueue(bearer: String, cursor: String? = null): TransportQueue = withContext(Dispatchers.IO) {
        val path = buildString {
            append("/api/mobile/v1/transport/queue?persona=transport")
            if (!cursor.isNullOrBlank()) append("&cursor=${urlPart(cursor)}")
        }
        val root = getEnvelope(path, bearer)
        parseTransportQueue(root)
    }

    suspend fun transportStatus(
        bearer: String,
        id: Int,
        status: String,
        lifecycleVersion: Int? = null,
    ): TransportJob = withContext(Dispatchers.IO) {
        val body = JSONObject().put("status", status)
        lifecycleVersion?.let { body.put("lifecycle_version", it) }
        val (code, text) = send("POST", "/api/mobile/v1/transport/requests/$id/status", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseTransportJob(JSONObject(text).getJSONObject("data"))
    }

    suspend fun transportHandoff(
        bearer: String,
        id: Int,
        handoffTo: String,
        receiverRole: String,
        acceptanceStatus: String,
        outstandingRisk: String?,
        summary: String?,
        lifecycleVersion: Int,
    ): TransportJob = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("handoff_to", handoffTo)
            .put("receiver_role", receiverRole)
            .put("acceptance_status", acceptanceStatus)
            .put("lifecycle_version", lifecycleVersion)
        if (!outstandingRisk.isNullOrBlank()) {
            body.put("outstanding_risks", JSONArray().put(outstandingRisk))
        }
        if (!summary.isNullOrBlank()) body.put("handoff_summary", summary)
        val (code, text) = send("POST", "/api/mobile/v1/transport/requests/$id/handoff", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseTransportJob(JSONObject(text).getJSONObject("data"))
    }

    suspend fun evsQueue(bearer: String): EvsQueue = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/evs/queue", bearer)
        parseEvsQueue(root)
    }

    suspend fun evsStatus(bearer: String, id: Int, status: String): EvsTurn = withContext(Dispatchers.IO) {
        val body = JSONObject().put("status", status)
        val (code, text) = send("POST", "/api/mobile/v1/evs/requests/$id/status", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        parseEvsTurn(JSONObject(text).getJSONObject("data"))
    }

    suspend fun opsDecision(bearer: String, approvalUuid: String, decision: String): Boolean = withContext(Dispatchers.IO) {
        val body = JSONObject().put("decision", decision)
        val (code, text) = send("POST", "/api/mobile/v1/ops/approvals/${urlPart(approvalUuid)}/decision", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).optJSONObject("data")?.optStringOrNull("decision") == decision
    }

    suspend fun staffingCandidates(bearer: String, id: Int): List<StaffingCandidate> = withContext(Dispatchers.IO) {
        val page = getData("/api/mobile/v1/staffing/requests/$id/candidates?persona=staffing_coordinator&per_page=100", bearer)
        page.optJSONArray("data").objects().map(::parseStaffingCandidate)
    }

    suspend fun fillStaffingRequest(
        bearer: String,
        id: Int,
        staffMemberId: Int,
        assignedSource: String,
    ): Boolean = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("staff_member_id", staffMemberId)
            .put("assigned_source", assignedSource.ifBlank { "float_pool" })
        val (code, text) = send("POST", "/api/mobile/v1/staffing/requests/$id/fill", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).has("data")
    }

    suspend fun orBoard(bearer: String): ORBoard = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/or/board", bearer)
        parseORBoard(root)
    }

    suspend fun commandHouse(bearer: String): HouseBrief = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/command/house", bearer)
        parseHouseBrief(root)
    }

    suspend fun opsInbox(bearer: String): List<OpsApproval> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/ops/inbox", bearer)
        root.getJSONArray("data").objects().map(::parseOpsApproval)
    }

    suspend fun staffingOverview(bearer: String): StaffingOverview = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/staffing/overview", bearer)
        parseStaffingOverview(root)
    }

    suspend fun improvementPdsa(bearer: String): List<PdsaCycle> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/improvement/pdsa", bearer)
        root.getJSONArray("data").objects().map(::parsePdsaCycle)
    }

    suspend fun improvementOpportunities(bearer: String): List<Opportunity> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/improvement/opportunities", bearer)
        root.getJSONArray("data").objects().map(::parseOpportunity)
    }

    suspend fun rtdcHouse(bearer: String): HouseRollup = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/rtdc/house", bearer)
        parseHouseRollup(root)
    }

    suspend fun placements(bearer: String): List<Placement> = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/rtdc/bed-requests", bearer)
        root.getJSONArray("data").objects().map(::parsePlacement)
    }

    suspend fun placementRecommendations(bearer: String, id: Int): PlacementRecommendations = withContext(Dispatchers.IO) {
        val root = getEnvelope("/api/mobile/v1/rtdc/bed-requests/$id/recommendations", bearer)
        parsePlacementRecommendations(root)
    }

    suspend fun placeBed(
        bearer: String,
        id: Int,
        action: String,
        chosenBedId: Int?,
        reason: String? = null,
    ): PlacementDecisionResult = withContext(Dispatchers.IO) {
        val body = JSONObject().put("action", action)
        chosenBedId?.let { body.put("chosen_bed_id", it) }
        if (!reason.isNullOrBlank()) body.put("reason", reason)
        val (code, text) = send("POST", "/api/mobile/v1/rtdc/bed-requests/$id/decision", body.toString(), bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        val root = JSONObject(text)
        val data = root.getJSONObject("data")
        PlacementDecisionResult(
            id = data.optInt("id", id),
            action = data.optString("action", action),
            status = data.optStringOrNull("status"),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
        )
    }

    suspend fun altitudeHome(bearer: String, persona: String): AltitudeHome = withContext(Dispatchers.IO) {
        parseAltitudeHome(getData(withPersona("/api/mobile/v1/altitude/home", persona), bearer))
    }

    suspend fun altitudeWorkspace(bearer: String, domain: String, persona: String): AltitudeWorkspace = withContext(Dispatchers.IO) {
        parseWorkspace(
            getData(withPersona("/api/mobile/v1/altitude/workspace/${urlPart(domain)}", persona), bearer),
        )
    }

    suspend fun drill(bearer: String, itemUuid: String, persona: String): DrillDetail = withContext(Dispatchers.IO) {
        parseDrill(
            getData(withPersona("/api/mobile/v1/drills/${urlPart(itemUuid)}", persona), bearer),
        )
    }

    suspend fun patientOperationalContext(
        bearer: String,
        contextRef: String,
        persona: String,
    ): PatientOperationalContext = withContext(Dispatchers.IO) {
        parsePatientContext(
            getData(withPersona("/api/mobile/v1/patients/${urlPart(contextRef)}/operational-context", persona), bearer),
        )
    }

    suspend fun activity(bearer: String, persona: String, cursor: String? = null): ActivityFeed = withContext(Dispatchers.IO) {
        val path = buildString {
            append(withPersona("/api/mobile/v1/activity", persona))
            if (!cursor.isNullOrBlank()) append("&cursor=").append(urlPart(cursor))
        }
        val root = getEnvelope(path, bearer)
        ActivityFeed(
            events = root.optJSONArray("data").objects().map(::parseActivityEvent),
            nextCursor = root.optJSONObject("meta")?.optStringOrNull("next_cursor"),
        )
    }

    suspend fun ackActivity(bearer: String, eventUuid: String, persona: String): Boolean = withContext(Dispatchers.IO) {
        val path = withPersona("/api/mobile/v1/activity/${urlPart(eventUuid)}/ack", persona)
        val (code, text) = send("POST", path, "{}", bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code)
        JSONObject(text).optJSONObject("data")?.optBoolean("acknowledged", false) ?: false
    }

    suspend fun eddyContext(bearer: String, scopeRef: String, persona: String): EddyContext = withContext(Dispatchers.IO) {
        parseEddyContext(
            getData(withPersona("/api/mobile/v1/eddy/context/${urlPart(scopeRef)}", persona), bearer),
        )
    }

    suspend fun flowWindow(
        bearer: String,
        persona: String,
        scope: String? = null,
        since: String? = null,
    ): FlowWindowData = flowWindowRaw(bearer, persona, scope, since).data

    suspend fun flowDemoScenarios(bearer: String, persona: String): List<FlowDemoScenario> = withContext(Dispatchers.IO) {
        val root = getEnvelope(withPersona("/api/mobile/v1/flow/demo-scenarios", persona), bearer)
        root.optJSONArray("data").objects().map(::parseFlowDemoScenario)
    }

    suspend fun flowOccupancyHistory(
        bearer: String,
        persona: String,
        from: String? = null,
        to: String? = null,
        asOf: String? = null,
        serviceLine: String? = null,
        floor: Int? = null,
        demo: String? = null,
        scenario: String? = null,
        limit: Int? = null,
    ): FlowOccupancyHistory = withContext(Dispatchers.IO) {
        val path = buildString {
            append(withPersona("/api/mobile/v1/flow/occupancy/history", persona))
            if (!from.isNullOrBlank()) append(if (contains('?')) '&' else '?').append("from=").append(urlPart(from))
            if (!to.isNullOrBlank()) append(if (contains('?')) '&' else '?').append("to=").append(urlPart(to))
            if (!asOf.isNullOrBlank()) append(if (contains('?')) '&' else '?').append("asOf=").append(urlPart(asOf))
            if (!serviceLine.isNullOrBlank()) append(if (contains('?')) '&' else '?').append("service_line=").append(urlPart(serviceLine))
            if (floor != null) append(if (contains('?')) '&' else '?').append("floor=").append(floor)
            if (!demo.isNullOrBlank()) append(if (contains('?')) '&' else '?').append("demo=").append(urlPart(demo))
            if (!scenario.isNullOrBlank()) append(if (contains('?')) '&' else '?').append("scenario=").append(urlPart(scenario))
            if (limit != null) append(if (contains('?')) '&' else '?').append("limit=").append(limit)
        }
        parseFlowOccupancyHistory(getEnvelope(path, bearer).getJSONObject("data"))
    }

    /**
     * Flow window with the raw envelope text retained so the caller can persist the last
     * FULL payload to the offline cache without re-serializing the hand-parsed DTOs.
     * `since` (ISO8601) requests a delta: events/snapshots trimmed to t > since; projections,
     * spaces and bed_statuses stay full. Out-of-range/malformed `since` ⇒ HTTP 422.
     */
    suspend fun flowWindowRaw(
        bearer: String,
        persona: String,
        scope: String? = null,
        since: String? = null,
    ): FlowWindowFetch = withContext(Dispatchers.IO) {
        val path = buildString {
            append(withPersona("/api/mobile/v1/flow/window", persona))
            if (!scope.isNullOrBlank()) {
                append(if (contains('?')) '&' else '?').append("scope=").append(urlPart(scope))
            }
            if (!since.isNullOrBlank()) {
                append(if (contains('?')) '&' else '?').append("since=").append(urlPart(since))
            }
        }
        val (root, raw) = getEnvelopeRaw(path, bearer)
        FlowWindowFetch(parseFlowWindow(root), raw)
    }

    suspend fun flowFloors(bearer: String): FlowFloorsDocument = flowFloorsRaw(bearer).first

    /** Floors document with its raw envelope text retained for the offline cache. */
    suspend fun flowFloorsRaw(bearer: String): Pair<FlowFloorsDocument, String> = withContext(Dispatchers.IO) {
        val (root, raw) = getEnvelopeRaw("/api/mobile/v1/flow/floors", bearer)
        parseFlowFloors(root) to raw
    }

    suspend fun revoke(bearer: String) = withContext(Dispatchers.IO) {
        runCatching { send("POST", "/api/auth/token/revoke", "{}", bearer) }
        Unit
    }

    // MARK: plumbing

    private fun getEnvelope(path: String, bearer: String): JSONObject = getEnvelopeRaw(path, bearer).first

    /** Like [getEnvelope] but also returns the raw response body (for the offline cache). */
    private fun getEnvelopeRaw(path: String, bearer: String): Pair<JSONObject, String> {
        val (code, text) = send("GET", path, null, bearer)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code, errorCode(text))
        return JSONObject(text) to text
    }

    private fun getData(path: String, bearer: String): JSONObject {
        return getEnvelope(path, bearer).getJSONObject("data")
    }

    private fun send(
        method: String,
        path: String,
        body: String?,
        bearer: String?,
        explicitIdempotencyKey: String? = null,
    ): Pair<Int, String> {
        val presentedStaffBearer = bearer?.takeIf { path.startsWith("/api/mobile/v1/") }
        val managesStaffSession = presentedStaffBearer != null
        var effectiveBearer = bearer
        if (presentedStaffBearer != null) {
            effectiveBearer = tokenCoordinator.bearerBeforeRequest(presentedStaffBearer) { refreshToken ->
                refreshSession(refreshToken)
            }
        }

        var response = sendRaw(
            method = method,
            path = path,
            body = body,
            bearer = effectiveBearer,
            explicitIdempotencyKey = explicitIdempotencyKey,
        )

        // A 401 can be replayed automatically only for a GET. Mutations keep their
        // existing idempotency/version reconciliation path and are never resent here.
        if (
            response.first == 401 &&
            method.equals("GET", ignoreCase = true) &&
            managesStaffSession &&
            effectiveBearer != null
        ) {
            val refreshedBearer = tokenCoordinator.bearerAfterUnauthorized(effectiveBearer) { refreshToken ->
                refreshSession(refreshToken)
            }
            response = sendRaw(
                method = method,
                path = path,
                body = body,
                bearer = refreshedBearer,
                explicitIdempotencyKey = explicitIdempotencyKey,
            )
            if (response.first == 401) {
                tokenCoordinator.invalidateAfterRejectedReplay(refreshedBearer)
            }
        }
        return response
    }

    private fun refreshSession(refreshToken: String): StaffTokenSession {
        val (code, text) = sendRaw(
            method = "POST",
            path = "/api/auth/token/refresh",
            body = null,
            bearer = refreshToken,
            explicitIdempotencyKey = null,
        )
        if (code !in 200..299) {
            throw ApiException(errorMessage(text, code), code, errorCode(text))
        }
        val result = parseTokenResult(JSONObject(text))
        val access = result.accessToken?.takeIf(String::isNotBlank)
            ?: throw ApiException("The server returned an incomplete staff session.", 401)
        val refresh = result.refreshToken?.takeIf(String::isNotBlank)
            ?: throw ApiException("The server returned an incomplete staff session.", 401)
        val expiresIn = result.expiresIn?.takeIf { it > 0 }
            ?: throw ApiException("The server returned an incomplete staff session.", 401)
        return StaffTokenSession(
            accessToken = access,
            refreshToken = refresh,
            accessExpiresAtEpochMs = System.currentTimeMillis() + expiresIn * 1_000L,
        )
    }

    private fun sendRaw(
        method: String,
        path: String,
        body: String?,
        bearer: String?,
        explicitIdempotencyKey: String?,
    ): Pair<Int, String> {
        val conn = (URL(baseUrl + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15000
            readTimeout = 15000
            setRequestProperty("Accept", "application/json")
            sensitiveNoStoreHeaders(path).forEach { (name, value) ->
                setRequestProperty(name, value)
            }
            if (shouldDisableHttpCaches(path)) useCaches = false
            bearer?.let { setRequestProperty("Authorization", "Bearer $it") }
            requestIdempotencyKey(method, path, body, explicitIdempotencyKey)
                ?.let { setRequestProperty("Idempotency-Key", it) }
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
            }
        }
        try {
            if (body != null) conn.outputStream.use { it.write(body.toByteArray()) }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use(BufferedReader::readText) ?: ""
            return code to text
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (e: Exception) {
            throw ApiException("Can't reach the server at $baseUrl. Is it running?", null)
        } finally {
            conn.disconnect()
        }
    }

    internal fun requestIdempotencyKey(
        method: String,
        path: String,
        body: String?,
        explicitIdempotencyKey: String?,
    ): String? = explicitIdempotencyKey ?: mobileIdempotencyKey(method, path, body)

    internal fun patientCommunicationNoStoreHeaders(path: String): Map<String, String> =
        if (isPatientCommunicationPath(path)) {
            noStoreHeaders()
        } else {
            emptyMap()
        }

    internal fun sensitiveNoStoreHeaders(path: String): Map<String, String> =
        if (isSensitiveNoStorePath(path)) {
            noStoreHeaders()
        } else {
            emptyMap()
        }

    private fun noStoreHeaders(): Map<String, String> =
            mapOf(
                "Cache-Control" to "no-store",
                "Pragma" to "no-cache",
            )

    internal fun shouldDisableHttpCaches(path: String): Boolean = isSensitiveNoStorePath(path)

    private fun isSensitiveNoStorePath(path: String): Boolean =
        isPatientCommunicationPath(path) ||
            path.substringBefore('?') == "/api/mobile/v1/me/sessions" ||
            path.substringBefore('?').startsWith("/api/mobile/v1/me/sessions/")

    private fun isPatientCommunicationPath(path: String): Boolean =
        path.startsWith("/api/mobile/v1/patient-communications/") ||
            path.substringBefore('?') == "/api/mobile/v1/for-you"

    internal fun mobileIdempotencyKey(method: String, path: String, body: String?): String? {
        val verb = method.uppercase()
        if (verb != "POST" || !path.startsWith("/api/mobile/v1/")) return null
        val material = "$verb\n$path\n${body.orEmpty()}"
        val digest = MessageDigest.getInstance("SHA-256").digest(material.toByteArray())
            .joinToString("") { "%02x".format(it) }
        return "hb-$digest"
    }

    internal fun patientCommunicationClaimBody(workItemVersion: Int, threadVersion: Int): String =
        JSONObject()
            .put("work_item_version", workItemVersion)
            .put("thread_version", threadVersion)
            .toString()

    internal fun patientCommunicationReplyBody(
        workItemVersion: Int,
        threadVersion: Int,
        message: String,
        clientMessageUuid: String,
    ): String = JSONObject()
        .put("work_item_version", workItemVersion)
        .put("thread_version", threadVersion)
        .put("message", message)
        .put("client_message_uuid", PatientCommunicationCommandIds.requireUuid(clientMessageUuid))
        .toString()

    internal fun patientCommunicationCloseBody(
        workItemVersion: Int,
        threadVersion: Int,
        reason: PatientCommunicationCloseReason,
    ): String = JSONObject()
        .put("work_item_version", workItemVersion)
        .put("thread_version", threadVersion)
        .put("reason_code", reason.wireValue)
        .toString()

    internal fun patientCommunicationReleaseBody(
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
    ): String = patientCommunicationRoutingBody(
        workItemVersion = workItemVersion,
        threadVersion = threadVersion,
        action = PatientCommunicationRoutingAction.Release,
        reasonCode = reasonCode,
    ).toString()

    internal fun patientCommunicationReassignBody(
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUuid: String,
        reasonCode: String,
    ): String = patientCommunicationRoutingBody(
        workItemVersion = workItemVersion,
        threadVersion = threadVersion,
        action = PatientCommunicationRoutingAction.Reassign,
        reasonCode = reasonCode,
    )
        .put(
            "target_membership_uuid",
            PatientCommunicationCommandIds.requireUuid(targetMembershipUuid),
        )
        .toString()

    internal fun patientCommunicationRerouteBody(
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUuid: String,
        reasonCode: String,
    ): String = patientCommunicationRoutingBody(
        workItemVersion = workItemVersion,
        threadVersion = threadVersion,
        action = PatientCommunicationRoutingAction.Reroute,
        reasonCode = reasonCode,
    )
        .put("target_pool_uuid", PatientCommunicationCommandIds.requireUuid(targetPoolUuid))
        .toString()

    private fun patientCommunicationRoutingBody(
        workItemVersion: Int,
        threadVersion: Int,
        action: PatientCommunicationRoutingAction,
        reasonCode: String,
    ): JSONObject {
        require(workItemVersion >= 1) { "A current work-item version is required." }
        require(threadVersion >= 1) { "A current thread version is required." }
        require(reasonCode in PatientCommunicationRoutingPolicy.allowedReasonCodes(action)) {
            "The reason code is not authorized for this action."
        }
        return JSONObject()
            .put("work_item_version", workItemVersion)
            .put("thread_version", threadVersion)
            .put("reason_code", reasonCode)
    }

    private fun patientCommunicationMutation(
        bearer: String,
        path: String,
        body: String,
        idempotencyKey: String,
    ): PatientCommunicationMutationResult {
        val exactKey = PatientCommunicationCommandIds.requireUuid(idempotencyKey)
        val (code, text) = send("POST", path, body, bearer, exactKey)
        if (code !in 200..299) throw ApiException(errorMessage(text, code), code, errorCode(text))
        return parsePatientCommunicationMutation(JSONObject(text).getJSONObject("data"))
    }

    /** The envelope's `error.code` (e.g. "invalid_since"), when present. */
    private fun errorCode(text: String): String? = runCatching {
        JSONObject(text).optJSONObject("error")?.optStringOrNull("code")
    }.getOrNull()

    private fun errorMessage(text: String, code: Int): String {
        runCatching {
            val o = JSONObject(text)
            o.optJSONObject("error")?.optStringOrNull("message")?.let { return it }
            o.optStringOrNull("message")?.let { if (it.isNotEmpty()) return it }
        }
        if (code == 401) return "Your session has expired. Please sign in again."
        return "Request failed (HTTP $code)."
    }

    private fun withPersona(path: String, persona: String?): String {
        if (persona.isNullOrBlank()) return path
        return path + if (path.contains("?")) "&persona=${urlPart(persona)}" else "?persona=${urlPart(persona)}"
    }

    private fun urlPart(value: String): String = URLEncoder.encode(value, "UTF-8")

    internal fun parsePatientCommunicationWorkItem(o: JSONObject): PatientCommunicationWorkItem {
        val topic = o.optJSONObject("topic") ?: JSONObject()
        val unit = o.optJSONObject("unit")
        val pool = o.optJSONObject("pool") ?: JSONObject()

        return PatientCommunicationWorkItem(
            workItemUuid = o.optString("work_item_uuid"),
            threadUuid = o.optString("thread_uuid"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            topic = PatientCommunicationTopic(
                code = topic.optString("code"),
                label = topic.optStringOrNull("label") ?: "Patient question",
            ),
            unit = unit?.let {
                PatientCommunicationUnit(
                    id = it.optInt("id"),
                    label = it.optStringOrNull("label") ?: "Unit",
                )
            },
            pool = PatientCommunicationPool(
                poolUuid = pool.optString("pool_uuid"),
                label = pool.optStringOrNull("label") ?: "Care team",
            ),
            status = o.optString("status", "open"),
            ownershipState = o.optString("ownership_state", "pool_owned"),
            assignedToMe = o.optBoolean("assigned_to_me", false),
            workItemVersion = o.optInt("work_item_version", 1),
            threadVersion = o.optInt("thread_version", 1),
            lastMessageAt = o.optStringOrNull("last_message_at"),
            dueAt = o.optStringOrNull("due_at"),
            escalateAt = o.optStringOrNull("escalate_at"),
            isResponseDue = o.optBoolean("is_response_due", false),
            isEscalationDue = o.optBoolean("is_escalation_due", false),
            closedAt = o.optStringOrNull("closed_at"),
            messages = o.optJSONArray("messages").objects().map(::parsePatientCommunicationMessage),
            hasEarlierMessages = o.optBoolean("has_earlier_messages", false),
        )
    }

    internal fun parsePatientCommunicationMessage(o: JSONObject): PatientCommunicationMessage =
        PatientCommunicationMessage(
            messageUuid = o.optString("message_uuid"),
            senderDisplayRole = o.optStringOrNull("sender_display_role") ?: "Care team",
            visibility = o.optString("visibility", "patient_visible"),
            messageKind = o.optString("message_kind", "message"),
            body = o.optStringOrNull("body"),
            deliveryState = o.optString("delivery_state", "sent"),
            sentAt = o.optStringOrNull("sent_at"),
        )

    internal fun parsePatientCommunicationMutation(data: JSONObject): PatientCommunicationMutationResult {
        requireExactKeys(data, setOf("work_item", "message", "event_uuid", "replayed"))
        val replayed = data.get("replayed")
        require(replayed is Boolean) { "replayed must be a boolean." }

        return PatientCommunicationMutationResult(
            workItem = when (val rawWorkItem = data.get("work_item")) {
                JSONObject.NULL -> null
                is JSONObject -> parsePatientCommunicationWorkItem(rawWorkItem)
                else -> throw IllegalArgumentException("work_item must be an object or null.")
            },
            message = when (val rawMessage = data.opt("message")) {
                null, JSONObject.NULL -> null
                is JSONObject -> parsePatientCommunicationMessage(rawMessage)
                else -> throw IllegalArgumentException("message must be an object or null.")
            },
            eventUuid = PatientCommunicationCommandIds.requireUuid(data.getString("event_uuid")),
            replayed = replayed,
        )
    }

    internal fun parsePatientCommunicationRouteCandidates(
        data: JSONObject,
        expectedWorkItemUuid: String? = null,
    ): PatientCommunicationRouteCandidates {
        requireExactKeys(
            data,
            setOf(
                "work_item_uuid",
                "work_item_version",
                "thread_version",
                "actions",
                "reason_options",
                "reassign_candidates",
                "reroute_candidates",
            ),
        )
        val workItemUuid = strictCanonicalUuid(data, "work_item_uuid")
        expectedWorkItemUuid?.let {
            require(workItemUuid == PatientCommunicationCommandIds.requireUuid(it)) {
                "Route candidates did not match the requested work item."
            }
        }
        val workItemVersion = strictPositiveInt(data, "work_item_version")
        val threadVersion = strictPositiveInt(data, "thread_version")
        val actionJson = data.getJSONObject("actions")
        requireExactKeys(actionJson, setOf("can_release", "can_reassign", "can_reroute"))
        val actions = PatientCommunicationRouteActions(
            canRelease = strictBoolean(actionJson, "can_release"),
            canReassign = strictBoolean(actionJson, "can_reassign"),
            canReroute = strictBoolean(actionJson, "can_reroute"),
        )
        val reasonJson = data.getJSONObject("reason_options")
        requireExactKeys(reasonJson, setOf("release", "reassign", "reroute"))
        val reasonOptions = PatientCommunicationRouteReasonOptions(
            release = parseRouteReasons(
                reasonJson.getJSONArray("release"),
                PatientCommunicationRoutingPolicy.releaseReasonCodes,
            ),
            reassign = parseRouteReasons(
                reasonJson.getJSONArray("reassign"),
                PatientCommunicationRoutingPolicy.reassignReasonCodes,
            ),
            reroute = parseRouteReasons(
                reasonJson.getJSONArray("reroute"),
                PatientCommunicationRoutingPolicy.rerouteReasonCodes,
            ),
        )
        val reassignCandidates = data.getJSONArray("reassign_candidates").let { array ->
            require(array.length() <= PatientCommunicationRoutingPolicy.MAX_CANDIDATES) {
                "Too many reassign candidates."
            }
            List(array.length()) { index ->
                val candidate = array.getJSONObject(index)
                requireExactKeys(candidate, setOf("membership_uuid", "label", "membership_role"))
                val role = strictBoundedText(candidate, "membership_role", 32)
                require(role in PatientCommunicationRoutingPolicy.membershipRoles) {
                    "Unknown membership role."
                }
                PatientCommunicationReassignCandidate(
                    membershipUuid = strictCanonicalUuid(candidate, "membership_uuid"),
                    label = strictBoundedText(
                        candidate,
                        "label",
                        PatientCommunicationRoutingPolicy.MAX_LABEL_LENGTH,
                    ),
                    membershipRole = role,
                )
            }.also { candidates ->
                require(candidates.distinctBy { it.membershipUuid }.size == candidates.size) {
                    "Duplicate reassign candidate."
                }
            }
        }
        val rerouteCandidates = data.getJSONArray("reroute_candidates").let { array ->
            require(array.length() <= PatientCommunicationRoutingPolicy.MAX_CANDIDATES) {
                "Too many reroute candidates."
            }
            List(array.length()) { index ->
                val candidate = array.getJSONObject(index)
                requireExactKeys(candidate, setOf("pool_uuid", "label", "scope_type", "unit"))
                val scopeType = strictBoundedText(candidate, "scope_type", 32)
                require(scopeType in PatientCommunicationRoutingPolicy.scopeTypes) {
                    "Unknown responsibility-pool scope."
                }
                val unit = when (val rawUnit = candidate.get("unit")) {
                    JSONObject.NULL -> null
                    is JSONObject -> {
                        requireExactKeys(rawUnit, setOf("id", "label"))
                        PatientCommunicationUnit(
                            id = strictPositiveInt(rawUnit, "id"),
                            label = strictBoundedText(
                                rawUnit,
                                "label",
                                PatientCommunicationRoutingPolicy.MAX_LABEL_LENGTH,
                            ),
                        )
                    }

                    else -> throw IllegalArgumentException("unit must be an object or null.")
                }
                require(scopeType != "unit" || unit != null) {
                    "Unit-scoped responsibility pools require a unit."
                }
                require(scopeType == "unit" || unit == null) {
                    "Non-unit responsibility pools cannot include a unit."
                }
                PatientCommunicationRerouteCandidate(
                    poolUuid = strictCanonicalUuid(candidate, "pool_uuid"),
                    label = strictBoundedText(
                        candidate,
                        "label",
                        PatientCommunicationRoutingPolicy.MAX_LABEL_LENGTH,
                    ),
                    scopeType = scopeType,
                    unit = unit,
                )
            }.also { candidates ->
                require(candidates.distinctBy { it.poolUuid }.size == candidates.size) {
                    "Duplicate reroute candidate."
                }
            }
        }
        require(!actions.canRelease || reasonOptions.release.isNotEmpty()) {
            "Release is enabled without a valid reason option."
        }
        require(!actions.canReassign || (reasonOptions.reassign.isNotEmpty() && reassignCandidates.isNotEmpty())) {
            "Reassign is enabled without valid reasons and targets."
        }
        require(actions.canReassign || reassignCandidates.isEmpty()) {
            "Reassign candidates were returned for a disabled action."
        }
        require(!actions.canReroute || (reasonOptions.reroute.isNotEmpty() && rerouteCandidates.isNotEmpty())) {
            "Reroute is enabled without valid reasons and targets."
        }
        require(actions.canReroute || rerouteCandidates.isEmpty()) {
            "Reroute candidates were returned for a disabled action."
        }
        return PatientCommunicationRouteCandidates(
            workItemUuid = workItemUuid,
            workItemVersion = workItemVersion,
            threadVersion = threadVersion,
            actions = actions,
            reasonOptions = reasonOptions,
            reassignCandidates = reassignCandidates,
            rerouteCandidates = rerouteCandidates,
        )
    }

    private fun parseRouteReasons(
        array: JSONArray,
        allowlist: Set<String>,
    ): List<PatientCommunicationRouteReason> {
        require(array.length() <= PatientCommunicationRoutingPolicy.MAX_REASON_OPTIONS) {
            "Too many routing reason options."
        }
        return List(array.length()) { index ->
            val reason = array.getJSONObject(index)
            requireExactKeys(reason, setOf("code", "label"))
            val code = strictBoundedText(reason, "code", 64)
            require(code in allowlist) { "Unknown routing reason code." }
            PatientCommunicationRouteReason(
                code = code,
                label = strictBoundedText(
                    reason,
                    "label",
                    PatientCommunicationRoutingPolicy.MAX_LABEL_LENGTH,
                ),
            )
        }.also { reasons ->
            require(reasons.distinctBy { it.code }.size == reasons.size) {
                "Duplicate routing reason option."
            }
        }
    }

    private fun strictCanonicalUuid(json: JSONObject, key: String): String =
        PatientCommunicationCommandIds.requireUuid(strictBoundedText(json, key, 36))

    private fun requireExactKeys(json: JSONObject, expected: Set<String>) {
        val actual = mutableSetOf<String>()
        val keys = json.keys()
        while (keys.hasNext()) actual += keys.next()
        require(actual == expected) { "Routing response contained an unexpected shape." }
    }

    private fun strictPositiveInt(json: JSONObject, key: String): Int {
        val value = json.get(key)
        require(value is Int && value >= 1) { "$key must be a positive integer." }
        return value
    }

    private fun strictBoolean(json: JSONObject, key: String): Boolean {
        val value = json.get(key)
        require(value is Boolean) { "$key must be a boolean." }
        return value
    }

    private fun strictBoundedText(json: JSONObject, key: String, maxLength: Int): String {
        val value = json.get(key)
        require(value is String && value.isNotEmpty() && value.length <= maxLength) {
            "$key must be bounded text."
        }
        require(value == value.trim() && value.none(Char::isISOControl)) {
            "$key contains unsupported text."
        }
        return value
    }

    internal fun parseAltitudeHome(data: JSONObject): AltitudeHome = AltitudeHome(
        altitude = data.optString("altitude", "A0"),
        persona = parsePersona(data.optJSONObject("persona")),
        status = parseStatus(data.optJSONObject("status")),
        generatedAt = data.optStringOrNull("generated_at"),
        glanceQuestion = data.optStringOrNull("glance_question")
            ?: data.optJSONObject("persona")?.optStringOrNull("question")
            ?: "What needs attention right now?",
        tiles = data.optJSONArray("tiles").objects().map(::parseTile),
        forYouHead = data.optJSONArray("for_you_head").objects().map(::parseForYouItem),
        activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
        web = parseWeb(data.optJSONObject("web")),
    )

    private fun parseWorkspace(data: JSONObject): AltitudeWorkspace {
        val workspace = data.optJSONObject("workspace") ?: JSONObject()
        val domain = data.optString("domain", "ops")
        val summary = workspace.optJSONObject("summary")
        return AltitudeWorkspace(
            altitude = data.optString("altitude", "A1"),
            persona = parsePersona(data.optJSONObject("persona")),
            domain = domain,
            generatedAt = data.optStringOrNull("generated_at"),
            status = parseStatus(data.optJSONObject("status")),
            summary = AltitudeWorkspaceSummary(
                label = summary?.optStringOrNull("label") ?: humanize(domain),
                count = summary?.optIntOrNull("count"),
            ),
            items = workspace.optJSONArray("items").objects().map { parseWorkspaceItem(domain, it) },
            activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
            web = parseWeb(data.optJSONObject("web")),
        )
    }

    private fun parseDrill(data: JSONObject): DrillDetail = DrillDetail(
        altitude = data.optString("altitude", "A2"),
        persona = parsePersona(data.optJSONObject("persona")),
        itemUuid = data.optString("item_uuid"),
        generatedAt = data.optStringOrNull("generated_at"),
        domain = data.optString("domain", "ops"),
        status = parseStatus(data.optJSONObject("status"), normalizeStatus(data.optStringOrNull("status"))),
        explanation = data.optStringOrNull("explanation") ?: "Operational drill detail.",
        dependencies = data.optJSONArray("dependencies").objects().map { safeFields(it) },
        activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
        patientContextRef = data.optStringOrNull("patient_context_ref"),
        actions = data.optJSONArray("actions").objects().map(::parseAction),
        web = parseWeb(data.optJSONObject("web")),
    )

    internal fun parsePatientContext(data: JSONObject): PatientOperationalContext {
        val patient = data.optJSONObject("patient") ?: JSONObject()
        return PatientOperationalContext(
            altitude = data.optString("altitude", "A2P"),
            persona = parsePersona(data.optJSONObject("persona")),
            patient = PatientIdentity(
                patientContextRef = patient.optStringOrNull("patient_context_ref"),
                display = patient.optStringOrNull("display"),
                phiMinimized = patient.optBoolean("phi_minimized", true),
            ),
            header = safeFields(data.optJSONObject("header")),
            statusSpine = data.optJSONArray("status_spine").objects().map(::parseStatusRow),
            timeline = data.optJSONArray("timeline").objects().map(::parseTimelineRow),
            dependencies = data.optJSONArray("dependencies").objects().map(::parseDependencyRow),
            recommendations = data.optJSONArray("recommendations").objects().map(::parseRecommendationRow),
            actions = data.optJSONArray("actions").objects().map(::parseAction),
            activity = data.optJSONArray("activity").objects().map(::parseActivityEvent),
            web = parseWeb(data.optJSONObject("web")),
            phiPolicy = safeFields(data.optJSONObject("phi_policy")),
        )
    }

    private fun parseEddyContext(data: JSONObject): EddyContext = EddyContext(
        scopeRef = data.optString("scope_ref"),
        scopeType = data.optString("scope_type", "scope"),
        generatedAt = data.optStringOrNull("generated_at"),
        persona = parsePersona(data.optJSONObject("persona")),
        phiPolicy = safeFields(data.optJSONObject("phi_policy")),
        context = summarizeContext(data.optJSONObject("context")),
        questionsSupported = data.optJSONArray("questions_supported").strings(),
    )

    private fun parsePersona(o: JSONObject?): PersonaData {
        val roleId = o?.optStringOrNull("role_id") ?: MobileRoleCatalog.default.id
        val local = MobileRoleCatalog.byId(roleId)
        return PersonaData(
            roleId = roleId,
            title = o?.optStringOrNull("title") ?: local.title,
            assignmentScope = o?.optStringOrNull("assignment_scope"),
            home = o?.optStringOrNull("home"),
            focus = o?.optStringOrNull("focus"),
            question = o?.optStringOrNull("question") ?: local.question,
            web = o?.optStringOrNull("web"),
        )
    }

    private fun parseStatus(o: JSONObject?, fallback: String = "info"): OperationalStatus {
        val value = normalizeStatus(o?.optStringOrNull("value") ?: fallback)
        return OperationalStatus(
            value = value,
            label = o?.optStringOrNull("label") ?: humanize(value),
            glyph = o?.optStringOrNull("glyph"),
            generatedAt = o?.optStringOrNull("generated_at"),
        )
    }

    private fun parseCensusUnit(o: JSONObject): CensusUnit = CensusUnit(
        unitId = o.optInt("unit_id"),
        name = o.optString("name"),
        type = o.optString("type"),
        staffedBedCount = o.optInt("staffed_bed_count"),
        occupied = o.optInt("occupied"),
        available = o.optInt("available"),
        blocked = o.optInt("blocked"),
        canAdmit = o.optInt("can_admit"),
        bedNeed = o.optInt("bed_need"),
        status = o.optString("status", "info"),
    )

    private fun parseTile(o: JSONObject): AltitudeTile {
        val status = normalizeStatus(o.optStringOrNull("status"))
        return AltitudeTile(
            key = o.optString("key"),
            label = o.optStringOrNull("label") ?: humanize(o.optString("key", "tile")),
            value = o.optString("value"),
            status = status,
            provenance = safeFields(o.optJSONObject("provenance")),
        )
    }

    internal fun parseForYouItem(o: JSONObject): ForYouItem {
        val status = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("status")
                ?: o.optJSONObject("status_detail")?.optStringOrNull("value"),
        )
        val id = o.optString("id")
        val type = o.optString("type")
        val domain = o.optStringOrNull("domain")
        val patientCommunication = PatientCommunicationForYou.isRestrictedCandidate(
            id = id,
            type = type,
            domain = domain,
        )
        val routeSafe = patientCommunication &&
            PatientCommunicationForYou.isExactRoutableTriple(
                id = id,
                type = type,
                domain = domain,
            )
        return ForYouItem(
            id = id,
            type = if (patientCommunication) PatientCommunicationForYou.TYPE else type,
            domain = if (routeSafe) PatientCommunicationForYou.DOMAIN else if (patientCommunication) null else domain,
            tier = status,
            title = if (patientCommunication) {
                PatientCommunicationForYou.TITLE
            } else {
                o.optStringOrNull("title") ?: "Operational item"
            },
            subtitle = if (patientCommunication) {
                PatientCommunicationForYou.SUBTITLE
            } else {
                o.optStringOrNull("subtitle") ?: ""
            },
            unit = if (patientCommunication) null else o.optStringOrNull("unit"),
            at = o.optStringOrNull("at") ?: o.optStringOrNull("created_at"),
            patientContextRef = if (patientCommunication) null else o.optStringOrNull("patient_context_ref"),
        )
    }

    internal fun parseTransportQueue(root: JSONObject): TransportQueue {
        val data = root.getJSONObject("data")
        val metrics = data.optJSONObject("metrics") ?: JSONObject()

        return TransportQueue(
            metrics = TransportMetrics(
                active = metrics.optInt("active"),
                stat = metrics.optInt("stat"),
                atRisk = metrics.optInt("at_risk"),
                completedToday = metrics.optInt("completed_today"),
            ),
            jobs = data.optJSONArray("jobs").objects().map(::parseTransportJob),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
            nextCursor = root.optJSONObject("meta")?.optStringOrNull("next_cursor"),
            hasMore = root.optJSONObject("meta")?.optBoolean("has_more", false) ?: false,
        )
    }

    internal fun parseTransportJob(o: JSONObject): TransportJob {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("priority")
                ?: o.optStringOrNull("status"),
        )
        val sla = o.optJSONObject("sla") ?: JSONObject()

        return TransportJob(
            id = o.optInt("id"),
            uuid = o.optStringOrNull("uuid"),
            type = o.optStringOrNull("type") ?: "transport",
            priority = o.optStringOrNull("priority") ?: "routine",
            status = o.optStringOrNull("status") ?: "requested",
            visualStatus = visualStatus,
            origin = o.optStringOrNull("origin"),
            destination = o.optStringOrNull("destination"),
            mode = o.optStringOrNull("mode"),
            neededAt = o.optStringOrNull("needed_at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            claimedByMe = o.optBoolean("claimed_by_me", false),
            availableToClaim = o.optBoolean("available_to_claim", false),
            resourceName = o.optStringOrNull("resource_name"),
            handoffRequired = o.optBoolean("handoff_required", false),
            allowedTransitions = o.optJSONArray("allowed_transitions").strings(),
            canHandoff = o.optBoolean("can_handoff", false),
            lifecycleVersion = o.optInt("lifecycle_version", 1),
            sla = TransportSla(
                minutesUntilDue = sla.optIntOrNull("minutes_until_due"),
                atRisk = sla.optBoolean("at_risk", false),
                label = sla.optStringOrNull("label") ?: "No target",
            ),
        )
    }

    internal fun parseEvsQueue(root: JSONObject): EvsQueue {
        val data = root.getJSONObject("data")
        val metrics = data.optJSONObject("metrics") ?: JSONObject()

        return EvsQueue(
            metrics = EvsMetrics(
                pending = metrics.optInt("pending"),
                overdue = metrics.optInt("overdue"),
                isolation = metrics.optInt("isolation"),
                completedToday = metrics.optInt("completed_today"),
            ),
            turns = data.optJSONArray("turns").objects().map(::parseEvsTurn),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    internal fun parseEvsTurn(o: JSONObject): EvsTurn {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("priority")
                ?: o.optStringOrNull("status"),
        )
        val sla = o.optJSONObject("sla") ?: JSONObject()

        return EvsTurn(
            id = o.optInt("id"),
            uuid = o.optStringOrNull("uuid"),
            requestType = o.optStringOrNull("request_type") ?: "clean",
            priority = o.optStringOrNull("priority") ?: "routine",
            status = o.optStringOrNull("status") ?: "requested",
            visualStatus = visualStatus,
            locationLabel = o.optStringOrNull("location_label"),
            unitId = o.optIntOrNull("unit_id"),
            turnType = o.optStringOrNull("turn_type"),
            isolationRequired = o.optBoolean("isolation_required", false),
            neededAt = o.optStringOrNull("needed_at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            sla = EvsSla(
                minutesUntilDue = sla.optIntOrNull("minutes_until_due"),
                atRisk = sla.optBoolean("at_risk", false),
                label = sla.optStringOrNull("label") ?: "No target",
            ),
        )
    }

    internal fun parseORBoard(root: JSONObject): ORBoard {
        val data = root.getJSONObject("data")
        val metrics = data.optJSONObject("metrics") ?: JSONObject()

        return ORBoard(
            rooms = data.optJSONArray("rooms").objects().map(::parseORRoom),
            metrics = ORMetrics(
                running = metrics.optInt("running"),
                turnover = metrics.optInt("turnover"),
                available = metrics.optInt("available"),
                total = metrics.optInt("total"),
                avgTurnoverMin = metrics.optInt("avg_turnover_min"),
            ),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    private fun parseORRoom(o: JSONObject): ORRoom {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("status"),
        )

        return ORRoom(
            id = o.optInt("id"),
            name = o.optStringOrNull("name") ?: "OR-${o.optInt("id")}",
            status = o.optStringOrNull("status") ?: "available",
            tier = normalizeStatus(o.optStringOrNull("tier")),
            visualStatus = visualStatus,
            timeRemaining = o.optIntOrNull("time_remaining"),
            turnoverMin = o.optIntOrNull("turnover_min"),
            current = parseORCaseInfo(o.optJSONObject("current")),
            next = parseORNextInfo(o.optJSONObject("next")),
        )
    }

    private fun parseORCaseInfo(o: JSONObject?): ORCaseInfo? {
        if (o == null) return null

        return ORCaseInfo(
            procedure = o.optStringOrNull("procedure") ?: "Procedure",
            surgeon = o.optStringOrNull("surgeon") ?: "Care team",
            elapsed = o.optInt("elapsed"),
            expectedDuration = o.optInt("expected_duration"),
            expectedEnd = o.optStringOrNull("expected_end"),
            startTime = o.optStringOrNull("start_time"),
        )
    }

    private fun parseORNextInfo(o: JSONObject?): ORNextInfo? {
        if (o == null) return null

        return ORNextInfo(
            startTime = o.optStringOrNull("start_time"),
            procedure = o.optStringOrNull("procedure") ?: "Procedure",
        )
    }

    internal fun parseHouseBrief(root: JSONObject): HouseBrief {
        val data = root.getJSONObject("data")

        return HouseBrief(
            strain = parseExecStrain(data.optJSONObject("strain")),
            hero = data.optJSONArray("hero").objects().map(::parseHeroKpi),
            generatedAt = data.optStringOrNull("generated_at"),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    private fun parseExecStrain(o: JSONObject?): ExecStrain = ExecStrain(
        level = o?.optInt("level") ?: 0,
        label = o?.optStringOrNull("label") ?: "Surge Level 0",
        status = normalizeStatus(o?.optStringOrNull("status")),
        previousLevel = o?.optInt("previousLevel") ?: 0,
        drivers = o?.optJSONArray("drivers").objects().map(::parseStrainDriver),
        updatedAt = o?.optStringOrNull("updatedAtIso"),
    )

    private fun parseStrainDriver(o: JSONObject): StrainDriver = StrainDriver(
        label = o.optStringOrNull("label") ?: "Driver",
        value = o.optStringOrNull("value") ?: "-",
        status = normalizeStatus(o.optStringOrNull("status")),
    )

    private fun parseHeroKpi(o: JSONObject): HeroKpi = HeroKpi(
        key = o.optStringOrNull("key") ?: o.toString().hashCode().toString(),
        label = o.optStringOrNull("label") ?: "Metric",
        display = o.optStringOrNull("display") ?: o.optStringOrNull("value") ?: "-",
        status = normalizeStatus(o.optStringOrNull("status")),
        targetDisplay = o.optStringOrNull("target_display"),
    )

    internal fun parseOpsApproval(o: JSONObject): OpsApproval {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("risk"),
        )

        return OpsApproval(
            approvalUuid = o.optStringOrNull("approval_uuid") ?: "",
            title = o.optStringOrNull("title") ?: "Operational approval",
            rationale = o.optStringOrNull("rationale"),
            type = o.optStringOrNull("type"),
            risk = o.optStringOrNull("risk"),
            tier = normalizeStatus(o.optStringOrNull("tier")),
            visualStatus = visualStatus,
            owner = o.optStringOrNull("owner"),
            requestedAt = o.optStringOrNull("requested_at"),
        )
    }

    internal fun parseStaffingOverview(root: JSONObject): StaffingOverview {
        val data = root.getJSONObject("data")

        return StaffingOverview(
            metrics = parseStaffingMetrics(data.optJSONObject("metrics") ?: JSONObject()),
            unitsAtRisk = data.optJSONArray("units_at_risk").objects().map(::parseUnitAtRisk),
            queue = data.optJSONArray("queue").objects().map(::parseStaffingReq),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    private fun parseStaffingMetrics(o: JSONObject): StaffingMetrics = StaffingMetrics(
        openRequests = o.optInt("open_requests"),
        atRiskUnits = o.optInt("at_risk_units"),
        criticalGaps = o.optInt("critical_gaps"),
        coveragePct = o.optInt("coverage_pct"),
        statRequests = o.optInt("stat_requests"),
        totalGapHeadcount = o.optInt("total_gap_headcount"),
    )

    private fun parseUnitAtRisk(o: JSONObject): UnitAtRisk = UnitAtRisk(
        unitId = o.optInt("unit_id"),
        unitLabel = o.optStringOrNull("unit_label") ?: "Unit",
        status = o.optStringOrNull("status") ?: "gap",
        gapHeadcount = o.optInt("gap_headcount"),
        worstRoleLabel = o.optStringOrNull("worst_role_label") ?: "Staff",
        belowMinimumSafe = o.optBoolean("below_minimum_safe", false),
    )

    internal fun parseStaffingReq(o: JSONObject): StaffingReq {
        val sla = o.optJSONObject("sla") ?: JSONObject()

        return StaffingReq(
            staffingRequestId = o.optInt("staffing_request_id"),
            unitLabel = o.optStringOrNull("unit_label"),
            roleLabel = o.optStringOrNull("role_label"),
            priority = o.optStringOrNull("priority") ?: "routine",
            status = o.optStringOrNull("status") ?: "requested",
            headcountNeeded = o.optIntOrNull("headcount_needed"),
            sla = EvsSla(
                minutesUntilDue = sla.optIntOrNull("minutes_until_due"),
                atRisk = sla.optBoolean("at_risk", false),
                label = sla.optStringOrNull("label") ?: "No target",
            ),
        )
    }

    internal fun parseStaffingCandidate(o: JSONObject): StaffingCandidate = StaffingCandidate(
        staffMemberId = o.optInt("staff_member_id"),
        displayName = o.optStringOrNull("display_name") ?: "Staff member",
        roleLabel = o.optStringOrNull("role_label") ?: "Staff",
        eligible = o.optBoolean("eligible", false),
        eligibilityState = o.optStringOrNull("eligibility_state") ?: "unavailable",
        reasonCodes = o.optJSONArray("reason_codes").strings(),
        overlappingAssignments = o.optInt("overlapping_assignments"),
    )

    internal fun parsePdsaCycle(o: JSONObject): PdsaCycle = PdsaCycle(
        id = o.optInt("id"),
        title = o.optStringOrNull("title") ?: "PDSA cycle",
        status = o.optStringOrNull("status") ?: "active",
        owner = o.optStringOrNull("owner"),
        objective = o.optStringOrNull("objective"),
        unit = o.optStringOrNull("unit"),
        startedAt = o.optStringOrNull("started_at"),
        targetDate = o.optStringOrNull("target_date"),
    )

    internal fun parseOpportunity(o: JSONObject): Opportunity = Opportunity(
        id = o.optInt("id"),
        title = o.optStringOrNull("title") ?: "Improvement opportunity",
        description = o.optStringOrNull("description"),
        department = o.optStringOrNull("department"),
        priority = o.optStringOrNull("priority") ?: "Low",
        status = o.optStringOrNull("status") ?: "Open",
        impact = o.optIntOrNull("impact"),
    )

    internal fun parseFlowWindow(root: JSONObject): FlowWindowData {
        val data = root.getJSONObject("data")
        val window = data.optJSONObject("window") ?: JSONObject()
        val lens = data.optJSONObject("lens") ?: JSONObject()
        val scope = data.optJSONObject("scope") ?: JSONObject()
        val spaces = data.optJSONObject("spaces")

        return FlowWindowData(
            window = FlowWindowRange(
                from = window.optString("from"),
                to = window.optString("to"),
                now = window.optString("now"),
                since = window.optStringOrNull("since"),
            ),
            lens = FlowLens(
                roleId = lens.optString("role_id"),
                scopesAllowed = lens.optJSONArray("scopes_allowed").strings(),
                layers = lens.optJSONArray("layers").strings(),
                eventKinds = lens.optJSONArray("event_kinds").strings(),
                projectionKinds = lens.optJSONArray("projection_kinds").strings(),
                patientDots = lens.optString("patient_dots", "none"),
                actions = lens.optJSONArray("actions").strings(),
                defaultZoomHours = lens.optInt("default_zoom_hours", 48),
            ),
            scope = FlowScope(
                type = scope.optString("type", "house"),
                floor = scope.optIntOrNull("floor"),
                unitId = scope.optIntOrNull("unit_id"),
                patientContextRef = scope.optStringOrNull("patient_context_ref"),
                label = scope.optStringOrNull("label") ?: "House",
            ),
            spacesFloors = spaces?.optJSONArray("floors").objects().map(::parseFlowFloorRollup),
            snapshots = data.optJSONArray("snapshots").objects().map(::parseFlowSnapshot),
            events = data.optJSONArray("events").objects().map(::parseFlowTimelineEvent),
            projections = data.optJSONArray("projections").objects().map(::parseFlowProjection),
            // Optional turn-map layer — absent unless scope is floor/unit and the lens allows it.
            bedStatuses = data.optJSONArray("bed_statuses").objects().map(::parseFlowBedStatus),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
        )
    }

    private fun parseFlowDemoScenario(o: JSONObject): FlowDemoScenario = FlowDemoScenario(
        key = o.optString("key"),
        label = o.optStringOrNull("label") ?: o.optString("key"),
        enabled = o.optBoolean("enabled", o.optString("status") == "enabled"),
        historySupported = o.optBoolean("history_supported", false),
        sourceMode = o.optStringOrNull("source_mode"),
    )

    private fun parseFlowOccupancyHistory(data: JSONObject): FlowOccupancyHistory {
        val window = data.optJSONObject("window") ?: JSONObject()
        val lens = data.optJSONObject("lens") ?: JSONObject()
        val summary = data.optJSONObject("summary") ?: JSONObject()

        return FlowOccupancyHistory(
            window = FlowHistoryWindow(
                from = window.optString("from"),
                to = window.optString("to"),
                limit = window.optInt("limit", 120),
            ),
            lens = FlowHistoryLens(
                roleId = lens.optString("role_id"),
                patientDots = lens.optString("patient_dots", "none"),
                projectionKinds = lens.optJSONArray("projection_kinds").strings(),
            ),
            scenario = data.optStringOrNull("scenario"),
            history = data.optJSONArray("history").objects().map(::parseFlowHistorySnapshot),
            summary = FlowHistorySummary(
                snapshots = summary.optInt("snapshots"),
                activePatientCount = summary.optInt("active_patient_count"),
                sourceMode = summary.optString("source_mode", "snapshot"),
                redacted = summary.optBoolean("redacted", true),
            ),
        )
    }

    private fun parseFlowHistorySnapshot(o: JSONObject): FlowHistorySnapshot = FlowHistorySnapshot(
        snapshotAt = o.optStringOrNull("snapshot_at"),
        activePatientCount = o.optInt("active_patient_count"),
        occupancyDetailCount = o.optJSONArray("occupancy_details")?.length() ?: 0,
        timerStatusCounts = intMap(o.optJSONObject("timer_status_counts")),
    )

    private fun intMap(o: JSONObject?): Map<String, Int> {
        if (o == null) return emptyMap()
        val out = mutableMapOf<String, Int>()
        val keys = o.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            out[key] = o.optInt(key)
        }
        return out
    }

    private fun parseFlowBedStatus(o: JSONObject): FlowBedStatus = FlowBedStatus(
        bedId = o.optInt("bed_id"),
        unitId = o.optIntOrNull("unit_id"),
        label = o.optStringOrNull("label") ?: "Bed ${o.optInt("bed_id")}",
        status = o.optStringOrNull("status") ?: "occupied",
    )

    private fun parseFlowFloorRollup(o: JSONObject): FlowFloorRollup = FlowFloorRollup(
        floor = o.optInt("floor"),
        label = o.optStringOrNull("label") ?: "Floor ${o.optInt("floor")}",
        staffed = o.optInt("staffed"),
        occupied = o.optInt("occupied"),
        occupancyPct = o.optInt("occupancy_pct"),
        units = o.optJSONArray("units").objects().map(::parseFlowUnitRollup),
    )

    private fun parseFlowUnitRollup(o: JSONObject): FlowUnitRollup = FlowUnitRollup(
        unitId = o.optInt("unit_id"),
        abbr = o.optStringOrNull("abbr") ?: "",
        name = o.optStringOrNull("name") ?: "Unit",
        staffed = o.optInt("staffed"),
        occupied = o.optInt("occupied"),
        available = o.optInt("available"),
        blocked = o.optInt("blocked"),
        occupancyPct = o.optInt("occupancy_pct"),
    )

    private fun parseFlowSnapshot(o: JSONObject): FlowSnapshot = FlowSnapshot(
        t = o.optString("t"),
        unitId = o.optInt("unit_id"),
        staffed = o.optInt("staffed"),
        occupied = o.optInt("occupied"),
        available = o.optInt("available"),
        blocked = o.optInt("blocked"),
    )

    private fun parseFlowTimelineEvent(o: JSONObject): FlowTimelineEvent = FlowTimelineEvent(
        t = o.optString("t"),
        kind = o.optString("kind"),
        label = o.optStringOrNull("label") ?: humanize(o.optString("kind", "event")),
        tier = o.optStringOrNull("tier") ?: "info",
        unitId = o.optIntOrNull("unit_id"),
        fromSpace = o.optStringOrNull("from_space"),
        toSpace = o.optStringOrNull("to_space"),
        patientContextRef = o.optStringOrNull("patient_context_ref"),
        provenanceSource = o.optJSONObject("provenance")?.optStringOrNull("source") ?: "",
        entityRef = o.optJSONObject("entity")?.optStringOrNull("ref"),
    )

    private fun parseFlowProjection(o: JSONObject): FlowProjection {
        val band = o.optJSONObject("band")
        val provenance = o.optJSONObject("provenance")
        return FlowProjection(
            t = o.optString("t"),
            kind = o.optString("kind"),
            confidence = o.optStringOrNull("confidence") ?: "possible",
            label = o.optStringOrNull("label") ?: humanize(o.optString("kind", "projection")),
            unitId = o.optIntOrNull("unit_id"),
            bedId = o.optIntOrNull("bed_id"),
            room = o.optStringOrNull("room"),
            value = o.optIntOrNull("value"),
            bandLower = band?.optIntOrNull("lower"),
            bandUpper = band?.optIntOrNull("upper"),
            endsAt = o.optStringOrNull("ends_at"),
            derived = o.optBoolean("derived", false),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            provenanceService = provenance?.optStringOrNull("service") ?: "",
            provenanceReliability = provenance?.optDoubleOrNull("reliability"),
        )
    }

    internal fun parseFlowFloors(root: JSONObject): FlowFloorsDocument {
        val data = root.getJSONObject("data")
        return FlowFloorsDocument(
            version = data.optStringOrNull("version")
                ?: root.optJSONObject("meta")?.optStringOrNull("version")
                ?: "",
            floors = data.optJSONArray("floors").objects().map(::parseFlowFloor),
        )
    }

    private fun parseFlowFloor(o: JSONObject): FlowFloor = FlowFloor(
        floor = o.optInt("floor"),
        label = o.optStringOrNull("label") ?: "Floor ${o.optInt("floor")}",
        bounds = o.optJSONArray("bounds").doubles(),
        spaces = o.optJSONArray("spaces").objects().map(::parseFlowPlate),
    )

    private fun parseFlowPlate(o: JSONObject): FlowPlate = FlowPlate(
        id = o.optInt("id"),
        code = o.optString("code"),
        category = o.optStringOrNull("category") ?: "room",
        label = o.optStringOrNull("label") ?: o.optString("code"),
        rect = o.optJSONArray("rect").doubles(),
        unitId = o.optIntOrNull("unit_id"),
        bedId = o.optIntOrNull("bed_id"),
    )

    internal fun parseHouseRollup(root: JSONObject): HouseRollup {
        val data = root.getJSONObject("data")
        val occupancy = data.optJSONObject("occupancy") ?: JSONObject()

        return HouseRollup(
            occupancy = HouseOccupancy(
                occupied = occupancy.optInt("occupied"),
                staffed = occupancy.optInt("staffed"),
                percent = occupancy.optInt("percent"),
            ),
            netBedNeed = data.optInt("net_bed_need"),
            pendingPlacements = data.optInt("pending_placements"),
            edBoarding = data.optInt("ed_boarding"),
            units = data.optJSONArray("units").objects().map(::parseCensusUnit),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
            stale = root.optJSONObject("meta")?.optBoolean("stale", false) ?: false,
        )
    }

    internal fun parsePlacement(o: JSONObject): Placement {
        val visualStatus = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("status"),
        )

        return Placement(
            id = o.optInt("id"),
            source = o.optStringOrNull("source"),
            service = o.optStringOrNull("service"),
            acuityTier = o.optIntOrNull("acuity_tier"),
            tier = normalizeStatus(o.optStringOrNull("tier")),
            visualStatus = visualStatus,
            isolationRequired = o.optIsolation("isolation_required"),
            requiredUnitType = o.optStringOrNull("required_unit_type"),
            at = o.optStringOrNull("at"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
        )
    }

    internal fun parsePlacementRecommendations(root: JSONObject): PlacementRecommendations {
        val data = root.getJSONObject("data")

        return PlacementRecommendations(
            recommendations = data.optJSONArray("recommendations").objects().map(::parsePlacementRecommendation),
            runnerUpDelta = data.optIntOrNull("runner_up_delta"),
            webLink = root.optJSONObject("links")?.optStringOrNull("web"),
        )
    }

    private fun parsePlacementRecommendation(o: JSONObject): PlacementRecommendation = PlacementRecommendation(
        bedId = o.optInt("bed_id"),
        bedLabel = o.optStringOrNull("bed_label") ?: "Bed",
        unitName = o.optStringOrNull("unit_name") ?: "Unit",
        score = o.optDouble("score", 0.0).toInt(),
        chips = o.optJSONArray("chips").objects().map { chip ->
            PlacementChip(
                label = chip.optStringOrNull("label") ?: "Constraint",
                ok = chip.optBoolean("ok", false),
            )
        },
    )

    private fun parseWorkspaceItem(domain: String, o: JSONObject): AltitudeWorkspaceItem {
        val id = o.optStringOrNull("id") ?: o.optStringOrNull("approval_uuid") ?: o.toString().hashCode().toString()
        val status = normalizeStatus(
            o.optStringOrNull("visual_status")
                ?: o.optStringOrNull("tier")
                ?: o.optStringOrNull("priority")
                ?: o.optStringOrNull("status"),
        )
        return AltitudeWorkspaceItem(
            id = id,
            title = workspaceTitle(domain, o),
            subtitle = workspaceSubtitle(o),
            domain = domain,
            status = status,
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            drillItemId = drillIdForWorkspace(domain, id, o),
            fields = safeFields(
                o,
                exclude = setOf("id", "title", "subtitle", "visual_status", "patient_context_ref", "patient_ref"),
            ),
        )
    }

    internal fun parseActivityEvent(o: JSONObject): ActivityEvent {
        val status = o.optJSONObject("status")
        val statusValue = normalizeStatus(
            status?.optStringOrNull("value")
                ?: status?.optStringOrNull("severity")
                ?: status?.optStringOrNull("status_after")
                ?: status?.optStringOrNull("current")
                ?: status?.optStringOrNull("status"),
        )
        return ActivityEvent(
            eventUuid = o.optString("event_uuid"),
            eventType = o.optString("event_type"),
            occurredAt = o.optStringOrNull("occurred_at"),
            actorRole = o.optStringOrNull("actor_role"),
            sourceSurface = o.optStringOrNull("source_surface"),
            domain = o.optString("domain", "ops"),
            patientContextRef = o.optStringOrNull("patient_context_ref"),
            statusValue = statusValue,
            statusLabel = status?.optStringOrNull("label") ?: humanize(statusValue),
        )
    }

    private fun parseAction(o: JSONObject): GenericAction {
        val kind = o.optString("kind", "view")
        return GenericAction(
            kind = kind,
            label = o.optStringOrNull("label") ?: humanize(kind),
            endpoint = o.optStringOrNull("endpoint"),
            requiresOnline = o.optBoolean("requires_online", true),
        )
    }

    private fun parseStatusRow(o: JSONObject): PatientListRow = PatientListRow(
        title = o.optStringOrNull("label") ?: humanize(o.optString("domain", "status")),
        subtitle = o.optStringOrNull("domain")?.let(::humanize),
        status = normalizeStatus(o.optStringOrNull("status")),
        at = o.optStringOrNull("at"),
        fields = safeFields(o, exclude = setOf("label", "domain", "status", "at")),
    )

    private fun parseTimelineRow(o: JSONObject): PatientListRow = PatientListRow(
        title = humanize(o.optString("event_type", "event")),
        subtitle = listOfNotNull(o.optStringOrNull("domain")?.let(::humanize), o.optStringOrNull("actor_role")?.let(::humanize))
            .joinToString(" / ")
            .ifBlank { null },
        status = normalizeStatus(o.optStringOrNull("status_after")),
        at = o.optStringOrNull("occurred_at"),
        fields = safeFields(o, exclude = setOf("event_type", "domain", "actor_role", "status_after", "occurred_at")),
    )

    private fun parseDependencyRow(o: JSONObject): PatientListRow = PatientListRow(
        title = o.optStringOrNull("label") ?: humanize(o.optString("dependency_type", "dependency")),
        subtitle = o.optStringOrNull("owner_role")?.let { "Owner: ${humanize(it)}" },
        status = normalizeStatus(o.optStringOrNull("status")),
        at = null,
        fields = safeFields(o, exclude = setOf("label", "dependency_type", "owner_role", "status")),
    )

    private fun parseRecommendationRow(o: JSONObject): PatientListRow = PatientListRow(
        title = o.optStringOrNull("title") ?: "Recommendation",
        subtitle = o.optStringOrNull("rationale") ?: o.optStringOrNull("source"),
        status = normalizeStatus(o.optStringOrNull("risk_level") ?: o.optStringOrNull("status")),
        at = null,
        fields = safeFields(o, exclude = setOf("title", "rationale", "source", "risk_level", "status")),
    )

    private fun parseWeb(o: JSONObject?): WebLink? {
        if (o == null) return null
        return WebLink(
            href = o.optStringOrNull("href"),
            label = o.optStringOrNull("label"),
            altitude = o.optStringOrNull("altitude"),
        )
    }

    private fun workspaceTitle(domain: String, o: JSONObject): String =
        o.optStringOrNull("title") ?: when (domain) {
            "transport" -> listOfNotNull(o.optStringOrNull("origin"), o.optStringOrNull("destination"))
                .joinToString(" to ")
                .ifBlank { "Transport job" }
            "evs" -> o.optStringOrNull("location_label") ?: "EVS turn"
            "staffing" -> listOfNotNull(o.optStringOrNull("role")?.let(::humanize), o.optStringOrNull("unit_label"))
                .joinToString(" / ")
                .ifBlank { "Staffing request" }
            "ops", "approvals" -> o.optStringOrNull("title") ?: "Operational approval"
            else -> o.optStringOrNull("type")?.let(::humanize) ?: "${humanize(domain)} item"
        }

    private fun workspaceSubtitle(o: JSONObject): String? =
        listOfNotNull(
            o.optStringOrNull("priority")?.let(::humanize),
            o.optStringOrNull("status")?.let(::humanize),
            o.optStringOrNull("turn_type")?.let(::humanize),
            o.optStringOrNull("needed_by"),
            o.optStringOrNull("requested_at"),
        ).joinToString(" / ").ifBlank { null }

    private fun drillIdForWorkspace(domain: String, id: String, o: JSONObject): String? {
        if (supportedDrillId(id)) return id
        return when (domain) {
            "transport" -> "transport-$id"
            "evs" -> "evs-$id"
            "rtdc", "capacity", "bed-management" -> when (o.optStringOrNull("type")) {
                "bed_request" -> "bedreq-$id"
                "barrier" -> "barrier-$id"
                else -> null
            }
            else -> null
        }
    }

    private fun supportedDrillId(id: String): Boolean =
        id.startsWith("bedreq-") ||
            id.startsWith("barrier-") ||
            id.startsWith("transport-") ||
            id.startsWith("evs-") ||
            Regex("^[0-9a-fA-F-]{36}$").matches(id)

    private fun summarizeContext(o: JSONObject?): List<DisplayField> {
        if (o == null) return emptyList()
        val fields = mutableListOf<DisplayField>()
        o.optJSONObject("event")?.let {
            fields += DisplayField("Event", humanize(it.optString("event_type", "event")))
            fields += DisplayField("Domain", humanize(it.optString("domain", "ops")))
        }
        o.optStringOrNull("patient_context_ref")?.let {
            fields += DisplayField("Patient context", it)
        }
        o.optJSONArray("dependencies")?.let {
            fields += DisplayField("Dependencies", "${it.length()}")
        }
        o.optJSONArray("activity")?.let {
            fields += DisplayField("Activity events", "${it.length()}")
        }
        o.optJSONObject("patient_flow_4d")?.let { flow ->
            fields += DisplayField("Surface", humanize(flow.optString("surface", "patient_flow_4d")))
            flow.optJSONObject("current_metrics")?.let { metrics ->
                fields += DisplayField("Active patients", metrics.optInt("active", 0).toString())
                fields += DisplayField("Delayed", metrics.optInt("delayed", 0).toString())
                fields += DisplayField("Watch", metrics.optInt("watch", 0).toString())
            }
            flow.optJSONArray("top_barriers")?.let {
                fields += DisplayField("Top barriers", "${it.length()}")
            }
            flow.optJSONObject("redaction")?.optStringOrNull("patient_dots")?.let {
                fields += DisplayField("Patient dots", humanize(it))
            }
        }
        if (fields.isEmpty()) fields += safeFields(o)
        return fields
    }

    private fun safeFields(
        o: JSONObject?,
        exclude: Set<String> = emptySet(),
    ): List<DisplayField> {
        if (o == null) return emptyList()
        val fields = mutableListOf<DisplayField>()
        val keys = o.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            if (key in exclude) continue
            if (key.contains("patient_ref")) continue
            if (key == "encounter_ref") continue
            val value = o.opt(key)
            val text = displayValue(value)
            if (!text.isNullOrBlank()) fields += DisplayField(humanize(key), text)
        }
        return fields
    }

    private fun displayValue(value: Any?): String? = when (value) {
        null, JSONObject.NULL -> null
        is JSONObject -> {
            val count = value.length()
            if (count == 0) null else "$count field${if (count == 1) "" else "s"}"
        }
        is JSONArray -> "${value.length()} item${if (value.length() == 1) "" else "s"}"
        is Boolean -> if (value) "Yes" else "No"
        else -> value.toString()
    }

    private fun normalizeStatus(value: String?): String {
        return when (value?.lowercase()) {
            "critical", "critical_gap", "t1", "stat", "overdue", "failed", "blocked" -> "critical"
            "warning", "gap", "t2", "urgent", "high", "pending", "active", "boarding" -> "warning"
            "success", "complete", "completed", "placed", "resolved", "filled" -> "success"
            "info", "t3", "t4", "low", "normal" -> "info"
            else -> "info"
        }
    }

    private fun humanize(value: String): String =
        value.replace('.', ' ')
            .replace('_', ' ')
            .replace('-', ' ')
            .split(' ')
            .filter { it.isNotBlank() }
            .joinToString(" ") { part -> part.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
}

private fun JSONObject.optStringOrNull(key: String): String? =
    if (isNull(key) || !has(key)) null else optString(key)

private fun JSONObject.optIntOrNull(key: String): Int? =
    if (isNull(key) || !has(key)) null else optInt(key)

private fun JSONObject.optDoubleOrNull(key: String): Double? =
    if (isNull(key) || !has(key)) null else optDouble(key)

private fun JSONObject.optIsolation(key: String): String? {
    if (isNull(key) || !has(key)) return null
    val raw = opt(key)
    return when (raw) {
        is Boolean -> if (raw) "isolation" else null
        JSONObject.NULL -> null
        else -> raw?.toString()?.takeIf { it.isNotBlank() }
    }
}

private fun JSONArray?.objects(): List<JSONObject> {
    if (this == null) return emptyList()
    return List(length()) { i -> optJSONObject(i) }.filterNotNull()
}

private fun JSONArray?.strings(): List<String> {
    if (this == null) return emptyList()
    return List(length()) { i -> optString(i) }.filter { it.isNotBlank() }
}

private fun JSONArray?.doubles(): List<Double> {
    if (this == null) return emptyList()
    return List(length()) { i -> optDouble(i, 0.0) }
}
