package net.acumenus.hummingbird

import android.os.Bundle
import android.view.WindowManager
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.fragment.app.FragmentActivity
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Surface
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AppLock
import net.acumenus.hummingbird.data.AuthPhase
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.notifications.UrgencyChannels
import net.acumenus.hummingbird.ui.LockScreen
import net.acumenus.hummingbird.ui.LoginScreen
import net.acumenus.hummingbird.ui.MainScreen
import net.acumenus.hummingbird.ui.PasswordChangeScreen
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import net.acumenus.hummingbird.ui.theme.Z

class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        // Staff surfaces can contain restricted patient context and messages.
        // Prevent screenshots, screen recording, and recent-app thumbnails.
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        enableEdgeToEdge()
        AppLock.init(this)
        // Register the T1–T4 urgency notification channels at app start (FCM send still
        // blocked on server credentials — registration only).
        UrgencyChannels.register(this)
        // Build-variant implementations are deliberate: debug parses emulator/UI-test
        // extras, while release ignores the Intent entirely and contains no hook keys.
        val launchState = HummingbirdLaunchHooks.from(intent)

        setContent {
            val auth: AuthViewModel = viewModel()
            var triedAuto by remember { mutableStateOf(false) }

            LaunchedEffect(Unit) { auth.bootstrap() }
            LaunchedEffect(auth.phase) {
                val credentials = launchState.autologin
                if (credentials != null && !triedAuto && auth.phase == AuthPhase.LOGGED_OUT) {
                    triedAuto = true
                    auth.login(credentials.username, credentials.password)
                }
            }

            HummingbirdTheme {
                Surface(color = Z.bg, modifier = Modifier.fillMaxSize()) {
                    when (auth.phase) {
                        AuthPhase.LOADING -> Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Z.primary)
                        }
                        AuthPhase.LOGGED_OUT -> LoginScreen(auth)
                        AuthPhase.NEEDS_PASSWORD_CHANGE -> PasswordChangeScreen(auth)
                        AuthPhase.LOGGED_IN -> Box(modifier = Modifier.fillMaxSize()) {
                            MainScreen(auth, launchState.config)
                            if (AppLock.locked) {
                                LockScreen(
                                    onUnlockRequest = { AppLock.prompt(this@MainActivity) { } },
                                    onSignOut = {
                                        AppLock.unlock()
                                        auth.logout()
                                    },
                                )
                            }
                        }
                    }
                }
            }
        }
    }

    override fun onStop() {
        super.onStop()
        // Leaving the foreground engages the opt-in app lock (parity with iOS scenePhase).
        AppLock.engage()
    }
}
