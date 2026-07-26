package net.acumenus.nightingale

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

class MainActivity : ComponentActivity() {
    private var privacyCovered by mutableStateOf(false)
    internal val volatileInputState = NightingaleVolatileInputState()

    internal val isPrivacyCoverActive: Boolean
        get() = privacyCovered

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        NightingalePrivacyPolicy.protect(window)
        enableEdgeToEdge()
        setContent {
            NightingaleFoundationScreen(privacyCovered = privacyCovered)
        }
    }

    override fun onPause() {
        volatileInputState.clear(NightingaleVolatileInputClearReason.APPLICATION_INACTIVE)
        privacyCovered = true
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        privacyCovered = false
    }
}

/** The compile-time guard for the safe, pre-pilot Nightingale foundation. */
object NightingaleProductBoundary {
    const val productName = "Nightingale"
    const val livePatientAccessEnabled = false
    const val staffEndpointsPermitted = false
}
