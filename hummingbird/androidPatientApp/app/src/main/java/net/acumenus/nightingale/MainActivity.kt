package net.acumenus.nightingale

import android.os.Bundle
import android.os.Build
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.getValue
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import net.acumenus.nightingale.ui.NightingaleTheme
import net.acumenus.nightingale.ui.PatientApp
import net.acumenus.nightingale.ui.PatientPresentationAccessibilityProvider
import net.acumenus.nightingale.data.EncryptedPatientCredentialStore
import net.acumenus.nightingale.data.PatientApiClient
import net.acumenus.nightingale.data.PatientApiConfiguration
import net.acumenus.nightingale.data.PatientDeviceDescriptor
import net.acumenus.nightingale.data.PatientSessionCoordinator

class MainActivity : ComponentActivity() {
    private var privacyCovered by mutableStateOf(false)

    /** Lifecycle-backed state exposed to same-module instrumentation only. */
    internal val isPrivacyCoverActive: Boolean
        get() = privacyCovered

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        PatientPrivacyPolicy.protect(window)
        enableEdgeToEdge()

        val launchState = PatientLaunchHooks.from(intent)
        setContent {
            val configuration = remember { PatientApiConfiguration.fromBuild() }
            val coordinator = remember(configuration) {
                if (!configuration.enabled) {
                    null
                } else {
                    runCatching {
                        val store = EncryptedPatientCredentialStore(applicationContext)
                        PatientSessionCoordinator(
                            api = PatientApiClient(configuration),
                            credentials = store,
                            device = PatientDeviceDescriptor(
                                uuid = store.getOrCreateDeviceUuid(),
                                name = Build.MODEL,
                                appVersion = BuildConfig.VERSION_NAME,
                                osVersion = Build.VERSION.RELEASE,
                            ),
                        )
                    }.getOrNull()
                }
            }
            val viewModel = remember(launchState, coordinator) {
                PatientAppViewModel(launchState, coordinator)
            }
            LaunchedEffect(viewModel) {
                viewModel.restoreSession()
            }
            DisposableEffect(viewModel) {
                onDispose(viewModel::close)
            }
            val presentationPreferences = (viewModel.state.session as? PatientSessionState.Ready)
                ?.snapshot
                ?.preferences
            NightingaleTheme(
                highContrast = presentationPreferences?.highContrast == true,
            ) {
                PatientPresentationAccessibilityProvider(preferences = presentationPreferences) {
                    PatientApp(
                        viewModel = viewModel,
                        privacyCovered = privacyCovered,
                    )
                }
            }
        }
    }

    override fun onPause() {
        privacyCovered = true
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        privacyCovered = false
    }
}
