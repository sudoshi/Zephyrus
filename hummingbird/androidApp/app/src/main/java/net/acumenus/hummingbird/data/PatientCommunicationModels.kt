package net.acumenus.hummingbird.data

import java.util.UUID

data class PatientCommunicationTopic(
    val code: String,
    val label: String,
)

data class PatientCommunicationUnit(
    val id: Int,
    val label: String,
)

data class PatientCommunicationPool(
    val poolUuid: String,
    val label: String,
)

data class PatientCommunicationMessage(
    val messageUuid: String,
    val senderDisplayRole: String,
    val visibility: String,
    val messageKind: String,
    val body: String?,
    val deliveryState: String,
    val sentAt: String?,
)

data class PatientCommunicationWorkItem(
    val workItemUuid: String,
    val threadUuid: String,
    val patientContextRef: String?,
    val topic: PatientCommunicationTopic,
    val unit: PatientCommunicationUnit?,
    val pool: PatientCommunicationPool,
    val status: String,
    val ownershipState: String,
    val assignedToMe: Boolean,
    val workItemVersion: Int,
    val threadVersion: Int,
    val lastMessageAt: String?,
    val dueAt: String?,
    val escalateAt: String?,
    val isResponseDue: Boolean,
    val isEscalationDue: Boolean,
    val closedAt: String?,
    val messages: List<PatientCommunicationMessage> = emptyList(),
    val hasEarlierMessages: Boolean = false,
)

data class PatientCommunicationInbox(
    val items: List<PatientCommunicationWorkItem>,
    val count: Int,
)

data class PatientCommunicationMutationResult(
    val workItem: PatientCommunicationWorkItem?,
    val message: PatientCommunicationMessage?,
    val eventUuid: String?,
    val replayed: Boolean,
)

data class PatientCommunicationRouteActions(
    val canRelease: Boolean,
    val canReassign: Boolean,
    val canReroute: Boolean,
)

data class PatientCommunicationRouteReason(
    val code: String,
    val label: String,
)

data class PatientCommunicationRouteReasonOptions(
    val release: List<PatientCommunicationRouteReason>,
    val reassign: List<PatientCommunicationRouteReason>,
    val reroute: List<PatientCommunicationRouteReason>,
)

data class PatientCommunicationReassignCandidate(
    val membershipUuid: String,
    val label: String,
    val membershipRole: String,
)

data class PatientCommunicationRerouteCandidate(
    val poolUuid: String,
    val label: String,
    val scopeType: String,
    val unit: PatientCommunicationUnit?,
)

data class PatientCommunicationRouteCandidates(
    val workItemUuid: String,
    val workItemVersion: Int,
    val threadVersion: Int,
    val actions: PatientCommunicationRouteActions,
    val reasonOptions: PatientCommunicationRouteReasonOptions,
    val reassignCandidates: List<PatientCommunicationReassignCandidate>,
    val rerouteCandidates: List<PatientCommunicationRerouteCandidate>,
)

enum class PatientCommunicationRoutingAction(
    val label: String,
) {
    Release("Release to team"),
    Reassign("Reassign responder"),
    Reroute("Reroute to another team"),
}

/**
 * Client-side routing policy is deliberately narrower than presentation state. The server's
 * action flags remain authoritative, while this layer fails closed on unknown codes and prevents
 * a stale or fabricated reason/opaque target from becoming a command.
 */
object PatientCommunicationRoutingPolicy {
    const val MAX_REASON_OPTIONS = 12
    const val MAX_CANDIDATES = 50
    const val MAX_LABEL_LENGTH = 120

    val releaseReasonCodes = setOf(
        "return_to_team",
        "shift_handoff",
        "responder_unavailable",
        "incorrect_assignment",
    )
    val reassignReasonCodes = setOf(
        "supervisor_assignment",
        "shift_handoff",
        "coverage_change",
        "workload_balance",
    )
    val rerouteReasonCodes = setOf(
        "wrong_team",
        "unit_transfer",
        "service_change",
        "specialty_needed",
    )
    val membershipRoles = setOf("responder", "triage", "supervisor")
    val scopeTypes = setOf("unit", "facility", "enterprise")

    fun canOpen(canRespond: Boolean, item: PatientCommunicationWorkItem): Boolean =
        canRespond && item.status == "open"

    fun matchesDisplayedItem(
        candidates: PatientCommunicationRouteCandidates,
        item: PatientCommunicationWorkItem,
    ): Boolean =
        candidates.workItemUuid == item.workItemUuid &&
            candidates.workItemVersion == item.workItemVersion &&
            candidates.threadVersion == item.threadVersion

    fun isAllowed(action: PatientCommunicationRoutingAction, actions: PatientCommunicationRouteActions): Boolean =
        when (action) {
            PatientCommunicationRoutingAction.Release -> actions.canRelease
            PatientCommunicationRoutingAction.Reassign -> actions.canReassign
            PatientCommunicationRoutingAction.Reroute -> actions.canReroute
        }

    fun reasons(
        action: PatientCommunicationRoutingAction,
        options: PatientCommunicationRouteReasonOptions,
    ): List<PatientCommunicationRouteReason> = when (action) {
        PatientCommunicationRoutingAction.Release -> options.release
        PatientCommunicationRoutingAction.Reassign -> options.reassign
        PatientCommunicationRoutingAction.Reroute -> options.reroute
    }

    fun allowedReasonCodes(action: PatientCommunicationRoutingAction): Set<String> = when (action) {
        PatientCommunicationRoutingAction.Release -> releaseReasonCodes
        PatientCommunicationRoutingAction.Reassign -> reassignReasonCodes
        PatientCommunicationRoutingAction.Reroute -> rerouteReasonCodes
    }

    fun canSubmit(
        candidates: PatientCommunicationRouteCandidates,
        action: PatientCommunicationRoutingAction?,
        reasonCode: String?,
        targetUuid: String?,
    ): Boolean {
        if (action == null || !isAllowed(action, candidates.actions)) return false
        if (reasons(action, candidates.reasonOptions).none { it.code == reasonCode }) return false
        return when (action) {
            PatientCommunicationRoutingAction.Release -> targetUuid == null
            PatientCommunicationRoutingAction.Reassign ->
                candidates.reassignCandidates.any { it.membershipUuid == targetUuid }
            PatientCommunicationRoutingAction.Reroute ->
                candidates.rerouteCandidates.any { it.poolUuid == targetUuid }
        }
    }
}

enum class PatientCommunicationCloseReason(
    val wireValue: String,
    val label: String,
) {
    QuestionAnswered("question_answered", "Question answered"),
    Duplicate("duplicate", "Duplicate conversation"),
    Transferred("transferred", "Transferred to another workflow"),
    PatientRequested("patient_requested", "Patient requested closure"),
    Other("other", "Other approved reason"),
}

/** Server-derived capability hints; every resource request remains independently gated. */
object PatientCommunicationAccess {
    fun isEligible(me: MeData?): Boolean = me?.canViewPatientCommunications == true

    fun canRespond(me: MeData?): Boolean =
        me?.canViewPatientCommunications == true && me.canRespondPatientCommunications
}

/**
 * PHI-minimized routing for the server-authorized attention item returned by `/for-you`.
 *
 * The type is retained across every local role queue. Navigation remains stricter: the item must
 * also have the communications domain and an exact, canonical work-item UUID identifier.
 */
object PatientCommunicationForYou {
    const val TYPE = "patient_communication"
    const val DOMAIN = "communications"
    const val ID_PREFIX = "patient-communication-"
    const val TITLE = "Patient message needs attention"
    const val SUBTITLE = "Open Messages to review this care-team communication."

    fun isType(type: String): Boolean = type == TYPE

    fun isRestrictedCandidate(id: String, type: String, domain: String?): Boolean =
        isType(type) || domain == DOMAIN || id.startsWith(ID_PREFIX)

    fun isExactRoutableTriple(id: String, type: String, domain: String?): Boolean =
        isType(type) && domain == DOMAIN && canonicalWorkItemUuid(id) != null

    fun isAttentionItem(item: ForYouItem): Boolean =
        isType(item.type) && item.domain == DOMAIN

    fun workItemUuid(item: ForYouItem): String? {
        if (!isAttentionItem(item)) return null
        return canonicalWorkItemUuid(item.id)
    }

    private fun canonicalWorkItemUuid(id: String): String? {
        if (!id.startsWith(ID_PREFIX)) return null
        val candidate = id.drop(ID_PREFIX.length)
        return runCatching { PatientCommunicationCommandIds.requireUuid(candidate) }.getOrNull()
    }

    fun urgencyLabel(tier: String): String = when (tier.lowercase()) {
        "critical" -> "Immediate attention"
        "warning" -> "Urgent"
        "success" -> "Within response target"
        else -> "Needs review"
    }
}

/** UUIDs are generated at the explicit user-action boundary and retained in memory for exact retry. */
object PatientCommunicationCommandIds {
    fun next(): String = UUID.randomUUID().toString()

    fun requireUuid(value: String): String {
        val normalized = UUID.fromString(value).toString()
        require(normalized == value) { "Idempotency keys must be canonical UUIDs." }
        return normalized
    }
}

enum class PatientCommunicationAttention {
    EscalationDue,
    ResponseDue,
    AwaitingResponse,
    Responded,
    Closed,
}

object PatientCommunicationPresentation {
    private val claimableOwnershipStates = setOf("pool_owned", "rerouted", "escalated")

    fun isClaimable(item: PatientCommunicationWorkItem): Boolean =
        item.status == "open" && !item.assignedToMe && item.ownershipState in claimableOwnershipStates

    fun attention(item: PatientCommunicationWorkItem): PatientCommunicationAttention = when {
        item.status == "closed" -> PatientCommunicationAttention.Closed
        item.isEscalationDue -> PatientCommunicationAttention.EscalationDue
        item.isResponseDue -> PatientCommunicationAttention.ResponseDue
        item.ownershipState == "responded" -> PatientCommunicationAttention.Responded
        else -> PatientCommunicationAttention.AwaitingResponse
    }

    fun attentionLabel(item: PatientCommunicationWorkItem): String = when (attention(item)) {
        PatientCommunicationAttention.EscalationDue -> "Escalation due now"
        PatientCommunicationAttention.ResponseDue -> "Response due now"
        PatientCommunicationAttention.AwaitingResponse -> "Within response target"
        PatientCommunicationAttention.Responded -> "Care-team response sent"
        PatientCommunicationAttention.Closed -> "Closed"
    }
}

enum class PatientCommunicationRecovery {
    Reauthenticate,
    RefetchWithoutResend,
    ExplicitExactRetryAvailable,
    DiscardCommand,
}

/** No branch ever authorizes an automatic mutation resend. */
object PatientCommunicationMutationRecoveryPolicy {
    fun after(statusCode: Int?): PatientCommunicationRecovery = when {
        statusCode == 401 -> PatientCommunicationRecovery.Reauthenticate
        statusCode == 409 -> PatientCommunicationRecovery.RefetchWithoutResend
        statusCode == null || statusCode in 500..599 -> PatientCommunicationRecovery.ExplicitExactRetryAvailable
        else -> PatientCommunicationRecovery.DiscardCommand
    }
}
