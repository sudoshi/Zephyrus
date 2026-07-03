package net.acumenus.hummingbird.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.ui.theme.Z

/**
 * Full-screen overlay while the biometric app lock is engaged. Auto-prompts once on
 * appearance; keeps a manual retry and the sign-out escape hatch (parity with iOS LockView).
 */
@Composable
fun LockScreen(onUnlockRequest: () -> Unit, onSignOut: () -> Unit) {
    LaunchedEffect(Unit) { onUnlockRequest() }

    Column(
        modifier = Modifier.fillMaxSize().background(Z.bg).padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(Icons.Filled.Lock, contentDescription = null, tint = Z.inkMuted, modifier = Modifier.size(40.dp))
        Spacer(Modifier.height(16.dp))
        Text("Hummingbird is locked", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(8.dp))
        Text(
            "Unlock with your fingerprint, face, or device credential.",
            color = Z.inkMuted,
            fontSize = 14.sp,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(24.dp))
        Button(
            onClick = onUnlockRequest,
            colors = ButtonDefaults.buttonColors(containerColor = Z.primary, contentColor = Color.White),
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
        ) {
            Text("Unlock", fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
        }
        Spacer(Modifier.height(8.dp))
        TextButton(onClick = onSignOut) {
            Text("Sign out", color = Z.inkMuted, fontSize = 14.sp)
        }
    }
}
