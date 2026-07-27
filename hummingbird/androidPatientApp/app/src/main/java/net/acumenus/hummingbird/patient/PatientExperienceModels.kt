package net.acumenus.hummingbird.patient

import net.acumenus.hummingbird.patient.data.PatientImmediateHelp
import net.acumenus.hummingbird.patient.data.PatientDeviceSession
import net.acumenus.hummingbird.patient.data.PatientMessageThread
import net.acumenus.hummingbird.patient.data.PatientMessageTopic
import net.acumenus.hummingbird.patient.data.PatientPreferences
import net.acumenus.hummingbird.patient.data.PatientPreferencesUpdate

enum class PatientDestination(val label: String) {
    TODAY("Today"),
    PATH("My Path"),
    CARE_TEAM("Care Team"),
    MESSAGES("Messages"),
}

enum class PatientAuthMode {
    ENROLL,
    SIGN_IN,
}

sealed interface PatientSessionState {
    data class SignedOut(
        val authMode: PatientAuthMode = PatientAuthMode.ENROLL,
        val status: PatientAuthStatus = PatientAuthStatus.Idle,
    ) : PatientSessionState

    data class Ready(
        val snapshot: PatientSnapshot,
        val synthetic: Boolean,
    ) : PatientSessionState

    data class Loading(val message: String) : PatientSessionState

    data class Empty(
        val patientDisplayName: String,
        val message: String,
    ) : PatientSessionState
}

sealed interface PatientAuthStatus {
    data object Idle : PatientAuthStatus
    data class ValidationError(val message: String) : PatientAuthStatus
    data class Unavailable(val message: String) : PatientAuthStatus
    data class Failure(val message: String) : PatientAuthStatus
}

data class PatientUiState(
    val session: PatientSessionState,
    val destination: PatientDestination = PatientDestination.TODAY,
    val messaging: PatientMessagingState = PatientMessagingState.Hidden,
    val deviceSessions: PatientDeviceSessionsState = PatientDeviceSessionsState.Hidden,
    val preferences: PatientPreferencesState = PatientPreferencesState.Hidden,
)

/** Account-level presentation and delivery choices; never clinical care-plan data. */
sealed interface PatientPreferencesState {
    data object Hidden : PatientPreferencesState
    data class Unavailable(val message: String) : PatientPreferencesState
    data class Ready(
        val preferences: PatientPreferences,
        val saving: Boolean = false,
        val message: String? = null,
    ) : PatientPreferencesState
}

internal fun PatientPreferencesUpdate.applyTo(current: PatientPreferences): PatientPreferences =
    PatientPreferences(
        textSize = textSize ?: current.textSize,
        reducedMotion = reducedMotion ?: current.reducedMotion,
        highContrast = highContrast ?: current.highContrast,
        notificationPreview = notificationPreview ?: current.notificationPreview,
        preferredChannel = preferredChannel ?: current.preferredChannel,
    )

sealed interface PatientDeviceSessionsState {
    data object Hidden : PatientDeviceSessionsState
    data class Loading(val message: String) : PatientDeviceSessionsState
    data class Unavailable(
        val message: String,
        val canRetry: Boolean,
    ) : PatientDeviceSessionsState
    data class Ready(
        val sessions: List<PatientDeviceSession>,
        val selectedForRevocation: PatientDeviceSession? = null,
        val operation: PatientDeviceSessionOperation = PatientDeviceSessionOperation.Idle,
    ) : PatientDeviceSessionsState
}

sealed interface PatientDeviceSessionOperation {
    data object Idle : PatientDeviceSessionOperation
    data class Working(val sessionUuid: String) : PatientDeviceSessionOperation
    data class Notice(val message: String) : PatientDeviceSessionOperation
    data class Failure(val message: String) : PatientDeviceSessionOperation
}

sealed interface PatientMessagingState {
    data object Hidden : PatientMessagingState
    data class Loading(val message: String) : PatientMessagingState
    data class Unavailable(val message: String) : PatientMessagingState
    data class Ready(
        val topics: List<PatientMessageTopic>,
        val threads: List<PatientMessageThread>,
        val immediateHelp: PatientImmediateHelp,
        val canWrite: Boolean,
        val selectedThread: PatientMessageThread? = null,
        val operation: PatientMessagingOperation = PatientMessagingOperation.Idle,
    ) : PatientMessagingState
}

sealed interface PatientMessagingOperation {
    data object Idle : PatientMessagingOperation
    data class Working(val message: String) : PatientMessagingOperation
    data class Notice(val message: String) : PatientMessagingOperation
    data class Failure(val message: String) : PatientMessagingOperation
}

data class PatientSnapshot(
    val patientDisplayName: String,
    val heading: String,
    val asOfLabel: String,
    val sourceLabel: String,
    val uncertaintyNotice: String,
    val todayItems: List<PatientTodayItem>,
    val pathway: List<PatientPathStep>,
    val careTeam: List<PatientCareTeamMember>,
    val contexts: Map<PatientDestination, PatientDataContext> = emptyMap(),
    val todaySummary: String? = null,
    val todayNextSteps: List<String> = emptyList(),
    val todayNotices: List<String> = emptyList(),
    val pathwaySummary: String? = null,
    val pathwayCurrentStage: String? = null,
    val pathwayMilestones: List<PatientMilestone> = emptyList(),
    val pathwayGoals: List<PatientGoal> = emptyList(),
    val pathwayEducation: List<PatientEducation> = emptyList(),
    val pathwayNotices: List<String> = emptyList(),
    val pathwayEvents: PatientPathwayEventsView? = null,
    val dischargeReadiness: PatientDischargeReadinessView? = null,
    val roundsSummary: PatientRoundsSummaryView? = null,
    val careTeamSummary: String? = null,
    val careTeamCommunicationOptions: List<String> = emptyList(),
    val careTeamNotices: List<String> = emptyList(),
    val encounterUuid: String? = null,
    val encounterScopes: List<String> = emptyList(),
    val preferences: PatientPreferences = PatientPreferences(null, null, null, null, null),
)

data class PatientDataContext(
    val heading: String,
    val asOfLabel: String,
    val sourceLabel: String,
    val uncertaintyNotice: String,
    val stale: Boolean,
    val revisionNotice: String? = null,
)

data class PatientEnrollmentForm(
    val challengeUuid: String,
    val challengeToken: String,
    val verificationCode: String,
    val displayName: String,
    val email: String,
    val password: String,
    val passwordConfirmation: String,
)

data class PatientTodayItem(
    val title: String,
    val timing: String,
    val status: String,
    val explanation: String,
    val provenance: String,
)

data class PatientPathStep(
    val title: String,
    val state: String,
    val explanation: String,
    val provenance: String,
)

data class PatientMilestone(
    val id: String,
    val title: String,
    val status: String,
    val detail: String,
    val timing: String,
    val provenance: String,
)

data class PatientGoal(
    val id: String,
    val label: String,
    val authorLabel: String,
    val status: String,
    val detail: String,
    val provenance: String,
)

data class PatientEducation(
    val id: String,
    val title: String,
    val summary: String,
    val provenance: String,
)

data class PatientPathwayEventsView(
    val headline: String,
    val summary: String,
    val events: List<PatientPathwayEventView>,
    val notices: List<String>,
    val provenance: String,
)

data class PatientPathwayEventView(
    val id: String,
    val title: String,
    val whenLabel: String,
    val status: String,
    val detail: String,
    val category: String? = null,
)

data class PatientDischargeReadinessView(
    val headline: String,
    val summary: String,
    val estimatedRange: String?,
    val estimatedConfidence: String?,
    val criteria: List<PatientDischargeReadinessCriterion>,
    val unresolvedNeeds: List<String>,
    val medications: List<PatientDischargeReadinessMedication>,
    val followUp: List<PatientDischargeReadinessFollowUp>,
    val warningSigns: List<String>,
    val contacts: List<PatientDischargeReadinessContact>,
    val questions: List<String>,
    val notices: List<String>,
    val provenance: String,
)

data class PatientRoundsSummaryView(
    val headline: String,
    val summary: String,
    val roundWindow: String?,
    val topics: List<PatientRoundsTopicView>,
    val nextSteps: List<String>,
    val questions: List<String>,
    val notices: List<String>,
    val provenance: String,
)

data class PatientRoundsTopicView(
    val id: String,
    val title: String,
    val summary: String,
    val status: String,
)

data class PatientDischargeReadinessCriterion(
    val id: String,
    val label: String,
    val status: String,
    val detail: String,
)

data class PatientDischargeReadinessMedication(
    val id: String,
    val name: String,
    val purpose: String,
)

data class PatientDischargeReadinessFollowUp(
    val id: String,
    val label: String,
    val whenLabel: String,
)

data class PatientDischargeReadinessContact(
    val id: String,
    val label: String,
    val routeLabel: String,
)

data class PatientCareTeamMember(
    val name: String,
    val role: String,
    val availability: String,
    val responsibility: String,
    val provenance: String,
    val contactGuidance: String? = null,
)
