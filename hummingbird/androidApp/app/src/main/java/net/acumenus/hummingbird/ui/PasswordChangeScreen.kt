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
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.LockReset
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.ui.theme.Z

internal data class PasswordChangeValidation(
    val hasCurrentPassword: Boolean,
    val hasMinimumLength: Boolean,
    val differsFromCurrent: Boolean,
    val confirmationMatches: Boolean,
) {
    val isReady: Boolean
        get() = hasCurrentPassword && hasMinimumLength && differsFromCurrent && confirmationMatches
}

internal object PasswordChangePolicy {
    fun evaluate(currentPassword: String, newPassword: String, confirmation: String) =
        PasswordChangeValidation(
            hasCurrentPassword = currentPassword.isNotEmpty(),
            hasMinimumLength = newPassword.length >= 8,
            differsFromCurrent = newPassword.isNotEmpty() && newPassword != currentPassword,
            confirmationMatches = confirmation.isNotEmpty() && confirmation == newPassword,
        )
}

/**
 * Native forced-password completion. The narrowly scoped change token remains in the
 * view model only; this screen never receives, persists, logs, or displays it.
 */
@Composable
fun PasswordChangeScreen(auth: AuthViewModel) {
    var currentPassword by remember { mutableStateOf("") }
    var newPassword by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    val validation = PasswordChangePolicy.evaluate(currentPassword, newPassword, confirmation)

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(Z.bg)
            .safeDrawingPadding()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 24.dp, vertical = 32.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(
            Icons.Filled.LockReset,
            contentDescription = null,
            tint = Z.gold,
            modifier = Modifier.size(44.dp),
        )
        Spacer(Modifier.height(16.dp))
        Text("Set a new password", color = Z.ink, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(8.dp))
        Text(
            "Your account uses a temporary password. Choose a new one here to finish signing in.",
            color = Z.inkMuted,
        )
        Spacer(Modifier.height(24.dp))

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .background(Z.surface, RoundedCornerShape(24.dp))
                .padding(18.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            passwordField(
                label = "Current temporary password",
                value = currentPassword,
                onValueChange = { currentPassword = it },
                imeAction = ImeAction.Next,
            )
            passwordField(
                label = "New password",
                value = newPassword,
                onValueChange = { newPassword = it },
                imeAction = ImeAction.Next,
            )
            requirement("At least 8 characters", validation.hasMinimumLength)
            requirement("Different from the temporary password", validation.differsFromCurrent)
            passwordField(
                label = "Confirm new password",
                value = confirmation,
                onValueChange = { confirmation = it },
                imeAction = ImeAction.Done,
            )
            requirement("Passwords match", validation.confirmationMatches)

            auth.error?.let { message ->
                Row(
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    verticalAlignment = Alignment.Top,
                ) {
                    Icon(Icons.Filled.Info, contentDescription = null, tint = Z.statusCritical)
                    Text(message, color = Z.statusCritical)
                }
            }

            Button(
                onClick = { auth.changePassword(currentPassword, newPassword) },
                enabled = validation.isReady && !auth.busy,
                colors = ButtonDefaults.buttonColors(
                    containerColor = Z.primary,
                    contentColor = Color.White,
                ),
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) {
                if (auth.busy) {
                    CircularProgressIndicator(
                        color = Color.White,
                        strokeWidth = 2.dp,
                        modifier = Modifier.size(18.dp),
                    )
                    Spacer(Modifier.padding(horizontal = 4.dp))
                }
                Text(if (auth.busy) "Updating…" else "Update password")
            }
        }

        Spacer(Modifier.height(16.dp))
        TextButton(onClick = auth::cancelPasswordChange, enabled = !auth.busy) {
            Text("Back to sign in", color = Z.inkMuted)
        }
    }
}

@Composable
private fun passwordField(
    label: String,
    value: String,
    onValueChange: (String) -> Unit,
    imeAction: ImeAction,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        singleLine = true,
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Password,
            imeAction = imeAction,
        ),
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun requirement(label: String, satisfied: Boolean) {
    Row(
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            if (satisfied) Icons.Filled.CheckCircle else Icons.Filled.Info,
            contentDescription = null,
            tint = if (satisfied) Z.statusSuccess else Z.inkMuted,
        )
        Text(label, color = if (satisfied) Z.ink else Z.inkMuted)
    }
}
