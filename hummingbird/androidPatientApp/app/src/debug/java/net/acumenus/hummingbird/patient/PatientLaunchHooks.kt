package net.acumenus.hummingbird.patient

import android.content.Intent

/** Emulator-only launch controls. No credential or bearer-token extra exists. */
internal object PatientLaunchHooks {
    fun from(intent: Intent): PatientLaunchState = fromExtras(intent::getStringExtra)

    internal fun fromExtras(extra: (String) -> String?): PatientLaunchState {
        val scenario = extra("HB_PATIENT_SCENARIO")
        val destination = when (extra("HB_PATIENT_DESTINATION")?.lowercase()) {
            "path" -> PatientDestination.PATH
            "care-team" -> PatientDestination.CARE_TEAM
            "messages" -> PatientDestination.MESSAGES
            else -> PatientDestination.TODAY
        }
        val preview = when (extra("HB_PATIENT_STATE")?.lowercase()) {
            "loading" -> PatientLaunchPreview.LOADING
            "empty" -> PatientLaunchPreview.EMPTY
            "unavailable" -> PatientLaunchPreview.UNAVAILABLE
            "recoverable-error" -> PatientLaunchPreview.RECOVERABLE_ERROR
            else -> PatientLaunchPreview.NONE
        }
        return PatientLaunchState(
            syntheticReferenceRequested = scenario == "reference-inpatient",
            initialDestination = destination,
            preview = preview,
        )
    }
}
