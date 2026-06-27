package net.acumenus.hummingbird.ui

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Logout
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.HomeViewModel
import net.acumenus.hummingbird.ui.components.KpiTile
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(auth: AuthViewModel) {
    val home: HomeViewModel = viewModel()
    val bearer = auth.accessToken ?: ""

    // Live foreground refresh loop; auto-cancels when the composable leaves.
    LaunchedEffect(Unit) {
        while (true) {
            home.load(bearer)
            kotlinx.coroutines.delay(8000)
        }
    }
    LaunchedEffect(home.needsReauth) { if (home.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("House Status", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg, titleContentColor = Z.ink, actionIconContentColor = Z.ink,
                ),
                actions = {
                    IconButton(onClick = { home.load(bearer) }) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = { auth.logout() }) {
                        Icon(Icons.Filled.Logout, contentDescription = "Sign out")
                    }
                },
            )
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { greeting(auth) }
            item { houseRollup(home) }
            item { censusHeader(home) }
            if (home.units.isEmpty() && home.loading) {
                item {
                    Row(Modifier.fillMaxWidth().padding(top = 24.dp), horizontalArrangement = Arrangement.Center) {
                        CircularProgressIndicator(color = Z.primary)
                    }
                }
            }
            items(home.units, key = { it.unitId }) { unit -> KpiTile(unit) }
        }
    }
}

@Composable
private fun greeting(auth: AuthViewModel) {
    val name = auth.me?.name?.substringBefore(' ') ?: "there"
    Column {
        Text("Good shift, $name", color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
        auth.me?.workflowPreference?.let {
            Text("${it.replaceFirstChar { c -> c.uppercase() }} workflow", color = Z.inkMuted, fontSize = 13.sp)
        }
    }
}

@Composable
private fun houseRollup(home: HomeViewModel) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("HOUSE CAPACITY", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 0.5.sp)
            Spacer(Modifier.weight(1f))
            StatusChip(home.worstStatus)
        }
        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("${home.totalOccupied}", color = Z.ink, fontSize = 40.sp, fontWeight = FontWeight.SemiBold)
            Text("/ ${home.totalSafe} safe beds", color = Z.inkMuted, fontSize = 15.sp, modifier = Modifier.padding(bottom = 6.dp))
            Spacer(Modifier.weight(1f))
            Text("${home.occupancyPercent}%", color = home.worstStatus.color, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 4.dp))
        }
        HorizontalDivider(color = Z.border)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            val ok = home.pressuredCount == 0
            Icon(
                if (ok) Icons.Filled.CheckCircle else Icons.Filled.Warning,
                contentDescription = null,
                tint = if (ok) Z.statusSuccess else Z.statusWarning,
                modifier = Modifier.size(18.dp),
            )
            Text(
                if (ok) "All units within safe capacity"
                else "${home.pressuredCount} of ${home.units.size} units near or at capacity",
                color = Z.ink, fontSize = 13.sp,
            )
        }
    }
}

@Composable
private fun censusHeader(home: HomeViewModel) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Unit census", color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
        if (home.stale) {
            Icon(Icons.Filled.WifiOff, contentDescription = null, tint = Z.statusWarning, modifier = Modifier.size(14.dp))
        } else {
            val infinite = rememberInfiniteTransition(label = "live")
            val a by infinite.animateFloat(
                initialValue = 1f, targetValue = 0.25f,
                animationSpec = infiniteRepeatable(tween(1000), RepeatMode.Reverse), label = "liveAlpha",
            )
            Box(Modifier.size(7.dp).clip(CircleShape).background(Z.statusSuccess.copy(alpha = a)))
            Text("LIVE", color = Z.statusSuccess, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 0.5.sp)
        }
        Spacer(Modifier.weight(1f))
        Text("as of ${home.asOfDisplay}", color = Z.inkMuted, fontSize = 11.sp)
    }
}
