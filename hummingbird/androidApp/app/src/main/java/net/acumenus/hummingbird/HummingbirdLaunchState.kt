package net.acumenus.hummingbird

import net.acumenus.hummingbird.ui.HummingbirdLaunchConfig

/** Values consumed by MainActivity; only the debug source set can populate them. */
internal data class HummingbirdLaunchState(
    val autologin: HummingbirdAutologinCredentials? = null,
    val config: HummingbirdLaunchConfig = HummingbirdLaunchConfig(),
)

internal data class HummingbirdAutologinCredentials(
    val username: String,
    val password: String,
)
