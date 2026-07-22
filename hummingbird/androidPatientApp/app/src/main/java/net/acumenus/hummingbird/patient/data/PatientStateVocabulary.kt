package net.acumenus.hummingbird.patient.data

/**
 * The patient API transmits stable state codes. Keep their patient-language
 * rendering explicit and contextual instead of exposing a title-cased internal
 * identifier such as `at_risk`. A future translated registry must preserve the
 * codes and replace only this copy.
 */
internal enum class PatientStateDomain {
    SCHEDULE,
    PATHWAY,
    MILESTONE,
    PATHWAY_EVENT,
    GOAL,
    TIMING_CONFIDENCE,
    DISCHARGE_CRITERION,
    ROUNDS_TOPIC,
    PATHWAY_EVENT_CATEGORY,
}

internal object PatientStateVocabulary {
    const val VERSION = "patient-state-vocabulary.v1-draft"

    private val labelsByDomain: Map<PatientStateDomain, Map<String, String>> = mapOf(
        PatientStateDomain.SCHEDULE to mapOf(
            "requested" to "Requested",
            "planned" to "Planned",
            "confirmed" to "Confirmed",
            "in_progress" to "Happening now",
            "completed" to "Completed",
            "delayed" to "Delayed",
            "canceled" to "No longer planned",
        ),
        PatientStateDomain.PATHWAY to mapOf(
            "planned" to "Planned",
            "current" to "Happening now",
            "completed" to "Completed",
            "delayed" to "Delayed",
            "canceled" to "No longer planned",
        ),
        PatientStateDomain.MILESTONE to mapOf(
            "planned" to "Planned",
            "current" to "Happening now",
            "completed" to "Completed",
            "delayed" to "Delayed",
            "canceled" to "No longer planned",
        ),
        PatientStateDomain.PATHWAY_EVENT to mapOf(
            "planned" to "Planned",
            "current" to "Happening now",
            "completed" to "Completed",
            "delayed" to "Delayed",
            "canceled" to "No longer planned",
        ),
        PatientStateDomain.GOAL to mapOf(
            "proposed" to "Being considered",
            "planned" to "Planned",
            "in_progress" to "In progress",
            "completed" to "Completed",
            "paused" to "Paused",
            "canceled" to "No longer planned",
        ),
        PatientStateDomain.TIMING_CONFIDENCE to mapOf(
            "confirmed" to "Confirmed",
            "estimated" to "Estimated",
            "unknown" to "Not yet known",
        ),
        PatientStateDomain.DISCHARGE_CRITERION to mapOf(
            "met" to "Met",
            "pending" to "Still needed",
            "at_risk" to "Needs attention",
        ),
        PatientStateDomain.ROUNDS_TOPIC to mapOf(
            "discussed" to "Discussed",
            "current" to "Being reviewed",
            "planned" to "Planned",
        ),
        PatientStateDomain.PATHWAY_EVENT_CATEGORY to mapOf(
            "test" to "Test",
            "procedure" to "Procedure",
            "transport" to "Transportation",
            "other" to "Care update",
        ),
    )

    fun label(code: String, domain: PatientStateDomain): String =
        labelsByDomain[domain]?.get(code) ?: "Status being confirmed"

    fun labels(domain: PatientStateDomain): Map<String, String> =
        labelsByDomain.getValue(domain)

    /**
     * Older servers omit this additive field. Preserve that compatibility, but
     * do not render a projection explicitly stamped with unknown state copy.
     */
    fun isCompatible(serverVersion: String?): Boolean =
        serverVersion == null || serverVersion == VERSION
}
