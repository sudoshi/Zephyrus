package net.acumenus.hummingbird.patient.data

import org.json.JSONArray
import org.json.JSONObject
import java.time.OffsetDateTime

data class PatientEnvelope<T>(
    val data: T,
    val meta: PatientEnvelopeMeta,
    val links: Map<String, String>,
)

data class PatientEnvelopeMeta(
    val asOf: String?,
    val stale: Boolean,
    val version: Long?,
    val count: Int? = null,
    val sourceFreshness: PatientSourceFreshness? = null,
    val policyVersion: String? = null,
    val stateVocabularyVersion: String? = null,
    val requestId: String? = null,
    val generatedAt: String? = null,
    val idempotencyReplayed: Boolean? = null,
)

data class PatientSourceFreshness(
    val status: String,
    val observedAt: String?,
)

data class PatientTokenPair(
    val tokenType: String,
    val accessToken: String,
    val refreshToken: String,
    val expiresInSeconds: Int,
    val sessionUuid: String,
    val abilities: List<String>,
)

data class PatientProfile(
    val principalUuid: String,
    val principalType: String,
    val displayName: String,
    val email: String?,
    val phoneE164: String?,
    val emailVerified: Boolean,
    val phoneVerified: Boolean,
    val locale: String,
    val timezone: String,
    val preferences: PatientPreferences,
)

data class PatientPreferences(
    val textSize: String?,
    val reducedMotion: Boolean?,
    val highContrast: Boolean?,
    val notificationPreview: String?,
    val preferredChannel: String?,
)

/**
 * A partial replacement payload: null means the field is intentionally
 * omitted. The server rejects explicit nulls, so the client never serializes
 * them or invents a preference value on the patient's behalf.
 */
data class PatientPreferencesUpdate(
    val locale: String? = null,
    val timezone: String? = null,
    val textSize: String? = null,
    val reducedMotion: Boolean? = null,
    val highContrast: Boolean? = null,
    val notificationPreview: String? = null,
    val preferredChannel: String? = null,
) {
    internal fun json(): JSONObject = JSONObject().apply {
        locale?.let { put("locale", it) }
        timezone?.let { put("timezone", it) }
        textSize?.let { put("text_size", it) }
        reducedMotion?.let { put("reduced_motion", it) }
        highContrast?.let { put("high_contrast", it) }
        notificationPreview?.let { put("notification_preview", it) }
        preferredChannel?.let { put("preferred_channel", it) }
    }
}

/** In-memory-only metadata returned by the governed Manage devices surface. */
data class PatientDeviceSessionCollection(
    val sessions: List<PatientDeviceSession>,
)

data class PatientDeviceSession(
    val sessionUuid: String,
    val current: Boolean,
    val status: String,
    val device: PatientSessionDevice,
    val authMethod: String,
    val assuranceLevel: String?,
    val lastSeenAt: String?,
    val expiresAt: String?,
    val createdAt: String?,
)

data class PatientSessionDevice(
    val uuid: String?,
    val platform: String?,
    val name: String?,
    val appVersion: String?,
    val osVersion: String?,
)

data class PatientSessionRevocation(
    val sessionUuid: String,
    val revoked: Boolean,
    val alreadyRevoked: Boolean,
)

data class PatientEncounter(
    val encounterUuid: String,
    val grantUuid: String,
    val relationship: String,
    val scopes: List<String>,
    val validFrom: String?,
    val expiresAt: String?,
    val version: Long,
)

data class PatientEncounterCollection(
    val encounters: List<PatientEncounter>,
)

data class PatientApiError(
    val code: String,
    val message: String,
)

data class PatientProjectionDocument<T>(
    val projectionUuid: String,
    val encounterUuid: String,
    val kind: String,
    val content: T,
    val uncertainty: PatientProjectionUncertainty,
    val provenance: PatientProjectionProvenance,
    val revisionNotice: PatientProjectionRevisionNotice? = null,
    val observedAt: String?,
    val generatedAt: String?,
    val releasedAt: String?,
)

data class PatientProjectionRevisionNotice(
    val kind: String,
    val message: String,
)

data class PatientProjectionUncertainty(
    val level: String,
    val explanation: String,
    val canChange: Boolean,
    val reviewedAt: String?,
)

data class PatientProjectionProvenance(
    val projectionMethod: String,
    val sourceClass: String,
    val inputClasses: List<String>,
    val reviewState: String,
    val producerVersion: String,
)

data class PatientTodayContent(
    val headline: String,
    val summary: String,
    val schedule: List<PatientScheduleItem>,
    val nextSteps: List<String>,
    val notices: List<String>,
)

data class PatientScheduleItem(
    val itemUuid: String,
    val label: String,
    val detail: String?,
    val status: String,
    val timeWindow: String,
    val timingConfidence: String?,
    val preparation: String?,
    val canChange: Boolean,
)

data class PatientPathwayContent(
    val headline: String,
    val summary: String,
    val currentStage: String?,
    val stages: List<PatientPathwayStage>,
    val milestones: List<PatientPathwayMilestone>,
    val goals: List<PatientPathwayGoal>,
    val education: List<PatientEducationItem>,
    val questions: List<String>,
    val notices: List<String>,
)

data class PatientPathwayStage(
    val stageUuid: String,
    val title: String,
    val status: String,
    val summary: String,
    val expectedRange: String?,
    val timingConfidence: String?,
    val canChange: Boolean,
)

data class PatientPathwayMilestone(
    val milestoneUuid: String,
    val title: String,
    val status: String,
    val detail: String?,
    val timing: String?,
    val timingConfidence: String?,
    val canChange: Boolean,
)

data class PatientPathwayGoal(
    val goalUuid: String,
    val authorType: String,
    val label: String,
    val explanation: String?,
    val status: String,
    val targetRange: String?,
)

data class PatientEducationItem(
    val itemUuid: String,
    val title: String,
    val summary: String,
)

data class PatientPathwayEventsContent(
    val headline: String,
    val summary: String,
    val events: List<PatientPathwayEvent>,
    val notices: List<String>,
)

data class PatientPathwayEvent(
    val eventUuid: String,
    val title: String,
    val whenLabel: String,
    val status: String,
    val detail: String?,
    val category: String? = null,
)

data class PatientDischargeReadinessContent(
    val headline: String,
    val summary: String,
    val estimatedRange: String?,
    val estimatedConfidence: String?,
    val criteria: List<PatientDischargeCriterion>,
    val unresolvedNeeds: List<String>,
    val medications: List<PatientDischargeMedication>,
    val followUp: List<PatientDischargeFollowUp>,
    val warningSigns: List<String>,
    val contacts: List<PatientDischargeContact>,
    val questions: List<String>,
    val notices: List<String>,
)

data class PatientRoundsSummaryContent(
    val headline: String,
    val summary: String,
    val roundWindow: String?,
    val topics: List<PatientRoundsTopic>,
    val nextSteps: List<String>,
    val questions: List<String>,
    val notices: List<String>,
)

data class PatientRoundsTopic(
    val topicUuid: String,
    val title: String,
    val summary: String,
    val status: String,
)

data class PatientDischargeCriterion(
    val itemUuid: String,
    val label: String,
    val status: String,
    val detail: String?,
)

data class PatientDischargeMedication(
    val itemUuid: String,
    val name: String,
    val purpose: String?,
)

data class PatientDischargeFollowUp(
    val itemUuid: String,
    val label: String,
    val whenLabel: String,
)

data class PatientDischargeContact(
    val itemUuid: String,
    val label: String,
    val route: String,
)

data class PatientCareTeamContent(
    val headline: String,
    val summary: String,
    val members: List<PatientCareTeamProjectionMember>,
    val communicationOptions: List<String>,
    val notices: List<String>,
)

data class PatientCareTeamProjectionMember(
    val memberUuid: String,
    val displayName: String,
    val role: String,
    val service: String?,
    val responsibilities: List<String>,
    val contactRoute: String,
)

data class PatientImmediateHelp(
    val version: String,
    val text: String,
)

data class PatientMessageTopic(
    val code: String,
    val label: String,
    val description: String,
    val expectedResponseWindow: String,
)

data class PatientMessageTopics(
    val topics: List<PatientMessageTopic>,
    val immediateHelp: PatientImmediateHelp,
)

data class PatientMessageThreadCollection(
    val threads: List<PatientMessageThread>,
    val immediateHelp: PatientImmediateHelp,
)

data class PatientMessagingOverview(
    val topics: List<PatientMessageTopic>,
    val threads: List<PatientMessageThread>,
    val immediateHelp: PatientImmediateHelp,
)

data class PatientMessageThreadTopic(
    val code: String,
    val label: String,
    val description: String,
)

data class PatientMessageThread(
    val threadUuid: String,
    val topic: PatientMessageThreadTopic,
    val status: String,
    val ownershipState: String,
    val expectedResponseWindow: String,
    val version: Int,
    val lastMessageAt: String?,
    val createdAt: String?,
    val closedAt: String?,
    val closeReason: String?,
    val messages: List<PatientThreadMessage>,
)

data class PatientThreadMessage(
    val messageUuid: String,
    val senderDisplayRole: String,
    val messageKind: String,
    val body: String?,
    val relatesToMessageUuid: String?,
    val deliveryState: String,
    val sentAt: String?,
)

data class PatientThreadResult(val thread: PatientMessageThread)

data class PatientMessageResult(
    val thread: PatientMessageThread,
    val message: PatientThreadMessage,
)

data class PatientCreateThreadRequest(
    val topicCode: String,
    val message: String,
    val clientMessageUuid: String,
    val urgentGuidanceVersion: String,
    val idempotencyKey: String,
) {
    internal fun json(): JSONObject = JSONObject()
        .put("topic_code", topicCode)
        .put("message", message)
        .put("client_message_uuid", clientMessageUuid)
        .put("urgent_guidance_version", urgentGuidanceVersion)
}

/**
 * A patient-authored request to explain released pathway education. This model
 * deliberately has no completion, comprehension, consent, or assessment field.
 */
data class PatientEducationClarificationRequest(
    val message: String,
    val clientMessageUuid: String,
    val urgentGuidanceVersion: String,
    val idempotencyKey: String,
) {
    internal fun json(): JSONObject = JSONObject()
        .put("message", message)
        .put("client_message_uuid", clientMessageUuid)
        .put("urgent_guidance_version", urgentGuidanceVersion)
}

data class PatientSendMessageRequest(
    val message: String,
    val clientMessageUuid: String,
    val threadVersion: Int,
    val urgentGuidanceVersion: String,
    val idempotencyKey: String,
) {
    internal fun json(): JSONObject = JSONObject()
        .put("message", message)
        .put("client_message_uuid", clientMessageUuid)
        .put("thread_version", threadVersion)
        .put("urgent_guidance_version", urgentGuidanceVersion)
}

enum class PatientMessageAmendmentAction(val wireValue: String) {
    Correction("correction"),
    Retraction("retraction"),
}

data class PatientAmendMessageRequest(
    val action: PatientMessageAmendmentAction,
    val message: String?,
    val clientMessageUuid: String,
    val threadVersion: Int,
    val urgentGuidanceVersion: String,
    val idempotencyKey: String,
) {
    internal fun json(): JSONObject = JSONObject()
        .put("action", action.wireValue)
        .apply { message?.let { put("message", it) } }
        .put("client_message_uuid", clientMessageUuid)
        .put("thread_version", threadVersion)
        .put("urgent_guidance_version", urgentGuidanceVersion)
}

data class PatientCloseThreadRequest(
    val threadVersion: Int,
    val closeReason: String,
    val idempotencyKey: String,
) {
    internal fun json(): JSONObject = JSONObject()
        .put("thread_version", threadVersion)
        .put("close_reason", closeReason)
}

/**
 * Small explicit decoder for the backend's current patient envelope. Keeping
 * this decoder patient-specific prevents a staff DTO from crossing realms.
 */
object PatientEnvelopeDecoder {
    fun tokenPair(body: String): PatientEnvelope<PatientTokenPair> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        return PatientEnvelope(
            data = PatientTokenPair(
                tokenType = data.getString("token_type"),
                accessToken = data.getString("access_token"),
                refreshToken = data.getString("refresh_token"),
                expiresInSeconds = data.getInt("expires_in"),
                sessionUuid = data.getString("session_uuid"),
                abilities = data.getJSONArray("abilities").strings(),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun profile(body: String): PatientEnvelope<PatientProfile> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        return PatientEnvelope(
            data = PatientProfile(
                principalUuid = data.getString("principal_uuid"),
                principalType = data.getString("principal_type"),
                displayName = data.getString("display_name"),
                email = data.nullableString("email"),
                phoneE164 = data.nullableString("phone_e164"),
                emailVerified = data.optBoolean("email_verified", false),
                phoneVerified = data.optBoolean("phone_verified", false),
                locale = data.optString("locale", "en-US"),
                timezone = data.optString("timezone", "UTC"),
                preferences = data.optJSONObject("preferences")?.let { preferences ->
                    PatientPreferences(
                        textSize = preferences.nullableString("text_size"),
                        reducedMotion = preferences.nullableBoolean("reduced_motion"),
                        highContrast = preferences.nullableBoolean("high_contrast"),
                        notificationPreview = preferences.nullableString("notification_preview"),
                        preferredChannel = preferences.nullableString("preferred_channel"),
                    )
                } ?: PatientPreferences(null, null, null, null, null),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun patientSessions(body: String): PatientEnvelope<PatientDeviceSessionCollection> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        val sessions = data.getJSONArray("sessions")
        require(sessions.length() <= MAX_PATIENT_SESSIONS) {
            "Patient session responses may contain at most $MAX_PATIENT_SESSIONS rows."
        }
        val decoded = List(sessions.length()) { index ->
            val session = sessions.getJSONObject(index)
            val device = session.getJSONObject("device")
            val status = session.getString("status")
            require(status == "active") { "Patient session status must be active." }
            val authMethod = session.getString("auth_method")
            require(authMethod in PATIENT_AUTH_METHODS) {
                "Patient session authentication method is not supported."
            }
            val platform = device.boundedNullableString("platform", MAX_PLATFORM_LENGTH)
            require(platform == null || platform in PATIENT_DEVICE_PLATFORMS) {
                "Patient session device platform is not supported."
            }
            PatientDeviceSession(
                sessionUuid = PatientEndpoints.requireCanonicalUuid(
                    session.getString("session_uuid"),
                    "Session handle",
                ),
                current = session.getBoolean("current"),
                status = status,
                device = PatientSessionDevice(
                    uuid = device.nullableString("uuid")?.let { uuid ->
                        PatientEndpoints.requireCanonicalUuid(uuid, "Device handle")
                    },
                    platform = platform,
                    name = device.boundedNullableString("name", MAX_DEVICE_NAME_LENGTH),
                    appVersion = device.boundedNullableString(
                        "app_version",
                        MAX_VERSION_LENGTH,
                    ),
                    osVersion = device.boundedNullableString(
                        "os_version",
                        MAX_VERSION_LENGTH,
                    ),
                ),
                authMethod = authMethod,
                assuranceLevel = session.boundedNullableString(
                    "assurance_level",
                    MAX_ASSURANCE_LEVEL_LENGTH,
                ),
                lastSeenAt = session.validatedDateTimeOrNull("last_seen_at"),
                expiresAt = session.validatedDateTimeOrNull("expires_at"),
                createdAt = session.validatedDateTimeOrNull("created_at"),
            )
        }
        require(decoded.map { it.sessionUuid }.toSet().size == decoded.size) {
            "Patient session responses may not contain duplicate sessions."
        }
        require(decoded.count(PatientDeviceSession::current) <= 1) {
            "Patient session responses may identify at most one current session."
        }
        return PatientEnvelope(
            data = PatientDeviceSessionCollection(sessions = decoded),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun patientSessionRevocation(body: String): PatientEnvelope<PatientSessionRevocation> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        val revoked = data.getBoolean("revoked")
        require(revoked) { "Patient session revocation must confirm revocation." }
        return PatientEnvelope(
            data = PatientSessionRevocation(
                sessionUuid = PatientEndpoints.requireCanonicalUuid(
                    data.getString("session_uuid"),
                    "Session handle",
                ),
                revoked = revoked,
                alreadyRevoked = data.getBoolean("already_revoked"),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun encounters(body: String): PatientEnvelope<PatientEncounterCollection> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        val encounters = data.getJSONArray("encounters")
        return PatientEnvelope(
            data = PatientEncounterCollection(
                encounters = List(encounters.length()) { index ->
                    val encounter = encounters.getJSONObject(index)
                    PatientEncounter(
                        encounterUuid = encounter.getString("encounter_uuid"),
                        grantUuid = encounter.getString("grant_uuid"),
                        relationship = encounter.getString("relationship"),
                        scopes = encounter.getJSONArray("scopes").strings(),
                        validFrom = encounter.nullableString("valid_from"),
                        expiresAt = encounter.nullableString("expires_at"),
                        version = encounter.getLong("version"),
                    )
                },
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun today(body: String): PatientEnvelope<PatientProjectionDocument<PatientTodayContent>> =
        projection(body) { content ->
            PatientTodayContent(
                headline = content.getString("headline"),
                summary = content.getString("summary"),
                schedule = content.objects("schedule") { item ->
                    PatientScheduleItem(
                        itemUuid = item.getString("item_uuid"),
                        label = item.getString("label"),
                        detail = item.nullableString("detail"),
                        status = item.getString("status"),
                        timeWindow = item.getString("time_window"),
                        timingConfidence = item.nullableString("timing_confidence"),
                        preparation = item.nullableString("preparation"),
                        canChange = item.optBoolean("can_change", true),
                    )
                },
                nextSteps = content.stringList("next_steps"),
                notices = content.stringList("notices"),
            )
        }

    fun pathway(body: String): PatientEnvelope<PatientProjectionDocument<PatientPathwayContent>> =
        projection(body) { content ->
            PatientPathwayContent(
                headline = content.getString("headline"),
                summary = content.getString("summary"),
                currentStage = content.nullableString("current_stage"),
                stages = content.objects("stages") { stage ->
                    PatientPathwayStage(
                        stageUuid = stage.getString("stage_uuid"),
                        title = stage.getString("title"),
                        status = stage.getString("status"),
                        summary = stage.getString("summary"),
                        expectedRange = stage.nullableString("expected_range"),
                        timingConfidence = stage.nullableString("timing_confidence"),
                        canChange = stage.optBoolean("can_change", true),
                    )
                },
                milestones = content.objects("milestones") { milestone ->
                    PatientPathwayMilestone(
                        milestoneUuid = milestone.getString("milestone_uuid"),
                        title = milestone.getString("title"),
                        status = milestone.getString("status"),
                        detail = milestone.nullableString("detail"),
                        timing = milestone.nullableString("timing"),
                        timingConfidence = milestone.nullableString("timing_confidence"),
                        canChange = milestone.optBoolean("can_change", true),
                    )
                },
                goals = content.objects("goals") { goal ->
                    PatientPathwayGoal(
                        goalUuid = goal.getString("goal_uuid"),
                        authorType = goal.getString("author_type"),
                        label = goal.getString("label"),
                        explanation = goal.nullableString("explanation"),
                        status = goal.getString("status"),
                        targetRange = goal.nullableString("target_range"),
                    )
                },
                education = content.objects("education") { education ->
                    PatientEducationItem(
                        itemUuid = education.getString("item_uuid"),
                        title = education.getString("title"),
                        summary = education.getString("summary"),
                    )
                },
                questions = content.stringList("questions"),
                notices = content.stringList("notices"),
            )
        }

    fun dischargeReadiness(
        body: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientDischargeReadinessContent>> =
        projection(body) { content ->
            PatientDischargeReadinessContent(
                headline = content.getString("headline"),
                summary = content.getString("summary"),
                estimatedRange = content.nullableString("estimated_range"),
                estimatedConfidence = content.nullableString("estimated_confidence"),
                criteria = content.objects("criteria") { criterion ->
                    PatientDischargeCriterion(
                        itemUuid = criterion.getString("item_uuid"),
                        label = criterion.getString("label"),
                        status = criterion.getString("status"),
                        detail = criterion.nullableString("detail"),
                    )
                },
                unresolvedNeeds = content.stringList("unresolved_needs"),
                medications = content.objects("medications") { medication ->
                    PatientDischargeMedication(
                        itemUuid = medication.getString("item_uuid"),
                        name = medication.getString("name"),
                        purpose = medication.nullableString("purpose"),
                    )
                },
                followUp = content.objects("follow_up") { followUp ->
                    PatientDischargeFollowUp(
                        itemUuid = followUp.getString("item_uuid"),
                        label = followUp.getString("label"),
                        whenLabel = followUp.getString("when"),
                    )
                },
                warningSigns = content.stringList("warning_signs"),
                contacts = content.objects("contacts") { contact ->
                    PatientDischargeContact(
                        itemUuid = contact.getString("item_uuid"),
                        label = contact.getString("label"),
                        route = contact.getString("route"),
                    )
                },
                questions = content.stringList("questions"),
                notices = content.stringList("notices"),
            )
        }

    fun roundsSummary(
        body: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientRoundsSummaryContent>> =
        projection(body) { content ->
            PatientRoundsSummaryContent(
                headline = content.getString("headline"),
                summary = content.getString("summary"),
                roundWindow = content.nullableString("round_window"),
                topics = content.objects("topics") { topic ->
                    PatientRoundsTopic(
                        topicUuid = topic.getString("topic_uuid"),
                        title = topic.getString("title"),
                        summary = topic.getString("summary"),
                        status = topic.getString("status"),
                    )
                },
                nextSteps = content.stringList("next_steps"),
                questions = content.stringList("questions"),
                notices = content.stringList("notices"),
            )
        }

    fun pathwayEvents(
        body: String,
    ): PatientEnvelope<PatientProjectionDocument<PatientPathwayEventsContent>> =
        projection(body) { content ->
            PatientPathwayEventsContent(
                headline = content.getString("headline"),
                summary = content.getString("summary"),
                events = content.objects("events") { event ->
                    PatientPathwayEvent(
                        eventUuid = event.getString("event_uuid"),
                        title = event.getString("title"),
                        whenLabel = event.getString("when"),
                        status = event.getString("status"),
                        detail = event.nullableString("detail"),
                        category = event.nullableString("category"),
                    )
                },
                notices = content.stringList("notices"),
            )
        }

    fun careTeam(body: String): PatientEnvelope<PatientProjectionDocument<PatientCareTeamContent>> =
        projection(body) { content ->
            PatientCareTeamContent(
                headline = content.getString("headline"),
                summary = content.getString("summary"),
                members = content.objects("members") { member ->
                    PatientCareTeamProjectionMember(
                        memberUuid = member.getString("member_uuid"),
                        displayName = member.getString("display_name"),
                        role = member.getString("role"),
                        service = member.nullableString("service"),
                        responsibilities = member.stringList("responsibilities"),
                        contactRoute = member.getString("contact_route"),
                    )
                },
                communicationOptions = content.stringList("communication_options"),
                notices = content.stringList("notices"),
            )
        }

    fun messageTopics(body: String): PatientEnvelope<PatientMessageTopics> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        return PatientEnvelope(
            data = PatientMessageTopics(
                topics = data.objects("topics") { topic ->
                    PatientMessageTopic(
                        code = topic.getString("code"),
                        label = topic.getString("label"),
                        description = topic.getString("description"),
                        expectedResponseWindow = topic.getString("expected_response_window"),
                    )
                },
                immediateHelp = data.getJSONObject("immediate_help").immediateHelp(),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun messageThreads(body: String): PatientEnvelope<PatientMessageThreadCollection> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        return PatientEnvelope(
            data = PatientMessageThreadCollection(
                threads = data.objects("threads", ::messageThread),
                immediateHelp = data.getJSONObject("immediate_help").immediateHelp(),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun messageThread(body: String): PatientEnvelope<PatientThreadResult> {
        val root = JSONObject(body)
        return PatientEnvelope(
            data = PatientThreadResult(messageThread(root.getJSONObject("data").getJSONObject("thread"))),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun sentMessage(body: String): PatientEnvelope<PatientMessageResult> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        return PatientEnvelope(
            data = PatientMessageResult(
                thread = messageThread(data.getJSONObject("thread")),
                message = threadMessage(data.getJSONObject("message")),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    fun error(body: String): PatientApiError? = runCatching {
        val error = JSONObject(body).getJSONObject("error")
        PatientApiError(error.getString("code"), error.getString("message"))
    }.getOrNull()

    private fun <T> projection(
        body: String,
        decodeContent: (JSONObject) -> T,
    ): PatientEnvelope<PatientProjectionDocument<T>> {
        val root = JSONObject(body)
        val data = root.getJSONObject("data")
        val uncertainty = data.getJSONObject("uncertainty")
        val provenance = data.getJSONObject("provenance")
        return PatientEnvelope(
            data = PatientProjectionDocument(
                projectionUuid = data.getString("projection_uuid"),
                encounterUuid = data.getString("encounter_uuid"),
                kind = data.getString("kind"),
                content = decodeContent(data.getJSONObject("content")),
                uncertainty = PatientProjectionUncertainty(
                    level = uncertainty.getString("level"),
                    explanation = uncertainty.getString("explanation"),
                    canChange = uncertainty.getBoolean("can_change"),
                    reviewedAt = uncertainty.nullableString("reviewed_at"),
                ),
                provenance = PatientProjectionProvenance(
                    projectionMethod = provenance.getString("projection_method"),
                    sourceClass = provenance.getString("source_class"),
                    inputClasses = provenance.stringList("input_classes"),
                    reviewState = provenance.getString("review_state"),
                    producerVersion = provenance.getString("producer_version"),
                ),
                revisionNotice = data.revisionNotice(),
                observedAt = data.nullableString("observed_at"),
                generatedAt = data.nullableString("generated_at"),
                releasedAt = data.nullableString("released_at"),
            ),
            meta = root.meta(),
            links = root.links(),
        )
    }

    private fun JSONObject.revisionNotice(): PatientProjectionRevisionNotice? {
        val value = optJSONObject("revision_notice") ?: return null
        val kind = value.getString("kind")
        require(kind == "correction") { "patient_projection_revision_notice_kind_invalid" }

        return PatientProjectionRevisionNotice(
            kind = kind,
            message = value.getString("message"),
        )
    }

    private fun JSONObject.meta(): PatientEnvelopeMeta {
        val value = optJSONObject("meta") ?: JSONObject()
        return PatientEnvelopeMeta(
            asOf = value.nullableString("as_of"),
            stale = value.optBoolean("stale", false),
            version = value.nullableLong("version"),
            count = value.nullableInt("count"),
            sourceFreshness = value.optJSONObject("source_freshness")?.let { freshness ->
                PatientSourceFreshness(
                    status = freshness.getString("status"),
                    observedAt = freshness.nullableString("observed_at"),
                )
            },
            policyVersion = value.nullableString("policy_version"),
            stateVocabularyVersion = value.nullableString("state_vocabulary_version"),
            requestId = value.nullableString("request_id"),
            generatedAt = value.nullableString("generated_at"),
            idempotencyReplayed = value.nullableBoolean("idempotency_replayed"),
        )
    }

    private fun JSONObject.immediateHelp(): PatientImmediateHelp = PatientImmediateHelp(
        version = getString("version"),
        text = getString("text"),
    )

    private fun messageThread(value: JSONObject): PatientMessageThread {
        val topic = value.getJSONObject("topic")
        return PatientMessageThread(
            threadUuid = value.getString("thread_uuid"),
            topic = PatientMessageThreadTopic(
                code = topic.getString("code"),
                label = topic.getString("label"),
                description = topic.getString("description"),
            ),
            status = value.getString("status"),
            ownershipState = value.getString("ownership_state"),
            expectedResponseWindow = value.getString("expected_response_window"),
            version = value.getInt("version"),
            lastMessageAt = value.nullableString("last_message_at"),
            createdAt = value.nullableString("created_at"),
            closedAt = value.nullableString("closed_at"),
            closeReason = value.nullableString("close_reason"),
            messages = value.objects("messages", ::threadMessage),
        )
    }

    private fun threadMessage(value: JSONObject): PatientThreadMessage = PatientThreadMessage(
        messageUuid = value.getString("message_uuid"),
        senderDisplayRole = value.getString("sender_display_role"),
        messageKind = value.getString("message_kind"),
        body = value.nullableString("body"),
        relatesToMessageUuid = value.nullableString("relates_to_message_uuid"),
        deliveryState = value.getString("delivery_state"),
        sentAt = value.nullableString("sent_at"),
    )

    private fun JSONObject.links(): Map<String, String> {
        val objectValue = optJSONObject("links") ?: return emptyMap()
        return objectValue.keys().asSequence()
            .mapNotNull { key -> objectValue.nullableString(key)?.let { key to it } }
            .toMap()
    }

    private fun JSONObject.nullableString(key: String): String? =
        if (isNull(key) || !has(key)) null else getString(key)

    private fun JSONObject.nullableLong(key: String): Long? =
        if (isNull(key) || !has(key)) null else getLong(key)

    private fun JSONObject.nullableInt(key: String): Int? =
        if (isNull(key) || !has(key)) null else getInt(key)

    private fun JSONObject.nullableBoolean(key: String): Boolean? =
        if (isNull(key) || !has(key)) null else getBoolean(key)

    private fun JSONObject.boundedNullableString(key: String, maxLength: Int): String? {
        val value = nullableString(key)?.trim()?.takeIf(String::isNotEmpty) ?: return null
        require(value.length <= maxLength) { "$key exceeds the patient contract limit." }
        require(value.none(Char::isISOControl)) { "$key contains unsupported control characters." }
        return value
    }

    private fun JSONObject.validatedDateTimeOrNull(key: String): String? {
        val value = boundedNullableString(key, MAX_DATE_TIME_LENGTH) ?: return null
        require(runCatching { OffsetDateTime.parse(value) }.isSuccess) {
            "$key must be an ISO-8601 date-time."
        }
        return value
    }

    private fun JSONArray.strings(): List<String> =
        List(length()) { index -> getString(index) }

    private fun JSONObject.stringList(key: String): List<String> =
        optJSONArray(key)?.strings().orEmpty()

    private fun <T> JSONObject.objects(key: String, transform: (JSONObject) -> T): List<T> {
        val array = optJSONArray(key) ?: return emptyList()
        return List(array.length()) { index -> transform(array.getJSONObject(index)) }
    }

    private const val MAX_PATIENT_SESSIONS = 100
    private const val MAX_DEVICE_NAME_LENGTH = 190
    private const val MAX_VERSION_LENGTH = 80
    private const val MAX_ASSURANCE_LEVEL_LENGTH = 32
    private const val MAX_PLATFORM_LENGTH = 16
    private const val MAX_DATE_TIME_LENGTH = 64
    private val PATIENT_AUTH_METHODS = setOf(
        "password",
        "enrollment",
        "federated",
        "passkey",
        "recovery",
    )
    private val PATIENT_DEVICE_PLATFORMS = setOf("ios", "android", "web")
}
