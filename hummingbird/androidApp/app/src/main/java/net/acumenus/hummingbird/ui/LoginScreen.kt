package net.acumenus.hummingbird.ui

import android.provider.Settings
import androidx.compose.animation.Crossfade
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import net.acumenus.hummingbird.BuildConfig
import net.acumenus.hummingbird.R
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.ui.theme.Z

/**
 * Sign-in over the rotating hummingbird photography (parity with the iOS auth
 * atmosphere): full-bleed artwork, dark scrims for legibility, and a translucent
 * form card. The slideshow pauses when the system animator scale is 0 (reduce motion).
 */
@Composable
fun LoginScreen(auth: AuthViewModel) {
    var username by remember { mutableStateOf("demo") }
    var password by remember { mutableStateOf("Password123!") }
    var slide by remember { mutableIntStateOf(0) }

    val slides = remember {
        listOf(
            R.drawable.auth_hummingbird_04,
            R.drawable.auth_hummingbird_07,
            R.drawable.auth_hummingbird_12,
            R.drawable.auth_hummingbird_06,
        )
    }
    val context = LocalContext.current
    val reduceMotion = remember {
        Settings.Global.getFloat(context.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f
    }

    LaunchedEffect(reduceMotion) {
        if (!reduceMotion) {
            while (true) {
                delay(9_500)
                slide = (slide + 1) % slides.size
            }
        }
    }

    Box(modifier = Modifier.fillMaxSize().background(Z.bg)) {
        Crossfade(targetState = slide, animationSpec = tween(1600), label = "authSlides") { i ->
            Image(
                painter = painterResource(slides[i]),
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
        }
        // Scrims keep ink AA-legible over any slide (values mirror the iOS backdrop).
        Box(
            modifier = Modifier.fillMaxSize().background(
                Brush.verticalGradient(
                    listOf(Color.Black.copy(alpha = 0.36f), Color(0xFF05070C).copy(alpha = 0.74f)),
                ),
            ),
        )
        Box(
            modifier = Modifier.fillMaxSize().background(
                Brush.horizontalGradient(
                    listOf(
                        Color(0xFF051210).copy(alpha = 0.56f),
                        Color.Black.copy(alpha = 0.24f),
                        Color(0xFF0A0F21).copy(alpha = 0.56f),
                    ),
                ),
            ),
        )

        Column(
            modifier = Modifier
                .fillMaxSize()
                .safeDrawingPadding()
                .verticalScroll(rememberScrollState())
                .padding(20.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Image(
                painter = painterResource(R.mipmap.ic_launcher_foreground),
                contentDescription = "Hummingbird",
                modifier = Modifier.size(96.dp),
            )
            Text("Hummingbird", color = Z.ink, fontSize = 28.sp, fontWeight = FontWeight.SemiBold)
            Text("Zephyrus operations, in your pocket", color = Z.inkMuted, fontSize = 14.sp)
            Spacer(Modifier.height(20.dp))

            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(28.dp))
                    .background(Z.surface.copy(alpha = 0.92f))
                    .border(1.dp, Z.border.copy(alpha = 0.6f), RoundedCornerShape(28.dp))
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                field("USERNAME OR EMAIL", username, { username = it }, secure = false)
                field("PASSWORD", password, { password = it }, secure = true)

                auth.error?.let { msg ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Filled.Warning, contentDescription = null, tint = Z.statusCritical, modifier = Modifier.size(16.dp))
                        Text(msg, color = Z.statusCritical, fontSize = 13.sp)
                    }
                }

                Button(
                    onClick = { auth.login(username, password) },
                    enabled = !auth.busy && username.isNotEmpty() && password.isNotEmpty(),
                    colors = ButtonDefaults.buttonColors(containerColor = Z.primary, contentColor = Color.White),
                    modifier = Modifier.fillMaxWidth().height(48.dp),
                ) {
                    if (auth.busy) {
                        CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                    }
                    Text(if (auth.busy) "Signing in…" else "Sign in", fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                }
            }

            if (BuildConfig.DEBUG) {
                Spacer(Modifier.height(16.dp))
                Text("Connected to ${ApiClient.BASE_URL}", color = Z.inkMuted, fontSize = 11.sp)
            }
        }
    }
}

@Composable
private fun field(label: String, value: String, onChange: (String) -> Unit, secure: Boolean) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 0.5.sp)
        OutlinedTextField(
            value = value,
            onValueChange = onChange,
            singleLine = true,
            visualTransformation = if (secure) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
            keyboardOptions = KeyboardOptions(keyboardType = if (secure) KeyboardType.Password else KeyboardType.Email),
            modifier = Modifier.fillMaxWidth(),
            colors = OutlinedTextFieldDefaults.colors(
                focusedContainerColor = Z.bg,
                unfocusedContainerColor = Z.bg,
                focusedBorderColor = Z.primary,
                unfocusedBorderColor = Z.border,
                focusedTextColor = Z.ink,
                unfocusedTextColor = Z.ink,
                cursorColor = Z.primary,
            ),
        )
    }
}
