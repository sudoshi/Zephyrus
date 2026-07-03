package net.acumenus.hummingbird.ui.capacity

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
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
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
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.CensusUnit
import net.acumenus.hummingbird.data.HouseRollup
import net.acumenus.hummingbird.data.Placement
import net.acumenus.hummingbird.data.PlacementChip
import net.acumenus.hummingbird.data.PlacementRecommendation
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.hbConfirmHaptic
import net.acumenus.hummingbird.ui.components.hbRejectHaptic
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HouseCapacityScreen(
    auth: AuthViewModel,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenPlacement: (Placement) -> Unit,
) {
    val vm: HouseCapacityViewModel = viewModel()
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
                title = { Text("House Capacity", fontWeight = FontWeight.SemiBold) },
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
                    CapacityError("Can't reach the server. Check your connection and try again.") {
                        vm.load(bearer)
                    }
                }
            } else {
                vm.error?.let { item { CapacityError(it) { vm.load(bearer) } } }
            }

            val house = vm.house
            if (forceError) {
                // Test affordance state; keep the house view quiet.
            } else if (house == null && vm.loading) {
                item { CapacityLoading() }
            } else if (house != null) {
                item { RollupCard(house) }
                val sortedPlacements = priorityPlacements(vm.placements)
                if (sortedPlacements.isEmpty()) {
                    item { CapacitySectionLabel("Placements") }
                    item { Text("No pending bed requests.", color = Z.inkMuted, fontSize = 13.sp) }
                } else {
                    item { CapacitySectionLabel("Pending placements (${sortedPlacements.size}) / highest risk, oldest first") }
                    items(sortedPlacements, key = { it.id }) { placement ->
                        PlacementRow(placement) { onOpenPlacement(placement) }
                    }
                }

                val pressured = house.units
                    .filter { it.capacity == CapacityStatus.WARNING || it.capacity == CapacityStatus.CRITICAL }
                    .sortedByDescending { it.capacity.severity }
                if (pressured.isNotEmpty()) {
                    item { CapacitySectionLabel("Units under pressure (${pressured.size})") }
                    items(pressured, key = { it.unitId }) { unit -> PressuredUnitRow(unit) }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PlacementDetailScreen(
    auth: AuthViewModel,
    placement: Placement,
    onBack: () -> Unit,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    val vm: HouseCapacityViewModel = viewModel()
    val bearer = auth.accessToken ?: ""
    val recs = vm.recommendations?.recommendations.orEmpty()
    val top = recs.firstOrNull()

    LaunchedEffect(bearer, placement.id) {
        vm.loadRecommendations(bearer, placement.id)
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Placement", fontWeight = FontWeight.SemiBold) },
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
            if (top != null) {
                PlacementActionBar(
                    top = top,
                    working = vm.decisionWorking,
                    onPlace = { vm.decide(bearer, placement.id, "accepted", top.bedId, onBack) },
                    onReject = { vm.decide(bearer, placement.id, "rejected", null, onBack) },
                )
            }
        },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item { PlacementRequestCard(placement) }
            item {
                OutlinedButton(onClick = { onOpenDrill("bedreq-${placement.id}") }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.Filled.Info, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(8.dp))
                    Text("Explain placement signal")
                }
            }
            placement.patientContextRef?.let { ref ->
                item {
                    OutlinedButton(onClick = { onOpenPatient(ref) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Icon(Icons.Filled.Person, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text("Open patient context")
                    }
                }
            }
            if (vm.loadingRecommendations) {
                item { CapacityRecommendationLoading() }
            } else if (vm.error != null) {
                item { CapacityError(vm.error ?: "Couldn't get a bed") { vm.loadRecommendations(bearer, placement.id) } }
            } else if (recs.isEmpty()) {
                item { NoSafeBed() }
            } else {
                item { RecommendationCard(recs.first(), isTop = true) }
                if (recs.size > 1) {
                    item { CapacitySectionLabel("Also considered") }
                    items(recs.drop(1), key = { it.bedId }) { rec ->
                        RecommendationCard(rec, isTop = false)
                    }
                }
            }
        }
    }
}

@Composable
private fun RollupCard(house: HouseRollup) {
    val occStatus = when {
        house.occupancy.percent >= 100 -> CapacityStatus.CRITICAL
        house.occupancy.percent >= 90 -> CapacityStatus.WARNING
        else -> CapacityStatus.SUCCESS
    }

    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                "${house.occupancy.percent}%",
                color = occStatus.color,
                fontSize = 40.sp,
                fontWeight = FontWeight.SemiBold,
                fontFamily = FontFamily.Monospace,
            )
            Spacer(Modifier.size(12.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("House occupancy", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                Text("${house.occupancy.occupied} / ${house.occupancy.staffed} staffed beds", color = Z.inkMuted, fontSize = 13.sp)
            }
        }
        HorizontalDivider(color = Z.border)
        Row(verticalAlignment = Alignment.CenterVertically) {
            RollupMiniStat(house.netBedNeed.toString(), "net bed need", if (house.netBedNeed > 0) CapacityStatus.WARNING else CapacityStatus.SUCCESS)
            RollupDivider()
            RollupMiniStat(house.pendingPlacements.toString(), "placements", if (house.pendingPlacements > 0) CapacityStatus.WARNING else null)
            RollupDivider()
            RollupMiniStat(house.edBoarding.toString(), "ED boarding", if (house.edBoarding > 4) CapacityStatus.WARNING else null)
        }
    }
}

@Composable
private fun RowScope.RollupMiniStat(value: String, label: String, tone: CapacityStatus?) {
    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(value, color = tone?.color ?: Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
        Text(label, color = Z.inkMuted, fontSize = 11.sp)
    }
}

@Composable
private fun RollupDivider() {
    Box(Modifier.width(1.dp).height(26.dp).background(Z.border))
}

@Composable
private fun PlacementRow(placement: Placement, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .clickable { onClick() },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(placement.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(placement.service ?: "Unassigned service", color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (placement.needsIsolation) IsolationBadge()
            }
            placementAge(placement.at)?.let {
                Text("Waiting $it", color = placement.capacity.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
            }
            Text(placementSubtitle(placement), color = Z.inkMuted, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
    }
}

@Composable
private fun PressuredUnitRow(unit: CensusUnit) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(Z.surface)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(unit.name, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
        Text("${unit.occupied}/${unit.staffedBedCount}", color = Z.inkMuted, fontSize = 13.sp, fontFamily = FontFamily.Monospace)
        StatusChip(unit.capacity)
    }
}

@Composable
private fun PlacementRequestCard(placement: Placement) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatusChip(placement.capacity)
            if (placement.needsIsolation) IsolationBadge()
        }
        Text(placement.service ?: "Unassigned service", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            placement.source?.let { DetailChip("Source", it) }
            placement.acuityTier?.let { DetailChip("Acuity", "tier $it") }
            placement.requiredUnitType?.let { DetailChip("Needs", capacityLabel(it)) }
        }
    }
}

@Composable
private fun RecommendationCard(rec: PlacementRecommendation, isTop: Boolean) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        if (isTop) {
            Text("Recommended bed", color = Z.gold, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(rec.bedLabel, color = Z.ink, fontSize = if (isTop) 22.sp else 17.sp, fontWeight = FontWeight.SemiBold)
                Text(rec.unitName, color = Z.inkMuted, fontSize = 13.sp)
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(rec.score.toString(), color = Z.primary, fontSize = if (isTop) 28.sp else 20.sp, fontWeight = FontWeight.SemiBold, fontFamily = FontFamily.Monospace)
                Text("score", color = Z.inkMuted, fontSize = 10.sp)
            }
        }
        if (isTop) {
            PlacementChips(rec.chips)
        }
    }
}

@Composable
private fun PlacementChips(chips: List<PlacementChip>) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        chips.forEach { chip ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                val status = if (chip.ok) CapacityStatus.SUCCESS else CapacityStatus.WARNING
                Icon(status.icon, contentDescription = null, tint = status.color, modifier = Modifier.size(16.dp))
                Text(chip.label, color = Z.ink, fontSize = 13.sp, modifier = Modifier.weight(1f))
            }
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
private fun IsolationBadge() {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(CapacityStatus.WARNING.color.copy(alpha = 0.15f))
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        Icon(Icons.Filled.Warning, contentDescription = null, tint = CapacityStatus.WARNING.color, modifier = Modifier.size(13.dp))
        Text("ISO", color = CapacityStatus.WARNING.color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun PlacementActionBar(
    top: PlacementRecommendation,
    working: Boolean,
    onPlace: () -> Unit,
    onReject: () -> Unit,
) {
    val view = LocalView.current
    Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(
            onClick = {
                view.hbConfirmHaptic()
                onPlace()
            },
            enabled = !working,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            colors = ButtonDefaults.buttonColors(containerColor = if (working) Z.primary.copy(alpha = 0.55f) else Z.primary),
        ) {
            Text("Place in ${top.bedLabel}", fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
        }
        TextButton(
            onClick = {
                view.hbRejectHaptic()
                onReject()
            },
            enabled = !working,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
        ) {
            Text("Reject request", color = CapacityStatus.CRITICAL.color, fontSize = 15.sp, fontWeight = FontWeight.Medium)
        }
    }
}

@Composable
private fun CapacityLoading() {
    RetryableMessage(
        title = "Loading latest capacity",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun CapacityRecommendationLoading() {
    RetryableMessage(
        title = "Finding safe beds",
        message = "Checking availability and care constraints.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun NoSafeBed() {
    RetryableMessage(
        title = "No safe bed available",
        message = "No bed currently meets this request's safety and capability constraints.",
        tone = CapacityStatus.WARNING,
    )
}

@Composable
private fun CapacityError(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load capacity",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
}

@Composable
private fun CapacitySectionLabel(text: String) {
    Text(text, color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
}

private fun placementSubtitle(placement: Placement): String {
    val parts = listOfNotNull(
        placement.source,
        placement.acuityTier?.let { "tier $it" },
        placement.requiredUnitType?.let { "needs ${capacityLabel(it)}" },
    )
    return parts.joinToString(" / ").ifBlank { "Pending placement" }
}

private fun priorityPlacements(placements: List<Placement>): List<Placement> =
    placements.sortedWith(
        compareByDescending<Placement> { it.capacity.severity }
            .thenBy { it.at ?: "" }
            .thenBy { it.id },
    )

private fun placementAge(at: String?): String? {
    if (at == null) return null
    val inst = runCatching { OffsetDateTime.parse(at).toInstant() }.getOrNull() ?: return null
    val mins = Duration.between(inst, Instant.now()).toMinutes()
    return when {
        mins < 0 -> "scheduled"
        mins < 1 -> "just now"
        mins < 60 -> "${mins}m"
        mins < 1440 -> "${mins / 60}h"
        else -> "${mins / 1440}d"
    }
}

private fun capacityLabel(raw: String): String =
    raw.replace('_', ' ')
        .replace('-', ' ')
        .split(' ')
        .filter { it.isNotBlank() }
        .joinToString(" ") { part -> part.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
