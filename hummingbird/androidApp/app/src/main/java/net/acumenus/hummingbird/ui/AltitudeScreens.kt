package net.acumenus.hummingbird.ui

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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.ActivityEvent
import net.acumenus.hummingbird.data.AltitudeHome
import net.acumenus.hummingbird.data.AltitudeTile
import net.acumenus.hummingbird.data.AltitudeViewModel
import net.acumenus.hummingbird.data.AltitudeWorkspace
import net.acumenus.hummingbird.data.AltitudeWorkspaceItem
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.DisplayField
import net.acumenus.hummingbird.data.DrillDetail
import net.acumenus.hummingbird.data.EddyContext
import net.acumenus.hummingbird.data.ForYouItem
import net.acumenus.hummingbird.data.MobileRole
import net.acumenus.hummingbird.data.MobileRoleCatalog
import net.acumenus.hummingbird.data.PatientListRow
import net.acumenus.hummingbird.data.PatientOperationalContext
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime

private val workspaceDomains = listOf("rtdc", "capacity", "transport", "evs", "staffing", "ops", "approvals")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AltitudeHomeScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    bearer: String,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
    onOpenEddy: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id) {
        while (true) {
            vm.loadHome(bearer)
            kotlinx.coroutines.delay(15000)
        }
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Altitude Home", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
                actions = {
                    IconButton(onClick = { vm.loadHome(bearer) }) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = { auth.logout() }) {
                        Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = "Sign out")
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
            item { RoleSelector(vm.selectedRole, vm::selectRole) }
            vm.error?.let { item { ErrorPanel(it) } }
            val home = vm.home
            if (home == null && vm.loading) {
                item { LoadingPanel() }
            } else if (home != null) {
                item { PersonaGlance(home) }
                item { SectionTitle("A0 glance tiles") }
                items(home.tiles, key = { it.key }) { tile -> TileRow(tile) }
                item { SectionTitle("For You head") }
                if (home.forYouHead.isEmpty()) {
                    item { EmptyPanel("No immediate action items.") }
                }
                items(home.forYouHead, key = { it.id }) { item ->
                    ForYouAltitudeRow(
                        item = item,
                        onOpenDrill = onOpenDrill,
                        onOpenPatient = onOpenPatient,
                    )
                }
                item { SectionTitle("Recent relay activity") }
                if (home.activity.isEmpty()) {
                    item { EmptyPanel("No role-filtered activity yet.") }
                }
                items(home.activity, key = { it.eventUuid }) { event ->
                    ActivityRow(
                        event = event,
                        onOpenDrill = onOpenDrill,
                        onOpenPatient = onOpenPatient,
                        onAck = null,
                    )
                }
                item {
                    EddyButton(
                        label = "Open Eddy context for ${home.persona.title}",
                        onClick = { onOpenEddy(home.persona.roleId) },
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AltitudeWorkspaceScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    bearer: String,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, vm.selectedDomain) {
        while (true) {
            vm.loadWorkspace(bearer)
            kotlinx.coroutines.delay(15000)
        }
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Workspace", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
                actions = {
                    IconButton(onClick = { vm.loadWorkspace(bearer) }) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = { auth.logout() }) {
                        Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = "Sign out")
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
            item { RoleSelector(vm.selectedRole, vm::selectRole) }
            item { DomainSelector(vm.selectedDomain, vm::selectDomain) }
            vm.error?.let { item { ErrorPanel(it) } }
            val workspace = vm.workspace
            if (workspace == null && vm.loading) {
                item { LoadingPanel() }
            } else if (workspace != null) {
                item { WorkspaceHeader(workspace) }
                if (workspace.items.isEmpty()) {
                    item { EmptyPanel("No ${workspace.summary.label.lowercase()} rows returned.") }
                }
                items(workspace.items, key = { "${it.domain}:${it.id}" }) { item ->
                    WorkspaceItemRow(
                        item = item,
                        onOpenDrill = onOpenDrill,
                        onOpenPatient = onOpenPatient,
                    )
                }
                item { SectionTitle("Workspace activity") }
                if (workspace.activity.isEmpty()) {
                    item { EmptyPanel("No relay activity for this workspace.") }
                }
                items(workspace.activity, key = { it.eventUuid }) { event ->
                    ActivityRow(
                        event = event,
                        onOpenDrill = onOpenDrill,
                        onOpenPatient = onOpenPatient,
                        onAck = null,
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ActivityFeedScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    bearer: String,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id) {
        while (true) {
            vm.loadActivity(bearer)
            kotlinx.coroutines.delay(15000)
        }
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Activity", fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
                actions = {
                    IconButton(onClick = { vm.loadActivity(bearer) }) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = { auth.logout() }) {
                        Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = "Sign out")
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
            item { RoleSelector(vm.selectedRole, vm::selectRole) }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("Cross-persona relay", color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                    Text("Rows stay PHI-minimized until you open an authorized patient lens.", color = Z.inkMuted, fontSize = 13.sp)
                }
            }
            vm.error?.let { item { ErrorPanel(it) } }
            if (vm.activity.events.isEmpty() && vm.loading) {
                item { LoadingPanel() }
            } else if (vm.activity.events.isEmpty()) {
                item { EmptyPanel("No activity for ${vm.selectedRole.title}.") }
            }
            items(vm.activity.events, key = { it.eventUuid }) { event ->
                ActivityRow(
                    event = event,
                    onOpenDrill = onOpenDrill,
                    onOpenPatient = onOpenPatient,
                    onAck = { vm.acknowledgeActivity(bearer, event.eventUuid) },
                )
            }
            vm.activity.nextCursor?.let { cursor ->
                item {
                    OutlinedButton(
                        onClick = { vm.loadActivity(bearer, cursor) },
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text("Load older activity")
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DrillDetailScreen(
    vm: AltitudeViewModel,
    bearer: String,
    itemUuid: String,
    onBack: () -> Unit,
    onOpenPatient: (String) -> Unit,
    onOpenEddy: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, itemUuid) {
        vm.loadDrill(bearer, itemUuid)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("A2 drill", onBack) },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            vm.error?.let { item { ErrorPanel(it) } }
            val drill = vm.drill
            if (drill == null && vm.loading) {
                item { LoadingPanel() }
            } else if (drill != null) {
                item { DrillHero(drill) }
                drill.patientContextRef?.let { ref ->
                    item {
                        OutlinedButton(onClick = { onOpenPatient(ref) }, modifier = Modifier.fillMaxWidth()) {
                            Icon(Icons.Filled.Person, contentDescription = null, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.size(8.dp))
                            Text("Open A2P patient lens (${formatRef(ref)})")
                        }
                    }
                }
                item {
                    EddyButton(
                        label = "Open Eddy context",
                        onClick = { onOpenEddy(drill.patientContextRef ?: itemUuid) },
                    )
                }
                item { SectionTitle("Dependencies") }
                if (drill.dependencies.isEmpty()) {
                    item { EmptyPanel("No dependencies returned for this drill.") }
                }
                items(drill.dependencies) { fields -> FieldPanel(fields) }
                item { SectionTitle("Allowed actions") }
                if (drill.actions.isEmpty()) {
                    item { EmptyPanel("No mobile actions returned.") }
                }
                items(drill.actions, key = { it.kind }) { action ->
                    ActionRow(action.label, action.kind, action.endpoint)
                }
                item { SectionTitle("Activity") }
                if (drill.activity.isEmpty()) {
                    item { EmptyPanel("No event trail returned.") }
                }
                items(drill.activity, key = { it.eventUuid }) { event ->
                    ActivityRow(event, onOpenDrill = {}, onOpenPatient = onOpenPatient, onAck = null)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PatientContextScreen(
    vm: AltitudeViewModel,
    bearer: String,
    patientContextRef: String,
    onBack: () -> Unit,
    onOpenEddy: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, patientContextRef) {
        vm.loadPatientContext(bearer, patientContextRef)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("A2P patient lens", onBack) },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            vm.error?.let { item { ErrorPanel(it) } }
            val context = vm.patientContext
            if (context == null && vm.loading) {
                item { LoadingPanel() }
            } else if (context != null) {
                item { PatientHero(context) }
                item {
                    EddyButton(
                        label = "Open Eddy patient context",
                        onClick = { onOpenEddy(context.patient.patientContextRef ?: patientContextRef) },
                    )
                }
                item { SectionTitle("Header") }
                item { FieldPanel(context.header) }
                item { SectionTitle("Status spine") }
                patientRows(context.statusSpine)
                item { SectionTitle("Dependencies") }
                patientRows(context.dependencies)
                item { SectionTitle("Recommendations") }
                patientRows(context.recommendations)
                item { SectionTitle("Timeline") }
                patientRows(context.timeline)
                item { SectionTitle("Activity") }
                if (context.activity.isEmpty()) {
                    item { EmptyPanel("No patient-scoped activity returned.") }
                }
                items(context.activity, key = { it.eventUuid }) { event ->
                    ActivityRow(event, onOpenDrill = {}, onOpenPatient = {}, onAck = null)
                }
                item { SectionTitle("Allowed actions") }
                if (context.actions.isEmpty()) {
                    item { EmptyPanel("No patient actions returned.") }
                }
                items(context.actions, key = { it.kind }) { action ->
                    ActionRow(action.label, action.kind, action.endpoint)
                }
                item { SectionTitle("PHI policy") }
                item { FieldPanel(context.phiPolicy) }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EddyContextScreen(
    vm: AltitudeViewModel,
    bearer: String,
    scopeRef: String,
    onBack: () -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, scopeRef) {
        vm.loadEddyContext(bearer, scopeRef)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("Eddy context", onBack) },
    ) { inner ->
        LazyColumn(
            modifier = Modifier.padding(inner),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            vm.error?.let { item { ErrorPanel(it) } }
            val context = vm.eddyContext
            if (context == null && vm.loading) {
                item { LoadingPanel() }
            } else if (context != null) {
                item { EddyHero(context) }
                item { SectionTitle("Eddy-safe context") }
                item { FieldPanel(context.context) }
                item { SectionTitle("Policy") }
                item { FieldPanel(context.phiPolicy) }
                item { SectionTitle("Supported questions") }
                if (context.questionsSupported.isEmpty()) {
                    item { EmptyPanel("No suggested questions returned.") }
                }
                items(context.questionsSupported) { question ->
                    Text(
                        humanizeLocal(question),
                        color = Z.ink,
                        fontSize = 14.sp,
                        modifier = Modifier.fillMaxWidth().panel().padding(14.dp),
                    )
                }
            }
        }
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.patientRows(rows: List<PatientListRow>) {
    if (rows.isEmpty()) {
        item { EmptyPanel("None returned.") }
    } else {
        items(rows) { row -> PatientRow(row) }
    }
}

@Composable
private fun RoleSelector(selected: MobileRole, onSelect: (MobileRole) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Role", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(MobileRoleCatalog.roles, key = { it.id }) { role ->
                FilterChip(
                    selected = role.id == selected.id,
                    onClick = { onSelect(role) },
                    label = { Text(role.title) },
                )
            }
        }
    }
}

@Composable
private fun DomainSelector(selected: String, onSelect: (String) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Workspace domain", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(workspaceDomains, key = { it }) { domain ->
                FilterChip(
                    selected = domain == selected,
                    onClick = { onSelect(domain) },
                    label = { Text(humanizeLocal(domain)) },
                )
            }
        }
    }
}

@Composable
private fun PersonaGlance(home: AltitudeHome) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(home.persona.title, color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                Text(home.glanceQuestion, color = Z.inkMuted, fontSize = 13.sp)
            }
            StatusChip(home.status.capacity)
        }
        home.persona.focus?.let {
            HorizontalDivider(color = Z.border)
            Text(it, color = Z.ink, fontSize = 13.sp)
        }
        Text("Generated ${relTime(home.generatedAt) ?: "now"}", color = Z.inkMuted, fontSize = 11.sp)
    }
}

@Composable
private fun WorkspaceHeader(workspace: AltitudeWorkspace) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(workspace.summary.label, color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                Text("${workspace.altitude} / ${workspace.persona.title} / ${humanizeLocal(workspace.domain)}", color = Z.inkMuted, fontSize = 12.sp)
            }
            StatusChip(workspace.status.capacity)
        }
        Text("${workspace.summary.count ?: workspace.items.size} row${if ((workspace.summary.count ?: workspace.items.size) == 1) "" else "s"}", color = Z.inkMuted, fontSize = 13.sp)
    }
}

@Composable
private fun DrillHero(drill: DrillDetail) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(humanizeLocal(drill.domain), color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                Text("Drill ${drill.itemUuid}", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            StatusChip(drill.status.capacity)
        }
        Text(drill.explanation, color = Z.ink, fontSize = 14.sp)
    }
}

@Composable
private fun PatientHero(context: PatientOperationalContext) {
    val ref = context.patient.patientContextRef ?: "unknown"
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Person, contentDescription = null, tint = Z.primary, modifier = Modifier.size(24.dp))
            Spacer(Modifier.size(10.dp))
            Column(Modifier.weight(1f)) {
                Text(context.patient.display ?: "Authorized patient context", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                Text("Patient context ${formatRef(ref)}", color = Z.inkMuted, fontSize = 12.sp)
            }
        }
    }
}

@Composable
private fun EddyHero(context: EddyContext) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Info, contentDescription = null, tint = Z.primary, modifier = Modifier.size(22.dp))
            Spacer(Modifier.size(10.dp))
            Column(Modifier.weight(1f)) {
                Text("Eddy operational context", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                Text("${humanizeLocal(context.scopeType)} / ${formatRef(context.scopeRef)}", color = Z.inkMuted, fontSize = 12.sp)
            }
        }
        Text("Drafting context only. Human approval remains outside Eddy.", color = Z.ink, fontSize = 13.sp)
    }
}

@Composable
private fun TileRow(tile: AltitudeTile) {
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).panel(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(tile.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(tile.label, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            if (tile.provenance.isNotEmpty()) {
                Text(tile.provenance.joinToString(" / ") { "${it.label}: ${it.value}" }, color = Z.inkMuted, fontSize = 11.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
        Text(tile.value, color = tile.capacity.color, fontSize = 24.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(end = 14.dp))
    }
}

@Composable
private fun ForYouAltitudeRow(
    item: ForYouItem,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    val canDrill = supportedDrillId(item.id)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .then(if (canDrill) Modifier.clickable { onOpenDrill(item.id) } else Modifier),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(item.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(item.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            Text(item.subtitle, color = Z.inkMuted, fontSize = 13.sp)
            metaLine(item)?.let { Text(it, color = Z.inkMuted, fontSize = 11.sp) }
            item.patientContextRef?.let { ref ->
                TextButton(onClick = { onOpenPatient(ref) }, contentPadding = PaddingValues(0.dp)) {
                    Text("Patient lens ${formatRef(ref)}")
                }
            }
        }
        if (canDrill) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
        }
    }
}

@Composable
private fun WorkspaceItemRow(
    item: AltitudeWorkspaceItem,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    val drillId = item.drillItemId
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .then(if (drillId != null) Modifier.clickable { onOpenDrill(drillId) } else Modifier),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(item.capacity.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(item.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            item.subtitle?.let { Text(it, color = Z.inkMuted, fontSize = 13.sp) }
            if (item.fields.isNotEmpty()) {
                Text(item.fields.take(3).joinToString(" / ") { "${it.label}: ${it.value}" }, color = Z.inkMuted, fontSize = 11.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            item.patientContextRef?.let { ref ->
                TextButton(onClick = { onOpenPatient(ref) }, contentPadding = PaddingValues(0.dp)) {
                    Text("Patient lens ${formatRef(ref)}")
                }
            }
        }
        if (drillId != null) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
        }
    }
}

@Composable
private fun ActivityRow(
    event: ActivityEvent,
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
    onAck: (() -> Unit)?,
) {
    val status = CapacityStatus.from(event.statusValue ?: "info")
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .then(if (event.eventUuid.isNotBlank()) Modifier.clickable { onOpenDrill(event.eventUuid) } else Modifier),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(status.color))
        Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(humanizeLocal(event.eventType), color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                StatusChip(status)
            }
            Text(
                listOfNotNull(humanizeLocal(event.domain), event.actorRole?.let { "Actor: ${humanizeLocal(it)}" }, relTime(event.occurredAt)).joinToString(" / "),
                color = Z.inkMuted,
                fontSize = 12.sp,
            )
            event.patientContextRef?.let { ref ->
                TextButton(onClick = { onOpenPatient(ref) }, contentPadding = PaddingValues(0.dp)) {
                    Text("Patient context ${formatRef(ref)}")
                }
            }
            onAck?.let {
                TextButton(onClick = it, contentPadding = PaddingValues(0.dp)) {
                    Text("Acknowledge")
                }
            }
        }
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
    }
}

@Composable
private fun PatientRow(row: PatientListRow) {
    val status = row.status?.let { CapacityStatus.from(it) }
    Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(row.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
                row.subtitle?.let { Text(it, color = Z.inkMuted, fontSize = 12.sp) }
            }
            if (status != null) StatusChip(status)
        }
        row.at?.let { Text(relTime(it) ?: it, color = Z.inkMuted, fontSize = 11.sp) }
        if (row.fields.isNotEmpty()) FieldList(row.fields)
    }
}

@Composable
private fun FieldPanel(fields: List<DisplayField>) {
    Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (fields.isEmpty()) {
            Text("No details returned.", color = Z.inkMuted, fontSize = 13.sp)
        } else {
            FieldList(fields)
        }
    }
}

@Composable
private fun FieldList(fields: List<DisplayField>) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        fields.forEach { field ->
            Row(verticalAlignment = Alignment.Top) {
                Text(field.label, color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(0.42f))
                Text(field.value, color = Z.ink, fontSize = 12.sp, modifier = Modifier.weight(0.58f))
            }
        }
    }
}

@Composable
private fun ActionRow(label: String, kind: String, endpoint: String?) {
    Row(Modifier.fillMaxWidth().panel().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Z.primary, modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            Text(endpoint ?: humanizeLocal(kind), color = Z.inkMuted, fontSize = 11.sp)
        }
    }
}

@Composable
private fun EddyButton(label: String, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Icon(Icons.Filled.Info, contentDescription = null, modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(8.dp))
        Text(label)
    }
}

@Composable
private fun SectionTitle(title: String) {
    Text(title, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
}

@Composable
private fun LoadingPanel() {
    Row(Modifier.fillMaxWidth().padding(24.dp), horizontalArrangement = Arrangement.Center) {
        CircularProgressIndicator(color = Z.primary)
    }
}

@Composable
private fun EmptyPanel(text: String) {
    Row(Modifier.fillMaxWidth().panel().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Z.statusSuccess, modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(8.dp))
        Text(text, color = Z.inkMuted, fontSize = 13.sp)
    }
}

@Composable
private fun ErrorPanel(text: String) {
    Row(Modifier.fillMaxWidth().panel().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Filled.Info, contentDescription = null, tint = Z.statusWarning, modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(8.dp))
        Text(text, color = Z.ink, fontSize = 13.sp)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DetailTopBar(title: String, onBack: () -> Unit) {
    TopAppBar(
        title = { Text(title, fontWeight = FontWeight.SemiBold) },
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
}

private fun supportedDrillId(id: String): Boolean =
    id.startsWith("bedreq-") ||
        id.startsWith("barrier-") ||
        id.startsWith("transport-") ||
        id.startsWith("evs-") ||
        Regex("^[0-9a-fA-F-]{36}$").matches(id)

private fun metaLine(item: ForYouItem): String? {
    val parts = listOfNotNull(item.domain?.let(::humanizeLocal), item.unit, relTime(item.at))
    return if (parts.isEmpty()) null else parts.joinToString(" / ")
}

private fun relTime(at: String?): String? {
    if (at == null) return null
    val inst = runCatching { OffsetDateTime.parse(at).toInstant() }.getOrNull() ?: return null
    val mins = Duration.between(inst, Instant.now()).toMinutes()
    return when {
        mins < 0 -> "scheduled"
        mins < 1 -> "just now"
        mins < 60 -> "${mins}m ago"
        mins < 1440 -> "${mins / 60}h ago"
        else -> "${mins / 1440}d ago"
    }
}

private fun formatRef(ref: String): String =
    if (ref.length <= 12) ref else "...${ref.takeLast(8)}"

private fun humanizeLocal(value: String): String =
    value.replace('.', ' ')
        .replace('_', ' ')
        .replace('-', ' ')
        .split(' ')
        .filter { it.isNotBlank() }
        .joinToString(" ") { part -> part.replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() } }
