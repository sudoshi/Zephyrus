package net.acumenus.hummingbird.ui.or

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.ORCaseInfo
import net.acumenus.hummingbird.data.ORMetrics
import net.acumenus.hummingbird.data.ORNextInfo
import net.acumenus.hummingbird.data.ORRoom
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ORBoardScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenRoom: (ORRoom, String?) -> Unit,
) {
    val vm: ORViewModel = viewModel()
    val bearer = auth.accessToken ?: ""

    LaunchedEffect(bearer, forceError) {
        if (!forceError) {
            while (true) {
                vm.load(bearer)
                kotlinx.coroutines.delay(20000)
            }
        }
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("OR Board", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
                actions = {
                    IconButton(onClick = { vm.load(bearer) }) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = onOpenProfile) {
                        Icon(Icons.Filled.Person, contentDescription = "Profile")
                    }
                },
            )
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            if (forceError) {
                item {
                    ORError("Can't reach the server. Check your connection and try again.") {
                        vm.load(bearer)
                    }
                }
            } else {
                vm.error?.let { item { ORError(it) { vm.load(bearer) } } }
            }

            val board = vm.board
            if (forceError) {
                // Test affordance state; keep the board quiet.
            } else if (board == null && vm.loading) {
                item { ORLoading() }
            } else if (board != null) {
                item { ORMetricsRow(board.metrics) }
                if (board.rooms.isEmpty()) {
                    item { OREmpty() }
                } else {
                    item { ORSectionLabel("Rooms (${board.rooms.size})") }
                    items(board.rooms, key = { it.id }) { room ->
                        ORRoomRow(room) { onOpenRoom(room, board.webLink) }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CaseDetailScreen(
    room: ORRoom,
    webLink: String?,
    onBack: () -> Unit,
) {
    val uriHandler = LocalUriHandler.current

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text(room.name, fontWeight = FontWeight.SemiBold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    navigationIconContentColor = Z.ink,
                ),
            )
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { ORRoomHero(room) }
            room.current?.let { item { CurrentCaseCard(it, room) } }
            room.next?.let { item { NextCaseCard(it) } }
            if (room.current == null && room.next == null) {
                item { OREmptyCase() }
            }
            item { ORReadOnlyActions(room) }
            webLink?.let { link ->
                item {
                    OutlinedButton(onClick = { uriHandler.openUri(link) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text("Open in Zephyrus")
                    }
                }
            }
        }
    }
}

@Composable
private fun ORMetricsRow(metrics: ORMetrics) {
    Row(Modifier.fillMaxWidth().panel().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        ORMetricCell(metrics.running.toString(), "Running", CapacityStatus.INFO)
        ORMetricDivider()
        ORMetricCell(metrics.turnover.toString(), "Turnover", if (metrics.turnover > 0) CapacityStatus.WARNING else null)
        ORMetricDivider()
        ORMetricCell(metrics.available.toString(), "Open", null)
        ORMetricDivider()
        ORMetricCell("${metrics.avgTurnoverMin}m", "Turnover avg", null)
    }
}

@Composable
private fun RowScope.ORMetricCell(value: String, label: String, tone: CapacityStatus?) {
    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(value, color = tone?.color ?: Z.ink, fontSize = 24.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
        Text(label, color = Z.inkMuted, fontSize = 11.sp)
    }
}

@Composable
private fun ORMetricDivider() {
    Box(Modifier.width(1.dp).height(26.dp).background(Z.border))
}

@Composable
private fun ORRoomRow(room: ORRoom, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .clickable { onClick() },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(room.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(room.name, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                ORStatusChip(room)
            }
            room.current?.let { current ->
                Text(current.procedure, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text("${current.surgeon} / ${current.elapsed}m elapsed of ${current.expectedDuration}m", color = Z.inkMuted, fontSize = 12.sp)
            } ?: room.next?.let { next ->
                Text("Next: ${next.procedure}", color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                next.startTime?.let { Text("scheduled $it", color = Z.inkMuted, fontSize = 12.sp) }
            } ?: Text("No further cases scheduled", color = Z.inkMuted, fontSize = 12.sp)
        }
        ORRoomTiming(room)
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
    }
}

@Composable
private fun ORRoomTiming(room: ORRoom) {
    val text = when {
        room.current != null && room.timeRemaining != null -> "~${room.timeRemaining}m left"
        room.status == "turnover" && room.turnoverMin != null -> "ready ~${room.turnoverMin}m"
        else -> null
    }
    text?.let {
        Text(it, color = if (room.status == "turnover") CapacityStatus.WARNING.color else Z.inkMuted, fontSize = 12.sp, fontFamily = FontFamily.Monospace, modifier = Modifier.padding(end = 8.dp))
    }
}

@Composable
private fun ORRoomHero(room: ORRoom) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(room.name, color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                Text(roomStatusLabel(room.status), color = Z.inkMuted, fontSize = 13.sp)
            }
            ORStatusChip(room)
        }
        ORRoomTiming(room)
    }
}

@Composable
private fun CurrentCaseCard(current: ORCaseInfo, room: ORRoom) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Current case", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        Text(current.procedure, color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
        Text("${current.surgeon} / ${current.elapsed}m elapsed of ${current.expectedDuration}m", color = Z.inkMuted, fontSize = 13.sp)
        LinearProgressIndicator(progress = currentCaseProgress(current), modifier = Modifier.fillMaxWidth(), color = room.capacity.color, trackColor = Z.border)
        current.expectedEnd?.let { Text("Expected end $it", color = Z.inkMuted, fontSize = 12.sp) }
    }
}

@Composable
private fun NextCaseCard(next: ORNextInfo) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Next case", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        Text(next.procedure, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
        next.startTime?.let { Text("Scheduled $it", color = Z.inkMuted, fontSize = 12.sp) }
    }
}

@Composable
private fun ORReadOnlyActions(room: ORRoom) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Room actions", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        Button(
            onClick = {},
            enabled = false,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            colors = ButtonDefaults.buttonColors(disabledContainerColor = Z.surface, disabledContentColor = Z.inkMuted),
        ) {
            Icon(Icons.Filled.Edit, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.size(8.dp))
            Text("Safety note acknowledgement not available on mobile yet")
        }
        OutlinedButton(
            onClick = {},
            enabled = false,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
        ) {
            Icon(if (room.status == "delayed") Icons.Filled.Warning else Icons.Filled.CheckCircle, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.size(8.dp))
            Text("Room and delay status changes are read-only")
        }
    }
}

@Composable
private fun ORLoading() {
    RetryableMessage(
        title = "Loading the OR board",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun OREmpty() {
    RetryableMessage(
        title = "No rooms returned",
        message = "The OR board did not return active rooms.",
        tone = CapacityStatus.INFO,
    )
}

@Composable
private fun OREmptyCase() {
    RetryableMessage(
        title = "No case scheduled",
        message = "This room has no current or next case in the mobile board.",
        tone = CapacityStatus.SUCCESS,
    )
}

@Composable
private fun ORError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load the OR board",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun ORSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}

private fun roomStatusLabel(status: String): String = when (status) {
    "in_progress" -> "In Progress"
    "turnover" -> "Turnover"
    "delayed" -> "Delayed"
    else -> "Available"
}

@Composable
private fun ORStatusChip(room: ORRoom) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(room.capacity.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(room.capacity.icon, contentDescription = null, tint = room.capacity.color, modifier = Modifier.size(13.dp))
        Text(roomStatusLabel(room.status).uppercase(), color = room.capacity.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

private fun currentCaseProgress(current: ORCaseInfo): Float =
    current.elapsed.toFloat().coerceAtMost(current.expectedDuration.toFloat()) / current.expectedDuration.coerceAtLeast(1).toFloat()
