package net.acumenus.hummingbird.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

/** Centered empty/error/loading state, matching the iOS RetryableMessage tone. */
@Composable
fun RetryableMessage(
    title: String,
    message: String,
    tone: CapacityStatus = CapacityStatus.INFO,
    loading: Boolean = false,
    retryLabel: String? = null,
    onRetry: (() -> Unit)? = null,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = Z.s4)
            .padding(top = Z.s6),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(Z.s3),
    ) {
        if (loading) {
            CircularProgressIndicator(color = Z.primary, modifier = Modifier.size(40.dp))
        } else {
            Icon(tone.icon, contentDescription = null, tint = tone.color, modifier = Modifier.size(40.dp))
        }
        Text(title, color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
        Text(message, color = Z.inkMuted, fontSize = 13.sp, textAlign = TextAlign.Center)
        if (onRetry != null) {
            OutlinedButton(
                onClick = onRetry,
                modifier = Modifier.heightIn(min = 48.dp),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(Z.s2), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.Refresh, contentDescription = null, modifier = Modifier.size(18.dp))
                    Text(retryLabel ?: "Try again", fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}
