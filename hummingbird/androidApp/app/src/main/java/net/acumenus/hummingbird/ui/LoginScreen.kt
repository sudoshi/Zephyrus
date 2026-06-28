package net.acumenus.hummingbird.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MonitorHeart
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.Z

@Composable
fun LoginScreen(auth: AuthViewModel) {
    var username by remember { mutableStateOf("demo") }
    var password by remember { mutableStateOf("Password123!") }

    Column(
        modifier = Modifier.fillMaxSize().background(Z.bg).padding(20.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(Icons.Filled.MonitorHeart, contentDescription = null, tint = Z.primary, modifier = Modifier.size(48.dp))
        Spacer(Modifier.height(8.dp))
        Text("Hummingbird", color = Z.ink, fontSize = 28.sp, fontWeight = FontWeight.SemiBold)
        Text("Zephyrus operations, in your pocket", color = Z.inkMuted, fontSize = 14.sp)
        Spacer(Modifier.height(20.dp))

        Column(
            modifier = Modifier.fillMaxWidth().panel().padding(16.dp),
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
                colors = ButtonDefaults.buttonColors(containerColor = Z.primary, contentColor = androidx.compose.ui.graphics.Color.White),
                modifier = Modifier.fillMaxWidth().height(48.dp),
            ) {
                if (auth.busy) {
                    CircularProgressIndicator(color = androidx.compose.ui.graphics.Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                }
                Text(if (auth.busy) "Signing in…" else "Sign in", fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
            }
        }

        Spacer(Modifier.height(16.dp))
        Text("Connected to ${ApiClient.BASE_URL}", color = Z.inkMuted, fontSize = 11.sp)
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
