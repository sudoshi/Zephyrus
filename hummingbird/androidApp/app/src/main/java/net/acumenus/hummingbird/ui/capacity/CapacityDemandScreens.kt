package net.acumenus.hummingbird.ui.capacity

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
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
import androidx.compose.material3.TextButton
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
import net.acumenus.hummingbird.data.ExecStrain
import net.acumenus.hummingbird.data.HeroKpi
import net.acumenus.hummingbird.data.OpsApproval
import net.acumenus.hummingbird.data.StrainDriver
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CapacityDemandScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenApproval: (OpsApproval, String?) -> Unit,
) {
    val vm: CapacityDemandViewModel = viewModel()
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
                title = { Text("Capacity & Demand", fontWeight = FontWeight.SemiBold) },
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
                    CapacityDemandError("Can't reach the server. Check your connection and try again.") {
                        vm.load(bearer)
                    }
                }
            } else {
                vm.error?.let { item { CapacityDemandError(it) { vm.load(bearer) } } }
            }

            if (!forceError) {
                vm.brief?.let { brief ->
                    item { StrainHeader(brief.strain) }
                    if (brief.hero.isNotEmpty()) {
                        item { CapacityDemandSectionLabel("Hero KPIs") }
                        items(brief.hero, key = { it.key }) { kpi -> HeroKpiRow(kpi) }
                    }
                }
            }

            if (forceError) {
                // Test affordance state; keep approvals quiet.
            } else if (vm.approvals.isEmpty() && vm.loading) {
                item { CapacityDemandLoading() }
            } else if (vm.approvals.isEmpty() && vm.error == null) {
                item { CapacityDemandEmpty() }
            } else if (vm.approvals.isNotEmpty()) {
                item { CapacityDemandSectionLabel("Approvals (${vm.approvals.size})") }
                items(vm.approvals, key = { it.approvalUuid }) { approval ->
                    ApprovalRow(approval, approval.approvalUuid in vm.workingApprovalIds) {
                        onOpenApproval(approval, vm.brief?.webLink)
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ApprovalDetailScreen(
    auth: AuthViewModel,
    approval: OpsApproval,
    webLink: String?,
    onBack: () -> Unit,
) {
    val vm: CapacityDemandViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val uriHandler = LocalUriHandler.current
    val working = approval.approvalUuid in vm.workingApprovalIds

    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Approval", fontWeight = FontWeight.SemiBold) },
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
            ApprovalActionBar(
                working = working,
                onApprove = { vm.decide(bearer, approval, "approved", onBack) },
                onReject = { vm.decide(bearer, approval, "rejected", onBack) },
            )
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { ApprovalDetailCard(approval) }
            vm.error?.let { item { CapacityDemandError(it) } }
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
private fun StrainHeader(strain: ExecStrain) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("HOUSE STRAIN", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                Text("${strain.label} / ${strain.level} of 4", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
            }
            StatusChip(strain.capacity)
        }
        if (strain.drivers.isNotEmpty()) {
            strain.drivers.forEach { driver -> StrainDriverRow(driver) }
        }
    }
}

@Composable
private fun StrainDriverRow(driver: StrainDriver) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.width(4.dp).height(28.dp).background(driver.capacity.color))
        Text(driver.label, color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(1f))
        Text(driver.value, color = Z.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
    }
}

@Composable
private fun HeroKpiRow(kpi: HeroKpi) {
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).panel(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(kpi.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(kpi.label, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            kpi.targetDisplay?.let { Text("Target $it", color = Z.inkMuted, fontSize = 11.sp) }
        }
        Text(kpi.display, color = kpi.capacity.color, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace, modifier = Modifier.padding(end = 14.dp))
    }
}

@Composable
private fun ApprovalRow(approval: OpsApproval, working: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .clickable { onClick() },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(approval.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ApprovalRiskChip(approval)
                approval.owner?.let { Text(it, color = Z.inkMuted, fontSize = 11.sp) }
            }
            Text(approval.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            approval.rationale?.let { Text(it, color = Z.inkMuted, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
            if (working) {
                Text("Working", color = Z.primary, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
        }
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
    }
}

@Composable
private fun ApprovalDetailCard(approval: OpsApproval) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        ApprovalRiskChip(approval)
        Text(approval.title, color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        approval.rationale?.let { Text(it, color = Z.inkMuted, fontSize = 13.sp) }
        approval.type?.let { DetailLine("Type", it) }
        approval.owner?.let { DetailLine("Owner", it) }
        approval.requestedAt?.let { DetailLine("Requested", it) }
        Text("Human approval is required before this action can proceed.", color = Z.ink, fontSize = 13.sp)
    }
}

@Composable
private fun DetailLine(label: String, value: String) {
    Row(verticalAlignment = Alignment.Top) {
        Text(label, color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(0.35f))
        Text(value, color = Z.ink, fontSize = 12.sp, modifier = Modifier.weight(0.65f))
    }
}

@Composable
private fun ApprovalRiskChip(approval: OpsApproval) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(approval.capacity.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(approval.capacity.icon, contentDescription = null, tint = approval.capacity.color, modifier = Modifier.size(13.dp))
        Text((approval.risk ?: approval.visualStatus).uppercase(), color = approval.capacity.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun ApprovalActionBar(
    working: Boolean,
    onApprove: () -> Unit,
    onReject: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(
            onClick = onApprove,
            enabled = !working,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            colors = ButtonDefaults.buttonColors(containerColor = if (working) Z.primary.copy(alpha = 0.55f) else Z.primary),
        ) {
            Icon(Icons.Filled.CheckCircle, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.size(8.dp))
            Text(if (working) "Working" else "Approve", fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
        }
        TextButton(onClick = onReject, enabled = !working, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Icon(Icons.Filled.Warning, contentDescription = null, tint = CapacityStatus.WARNING.color, modifier = Modifier.size(18.dp))
            Spacer(Modifier.size(8.dp))
            Text("Reject", color = CapacityStatus.WARNING.color, fontSize = 15.sp, fontWeight = FontWeight.Medium)
        }
    }
}

@Composable
private fun CapacityDemandLoading() {
    RetryableMessage(
        title = "Loading approvals",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun CapacityDemandEmpty() {
    RetryableMessage(
        title = "Inbox clear",
        message = "No operational actions awaiting your approval.",
        tone = CapacityStatus.SUCCESS,
    )
}

@Composable
private fun CapacityDemandError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load approvals",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun CapacityDemandSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}
