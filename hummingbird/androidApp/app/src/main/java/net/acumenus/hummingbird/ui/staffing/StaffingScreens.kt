package net.acumenus.hummingbird.ui.staffing

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
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
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
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.StaffingCandidate
import net.acumenus.hummingbird.data.StaffingMetrics
import net.acumenus.hummingbird.data.StaffingReq
import net.acumenus.hummingbird.data.UnitAtRisk
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.flow.FlowBoardMode
import net.acumenus.hummingbird.ui.flow.FlowMapScreen
import net.acumenus.hummingbird.ui.flow.ListMapSegment
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StaffingScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenRequest: (StaffingReq, String?) -> Unit,
) {
    val vm: StaffingViewModel = viewModel()
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
                title = { Text("Staffing", fontWeight = FontWeight.SemiBold) },
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
            // §8 P10: coverage vs the curve — gap steps at shift boundaries.
            FlowMapScreen(
                auth = auth,
                persona = "staffing_coordinator",
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
                item { StaffingError("Can't reach the server. Check your connection and try again.") { vm.load(bearer) } }
            } else {
                vm.error?.let { item { StaffingError(it) { vm.load(bearer) } } }
            }

            if (forceError) {
                // Test affordance state; keep staffing content quiet.
            } else if (vm.overview == null && vm.loading) {
                item { StaffingLoading() }
            } else if (vm.overview == null && vm.error == null) {
                item { StaffingEmpty() }
            } else {
                vm.overview?.let { overview ->
                    item { StaffingMetricsRow(overview.metrics) }
                    if (overview.unitsAtRisk.isNotEmpty()) {
                        item { StaffingSectionLabel("BELOW MINIMUM-SAFE (${overview.unitsAtRisk.size})") }
                        items(overview.unitsAtRisk, key = { it.unitId }) { unit -> UnitAtRiskRow(unit) }
                    }
                    if (overview.queue.isNotEmpty()) {
                        item { StaffingSectionLabel("OPEN REQUESTS (${overview.queue.size})") }
                        items(overview.queue, key = { it.staffingRequestId }) { request ->
                            StaffingRequestRow(
                                request = request,
                                onClick = { onOpenRequest(request, overview.webLink) },
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
fun StaffingRequestDetailScreen(
    auth: AuthViewModel,
    request: StaffingReq,
    webLink: String?,
    onBack: () -> Unit,
) {
    val vm: StaffingViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val uriHandler = LocalUriHandler.current
    val working = request.staffingRequestId in vm.workingRequestIds
    val candidates = vm.candidatesByRequest[request.staffingRequestId].orEmpty()
    val selectedCandidateId = vm.selectedCandidateByRequest[request.staffingRequestId]

    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }
    LaunchedEffect(request.staffingRequestId, bearer) { vm.loadCandidates(bearer, request) }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Staffing request", fontWeight = FontWeight.SemiBold) },
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
            Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp)) {
                Button(
                    onClick = { vm.fillSelected(bearer, request, onBack) },
                    enabled = !working && selectedCandidateId != null,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = if (working) Z.primary.copy(alpha = 0.55f) else Z.primary),
                ) {
                    Text(if (working) "Filling shift" else "Fill with selected staff", fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { StaffingRequestDetailCard(request) }
            vm.error?.let { item { StaffingError(it) } }
            if (request.staffingRequestId in vm.candidateLoadingIds) {
                item { Text("Checking qualifications, availability, and conflicts...", color = Z.inkMuted, fontSize = 13.sp) }
            } else if (candidates.isEmpty()) {
                item { StaffingError("No staff currently pass the canonical role, qualification, availability, and conflict checks.") }
            } else {
                item { StaffingSectionLabel("QUALIFIED AND REVIEWED CANDIDATES") }
                items(candidates, key = { it.staffMemberId }) { candidate ->
                    StaffingCandidateRow(
                        candidate = candidate,
                        selected = selectedCandidateId == candidate.staffMemberId,
                        onSelect = { vm.selectCandidate(request.staffingRequestId, candidate.staffMemberId) },
                    )
                }
            }
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
private fun StaffingMetricsRow(metrics: StaffingMetrics) {
    Row(Modifier.fillMaxWidth().panel().padding(vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
        StaffingMetricCell("${metrics.coveragePct}%", "Coverage", if (metrics.coveragePct < 95) CapacityStatus.WARNING else CapacityStatus.SUCCESS)
        StaffingMetricDivider()
        StaffingMetricCell("${metrics.criticalGaps}", "Critical", if (metrics.criticalGaps > 0) CapacityStatus.CRITICAL else CapacityStatus.INFO)
        StaffingMetricDivider()
        StaffingMetricCell("${metrics.openRequests}", "Requests", if (metrics.openRequests > 0) CapacityStatus.WARNING else CapacityStatus.INFO)
        StaffingMetricDivider()
        StaffingMetricCell("${metrics.totalGapHeadcount}", "Gap FTE", if (metrics.totalGapHeadcount > 0) CapacityStatus.WARNING else CapacityStatus.INFO)
    }
}

@Composable
private fun RowScope.StaffingMetricCell(value: String, label: String, tone: CapacityStatus) {
    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(value, color = tone.color, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
        Text(label, color = Z.inkMuted, fontSize = 11.sp, maxLines = 1)
    }
}

@Composable
private fun StaffingMetricDivider() {
    Box(Modifier.width(1.dp).heightIn(min = 26.dp).background(Z.border))
}

@Composable
private fun UnitAtRiskRow(unit: UnitAtRisk) {
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).heightIn(min = 76.dp).panel(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(unit.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(unit.unitLabel, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                if (unit.belowMinimumSafe) {
                    Text("below safe", color = CapacityStatus.WARNING.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            Text("${unit.worstRoleLabel} / short ${unit.gapHeadcount}", color = Z.inkMuted, fontSize = 12.sp)
        }
        StatusChip(unit.capacity)
        Spacer(Modifier.size(10.dp))
    }
}

@Composable
private fun StaffingRequestRow(
    request: StaffingReq,
    onClick: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .panel()
            .clickable { onClick() }
            .padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StaffingPriorityChip(request)
            Spacer(Modifier.weight(1f))
            Text(request.sla.label, color = if (request.sla.atRisk) CapacityStatus.CRITICAL.color else Z.inkMuted, fontSize = 12.sp, fontFamily = FontFamily.Monospace)
        }
        Text("${request.roleLabel ?: "Staff"} / ${request.unitLabel ?: "-"}", color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Button(
            onClick = onClick,
            modifier = Modifier.fillMaxWidth().heightIn(min = 44.dp),
            colors = ButtonDefaults.buttonColors(containerColor = Z.primary),
        ) {
            Text("Choose qualified staff", fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun StaffingCandidateRow(
    candidate: StaffingCandidate,
    selected: Boolean,
    onSelect: () -> Unit,
) {
    OutlinedButton(
        onClick = onSelect,
        enabled = candidate.eligible,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(candidate.displayName, color = if (candidate.eligible) Z.ink else Z.inkMuted, fontWeight = FontWeight.SemiBold)
            Text(
                if (candidate.eligible) candidate.roleLabel else candidate.reasonCodes.joinToString(" / ") { it.replace('_', ' ') },
                color = Z.inkMuted,
                fontSize = 12.sp,
            )
        }
        Text(
            if (selected) "Selected" else candidate.eligibilityState.replace('_', ' '),
            color = if (candidate.eligible) Z.primary else CapacityStatus.WARNING.color,
            fontSize = 12.sp,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

@Composable
private fun StaffingRequestDetailCard(request: StaffingReq) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        StaffingPriorityChip(request)
        Text("${request.roleLabel ?: "Staff"} / ${request.unitLabel ?: "-"}", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        request.headcountNeeded?.let { StaffingDetailLine("Headcount", "$it") }
        StaffingDetailLine("Status", request.status)
        StaffingDetailLine("SLA", request.sla.label)
        Text("Filling requires a named person who passes canonical qualification, availability, unit, and overlap checks.", color = Z.inkMuted, fontSize = 13.sp)
    }
}

@Composable
private fun StaffingDetailLine(label: String, value: String) {
    Row(verticalAlignment = Alignment.Top) {
        Text(label, color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(0.35f))
        Text(value, color = Z.ink, fontSize = 12.sp, modifier = Modifier.weight(0.65f))
    }
}

@Composable
private fun StaffingPriorityChip(request: StaffingReq) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(request.capacity.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(request.capacity.icon, contentDescription = null, tint = request.capacity.color, modifier = Modifier.size(13.dp))
        Text(request.priority.uppercase(), color = request.capacity.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun StaffingLoading() {
    RetryableMessage(
        title = "Loading staffing",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun StaffingEmpty() {
    RetryableMessage(
        title = "Staffing stable",
        message = "No open staffing requests or below-safe units.",
        tone = CapacityStatus.SUCCESS,
    )
}

@Composable
private fun StaffingError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load staffing",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun StaffingSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}
