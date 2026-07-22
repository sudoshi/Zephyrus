package net.acumenus.hummingbird.patient

/** Release has no synthetic payload and cannot create a synthetic patient session. */
internal object SyntheticReferencePatientScenario {
    fun noticeOrNull(): String? = null

    fun previewSessionOrNull(preview: PatientLaunchPreview): PatientSessionState? = null

    fun snapshotOrNull(): PatientSnapshot? = null

    fun messagingOrNull(): PatientMessagingState.Ready? = null
}
