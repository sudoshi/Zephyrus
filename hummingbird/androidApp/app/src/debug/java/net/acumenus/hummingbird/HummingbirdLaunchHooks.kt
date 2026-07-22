package net.acumenus.hummingbird

import android.content.Intent
import net.acumenus.hummingbird.ui.HummingbirdLaunchConfig

/** Emulator and UI-test launch controls. This implementation is absent from release builds. */
internal object HummingbirdLaunchHooks {
    fun from(intent: Intent): HummingbirdLaunchState = fromExtras(intent::getStringExtra)

    internal fun fromExtras(extra: (String) -> String?): HummingbirdLaunchState {
        val credentials = if (extra("HB_AUTOLOGIN") == "1") {
            val username = extra("HB_USER")?.takeIf(String::isNotBlank)
            val password = extra("HB_PASS")?.takeIf(String::isNotBlank)
            if (username != null && password != null) {
                HummingbirdAutologinCredentials(username, password)
            } else {
                null
            }
        } else {
            null
        }

        return HummingbirdLaunchState(
            autologin = credentials,
            config = HummingbirdLaunchConfig(
                roleId = extra("HB_ROLE"),
                tab = extra("HB_TAB"),
                openUnitId = extra("HB_OPEN_UNIT")?.toIntOrNull(),
                openTarget = extra("HB_OPEN_TARGET"),
                forceError = extra("HB_FORCE_ERROR") == "1",
                debugExplorer = extra("HB_DEBUG_EXPLORER") == "1",
            ),
        )
    }
}
