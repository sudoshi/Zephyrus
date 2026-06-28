package net.acumenus.hummingbird.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Apartment
import androidx.compose.material.icons.filled.Block
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Hotel
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.ForYouItem
import net.acumenus.hummingbird.data.ForYouViewModel
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.Z
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ForYouScreen(auth: AuthViewModel) {
    val vm: ForYouViewModel = viewModel()
    val bearer = auth.accessToken ?: ""

    LaunchedEffect(Unit) {
        while (true) {
            vm.load(bearer)
            kotlinx.coroutines.delay(15000)
        }
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("For You", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Z.bg, titleContentColor = Z.ink),
            )
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                Column {
                    Text("Needs you now", color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                    Text(
                        "${vm.items.size} item${if (vm.items.size == 1) "" else "s"} to action",
                        color = Z.inkMuted, fontSize = 13.sp,
                    )
                }
            }
            if (vm.items.isEmpty() && !vm.loading) {
                item {
                    Column(
                        Modifier.fillMaxWidth().padding(top = 32.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Filled.CheckCircle, null, tint = Z.statusSuccess, modifier = Modifier.size(40.dp))
                        Text("All clear", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                        Text("Nothing needs your action right now.", color = Z.inkMuted, fontSize = 13.sp)
                    }
                }
            }
            items(vm.items, key = { it.id }) { ForYouRow(it) }
        }
    }
}

@Composable
private fun ForYouRow(item: ForYouItem) {
    val status = item.capacity
    Row(
        modifier = Modifier.fillMaxWidth().height(androidx.compose.foundation.layout.IntrinsicSize.Min).panel(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(status.color))
        Icon(iconFor(item.type), null, tint = status.color, modifier = Modifier.padding(start = 12.dp).size(22.dp))
        Column(Modifier.weight(1f).padding(12.dp)) {
            Text(item.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            Text(item.subtitle, color = Z.inkMuted, fontSize = 13.sp)
            metaLine(item)?.let { Text(it, color = Z.inkMuted, fontSize = 11.sp) }
        }
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
    }
}

private fun iconFor(type: String): ImageVector = when (type) {
    "bed_request" -> Icons.Filled.Hotel
    "barrier" -> Icons.Filled.Block
    "capacity" -> Icons.Filled.Apartment
    else -> Icons.Filled.Notifications
}

private fun metaLine(item: ForYouItem): String? {
    val parts = listOfNotNull(item.unit, relTime(item.at))
    return if (parts.isEmpty()) null else parts.joinToString(" · ")
}

private fun relTime(at: String?): String? {
    if (at == null) return null
    val inst = runCatching { OffsetDateTime.parse(at).toInstant() }.getOrNull() ?: return null
    val mins = Duration.between(inst, Instant.now()).toMinutes()
    return when {
        mins < 1 -> "just now"
        mins < 60 -> "${mins}m ago"
        mins < 1440 -> "${mins / 60}h ago"
        else -> "${mins / 1440}d ago"
    }
}
