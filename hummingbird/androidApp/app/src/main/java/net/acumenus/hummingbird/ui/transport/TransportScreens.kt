package net.acumenus.hummingbird.ui.transport

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
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import net.acumenus.hummingbird.data.TransportJob
import net.acumenus.hummingbird.data.TransportMetrics
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.hbConfirmHaptic
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.flow.FlowBoardMode
import net.acumenus.hummingbird.ui.flow.FlowMapScreen
import net.acumenus.hummingbird.ui.flow.ListMapSegment
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TransportJobsScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenJob: (TransportJob, String?) -> Unit,
) {
    val vm: TransportViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val view = LocalView.current
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
                title = { Text("Transport", fontWeight = FontWeight.SemiBold) },
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
                persona = "transport",
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
                    TransportError("Can't reach the server. Check your connection and try again.") {
                        vm.load(bearer)
                    }
                }
            } else {
                vm.error?.let {
                    item { TransportError(it) { vm.load(bearer) } }
                }
            }

            val queue = vm.queue
            if (forceError) {
                // Test affordance state; keep the queue quiet.
            } else if (queue == null && vm.loading) {
                item { TransportLoading() }
            } else if (queue != null) {
                item { TransportMetricsRow(queue.metrics) }
                if (queue.metrics.stat > 0 || queue.metrics.atRisk > 0) {
                    item { TransportStatBanner(queue.metrics) }
                }
                if (queue.jobs.isEmpty()) {
                    item { TransportEmpty() }
                } else {
                    val myTrips = claimedTransportJobs(queue.jobs)
                    val availableTrips = availableTransportJobs(queue.jobs)

                    if (myTrips.isNotEmpty()) {
                        item { TransportSectionLabel("My trips (${myTrips.size})") }
                        items(myTrips, key = { it.id }) { job ->
                            TransportJobRow(job, onClick = { onOpenJob(job, queue.webLink) })
                        }
                    }

                    if (availableTrips.isNotEmpty()) {
                        item { TransportSectionLabel("Available trips (${availableTrips.size})") }
                        items(availableTrips, key = { it.id }) { job ->
                            TransportJobRow(
                                job = job,
                                onClick = { onOpenJob(job, queue.webLink) },
                                inlineAction = TransportInlineAction("Claim", vm.workingJobId == job.id) {
                                    view.hbConfirmHaptic()
                                    vm.claim(bearer, job.id)
                                },
                            )
                        }
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
fun TransportJobDetailScreen(
    auth: AuthViewModel,
    job: TransportJob,
    webLink: String?,
    onBack: () -> Unit,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    val vm: TransportViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val uriHandler = LocalUriHandler.current
    var status by remember(job.id) { mutableStateOf(job.status) }
    var showHandoff by remember(job.id) { mutableStateOf(false) }
    val working = vm.workingJobId == job.id

    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Trip", fontWeight = FontWeight.SemiBold) },
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
            TransportPrimaryActionBar(
                status = status,
                working = working,
                onAdvance = { next ->
                    if (next == HANDOFF_SENTINEL) {
                        showHandoff = true
                    } else {
                        vm.advance(bearer, job.id, next)
                        status = next
                        if (next == "completed") onBack()
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
            item { TransportRouteCard(job, status) }
            item {
                OutlinedButton(onClick = { onOpenDrill("transport-${job.id}") }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.Filled.Info, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                    Text("Explain trip signal")
                }
            }
            job.patientContextRef?.let { ref ->
                item {
                    OutlinedButton(onClick = { onOpenPatient(ref) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Icon(Icons.Filled.Person, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text("Open transport-safe patient context")
                    }
                }
            }
            item { TransportProgressCard(status) }
            webLink?.let { link ->
                item {
                    OutlinedButton(onClick = { uriHandler.openUri(link) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text("Open in Zephyrus")
                    }
                }
            }
            vm.error?.let { item { TransportError(it) } }
        }
    }

    if (showHandoff) {
        HandoffSheet(
            onDismiss = { showHandoff = false },
            onComplete = { handoffTo, summary ->
                vm.handoff(bearer, job.id, handoffTo, summary)
                status = "handoff_complete"
                showHandoff = false
            },
        )
    }
}

@Composable
private fun TransportMetricsRow(metrics: TransportMetrics) {
    Row(Modifier.fillMaxWidth().panel().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        TransportMetricCell(metrics.active.toString(), "Active", null)
        TransportMetricDivider()
        TransportMetricCell(metrics.stat.toString(), "STAT", if (metrics.stat > 0) CapacityStatus.CRITICAL else null)
        TransportMetricDivider()
        TransportMetricCell(metrics.atRisk.toString(), "At risk", if (metrics.atRisk > 0) CapacityStatus.WARNING else null)
        TransportMetricDivider()
        TransportMetricCell(metrics.completedToday.toString(), "Done", null)
    }
}

@Composable
private fun RowScope.TransportMetricCell(value: String, label: String, tone: CapacityStatus?) {
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
private fun TransportMetricDivider() {
    Box(Modifier.width(1.dp).height(28.dp).background(Z.border))
}

@Composable
private fun TransportStatBanner(metrics: TransportMetrics) {
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
        Text(transportBannerText(metrics), color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun TransportJobRow(
    job: TransportJob,
    onClick: () -> Unit,
    inlineAction: TransportInlineAction? = null,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .clickable { onClick() },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(job.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                JobPriorityChip(job)
                Spacer(Modifier.weight(1f))
                Text(
                    job.sla.label,
                    color = if (job.sla.atRisk) CapacityStatus.CRITICAL.color else Z.inkMuted,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Medium,
                    fontFamily = FontFamily.Monospace,
                )
            }
            Text(transportRoute(job), color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(transportSubtitle(job), color = Z.inkMuted, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        if (inlineAction == null) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
        } else {
            TextButton(
                onClick = inlineAction.onClick,
                enabled = !inlineAction.working,
                modifier = Modifier.padding(end = 8.dp),
            ) {
                Text(if (inlineAction.working) "Claiming" else inlineAction.label)
            }
        }
    }
}

@Composable
fun JobPriorityChip(job: TransportJob) {
    val status = job.capacity
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(status.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(status.icon, contentDescription = null, tint = status.color, modifier = Modifier.size(13.dp))
        Text(job.priority.uppercase(), color = status.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun TransportRouteCard(job: TransportJob, status: String) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            JobPriorityChip(job)
            Spacer(Modifier.weight(1f))
            Text(job.sla.label, color = if (job.sla.atRisk) CapacityStatus.CRITICAL.color else Z.inkMuted, fontSize = 13.sp, fontFamily = FontFamily.Monospace)
        }
        Text(transportRoute(job), color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            DetailChip("Type", statusLabel(job.type))
            job.mode?.let { DetailChip("Mode", statusLabel(it)) }
            DetailChip("Status", statusLabel(status))
        }
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
private fun TransportProgressCard(status: String) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Progress", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        transportLifecycle.forEach { step -> TransportStepRow(step, status) }
    }
}

@Composable
private fun TransportStepRow(step: TransportStep, status: String) {
    val done = isTransportStepDone(step, status)
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
private fun TransportPrimaryActionBar(status: String, working: Boolean, onAdvance: (String) -> Unit) {
    val next = nextTransportAction(status)
    val view = LocalView.current
    Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp)) {
        if (next == null) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = CapacityStatus.SUCCESS.color, modifier = Modifier.size(18.dp))
                Spacer(Modifier.size(8.dp))
                Text("Trip complete", color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
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
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HandoffSheet(
    onDismiss: () -> Unit,
    onComplete: (String, String?) -> Unit,
) {
    var handoffTo by remember { mutableStateOf("") }
    var summary by remember { mutableStateOf("") }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Z.bg) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Text("Structured handoff", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
            OutlinedTextField(
                value = handoffTo,
                onValueChange = { handoffTo = it },
                label = { Text("Handing off to") },
                placeholder = { Text("Receiving nurse / unit") },
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = summary,
                onValueChange = { summary = it },
                label = { Text("Summary (optional)") },
                placeholder = { Text("Anything the receiver should know") },
                minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxWidth()) {
                TextButton(onClick = onDismiss, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                    Text("Cancel")
                }
                Button(
                    onClick = { onComplete(handoffTo.trim(), summary.takeIf { it.isNotBlank() }) },
                    enabled = handoffTo.isNotBlank(),
                    modifier = Modifier.weight(1f).heightIn(min = 48.dp),
                ) {
                    Text("Complete")
                }
            }
            Spacer(Modifier.height(12.dp))
        }
    }
}

@Composable
private fun TransportLoading() {
    RetryableMessage(
        title = "Loading latest trips",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun TransportEmpty() {
    RetryableMessage(
        title = "Queue clear",
        message = "No active transport jobs right now.",
        tone = CapacityStatus.SUCCESS,
    )
}

@Composable
private fun TransportError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load trips",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun TransportSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}

private data class TransportStep(val label: String, val statuses: List<String>)

private data class TransportAction(val label: String, val status: String)

private data class TransportInlineAction(val label: String, val working: Boolean, val onClick: () -> Unit)

private const val HANDOFF_SENTINEL = "__handoff__"

private val transportLifecycle = listOf(
    TransportStep("Claimed", listOf("assigned", "dispatched")),
    TransportStep("Dispatched", listOf("dispatched")),
    TransportStep("At pickup", listOf("arrived_pickup", "patient_ready", "patient_not_ready")),
    TransportStep("Picked up", listOf("picked_up")),
    TransportStep("En route", listOf("en_route")),
    TransportStep("Arrived", listOf("arrived_destination")),
    TransportStep("Handed off", listOf("handoff_started", "handoff_complete")),
    TransportStep("Complete", listOf("completed")),
)

private val transportOrder = listOf(
    "requested",
    "accepted",
    "queued",
    "assigned",
    "dispatched",
    "arrived_pickup",
    "patient_ready",
    "patient_not_ready",
    "picked_up",
    "en_route",
    "arrived_destination",
    "handoff_started",
    "handoff_complete",
    "completed",
)

private fun transportRank(status: String): Int = transportOrder.indexOf(status)

private fun isTransportStepDone(step: TransportStep, status: String): Boolean {
    val stepRank = step.statuses.map(::transportRank).maxOrNull() ?: return false
    return transportRank(status) > stepRank
}

private fun nextTransportAction(status: String): TransportAction? = when (status) {
    "requested", "accepted", "queued" -> TransportAction("Claim this trip", "assigned")
    "assigned" -> TransportAction("Start dispatch", "dispatched")
    "dispatched" -> TransportAction("Arrived at pickup", "arrived_pickup")
    "arrived_pickup", "patient_ready", "patient_not_ready" -> TransportAction("Picked up", "picked_up")
    "picked_up" -> TransportAction("En route", "en_route")
    "en_route" -> TransportAction("Arrived at destination", "arrived_destination")
    "arrived_destination", "handoff_started" -> TransportAction("Complete handoff", HANDOFF_SENTINEL)
    "handoff_complete" -> TransportAction("Mark trip complete", "completed")
    else -> null
}

private fun availableTransportJobs(jobs: List<TransportJob>): List<TransportJob> =
    jobs.filter { isTransportClaimable(it.status) }

private fun claimedTransportJobs(jobs: List<TransportJob>): List<TransportJob> =
    jobs.filterNot { isTransportClaimable(it.status) }

private fun isTransportClaimable(status: String): Boolean =
    status in listOf("requested", "accepted", "queued")

private fun transportBannerText(metrics: TransportMetrics): String {
    val parts = listOfNotNull(
        metrics.stat.takeIf { it > 0 }?.let { "$it STAT" },
        metrics.atRisk.takeIf { it > 0 }?.let { "$it at risk" },
    )
    return parts.joinToString(" / ") + " needs a runner now"
}

private fun transportRoute(job: TransportJob): String =
    listOf(job.origin ?: "Unknown origin", job.destination ?: "Unknown destination").joinToString(" to ")

private fun transportSubtitle(job: TransportJob): String =
    listOfNotNull(job.type, job.mode, statusLabel(job.status))
        .map(::statusLabel)
        .joinToString(" / ")

private fun statusLabel(raw: String): String =
    raw.replace('_', ' ')
        .replace('-', ' ')
        .split(' ')
        .filter { it.isNotBlank() }
        .joinToString(" ") { part -> part.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
