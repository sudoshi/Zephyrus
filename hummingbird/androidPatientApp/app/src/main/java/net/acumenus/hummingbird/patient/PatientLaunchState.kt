package net.acumenus.hummingbird.patient

/** Debug may request a synthetic scenario; release always returns this default. */
internal data class PatientLaunchState(
    val syntheticReferenceRequested: Boolean = false,
    val initialDestination: PatientDestination = PatientDestination.TODAY,
    val preview: PatientLaunchPreview = PatientLaunchPreview.NONE,
)

/** Deterministic non-PHI states used only when the debug launch hook selects one. */
internal enum class PatientLaunchPreview {
    NONE,
    LOADING,
    EMPTY,
    UNAVAILABLE,
    RECOVERABLE_ERROR,
}
