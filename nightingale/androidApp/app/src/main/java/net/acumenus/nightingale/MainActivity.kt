package net.acumenus.nightingale

import android.app.UiModeManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

class MainActivity : ComponentActivity() {
    private var privacyCovered by mutableStateOf(false)
    private var systemReduceMotion by mutableStateOf(false)
    private var systemHighContrast by mutableStateOf(false)
    internal val volatileInputState = NightingaleVolatileInputState()
    internal lateinit var presentationPreferences: NightingalePresentationPreferences
        private set

    internal val isPrivacyCoverActive: Boolean
        get() = privacyCovered

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        NightingalePrivacyPolicy.protect(window)
        presentationPreferences = NightingalePresentationPreferences(this)
        refreshSystemPresentationSettings()
        enableEdgeToEdge()
        setContent {
            NightingaleFoundationScreen(
                privacyCovered = privacyCovered,
                presentationPreferences = presentationPreferences,
                systemReduceMotion = systemReduceMotion,
                highContrast = systemHighContrast,
            )
        }
    }

    override fun onPause() {
        volatileInputState.clear(NightingaleVolatileInputClearReason.APPLICATION_INACTIVE)
        privacyCovered = true
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        refreshSystemPresentationSettings()
        privacyCovered = false
    }

    private fun refreshSystemPresentationSettings() {
        systemReduceMotion = runCatching {
            Settings.Global.getFloat(
                contentResolver,
                Settings.Global.ANIMATOR_DURATION_SCALE,
                1f,
            ) == 0f
        }.getOrDefault(false)
        systemHighContrast =
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE &&
            (getSystemService(UI_MODE_SERVICE) as? UiModeManager)?.contrast?.let {
                it > 0f
            } == true
    }
}

/** The compile-time guard for the safe, pre-pilot Nightingale foundation. */
object NightingaleProductBoundary {
    const val productName = "Nightingale"
    const val livePatientAccessEnabled = false
    const val staffEndpointsPermitted = false
}
