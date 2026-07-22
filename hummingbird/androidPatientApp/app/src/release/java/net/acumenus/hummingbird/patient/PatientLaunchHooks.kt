package net.acumenus.hummingbird.patient

import android.content.Intent

/** Release binaries ignore every launch extra and cannot enter synthetic mode. */
internal object PatientLaunchHooks {
    fun from(@Suppress("UNUSED_PARAMETER") intent: Intent): PatientLaunchState = PatientLaunchState()

    internal fun fromExtras(@Suppress("UNUSED_PARAMETER") extra: (String) -> String?): PatientLaunchState =
        PatientLaunchState()
}
