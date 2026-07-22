package net.acumenus.hummingbird

import android.content.Intent

/** Release builds deliberately ignore all launch extras. */
internal object HummingbirdLaunchHooks {
    fun from(@Suppress("UNUSED_PARAMETER") intent: Intent): HummingbirdLaunchState =
        HummingbirdLaunchState()
}
