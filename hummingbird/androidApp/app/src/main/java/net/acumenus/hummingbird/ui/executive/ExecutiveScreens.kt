package net.acumenus.hummingbird.ui.executive

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
import net.acumenus.hummingbird.data.HouseBrief
import net.acumenus.hummingbird.data.StrainDriver
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HouseBriefScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenStrain: (HouseBrief) -> Unit,
) {
    val vm: ExecutiveViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val uriHandler = LocalUriHandler.current

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
                title = { Text("House Brief", fontWeight = FontWeight.SemiBold) },
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
                item { ExecutiveError("Can't reach the server. Check your connection and try again.") { vm.load(bearer) } }
            } else {
                vm.error?.let { item { ExecutiveError(it) { vm.load(bearer) } } }
            }

            if (forceError) {
                // Test affordance state; keep brief content quiet.
            } else if (vm.brief == null && vm.loading) {
                item { ExecutiveLoading() }
            } else if (vm.brief == null && vm.error == null) {
                item { ExecutiveEmpty() }
            } else {
                vm.brief?.let { brief ->
                    item { ExecutiveStrainCard(brief.strain) { onOpenStrain(brief) } }
                    item {
                        materialDriver(brief.strain)?.let(::ExecutiveOneThing) ?: ExecutiveCalmState()
                    }
                    if (brief.hero.isNotEmpty()) {
                        item { ExecutiveSectionLabel("HOUSE KPIS") }
                        items(brief.hero, key = { it.key }) { kpi -> HeroKpiTile(kpi) }
                    }
                    brief.webLink?.let { link ->
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
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StrainDetailScreen(
    brief: HouseBrief,
    onBack: () -> Unit,
) {
    val uriHandler = LocalUriHandler.current

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Strain detail", fontWeight = FontWeight.SemiBold) },
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
            item { ExecutiveStrainCard(brief.strain) {} }
            item { materialDriver(brief.strain)?.let(::ExecutiveOneThing) ?: ExecutiveCalmState() }
            item {
                Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Executive brief", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                    Text("Situation, plan, and impact narrative remains web-first until /command/brief is available on mobile.", color = Z.inkMuted, fontSize = 13.sp)
                    Text("Generated ${brief.generatedAt ?: "recently"}", color = Z.inkMuted, fontSize = 12.sp, fontFamily = FontFamily.Monospace)
                }
            }
            brief.webLink?.let { link ->
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
private fun ExecutiveStrainCard(strain: ExecStrain, onClick: () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .panel()
            .clickable { onClick() }
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("HOUSE STRAIN", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                Text("${strain.label} / ${strain.level} of 4", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
            }
            StatusChip(strain.capacity)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
            repeat(4) { index ->
                Box(
                    Modifier
                        .weight(1f)
                        .height(26.dp)
                        .background(if (index < strain.level) strain.capacity.color else Z.border),
                )
            }
        }
        strain.drivers.forEach { driver -> ExecutiveDriverRow(driver) }
    }
}

@Composable
private fun ExecutiveDriverRow(driver: StrainDriver) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Icon(driver.capacity.icon, contentDescription = null, tint = driver.capacity.color, modifier = Modifier.size(14.dp))
        Text(driver.label, color = Z.inkMuted, fontSize = 13.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text(driver.value, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
    }
}

@Composable
private fun ExecutiveOneThing(driver: StrainDriver) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(driver.capacity.color.copy(alpha = 0.12f))
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Filled.Warning, contentDescription = null, tint = driver.capacity.color, modifier = Modifier.size(22.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text("THE ONE THING", color = Z.inkMuted, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
            Text("${driver.label}: ${driver.value}", color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun ExecutiveCalmState() {
    Row(Modifier.fillMaxWidth().panel().padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = CapacityStatus.SUCCESS.color, modifier = Modifier.size(22.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text("THE ONE THING", color = Z.inkMuted, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
            Text("No material breach right now", color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun HeroKpiTile(kpi: HeroKpi) {
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).panel(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(kpi.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(kpi.label.uppercase(), color = Z.inkMuted, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            kpi.targetDisplay?.let { Text("target $it", color = Z.inkMuted, fontSize = 11.sp) }
        }
        Text(kpi.display, color = kpi.capacity.color, fontSize = 24.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace, modifier = Modifier.padding(end = 14.dp))
    }
}

@Composable
private fun ExecutiveLoading() {
    RetryableMessage(
        title = "Loading the brief",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun ExecutiveEmpty() {
    RetryableMessage(
        title = "Brief unavailable",
        message = "No executive brief has been generated yet.",
        tone = CapacityStatus.INFO,
    )
}

@Composable
private fun ExecutiveError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load the brief",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun ExecutiveSectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}

private fun materialDriver(strain: ExecStrain): StrainDriver? =
    strain.drivers.firstOrNull { it.capacity.severity >= CapacityStatus.WARNING.severity }
