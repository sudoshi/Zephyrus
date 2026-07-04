package net.acumenus.hummingbird.ui.evs

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
import androidx.compose.foundation.layout.fillMaxSize
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
import androidx.compose.material.icons.filled.HealthAndSafety
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.EvsMetrics
import net.acumenus.hummingbird.data.EvsTurn
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.hbConfirmHaptic
import net.acumenus.hummingbird.ui.components.hbRejectHaptic
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.flow.FlowBoardMode
import net.acumenus.hummingbird.ui.flow.FlowMapScreen
import net.acumenus.hummingbird.ui.flow.ListMapSegment
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BedTurnsScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenTurn: (EvsTurn, String?) -> Unit,
) {
    val vm: EvsViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    var boardMode by remember { mutableStateOf(FlowBoardMode.List) }

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
                title = { Text("Bed Turns", fontWeight = FontWeight.SemiBold) },
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
        Column(Modifier.padding(inner).fillMaxSize()) {
        ListMapSegment(
            mode = boardMode,
            onSelect = { boardMode = it },
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp),
        )
        if (boardMode == FlowBoardMode.Map) {
            FlowMapScreen(
                auth = auth,
                persona = "evs",
                modifier = Modifier.weight(1f).fillMaxWidth(),
            )
        } else {
        HbRefreshable(
            refreshing = vm.loading,
            onRefresh = { vm.load(bearer) },
            modifier = Modifier.weight(1f),
        ) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            if (forceError) {
                item {
                    EvsError("Can't reach the server. Check your connection and try again.") {
                        vm.load(bearer)
                    }
                }
            } else {
                vm.error?.let { item { EvsError(it) { vm.load(bearer) } } }
            }

            val queue = vm.queue
            if (forceError) {
                // Test affordance state; keep the turn queue quiet.
            } else if (queue == null && vm.loading) {
                item { EvsLoading() }
            } else if (queue != null) {
                item { EvsMetricsRow(queue.metrics) }
                if (queue.metrics.overdue > 0) {
                    item { EvsOverdueBanner(queue.metrics.overdue) }
                }
                if (queue.turns.isEmpty()) {
                    item { EvsEmpty() }
                } else {
                    item { EvsSectionLabel("Turn queue (${queue.turns.size}) / next dirty bed first") }
                    items(queue.turns, key = { it.id }) { turn ->
                        EvsTurnRow(turn) { onOpenTurn(turn, queue.webLink) }
                    }
                }
            }
        }
        }
        }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TurnDetailScreen(
    auth: AuthViewModel,
    turn: EvsTurn,
    webLink: String?,
    onBack: () -> Unit,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    val vm: EvsViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val uriHandler = LocalUriHandler.current
    var status by remember(turn.id) { mutableStateOf(turn.status) }
    val working = vm.workingTurnId == turn.id

    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Bed Turn", fontWeight = FontWeight.SemiBold) },
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
        bottomBar = {
            EvsPrimaryActionBar(
                turn = turn,
                status = status,
                working = working,
                onAdvance = { next ->
                    vm.advance(bearer, turn.id, next)
                    status = next
                    if (next == "completed") onBack()
                },
            )
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { EvsLocationCard(turn, status) }
            item {
                OutlinedButton(onClick = { onOpenDrill("evs-${turn.id}") }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.Filled.Info, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                    Text("Explain turn signal")
                }
            }
            turn.patientContextRef?.let { ref ->
                item {
                    OutlinedButton(onClick = { onOpenPatient(ref) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Icon(Icons.Filled.Person, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text("Open operational dependency context")
                    }
                }
            }
            if (turn.isolationRequired) {
                item { IsolationCallout() }
            }
            item { EvsProgressCard(status) }
            webLink?.let { link ->
                item {
                    OutlinedButton(onClick = { uriHandler.openUri(link) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text("Open in Zephyrus")
                    }
                }
            }
            vm.error?.let { item { EvsError(it) } }
        }
    }
}

@Composable
private fun EvsMetricsRow(metrics: EvsMetrics) {
    Row(Modifier.fillMaxWidth().panel().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        EvsMetricCell(metrics.pending.toString(), "Pending", null)
        EvsMetricDivider()
        EvsMetricCell(metrics.overdue.toString(), "Overdue", if (metrics.overdue > 0) CapacityStatus.CRITICAL else null)
        EvsMetricDivider()
        EvsMetricCell(metrics.isolation.toString(), "Isolation", if (metrics.isolation > 0) CapacityStatus.WARNING else null)
        EvsMetricDivider()
        EvsMetricCell(metrics.completedToday.toString(), "Done", null)
    }
}

@Composable
private fun RowScope.EvsMetricCell(value: String, label: String, tone: CapacityStatus?) {
    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(
            value,
            color = tone?.color ?: Z.ink,
            fontSize = 26.sp,
            fontWeight = FontWeight.SemiBold,
            fontFamily = FontFamily.Monospace,
        )
        Text(label, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.Medium)
    }
}

@Composable
private fun EvsMetricDivider() {
    Box(Modifier.width(1.dp).height(28.dp).background(Z.border))
}

@Composable
private fun EvsOverdueBanner(overdue: Int) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(CapacityStatus.CRITICAL.color.copy(alpha = 0.12f))
            .panel()
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(Icons.Filled.Warning, contentDescription = null, tint = CapacityStatus.CRITICAL.color, modifier = Modifier.size(20.dp))
        Text("$overdue turn${if (overdue == 1) "" else "s"} past due / beds waiting to open", color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun EvsTurnRow(turn: EvsTurn, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .clickable { onClick() },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(turn.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                TurnPriorityChip(turn)
                if (turn.isolationRequired) {
                    Spacer(Modifier.size(6.dp))
                    IsolationBadge()
                }
                Spacer(Modifier.weight(1f))
                Text(
                    turn.sla.label,
                    color = if ((turn.sla.minutesUntilDue ?: 0) < 0) CapacityStatus.CRITICAL.color else Z.inkMuted,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Medium,
                    fontFamily = FontFamily.Monospace,
                )
            }
            Text(turn.locationLabel ?: "Unknown location", color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(evsLabel(turn.turnType ?: turn.requestType), color = Z.inkMuted, fontSize = 12.sp)
        }
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
    }
}

@Composable
fun TurnPriorityChip(turn: EvsTurn) {
    val status = turn.capacity
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(status.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(status.icon, contentDescription = null, tint = status.color, modifier = Modifier.size(13.dp))
        Text(turn.priority.uppercase(), color = status.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun IsolationBadge() {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(CapacityStatus.WARNING.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(Icons.Filled.HealthAndSafety, contentDescription = null, tint = CapacityStatus.WARNING.color, modifier = Modifier.size(13.dp))
        Text("ISO", color = CapacityStatus.WARNING.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun EvsLocationCard(turn: EvsTurn, status: String) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TurnPriorityChip(turn)
            if (turn.isolationRequired) {
                Spacer(Modifier.size(6.dp))
                IsolationBadge()
            }
            Spacer(Modifier.weight(1f))
            Text(turn.sla.label, color = if ((turn.sla.minutesUntilDue ?: 0) < 0) CapacityStatus.CRITICAL.color else Z.inkMuted, fontSize = 13.sp, fontFamily = FontFamily.Monospace)
        }
        Text(turn.locationLabel ?: "Unknown location", color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        DetailChip("Turn", evsLabel(turn.turnType ?: turn.requestType))
        DetailChip("Status", evsLabel(status))
    }
}

@Composable
private fun DetailChip(label: String, value: String) {
    Column(
        modifier = Modifier
            .background(Z.bg)
            .padding(horizontal = 12.dp, vertical = 8.dp),
        verticalArrangement = Arrangement.spacedBy(1.dp),
    ) {
        Text(label.uppercase(), color = Z.inkMuted, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
        Text(value, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.Medium)
    }
}

@Composable
private fun IsolationCallout() {
    Column(
        Modifier
            .fillMaxWidth()
            .background(CapacityStatus.WARNING.color.copy(alpha = 0.10f))
            .panel()
            .padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Filled.HealthAndSafety, contentDescription = null, tint = CapacityStatus.WARNING.color, modifier = Modifier.size(20.dp))
            Text("Isolation clean - PPE required", color = Z.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        }
        Text("Don gown, gloves, and mask before entering. Follow the isolation disinfection SOP, then doff and dispose of PPE at the door.", color = Z.inkMuted, fontSize = 13.sp)
    }
}

@Composable
private fun EvsProgressCard(status: String) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Progress", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        evsLifecycle.forEach { step -> EvsStepRow(step, status) }
    }
}

@Composable
private fun EvsStepRow(step: EvsStep, status: String) {
    val done = isEvsStepDone(step, status)
    val current = status in step.statuses
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Icon(
            imageVector = if (done) Icons.Filled.CheckCircle else if (current) Icons.Filled.Info else CapacityStatus.INFO.icon,
            contentDescription = null,
            tint = if (done) CapacityStatus.SUCCESS.color else if (current) Z.primary else Z.border,
            modifier = Modifier.size(18.dp),
        )
        Text(
            step.label,
            color = if (current) Z.ink else Z.inkMuted,
            fontSize = 15.sp,
            fontWeight = if (current) FontWeight.SemiBold else FontWeight.Normal,
        )
    }
}

@Composable
private fun EvsPrimaryActionBar(turn: EvsTurn, status: String, working: Boolean, onAdvance: (String) -> Unit) {
    val next = nextEvsAction(turn, status)
    val unable = unableEvsAction(status)
    val view = LocalView.current
    Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (next == null) {
            val terminalTone = evsTerminalTone(status)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    if (terminalTone == CapacityStatus.SUCCESS) Icons.Filled.CheckCircle else Icons.Filled.Warning,
                    contentDescription = null,
                    tint = terminalTone.color,
                    modifier = Modifier.size(18.dp),
                )
                Spacer(Modifier.size(8.dp))
                Text(evsTerminalMessage(status), color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            }
        } else {
            Button(
                onClick = {
                    view.hbConfirmHaptic()
                    onAdvance(next.status)
                },
                enabled = !working,
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                colors = ButtonDefaults.buttonColors(containerColor = if (working) Z.primary.copy(alpha = 0.55f) else Z.primary),
            ) {
                Text(next.label, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
            }
            unable?.let {
                OutlinedButton(
                    onClick = {
                        view.hbRejectHaptic()
                        onAdvance(it.status)
                    },
                    enabled = !working,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                ) {
                    Text(it.label, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun EvsLoading() {
    RetryableMessage(
        title = "Loading latest turns",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun EvsEmpty() {
    RetryableMessage(
        title = "All clear",
        message = "No bed-turns waiting right now.",
        tone = CapacityStatus.SUCCESS,
    )
}

@Composable
private fun EvsError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load turns",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun EvsSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}

private data class EvsStep(val label: String, val statuses: List<String>)

private data class EvsAction(val label: String, val status: String)

private val evsLifecycle = listOf(
    EvsStep("Claimed", listOf("assigned")),
    EvsStep("Cleaning", listOf("in_progress")),
    EvsStep("Complete", listOf("completed")),
)

private val evsOrder = listOf("requested", "queued", "assigned", "in_progress", "completed")

private fun evsRank(status: String): Int = evsOrder.indexOf(status)

private fun isEvsStepDone(step: EvsStep, status: String): Boolean {
    val stepRank = step.statuses.map(::evsRank).maxOrNull() ?: return false
    return evsRank(status) > stepRank
}

private fun nextEvsAction(turn: EvsTurn, status: String): EvsAction? = when (status) {
    "requested", "queued" -> EvsAction("Claim this bed", "assigned")
    "assigned" -> EvsAction(if (turn.isolationRequired) "Start clean (PPE on)" else "Start cleaning", "in_progress")
    "in_progress" -> EvsAction("Mark complete", "completed")
    else -> null
}

private fun unableEvsAction(status: String): EvsAction? =
    if (status in listOf("completed", "canceled", "failed")) null else EvsAction("Unable to clean", "failed")

private fun evsTerminalMessage(status: String): String = when (status) {
    "failed" -> "Unable to clean - dispatcher alerted"
    "canceled" -> "Turn canceled"
    else -> "Bed turned - ready to place"
}

private fun evsTerminalTone(status: String): CapacityStatus =
    if (status in listOf("failed", "canceled")) CapacityStatus.WARNING else CapacityStatus.SUCCESS

private fun evsLabel(raw: String): String =
    raw.replace('_', ' ')
        .replace('-', ' ')
        .split(' ')
        .filter { it.isNotBlank() }
        .joinToString(" ") { part -> part.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
