package net.acumenus.hummingbird.patient.data

import net.acumenus.hummingbird.patient.PatientCareTeamMember
import net.acumenus.hummingbird.patient.PatientDataContext
import net.acumenus.hummingbird.patient.PatientDestination
import net.acumenus.hummingbird.patient.PatientDischargeReadinessContact
import net.acumenus.hummingbird.patient.PatientDischargeReadinessCriterion
import net.acumenus.hummingbird.patient.PatientDischargeReadinessFollowUp
import net.acumenus.hummingbird.patient.PatientDischargeReadinessMedication
import net.acumenus.hummingbird.patient.PatientDischargeReadinessView
import net.acumenus.hummingbird.patient.PatientEducation
import net.acumenus.hummingbird.patient.PatientGoal
import net.acumenus.hummingbird.patient.PatientMilestone
import net.acumenus.hummingbird.patient.PatientPathwayEventView
import net.acumenus.hummingbird.patient.PatientPathwayEventsView
import net.acumenus.hummingbird.patient.PatientPathStep
import net.acumenus.hummingbird.patient.PatientRoundsSummaryView
import net.acumenus.hummingbird.patient.PatientRoundsTopicView
import net.acumenus.hummingbird.patient.PatientSnapshot
import net.acumenus.hummingbird.patient.PatientTodayItem
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.UUID

sealed interface PatientSessionOutcome {
    data class Ready(val snapshot: PatientSnapshot) : PatientSessionOutcome
    data class Empty(val displayName: String, val message: String) : PatientSessionOutcome
}

data class PatientProjectionBundle(
    val today: PatientEnvelope<PatientProjectionDocument<PatientTodayContent>>?,
    val pathway: PatientEnvelope<PatientProjectionDocument<PatientPathwayContent>>?,
    val pathwayEvents: PatientEnvelope<PatientProjectionDocument<PatientPathwayEventsContent>>?,
    val dischargeReadiness: PatientEnvelope<PatientProjectionDocument<PatientDischargeReadinessContent>>?,
    val roundsSummary: PatientEnvelope<PatientProjectionDocument<PatientRoundsSummaryContent>>?,
    val careTeam: PatientEnvelope<PatientProjectionDocument<PatientCareTeamContent>>?,
)

data class PatientSignOutResult(val serverRevoked: Boolean)

data class PatientSessionRevocationOutcome(
    val result: PatientSessionRevocation,
    val currentSessionRevoked: Boolean,
)

/**
 * Synchronous session boundary. The view model moves calls off the main thread;
 * keeping orchestration synchronous makes refresh rotation and revoke clearing
 * deterministic and directly testable.
 */
class PatientSessionCoordinator(
    private val api: PatientApiGateway,
    private val credentials: PatientCredentialStore,
    private val device: PatientDeviceDescriptor,
) {
    fun signIn(email: String, password: CharArray): PatientSessionOutcome {
        val pair = api.exchangePassword(email, password, device).data
        persist(pair)
        return load(pair.accessToken)
    }

    fun enroll(request: PatientEnrollmentRequest): PatientSessionOutcome {
        val pair = api.verifyEnrollment(request, device).data
        persist(pair)
        return load(pair.accessToken)
    }

    fun restore(): PatientSessionOutcome? {
        val stored = credentials.read() ?: return null
        return try {
            load(stored.accessToken)
        } catch (error: PatientApiException) {
            if (error.statusCode != 401) throw error
            val refreshChars = stored.refreshToken.toCharArray()
            try {
                try {
                    val rotated = api.refresh(refreshChars).data
                    persist(rotated)
                    load(rotated.accessToken)
                } catch (refreshError: PatientApiException) {
                    if (refreshError.statusCode == 401 || refreshError.statusCode == 403) {
                        credentials.clear()
                    }
                    throw refreshError
                }
            } finally {
                refreshChars.fill('\u0000')
            }
        }
    }

    fun signOut(): PatientSignOutResult {
        val stored = credentials.read()
        var serverRevoked = stored == null
        try {
            if (stored != null) {
                val accessChars = stored.accessToken.toCharArray()
                try {
                    api.revoke(accessChars)
                    serverRevoked = true
                } finally {
                    accessChars.fill('\u0000')
                }
            }
        } catch (_: Exception) {
            serverRevoked = false
        } finally {
            credentials.clear()
        }
        return PatientSignOutResult(serverRevoked)
    }

    fun patientSessions(): List<PatientDeviceSession> =
        withSessionManagementAccess { token -> api.patientSessions(token).data.sessions }

    /** Saves patient-controlled display/delivery choices through the governed profile route. */
    fun updatePreferences(update: PatientPreferencesUpdate): PatientPreferences =
        withStoredAccess { token -> api.updatePreferences(token, update).data.preferences }

    /**
     * Performs exactly one user-confirmed DELETE (apart from the existing one-time 401 refresh
     * boundary). Transport and server failures are surfaced without an automatic retry.
     */
    fun revokePatientSession(sessionUuid: String): PatientSessionRevocationOutcome {
        PatientEndpoints.requireCanonicalUuid(sessionUuid, "Session handle")
        val currentSessionUuid = credentials.read()?.sessionUuid
            ?: throw PatientApiException(401, "patient_session_required", "Patient session required.")
        val result = withSessionManagementAccess { token ->
            api.revokePatientSession(token, sessionUuid).data
        }
        require(result.sessionUuid == sessionUuid && result.revoked) {
            "Patient session revocation response did not match the requested session."
        }
        val currentSessionRevoked = currentSessionUuid == sessionUuid
        if (currentSessionRevoked) credentials.clear()
        return PatientSessionRevocationOutcome(result, currentSessionRevoked)
    }

    fun messagingOverview(encounterUuid: String): PatientMessagingOverview =
        withStoredAccess { token ->
            val topics = api.messageTopics(token, encounterUuid).data
            val threads = api.messageThreads(token, encounterUuid).data
            if (topics.immediateHelp.version != threads.immediateHelp.version) {
                throw PatientApiException(
                    409,
                    "urgent_guidance_changed",
                    "Patient messaging guidance changed during refresh.",
                )
            }
            PatientMessagingOverview(
                topics = topics.topics,
                threads = threads.threads,
                immediateHelp = topics.immediateHelp,
            )
        }

    fun messageThread(threadUuid: String): PatientMessageThread =
        withStoredAccess { token -> api.messageThread(token, threadUuid).data.thread }

    fun createMessageThread(
        encounterUuid: String,
        topicCode: String,
        message: String,
        urgentGuidanceVersion: String,
    ): PatientMessageThread {
        val request = PatientCreateThreadRequest(
            topicCode = topicCode,
            message = message,
            clientMessageUuid = UUID.randomUUID().toString(),
            urgentGuidanceVersion = urgentGuidanceVersion,
            idempotencyKey = UUID.randomUUID().toString(),
        )
        return withStoredAccess { token ->
            api.createMessageThread(token, encounterUuid, request).data.thread
        }
    }

    fun requestEducationClarification(
        encounterUuid: String,
        educationItemUuid: String,
        message: String,
        urgentGuidanceVersion: String,
    ): PatientMessageThread {
        PatientEndpoints.requireCanonicalUuid(educationItemUuid, "Education item handle")
        val request = PatientEducationClarificationRequest(
            message = message,
            clientMessageUuid = UUID.randomUUID().toString(),
            urgentGuidanceVersion = urgentGuidanceVersion,
            idempotencyKey = UUID.randomUUID().toString(),
        )
        return withStoredAccess { token ->
            api.requestEducationClarification(token, encounterUuid, educationItemUuid, request).data.thread
        }
    }

    fun sendMessage(
        threadUuid: String,
        threadVersion: Int,
        message: String,
        urgentGuidanceVersion: String,
    ): PatientMessageResult {
        val request = PatientSendMessageRequest(
            message = message,
            clientMessageUuid = UUID.randomUUID().toString(),
            threadVersion = threadVersion,
            urgentGuidanceVersion = urgentGuidanceVersion,
            idempotencyKey = UUID.randomUUID().toString(),
        )
        return withStoredAccess { token -> api.sendMessage(token, threadUuid, request).data }
    }

    fun amendMessage(
        threadUuid: String,
        messageUuid: String,
        threadVersion: Int,
        action: PatientMessageAmendmentAction,
        message: String?,
        urgentGuidanceVersion: String,
    ): PatientMessageResult {
        val request = PatientAmendMessageRequest(
            action = action,
            message = message,
            clientMessageUuid = UUID.randomUUID().toString(),
            threadVersion = threadVersion,
            urgentGuidanceVersion = urgentGuidanceVersion,
            idempotencyKey = UUID.randomUUID().toString(),
        )
        return withStoredAccess { token ->
            api.amendMessage(token, threadUuid, messageUuid, request).data
        }
    }

    fun closeMessageThread(
        threadUuid: String,
        threadVersion: Int,
        closeReason: String = "no_longer_needed",
    ): PatientMessageThread {
        val request = PatientCloseThreadRequest(
            threadVersion = threadVersion,
            closeReason = closeReason,
            idempotencyKey = UUID.randomUUID().toString(),
        )
        return withStoredAccess { token ->
            api.closeMessageThread(token, threadUuid, request).data.thread
        }
    }

    private fun load(accessToken: String): PatientSessionOutcome {
        val token = accessToken.toCharArray()
        try {
            val profile = api.profile(token).data
            val encounter = api.encounters(token).data.encounters.firstOrNull()
                ?: return PatientSessionOutcome.Empty(
                    displayName = profile.displayName,
                    message = "No active hospital stay is available in Hummingbird Patient. Ask your care team if you expected to see one.",
                )

            val projections = PatientProjectionBundle(
                today = if ("today:read" in encounter.scopes) {
                    projectionOrNull { api.today(token, encounter.encounterUuid) }
                } else {
                    null
                },
                pathway = if ("pathway:read" in encounter.scopes) {
                    projectionOrNull { api.pathway(token, encounter.encounterUuid) }
                } else {
                    null
                },
                pathwayEvents = if ("pathway:read" in encounter.scopes) {
                    projectionOrNull { api.pathwayEvents(token, encounter.encounterUuid) }
                } else {
                    null
                },
                dischargeReadiness = if ("pathway:read" in encounter.scopes) {
                    projectionOrNull { api.dischargeReadiness(token, encounter.encounterUuid) }
                } else {
                    null
                },
                roundsSummary = if ("pathway:read" in encounter.scopes) {
                    projectionOrNull { api.roundsSummary(token, encounter.encounterUuid) }
                } else {
                    null
                },
                careTeam = if ("care_team:read" in encounter.scopes) {
                    projectionOrNull { api.careTeam(token, encounter.encounterUuid) }
                } else {
                    null
                },
            )
            return PatientSessionOutcome.Ready(
                PatientSnapshotFactory.create(
                    profile = profile,
                    projections = projections,
                    encounterUuid = encounter.encounterUuid,
                    encounterScopes = encounter.scopes,
                ),
            )
        } finally {
            token.fill('\u0000')
        }
    }

    private fun <T> projectionOrNull(
        load: () -> PatientEnvelope<PatientProjectionDocument<T>>,
    ): PatientEnvelope<PatientProjectionDocument<T>>? = try {
        load().takeIf { PatientStateVocabulary.isCompatible(it.meta.stateVocabularyVersion) }
    } catch (error: PatientApiException) {
        if (error.statusCode == 404 || error.statusCode == 403) null else throw error
    }

    /**
     * Executes an authenticated operation without exposing credentials above
     * this boundary. A rejected access token rotates once and retries the same
     * operation; message mutations remain exactly-once because their UUID
     * idempotency values are created before entering this helper.
     */
    private fun <T> withStoredAccess(operation: (CharArray) -> T): T {
        val stored = credentials.read()
            ?: throw PatientApiException(401, "patient_session_required", "Patient session required.")
        val access = stored.accessToken.toCharArray()
        try {
            return operation(access)
        } catch (error: PatientApiException) {
            if (error.statusCode != 401) throw error
        } finally {
            access.fill('\u0000')
        }

        val refresh = stored.refreshToken.toCharArray()
        try {
            val rotated = try {
                api.refresh(refresh).data
            } catch (error: PatientApiException) {
                if (error.statusCode == 401 || error.statusCode == 403) {
                    credentials.clear()
                }
                throw error
            }
            persist(rotated)
            val rotatedAccess = rotated.accessToken.toCharArray()
            try {
                return operation(rotatedAccess)
            } finally {
                rotatedAccess.fill('\u0000')
            }
        } finally {
            refresh.fill('\u0000')
        }
    }

    private fun <T> withSessionManagementAccess(operation: (CharArray) -> T): T = try {
        withStoredAccess(operation)
    } catch (error: PatientApiException) {
        if (error.statusCode == 401) credentials.clear()
        throw error
    }

    private fun persist(pair: PatientTokenPair) {
        try {
            credentials.write(
                PatientStoredCredentials(
                    accessToken = pair.accessToken,
                    refreshToken = pair.refreshToken,
                    sessionUuid = pair.sessionUuid,
                ),
            )
        } catch (storageError: Exception) {
            // A session that cannot be protected locally must not remain valid
            // remotely. Revoke the just-issued family and clear any partial
            // local write before surfacing the original storage failure.
            val accessToken = pair.accessToken.toCharArray()
            try {
                api.revoke(accessToken)
            } catch (revokeError: Exception) {
                storageError.addSuppressed(revokeError)
            } finally {
                accessToken.fill('\u0000')
                try {
                    credentials.clear()
                } catch (clearError: Exception) {
                    storageError.addSuppressed(clearError)
                }
            }
            throw storageError
        }
    }
}

object PatientSnapshotFactory {
    fun create(
        profile: PatientProfile,
        projections: PatientProjectionBundle,
        encounterUuid: String? = null,
        encounterScopes: List<String> = emptyList(),
    ): PatientSnapshot {
        val today = projections.today
        val pathway = projections.pathway
        val pathwayEvents = projections.pathwayEvents
        val dischargeReadiness = projections.dischargeReadiness
        val roundsSummary = projections.roundsSummary
        val careTeam = projections.careTeam
        val pathwayRevisionNotice = listOfNotNull(
            pathway?.data?.revisionNotice?.message,
            pathwayEvents?.data?.revisionNotice?.message,
            dischargeReadiness?.data?.revisionNotice?.message,
            roundsSummary?.data?.revisionNotice?.message,
        ).firstOrNull()
        val contexts = buildMap {
            today?.let { put(PatientDestination.TODAY, it.context("Today")) }
            pathway?.let { put(PatientDestination.PATH, it.context("My Path", pathwayRevisionNotice)) }
            if (pathway == null) pathwayEvents?.let { put(PatientDestination.PATH, it.context("My Path", pathwayRevisionNotice)) }
            if (pathway == null && pathwayEvents == null) dischargeReadiness?.let { put(PatientDestination.PATH, it.context("My Path", pathwayRevisionNotice)) }
            if (pathway == null && pathwayEvents == null && dischargeReadiness == null) roundsSummary?.let { put(PatientDestination.PATH, it.context("My Path", pathwayRevisionNotice)) }
            careTeam?.let { put(PatientDestination.CARE_TEAM, it.context("Care Team")) }
        }
        val fallbackContext = contexts[PatientDestination.TODAY]
            ?: contexts.values.firstOrNull()
            ?: unavailableContext("Your hospital stay")

        return PatientSnapshot(
            encounterUuid = encounterUuid,
            encounterScopes = encounterScopes,
            preferences = profile.preferences,
            patientDisplayName = profile.displayName,
            heading = fallbackContext.heading,
            asOfLabel = fallbackContext.asOfLabel,
            sourceLabel = fallbackContext.sourceLabel,
            uncertaintyNotice = fallbackContext.uncertaintyNotice,
            todayItems = today?.data?.content?.schedule?.map { item ->
                PatientTodayItem(
                    title = item.label,
                    timing = item.timeWindow,
                    status = PatientStateVocabulary.label(item.status, PatientStateDomain.SCHEDULE),
                    explanation = listOfNotNull(
                        item.detail,
                        item.preparation?.let { "Preparation: $it" },
                        item.timingConfidence?.let {
                            "Timing confidence: ${PatientStateVocabulary.label(it, PatientStateDomain.TIMING_CONFIDENCE)}."
                        },
                    ).joinToString(" ").ifBlank {
                        "This item is part of your currently released plan for today."
                    },
                    provenance = today.provenanceLabel(),
                )
            }.orEmpty(),
            pathway = pathway?.data?.content?.stages?.map { stage ->
                PatientPathStep(
                    title = stage.title,
                    state = PatientStateVocabulary.label(stage.status, PatientStateDomain.PATHWAY),
                    explanation = listOfNotNull(
                        stage.summary,
                        stage.expectedRange?.let { "Expected range: $it." },
                        stage.timingConfidence?.let {
                            "Timing confidence: ${PatientStateVocabulary.label(it, PatientStateDomain.TIMING_CONFIDENCE)}."
                        },
                    ).joinToString(" "),
                    provenance = pathway?.provenanceLabel().orEmpty(),
                )
            }.orEmpty(),
            careTeam = careTeam?.data?.content?.members?.map { member ->
                PatientCareTeamMember(
                    name = member.displayName,
                    role = member.role,
                    availability = member.service ?: "Current care-team assignment",
                    responsibility = member.responsibilities.joinToString(" ").ifBlank {
                        "Ask this team member what they are helping with during your stay."
                    },
                    provenance = careTeam.provenanceLabel(),
                    contactGuidance = member.contactRoute.patientContactGuidance(),
                )
            }.orEmpty(),
            contexts = contexts,
            todaySummary = today?.data?.content?.summary,
            todayNextSteps = today?.data?.content?.nextSteps.orEmpty(),
            todayNotices = today?.data?.content?.notices.orEmpty(),
            pathwaySummary = pathway?.data?.content?.summary,
            pathwayCurrentStage = pathway?.data?.content?.currentStage,
            pathwayMilestones = pathway?.data?.content?.milestones.orEmpty().map { milestone ->
                PatientMilestone(
                    id = milestone.milestoneUuid,
                    title = milestone.title,
                    status = PatientStateVocabulary.label(milestone.status, PatientStateDomain.MILESTONE),
                    detail = milestone.detail.orEmpty(),
                    timing = listOfNotNull(
                        milestone.timing,
                        milestone.timingConfidence?.let {
                            "Timing confidence: ${PatientStateVocabulary.label(it, PatientStateDomain.TIMING_CONFIDENCE)}."
                        },
                    ).joinToString(" "),
                    provenance = pathway?.provenanceLabel().orEmpty(),
                )
            },
            pathwayGoals = pathway?.data?.content?.goals.orEmpty().map { goal ->
                PatientGoal(
                    id = goal.goalUuid,
                    label = goal.label,
                    authorLabel = goal.authorType.patientGoalAuthorLabel(),
                    status = PatientStateVocabulary.label(goal.status, PatientStateDomain.GOAL),
                    detail = listOfNotNull(goal.explanation, goal.targetRange)
                        .joinToString(" ")
                        .ifBlank { "This is a released goal for your care pathway." },
                    provenance = pathway?.provenanceLabel().orEmpty(),
                )
            },
            pathwayEducation = pathway?.data?.content?.education.orEmpty().map { education ->
                PatientEducation(
                    id = education.itemUuid,
                    title = education.title,
                    summary = education.summary,
                    provenance = pathway?.provenanceLabel().orEmpty(),
                )
            },
            pathwayNotices = pathway?.data?.content?.notices.orEmpty(),
            pathwayEvents = pathwayEvents?.data?.content?.let { content ->
                PatientPathwayEventsView(
                    headline = content.headline,
                    summary = content.summary,
                    events = content.events.map { event ->
                        PatientPathwayEventView(
                            id = event.eventUuid,
                            title = event.title,
                            whenLabel = event.whenLabel,
                            status = PatientStateVocabulary.label(event.status, PatientStateDomain.PATHWAY_EVENT),
                            detail = event.detail.orEmpty(),
                            category = event.category?.let {
                                PatientStateVocabulary.label(it, PatientStateDomain.PATHWAY_EVENT_CATEGORY)
                            },
                        )
                    },
                    notices = content.notices,
                    provenance = pathwayEvents.provenanceLabel(),
                )
            },
            dischargeReadiness = dischargeReadiness?.data?.content?.let { content ->
                PatientDischargeReadinessView(
                    headline = content.headline,
                    summary = content.summary,
                    estimatedRange = content.estimatedRange,
                    estimatedConfidence = content.estimatedConfidence?.let {
                        PatientStateVocabulary.label(it, PatientStateDomain.TIMING_CONFIDENCE)
                    },
                    criteria = content.criteria.map { criterion ->
                        PatientDischargeReadinessCriterion(
                            id = criterion.itemUuid,
                            label = criterion.label,
                            status = PatientStateVocabulary.label(
                                criterion.status,
                                PatientStateDomain.DISCHARGE_CRITERION,
                            ),
                            detail = criterion.detail.orEmpty(),
                        )
                    },
                    unresolvedNeeds = content.unresolvedNeeds,
                    medications = content.medications.map { medication ->
                        PatientDischargeReadinessMedication(
                            id = medication.itemUuid,
                            name = medication.name,
                            purpose = medication.purpose.orEmpty(),
                        )
                    },
                    followUp = content.followUp.map { followUp ->
                        PatientDischargeReadinessFollowUp(
                            id = followUp.itemUuid,
                            label = followUp.label,
                            whenLabel = followUp.whenLabel,
                        )
                    },
                    warningSigns = content.warningSigns,
                    contacts = content.contacts.map { contact ->
                        PatientDischargeReadinessContact(
                            id = contact.itemUuid,
                            label = contact.label,
                            routeLabel = contact.route.patientContactGuidance(),
                        )
                    },
                    questions = content.questions,
                    notices = content.notices,
                    provenance = dischargeReadiness?.provenanceLabel().orEmpty(),
                )
            },
            roundsSummary = roundsSummary?.data?.content?.let { content ->
                PatientRoundsSummaryView(
                    headline = content.headline,
                    summary = content.summary,
                    roundWindow = content.roundWindow,
                    topics = content.topics.map { topic ->
                        PatientRoundsTopicView(
                            id = topic.topicUuid,
                            title = topic.title,
                            summary = topic.summary,
                            status = PatientStateVocabulary.label(topic.status, PatientStateDomain.ROUNDS_TOPIC),
                        )
                    },
                    nextSteps = content.nextSteps,
                    questions = content.questions,
                    notices = content.notices,
                    provenance = roundsSummary.provenanceLabel(),
                )
            },
            careTeamSummary = careTeam?.data?.content?.summary,
            careTeamCommunicationOptions = careTeam?.data?.content?.communicationOptions
                ?.mapNotNull { option -> option.patientCommunicationGuidance() }
                .orEmpty(),
            careTeamNotices = careTeam?.data?.content?.notices.orEmpty(),
        )
    }

    fun unavailableContext(heading: String): PatientDataContext = PatientDataContext(
        heading = heading,
        asOfLabel = "No released update is available",
        sourceLabel = "Source: no patient-facing projection released",
        uncertaintyNotice = "Ask your care team for the most current information.",
        stale = true,
    )

    private fun <T> PatientEnvelope<PatientProjectionDocument<T>>.context(
        fallbackHeading: String,
        revisionNotice: String? = data.revisionNotice?.message,
    ): PatientDataContext {
        val uncertainty = data.uncertainty
        return PatientDataContext(
            heading = when (val content = data.content) {
                is PatientTodayContent -> content.headline
                is PatientPathwayContent -> content.headline
                is PatientRoundsSummaryContent -> content.headline
                is PatientCareTeamContent -> content.headline
                else -> fallbackHeading
            },
            asOfLabel = "Updated ${formatTimestamp(data.releasedAt ?: meta.asOf)}",
            sourceLabel = provenanceLabel(),
            uncertaintyNotice = uncertainty.explanation,
            stale = meta.stale || meta.sourceFreshness?.status == "stale",
            revisionNotice = revisionNotice,
        )
    }

    private fun <T> PatientEnvelope<PatientProjectionDocument<T>>.provenanceLabel(): String {
        val source = data.provenance.sourceClass.replace('_', ' ')
        val review = data.provenance.reviewState.replace('_', ' ')
        return "Source: $source • $review"
    }

    private fun formatTimestamp(value: String?): String {
        if (value.isNullOrBlank()) return "time unavailable"
        return runCatching {
            OffsetDateTime.parse(value).format(DISPLAY_TIME)
        }.getOrDefault(value)
    }

    private fun String.patientContactGuidance(): String = when (this) {
        "speak_with_bedside_staff" -> "Ask your bedside staff to connect you."
        "call_button_for_urgent_help" -> "Use your bedside call button for urgent help."
        else -> "Ask bedside staff how to reach this care-team member."
    }

    private fun String.patientCommunicationGuidance(): String? = when (this) {
        "speak_with_bedside_staff" -> "Speak with your bedside nurse or another staff member."
        "call_button_for_urgent_help" -> "Use your bedside call button for urgent help."
        else -> null
    }

    private fun String.patientGoalAuthorLabel(): String = when (this) {
        "patient" -> "Your goal"
        "representative" -> "Goal shared by your representative"
        "care_team" -> "Care-team goal"
        else -> "Released pathway goal"
    }

    private val DISPLAY_TIME: DateTimeFormatter = DateTimeFormatter.ofPattern("MMM d, h:mm a")
}
