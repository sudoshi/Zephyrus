package net.acumenus.hummingbird

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.fragment.app.FragmentActivity
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.LockReset
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AppLock
import net.acumenus.hummingbird.data.AuthPhase
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.ui.HummingbirdLaunchConfig
import net.acumenus.hummingbird.ui.LockScreen
import net.acumenus.hummingbird.ui.LoginScreen
import net.acumenus.hummingbird.ui.MainScreen
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import net.acumenus.hummingbird.ui.theme.Z

class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        AppLock.init(this)
        // Test/demo affordance, mirrors iOS: `adb am start ... -e HB_AUTOLOGIN 1` lands on Home
        // for headless screenshots / UI tests. No-op for normal launches.
        val autologin = intent.getStringExtra("HB_AUTOLOGIN") == "1"
        val user = intent.getStringExtra("HB_USER") ?: "demo"
        val pass = intent.getStringExtra("HB_PASS") ?: "Password123!"
        val launchConfig = HummingbirdLaunchConfig(
            roleId = intent.getStringExtra("HB_ROLE"),
            tab = intent.getStringExtra("HB_TAB"),
            openUnitId = intent.getStringExtra("HB_OPEN_UNIT")?.toIntOrNull(),
            openTarget = intent.getStringExtra("HB_OPEN_TARGET"),
            forceError = intent.getStringExtra("HB_FORCE_ERROR") == "1",
            debugExplorer = intent.getStringExtra("HB_DEBUG_EXPLORER") == "1",
        )

        setContent {
            val auth: AuthViewModel = viewModel()
            var triedAuto by remember { mutableStateOf(false) }

            LaunchedEffect(Unit) { auth.bootstrap() }
            LaunchedEffect(auth.phase) {
                if (autologin && !triedAuto && auth.phase == AuthPhase.LOGGED_OUT) {
                    triedAuto = true
                    auth.login(user, pass)
                }
            }

            HummingbirdTheme {
                Surface(color = Z.bg, modifier = Modifier.fillMaxSize()) {
                    when (auth.phase) {
                        AuthPhase.LOADING -> Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Z.primary)
                        }
                        AuthPhase.LOGGED_OUT -> LoginScreen(auth)
                        AuthPhase.NEEDS_PASSWORD_CHANGE -> PasswordChangeNotice(auth)
                        AuthPhase.LOGGED_IN -> Box(modifier = Modifier.fillMaxSize()) {
                            MainScreen(auth, launchConfig)
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

@Composable
private fun PasswordChangeNotice(auth: AuthViewModel) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(Icons.Filled.LockReset, contentDescription = null, tint = Z.gold, modifier = Modifier.size(40.dp))
        Spacer(Modifier.height(16.dp))
        Text("Password change required", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(8.dp))
        Text(
            "Your account must set a new password before using Hummingbird. Finish this on the web app, then sign in again.",
            color = Z.inkMuted, fontSize = 14.sp, textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(16.dp))
        Button(onClick = { auth.logout() }, colors = ButtonDefaults.buttonColors(containerColor = Z.primary, contentColor = Color.White)) {
            Text("Back to sign in")
        }
    }
}
