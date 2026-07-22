package net.acumenus.hummingbird.patient.data

import net.acumenus.hummingbird.patient.BuildConfig
import okhttp3.CacheControl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.net.URI
import java.util.UUID as JavaUuid

object PatientEndpoints {
    const val ROOT = "/api/patient/v1"
    const val ENROLL = "$ROOT/auth/enroll/challenge/verify"
    const val TOKEN = "$ROOT/auth/token"
    const val REFRESH = "$ROOT/auth/token/refresh"
    const val REVOKE = "$ROOT/auth/token/revoke"
    const val PROFILE = "$ROOT/me"
    const val PREFERENCES = "$ROOT/me/preferences"
    const val SESSIONS = "$ROOT/me/sessions"
    const val ENCOUNTERS = "$ROOT/encounters"

    val staticPaths: Set<String> = setOf(
        ENROLL,
        TOKEN,
        REFRESH,
        REVOKE,
        PROFILE,
        PREFERENCES,
        SESSIONS,
        ENCOUNTERS,
    )

    val staticOperations: Set<PatientApiOperation> = setOf(
        PatientApiOperation(PatientHttpMethod.POST, ENROLL),
        PatientApiOperation(PatientHttpMethod.POST, TOKEN),
        PatientApiOperation(PatientHttpMethod.POST, REFRESH),
        PatientApiOperation(PatientHttpMethod.POST, REVOKE),
        PatientApiOperation(PatientHttpMethod.GET, PROFILE),
        PatientApiOperation(PatientHttpMethod.PUT, PREFERENCES),
        PatientApiOperation(PatientHttpMethod.GET, SESSIONS),
        PatientApiOperation(PatientHttpMethod.GET, ENCOUNTERS),
    )

    /**
     * Auditable source inventory. The backend exposes 25 live operations; the
     * native client consumes 23 direct patient surfaces. Notification-device
     * registration remains intentionally unconfigured until a provider is
     * selected and its token lifecycle is approved.
     */
    val consumedOperationInventory: Set<String> = setOf(
        "POST $ENROLL",
        "POST $TOKEN",
        "POST $REFRESH",
        "POST $REVOKE",
        "GET $PROFILE",
        "PUT $PREFERENCES",
        "GET $SESSIONS",
        "DELETE $ROOT/me/sessions/{sessionUuid}",
        "GET $ENCOUNTERS",
        "GET $ROOT/encounters/{encounterUuid}/today",
        "GET $ROOT/encounters/{encounterUuid}/pathway",
        "GET $ROOT/encounters/{encounterUuid}/pathway/events",
        "GET $ROOT/encounters/{encounterUuid}/discharge-readiness",
        "GET $ROOT/encounters/{encounterUuid}/rounds/summary",
        "GET $ROOT/encounters/{encounterUuid}/care-team",
        "GET $ROOT/encounters/{encounterUuid}/message-topics",
        "GET $ROOT/encounters/{encounterUuid}/threads",
        "POST $ROOT/encounters/{encounterUuid}/threads",
        "POST $ROOT/encounters/{encounterUuid}/education/{educationItemUuid}/clarifications",
        "GET $ROOT/threads/{threadUuid}",
        "POST $ROOT/threads/{threadUuid}/messages",
        "POST $ROOT/threads/{threadUuid}/messages/{messageUuid}/amend",
        "POST $ROOT/threads/{threadUuid}/close",
    )

    fun today(encounterUuid: String): String = projection(encounterUuid, "today")

    fun pathway(encounterUuid: String): String = projection(encounterUuid, "pathway")

    fun pathwayEvents(encounterUuid: String): String = projection(encounterUuid, "pathway/events")

    fun dischargeReadiness(encounterUuid: String): String = projection(encounterUuid, "discharge-readiness")

    fun roundsSummary(encounterUuid: String): String = projection(encounterUuid, "rounds/summary")

    fun careTeam(encounterUuid: String): String = projection(encounterUuid, "care-team")

    fun messageTopics(encounterUuid: String): String = encounterResource(encounterUuid, "message-topics")

    fun threads(encounterUuid: String): String = encounterResource(encounterUuid, "threads")

    fun educationClarification(encounterUuid: String, educationItemUuid: String): String {
        require(UUID.matches(encounterUuid)) { "Encounter handle must be a UUID." }
        require(UUID.matches(educationItemUuid)) { "Education item handle must be a UUID." }
        return "$ROOT/encounters/$encounterUuid/education/$educationItemUuid/clarifications"
    }

    fun thread(threadUuid: String): String = threadResource(threadUuid)

    fun messages(threadUuid: String): String = "${threadResource(threadUuid)}/messages"

    fun amendMessage(threadUuid: String, messageUuid: String): String {
        requireCanonicalUuid(messageUuid, "Message handle")
        return "${messages(threadUuid)}/$messageUuid/amend"
    }

    fun closeThread(threadUuid: String): String = "${threadResource(threadUuid)}/close"

    fun session(sessionUuid: String): String =
        "$SESSIONS/${requireCanonicalUuid(sessionUuid, "Session handle")}"

    fun requireCanonicalUuid(value: String, label: String = "Handle"): String {
        val canonical = runCatching { JavaUuid.fromString(value).toString() }.getOrNull()
        require(canonical == value) { "$label must be a canonical UUID." }
        return value
    }

    fun requirePatientPath(path: String): String {
        require(path == ROOT || path.startsWith("$ROOT/")) {
            "Patient clients may only call the patient API boundary."
        }
        require(!path.contains('?') && !path.contains('#')) {
            "Endpoint paths may not contain a query or fragment."
        }
        require(
            path in staticPaths ||
                PROJECTION_PATH.matches(path) ||
                MESSAGE_TOPICS_PATH.matches(path) ||
                ENCOUNTER_THREADS_PATH.matches(path) ||
                EDUCATION_CLARIFICATION_PATH.matches(path) ||
                THREAD_PATH.matches(path) ||
                THREAD_MESSAGES_PATH.matches(path) ||
                THREAD_MESSAGE_AMEND_PATH.matches(path) ||
                THREAD_CLOSE_PATH.matches(path) ||
                SESSION_PATH.matches(path),
        ) { "Unknown patient endpoint." }
        return path
    }

    fun requirePatientOperation(method: PatientHttpMethod, path: String): String {
        requirePatientPath(path)
        val operation = PatientApiOperation(method, path)
        require(
            operation in staticOperations ||
                (method == PatientHttpMethod.GET && PROJECTION_PATH.matches(path)) ||
                (method == PatientHttpMethod.GET && MESSAGE_TOPICS_PATH.matches(path)) ||
                (method in setOf(PatientHttpMethod.GET, PatientHttpMethod.POST) &&
                    ENCOUNTER_THREADS_PATH.matches(path)) ||
                (method == PatientHttpMethod.POST && EDUCATION_CLARIFICATION_PATH.matches(path)) ||
                (method == PatientHttpMethod.GET && THREAD_PATH.matches(path)) ||
                (method == PatientHttpMethod.POST && THREAD_MESSAGES_PATH.matches(path)) ||
                (method == PatientHttpMethod.POST && THREAD_MESSAGE_AMEND_PATH.matches(path)) ||
                (method == PatientHttpMethod.POST && THREAD_CLOSE_PATH.matches(path)) ||
                (method == PatientHttpMethod.DELETE && SESSION_PATH.matches(path)),
        ) { "Unknown patient operation." }
        return path
    }

    private fun projection(encounterUuid: String, surface: String): String {
        return encounterResource(encounterUuid, surface)
    }

    private fun encounterResource(encounterUuid: String, resource: String): String {
        require(UUID.matches(encounterUuid)) { "Encounter handle must be a UUID." }
        return "$ROOT/encounters/$encounterUuid/$resource"
    }

    private fun threadResource(threadUuid: String): String {
        require(UUID.matches(threadUuid)) { "Thread handle must be a UUID." }
        return "$ROOT/threads/$threadUuid"
    }

    private const val UUID_SEGMENT =
        "[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}"
    private val UUID = Regex("^$UUID_SEGMENT$")
    private val PROJECTION_PATH = Regex(
        "^${Regex.escape(ROOT)}/encounters/$UUID_SEGMENT/(?:today|pathway|pathway/events|discharge-readiness|rounds/summary|care-team)$",
    )
    private val MESSAGE_TOPICS_PATH = Regex(
        "^${Regex.escape(ROOT)}/encounters/$UUID_SEGMENT/message-topics$",
    )
    private val ENCOUNTER_THREADS_PATH = Regex(
        "^${Regex.escape(ROOT)}/encounters/$UUID_SEGMENT/threads$",
    )
    private val EDUCATION_CLARIFICATION_PATH = Regex(
        "^${Regex.escape(ROOT)}/encounters/$UUID_SEGMENT/education/$UUID_SEGMENT/clarifications$",
    )
    private val THREAD_PATH = Regex("^${Regex.escape(ROOT)}/threads/$UUID_SEGMENT$")
    private val THREAD_MESSAGES_PATH = Regex("^${Regex.escape(ROOT)}/threads/$UUID_SEGMENT/messages$")
    private val THREAD_MESSAGE_AMEND_PATH = Regex(
        "^${Regex.escape(ROOT)}/threads/$UUID_SEGMENT/messages/$UUID_SEGMENT/amend$",
    )
    private val THREAD_CLOSE_PATH = Regex("^${Regex.escape(ROOT)}/threads/$UUID_SEGMENT/close$")
    private const val CANONICAL_UUID_SEGMENT =
        "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"
    private val SESSION_PATH = Regex(
        "^${Regex.escape(SESSIONS)}/$CANONICAL_UUID_SEGMENT$",
    )
}

enum class PatientHttpMethod { GET, POST, PUT, DELETE }

data class PatientApiOperation(val method: PatientHttpMethod, val path: String)

data class PatientApiConfiguration(
    val enabled: Boolean,
    val baseUrl: String,
) {
    init {
        val uri = URI(baseUrl)
        require(uri.scheme == "https") { "Patient API transport must use HTTPS." }
        require(!uri.host.isNullOrBlank()) { "Patient API host is required." }
        require(uri.rawUserInfo == null) { "Patient API URLs may not embed credentials." }
        require(uri.rawQuery == null && uri.rawFragment == null) {
            "Patient API base URL may not contain a query or fragment."
        }
        require(uri.rawPath.isNullOrEmpty() || uri.rawPath == "/") {
            "Patient API base URL must not contain a path."
        }
    }

    companion object {
        fun fromBuild(): PatientApiConfiguration = PatientApiConfiguration(
            enabled = BuildConfig.PATIENT_API_ENABLED,
            baseUrl = BuildConfig.PATIENT_API_BASE_URL,
        )
    }
}

class PatientApiDisabledException : IllegalStateException(
    "Patient network features are disabled in this build.",
)

class PatientApiException(
    val statusCode: Int,
    val errorCode: String?,
    message: String,
) : IOException(message)

data class PatientDeviceDescriptor(
    val uuid: String,
    val name: String?,
    val appVersion: String,
    val osVersion: String,
)

data class PatientEnrollmentRequest(
    val challengeUuid: String,
    val challengeToken: CharArray,
    val verificationCode: CharArray,
    val displayName: String,
    val email: String,
    val password: CharArray,
)

interface PatientApiGateway {
    fun exchangePassword(
        email: String,
        password: CharArray,
        device: PatientDeviceDescriptor,
    ): PatientEnvelope<PatientTokenPair>

    fun verifyEnrollment(
        request: PatientEnrollmentRequest,
        device: PatientDeviceDescriptor,
    ): PatientEnvelope<PatientTokenPair>

    fun profile(accessToken: CharArray): PatientEnvelope<PatientProfile>
    fun updatePreferences(
        accessToken: CharArray,
        preferences: PatientPreferencesUpdate,
    ): PatientEnvelope<PatientProfile>
    fun patientSessions(
        accessToken: CharArray,
    ): PatientEnvelope<PatientDeviceSessionCollection>
    fun revokePatientSession(
        accessToken: CharArray,
        sessionUuid: String,
    ): PatientEnvelope<PatientSessionRevocation>
    fun encounters(accessToken: CharArray): PatientEnvelope<PatientEncounterCollection>
    fun today(accessToken: CharArray, encounterUuid: String): PatientEnvelope<PatientProjectionDocument<PatientTodayContent>>
    fun pathway(accessToken: CharArray, encounterUuid: String): PatientEnvelope<PatientProjectionDocument<PatientPathwayContent>>
    fun pathwayEvents(accessToken: CharArray, encounterUuid: String): PatientEnvelope<PatientProjectionDocument<PatientPathwayEventsContent>>
    fun dischargeReadiness(accessToken: CharArray, encounterUuid: String): PatientEnvelope<PatientProjectionDocument<PatientDischargeReadinessContent>>
    fun roundsSummary(accessToken: CharArray, encounterUuid: String): PatientEnvelope<PatientProjectionDocument<PatientRoundsSummaryContent>>
    fun careTeam(accessToken: CharArray, encounterUuid: String): PatientEnvelope<PatientProjectionDocument<PatientCareTeamContent>>
    fun messageTopics(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientMessageTopics>
    fun messageThreads(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientMessageThreadCollection>
    fun createMessageThread(
        accessToken: CharArray,
        encounterUuid: String,
        request: PatientCreateThreadRequest,
    ): PatientEnvelope<PatientThreadResult>
    fun requestEducationClarification(
        accessToken: CharArray,
        encounterUuid: String,
        educationItemUuid: String,
        request: PatientEducationClarificationRequest,
    ): PatientEnvelope<PatientThreadResult> = throw PatientApiDisabledException()
    fun messageThread(
        accessToken: CharArray,
        threadUuid: String,
    ): PatientEnvelope<PatientThreadResult>
    fun sendMessage(
        accessToken: CharArray,
        threadUuid: String,
        request: PatientSendMessageRequest,
    ): PatientEnvelope<PatientMessageResult>
    fun amendMessage(
        accessToken: CharArray,
        threadUuid: String,
        messageUuid: String,
        request: PatientAmendMessageRequest,
    ): PatientEnvelope<PatientMessageResult>
    fun closeMessageThread(
        accessToken: CharArray,
        threadUuid: String,
        request: PatientCloseThreadRequest,
    ): PatientEnvelope<PatientThreadResult>
    fun refresh(refreshToken: CharArray): PatientEnvelope<PatientTokenPair>
    fun revoke(accessOrRefreshToken: CharArray)
}

class PatientApiClient(
    private val configuration: PatientApiConfiguration = PatientApiConfiguration.fromBuild(),
    private val client: OkHttpClient = OkHttpClient(),
) : PatientApiGateway {
    /** Explicitly disables OkHttp disk caching at the patient boundary. */
    private val cachelessClient = client.newBuilder().cache(null).build()

    override fun exchangePassword(
        email: String,
        password: CharArray,
        device: PatientDeviceDescriptor,
    ): PatientEnvelope<PatientTokenPair> {
        val body = JSONObject()
            .put("email", email.trim().lowercase())
            .put("password", password.concatToString())
            .put("device", device.json())
        return PatientEnvelopeDecoder.tokenPair(post(PatientEndpoints.TOKEN, body))
    }

    override fun verifyEnrollment(
        request: PatientEnrollmentRequest,
        device: PatientDeviceDescriptor,
    ): PatientEnvelope<PatientTokenPair> {
        val body = JSONObject()
            .put("challenge_uuid", request.challengeUuid)
            .put("challenge_token", request.challengeToken.concatToString())
            .put("verification_code", request.verificationCode.concatToString())
            .put("display_name", request.displayName.trim())
            .put("email", request.email.trim().lowercase())
            .put("password", request.password.concatToString())
            .put("password_confirmation", request.password.concatToString())
            .put("device", device.json())
        return PatientEnvelopeDecoder.tokenPair(post(PatientEndpoints.ENROLL, body))
    }

    override fun profile(accessToken: CharArray): PatientEnvelope<PatientProfile> =
        PatientEnvelopeDecoder.profile(get(PatientEndpoints.PROFILE, accessToken))

    override fun updatePreferences(
        accessToken: CharArray,
        preferences: PatientPreferencesUpdate,
    ): PatientEnvelope<PatientProfile> = PatientEnvelopeDecoder.profile(
        put(PatientEndpoints.PREFERENCES, preferences.json(), accessToken),
    )

    override fun patientSessions(
        accessToken: CharArray,
    ): PatientEnvelope<PatientDeviceSessionCollection> = PatientEnvelopeDecoder.patientSessions(
        get(PatientEndpoints.SESSIONS, accessToken, noStore = true),
    )

    override fun revokePatientSession(
        accessToken: CharArray,
        sessionUuid: String,
    ): PatientEnvelope<PatientSessionRevocation> = PatientEnvelopeDecoder.patientSessionRevocation(
        delete(PatientEndpoints.session(sessionUuid), accessToken),
    )

    override fun encounters(accessToken: CharArray): PatientEnvelope<PatientEncounterCollection> =
        PatientEnvelopeDecoder.encounters(get(PatientEndpoints.ENCOUNTERS, accessToken))

    override fun today(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientTodayContent>> =
        PatientEnvelopeDecoder.today(get(PatientEndpoints.today(encounterUuid), accessToken))

    override fun pathway(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientPathwayContent>> =
        PatientEnvelopeDecoder.pathway(get(PatientEndpoints.pathway(encounterUuid), accessToken))

    override fun pathwayEvents(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientPathwayEventsContent>> =
        PatientEnvelopeDecoder.pathwayEvents(get(PatientEndpoints.pathwayEvents(encounterUuid), accessToken))

    override fun dischargeReadiness(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientDischargeReadinessContent>> =
        PatientEnvelopeDecoder.dischargeReadiness(
            get(PatientEndpoints.dischargeReadiness(encounterUuid), accessToken),
        )

    override fun roundsSummary(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientRoundsSummaryContent>> =
        PatientEnvelopeDecoder.roundsSummary(
            get(PatientEndpoints.roundsSummary(encounterUuid), accessToken),
        )

    override fun careTeam(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientCareTeamContent>> =
        PatientEnvelopeDecoder.careTeam(get(PatientEndpoints.careTeam(encounterUuid), accessToken))

    override fun messageTopics(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientMessageTopics> = PatientEnvelopeDecoder.messageTopics(
        get(PatientEndpoints.messageTopics(encounterUuid), accessToken),
    )

    override fun messageThreads(
        accessToken: CharArray,
        encounterUuid: String,
    ): PatientEnvelope<PatientMessageThreadCollection> = PatientEnvelopeDecoder.messageThreads(
        get(PatientEndpoints.threads(encounterUuid), accessToken),
    )

    override fun createMessageThread(
        accessToken: CharArray,
        encounterUuid: String,
        request: PatientCreateThreadRequest,
    ): PatientEnvelope<PatientThreadResult> = PatientEnvelopeDecoder.messageThread(
        post(
            PatientEndpoints.threads(encounterUuid),
            request.json(),
            accessToken,
            request.idempotencyKey,
        ),
    )

    override fun requestEducationClarification(
        accessToken: CharArray,
        encounterUuid: String,
        educationItemUuid: String,
        request: PatientEducationClarificationRequest,
    ): PatientEnvelope<PatientThreadResult> = PatientEnvelopeDecoder.messageThread(
        post(
            PatientEndpoints.educationClarification(encounterUuid, educationItemUuid),
            request.json(),
            accessToken,
            request.idempotencyKey,
        ),
    )

    override fun messageThread(
        accessToken: CharArray,
        threadUuid: String,
    ): PatientEnvelope<PatientThreadResult> = PatientEnvelopeDecoder.messageThread(
        get(PatientEndpoints.thread(threadUuid), accessToken),
    )

    override fun sendMessage(
        accessToken: CharArray,
        threadUuid: String,
        request: PatientSendMessageRequest,
    ): PatientEnvelope<PatientMessageResult> = PatientEnvelopeDecoder.sentMessage(
        post(
            PatientEndpoints.messages(threadUuid),
            request.json(),
            accessToken,
            request.idempotencyKey,
        ),
    )

    override fun amendMessage(
        accessToken: CharArray,
        threadUuid: String,
        messageUuid: String,
        request: PatientAmendMessageRequest,
    ): PatientEnvelope<PatientMessageResult> = PatientEnvelopeDecoder.sentMessage(
        post(
            PatientEndpoints.amendMessage(threadUuid, messageUuid),
            request.json(),
            accessToken,
            request.idempotencyKey,
        ),
    )

    override fun closeMessageThread(
        accessToken: CharArray,
        threadUuid: String,
        request: PatientCloseThreadRequest,
    ): PatientEnvelope<PatientThreadResult> = PatientEnvelopeDecoder.messageThread(
        post(
            PatientEndpoints.closeThread(threadUuid),
            request.json(),
            accessToken,
            request.idempotencyKey,
        ),
    )

    override fun refresh(refreshToken: CharArray): PatientEnvelope<PatientTokenPair> =
        PatientEnvelopeDecoder.tokenPair(post(PatientEndpoints.REFRESH, JSONObject(), refreshToken))

    override fun revoke(accessOrRefreshToken: CharArray) {
        post(PatientEndpoints.REVOKE, JSONObject(), accessOrRefreshToken)
    }

    private fun get(
        path: String,
        bearerToken: CharArray,
        noStore: Boolean = false,
    ): String {
        val builder = Request.Builder()
            .url(url(PatientHttpMethod.GET, path))
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${bearerToken.concatToString()}")
            .get()
        if (noStore) builder.patientNoStore()
        return execute(builder.build())
    }

    private fun delete(path: String, bearerToken: CharArray): String {
        val request = Request.Builder()
            .url(url(PatientHttpMethod.DELETE, path))
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${bearerToken.concatToString()}")
            .delete()
            .patientNoStore()
            .build()
        return execute(request)
    }

    private fun post(
        path: String,
        json: JSONObject,
        bearerToken: CharArray? = null,
        idempotencyKey: String? = null,
    ): String {
        val builder = Request.Builder()
            .url(url(PatientHttpMethod.POST, path))
            .header("Accept", "application/json")
            .post(json.toString().toRequestBody(JSON_MEDIA_TYPE))
        if (bearerToken != null) {
            builder.header("Authorization", "Bearer ${bearerToken.concatToString()}")
        }
        if (idempotencyKey != null) {
            builder.header("Idempotency-Key", idempotencyKey)
        }
        return execute(builder.build())
    }

    private fun put(path: String, json: JSONObject, bearerToken: CharArray): String = execute(
        Request.Builder()
            .url(url(PatientHttpMethod.PUT, path))
            .header("Accept", "application/json")
            .header("Authorization", "Bearer ${bearerToken.concatToString()}")
            .put(json.toString().toRequestBody(JSON_MEDIA_TYPE))
            .build(),
    )

    private fun url(method: PatientHttpMethod, path: String): String {
        if (!configuration.enabled) throw PatientApiDisabledException()
        val patientPath = PatientEndpoints.requirePatientOperation(method, path)
        return configuration.baseUrl.trimEnd('/') + patientPath
    }

    private fun execute(request: Request): String = cachelessClient.newCall(request).execute().use { response ->
        val body = response.body?.string().orEmpty()
        if (!response.isSuccessful) {
            val error = PatientEnvelopeDecoder.error(body)
            throw PatientApiException(
                statusCode = response.code,
                errorCode = error?.code,
                message = error?.message ?: "The patient service could not complete the request.",
            )
        }
        body
    }

    private fun Request.Builder.patientNoStore(): Request.Builder =
        cacheControl(
            CacheControl.Builder()
                .noCache()
                .noStore()
                .build(),
        ).header("Pragma", "no-cache")

    private fun PatientDeviceDescriptor.json(): JSONObject = JSONObject()
        .put("uuid", uuid)
        .put("platform", "android")
        .put("name", name)
        .put("app_version", appVersion)
        .put("os_version", osVersion)

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
