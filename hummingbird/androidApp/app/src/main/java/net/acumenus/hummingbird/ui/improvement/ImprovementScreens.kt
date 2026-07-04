package net.acumenus.hummingbird.ui.improvement

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
import net.acumenus.hummingbird.data.ApiClient
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.Opportunity
import net.acumenus.hummingbird.data.PdsaCycle
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.flow.FlowBoardMode
import net.acumenus.hummingbird.ui.flow.FlowMapScreen
import net.acumenus.hummingbird.ui.flow.ListMapSegment
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ImprovementScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenCycle: (PdsaCycle) -> Unit,
    onOpenOpportunity: (Opportunity) -> Unit,
) {
    val vm: ImprovementViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    var boardMode by remember { mutableStateOf(FlowBoardMode.List) }

    LaunchedEffect(bearer, forceError) {
        if (!forceError) {
            while (true) {
                vm.load(bearer)
                kotlinx.coroutines.delay(30000)
            }
        }
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Improvement", fontWeight = FontWeight.SemiBold) },
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
            // §8 P8: the pattern, not the patient — replay + clip-to-share.
            FlowMapScreen(
                auth = auth,
                persona = "pi_lead",
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
                item { ImprovementError("Can't reach the server. Check your connection and try again.") { vm.load(bearer) } }
            } else {
                vm.error?.let { item { ImprovementError(it) { vm.load(bearer) } } }
            }

            if (forceError) {
                // Test affordance state; keep improvement content quiet.
            } else if (!vm.loaded && vm.loading) {
                item { ImprovementLoading() }
            } else if (!vm.loaded && vm.error == null) {
                item { ImprovementEmpty() }
            } else {
                val activeCycles = vm.activeCycles()
                item { ImprovementSummary(activeCycles.size, vm.opportunities.size) }
                if (activeCycles.isNotEmpty()) {
                    item { ImprovementSectionLabel("ACTIVE PDSA CYCLES (${activeCycles.size})") }
                    items(activeCycles, key = { it.id }) { cycle -> PdsaCycleRow(cycle) { onOpenCycle(cycle) } }
                }
                if (vm.opportunities.isNotEmpty()) {
                    item { ImprovementSectionLabel("OPPORTUNITIES (by impact)") }
                    items(vm.opportunities.sortedByDescending { it.impact ?: 0 }, key = { it.id }) { opportunity ->
                        OpportunityRow(opportunity) { onOpenOpportunity(opportunity) }
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
fun PdsaDetailScreen(
    cycle: PdsaCycle,
    onBack: () -> Unit,
) {
    val uriHandler = LocalUriHandler.current

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("PDSA cycle", fontWeight = FontWeight.SemiBold) },
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
            Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(enabled = false, onClick = {}, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text("Stage advance is web-only until the write API exists", fontWeight = FontWeight.SemiBold)
                }
            }
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { PdsaDetailCard(cycle) }
            item {
                OutlinedButton(onClick = { uriHandler.openUri("${ApiClient.BASE_URL}/improvement/pdsa") }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                    Text("Open in Zephyrus")
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OpportunityDetailScreen(
    opportunity: Opportunity,
    onBack: () -> Unit,
) {
    val uriHandler = LocalUriHandler.current

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Opportunity", fontWeight = FontWeight.SemiBold) },
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
            item { OpportunityDetailCard(opportunity) }
            item {
                OutlinedButton(onClick = { uriHandler.openUri("${ApiClient.BASE_URL}/improvement/opportunities") }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                    Text("Open in Zephyrus")
                }
            }
        }
    }
}

@Composable
private fun ImprovementSummary(activeCycles: Int, opportunities: Int) {
    Row(Modifier.fillMaxWidth().panel().padding(vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
        ImprovementMetricCell("$activeCycles", "Active cycles")
        Box(Modifier.width(1.dp).heightIn(min = 26.dp).background(Z.border))
        ImprovementMetricCell("$opportunities", "Opportunities")
    }
}

@Composable
private fun RowScope.ImprovementMetricCell(value: String, label: String) {
    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(value, color = Z.ink, fontSize = 24.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
        Text(label, color = Z.inkMuted, fontSize = 11.sp)
    }
}

@Composable
private fun PdsaCycleRow(cycle: PdsaCycle, onClick: () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .panel()
            .clickable { onClick() }
            .padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ImprovementStatusPill(cycle.status, CapacityStatus.INFO)
            Spacer(Modifier.weight(1f))
            cycle.unit?.let { Text(it, color = Z.inkMuted, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        Text(cycle.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
        cycle.objective?.let { Text(it, color = Z.inkMuted, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
    }
}

@Composable
private fun OpportunityRow(opportunity: Opportunity, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .heightIn(min = 78.dp)
            .panel()
            .clickable { onClick() }
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text("${opportunity.impact ?: 0}", color = opportunity.priorityTier.color, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
            Text("impact", color = Z.inkMuted, fontSize = 9.sp)
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(opportunity.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("${opportunity.department ?: "-"} / ${opportunity.priority} / ${opportunity.status}", color = Z.inkMuted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Z.inkMuted)
    }
}

@Composable
private fun PdsaDetailCard(cycle: PdsaCycle) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        ImprovementStatusPill(cycle.status, CapacityStatus.INFO)
        Text(cycle.title, color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        cycle.objective?.let { Text(it, color = Z.inkMuted, fontSize = 13.sp) }
        cycle.owner?.let { ImprovementDetailLine("Owner", it) }
        cycle.unit?.let { ImprovementDetailLine("Unit", it) }
        cycle.startedAt?.let { ImprovementDetailLine("Started", it) }
        cycle.targetDate?.let { ImprovementDetailLine("Target", it) }
        Text("Read-only on mobile until the PDSA stage advance API exists.", color = Z.inkMuted, fontSize = 13.sp)
    }
}

@Composable
private fun OpportunityDetailCard(opportunity: Opportunity) {
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).heightIn(min = 120.dp).panel()) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(opportunity.priorityTier.color))
        Column(Modifier.weight(1f).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            ImprovementStatusPill(opportunity.priority, opportunity.priorityTier)
            Text(opportunity.title, color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
            opportunity.description?.let { Text(it, color = Z.inkMuted, fontSize = 13.sp) }
            opportunity.department?.let { ImprovementDetailLine("Department", it) }
            ImprovementDetailLine("Status", opportunity.status)
            opportunity.impact?.let { ImprovementDetailLine("Impact", "$it") }
            Text("Opportunity actions remain web-only until a write API is available.", color = Z.inkMuted, fontSize = 13.sp)
        }
    }
}

@Composable
private fun ImprovementDetailLine(label: String, value: String) {
    Row(verticalAlignment = Alignment.Top) {
        Text(label, color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(0.35f))
        Text(value, color = Z.ink, fontSize = 12.sp, modifier = Modifier.weight(0.65f))
    }
}

@Composable
private fun ImprovementStatusPill(label: String, tone: CapacityStatus) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(tone.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(tone.icon, contentDescription = null, tint = tone.color, modifier = Modifier.size(13.dp))
        Text(label.uppercase(), color = tone.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun ImprovementLoading() {
    RetryableMessage(
        title = "Loading improvement",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun ImprovementEmpty() {
    RetryableMessage(
        title = "No improvement work",
        message = "No active PDSA cycles or opportunities are available.",
        tone = CapacityStatus.INFO,
    )
}

@Composable
private fun ImprovementError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load improvement",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun ImprovementSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}
