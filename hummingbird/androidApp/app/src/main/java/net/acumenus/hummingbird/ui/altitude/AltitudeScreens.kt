package net.acumenus.hummingbird.ui.altitude

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
import androidx.compose.ui.platform.LocalUriHandler
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
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z
import java.time.Duration
import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val workspaceDomains = listOf("rtdc", "capacity", "transport", "evs", "staffing", "ops", "approvals")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AltitudeHomeScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    bearer: String,
    showRoleSelector: Boolean = false,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
    onOpenEddy: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, forceError) {
        if (!forceError) {
            while (true) {
                vm.loadHome(bearer)
                kotlinx.coroutines.delay(15000)
            }
        }
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text(vm.selectedRole.homeKind.tabLabel, fontWeight = FontWeight.SemiBold) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
                actions = {
                    IconButton(onClick = { vm.loadHome(bearer) }) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = onOpenProfile) {
                        Icon(Icons.Filled.Person, contentDescription = "Profile")
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
            if (showRoleSelector) {
                item { RoleSelector(vm.selectedRole, vm::selectRole) }
            }
            if (forceError) {
                item {
                    ErrorPanel("Can't reach the server. Check your connection and try again.") {
                        vm.loadHome(bearer)
                    }
                }
            } else {
                vm.error?.let {
                    item { ErrorPanel(it) { vm.loadHome(bearer) } }
                }
            }
            val home = vm.home
            if (forceError) {
                // Test affordance state; keep the rest of the view quiet.
            } else if (home == null && vm.loading) {
                item { LoadingPanel() }
            } else if (home != null) {
                item { PersonaGlance(home) }
                item { SectionTitle("Glance") }
                items(home.tiles, key = { it.key }) { tile -> TileRow(tile) }
                item { SectionTitle("For You") }
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
                    item { EmptyPanel("No recent activity for this role.") }
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
fun DebugAltitudeExplorerScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    bearer: String,
    onBack: () -> Unit,
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
                title = { Text("Debug Altitude Explorer", fontWeight = FontWeight.SemiBold) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    navigationIconContentColor = Z.ink,
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
    showRoleSelector: Boolean = false,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenDrill: (String) -> Unit,
    onOpenPatient: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, forceError) {
        if (!forceError) {
            while (true) {
                vm.loadActivity(bearer)
                kotlinx.coroutines.delay(15000)
            }
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
                    IconButton(onClick = onOpenProfile) {
                        Icon(Icons.Filled.Person, contentDescription = "Profile")
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
            if (showRoleSelector) {
                item { RoleSelector(vm.selectedRole, vm::selectRole) }
            }
            item { ActivityFeedHeader(vm.selectedRole) }
            if (forceError) {
                item {
                    ErrorPanel("Can't reach the server. Check your connection and try again.") {
                        vm.loadActivity(bearer)
                    }
                }
            } else {
                vm.error?.let {
                    item { ErrorPanel(it) { vm.loadActivity(bearer) } }
                }
            }
            if (forceError) {
                // Test affordance state; keep the rest of the view quiet.
            } else if (vm.activity.events.isEmpty() && vm.loading) {
                item { LoadingPanel() }
            } else if (vm.activity.events.isEmpty()) {
                item { EmptyPanel("No activity for ${vm.selectedRole.title}.") }
            }
            activityGroups(vm.activity.events).forEach { group ->
                item(key = "day-${group.key}") { ActivityDayHeader(group.label, group.events.size) }
                items(group.events, key = { it.eventUuid }) { event ->
                    ActivityRow(
                        event = event,
                        onOpenDrill = onOpenDrill,
                        onOpenPatient = onOpenPatient,
                        onAck = activityAck(event) { vm.acknowledgeActivity(bearer, event.eventUuid) },
                    )
                }
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
    val uriHandler = LocalUriHandler.current

    LaunchedEffect(bearer, vm.selectedRole.id, itemUuid) {
        vm.loadDrill(bearer, itemUuid)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("Drill detail", onBack) },
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
                            Text("Open patient context")
                        }
                    }
                }
                item {
                    EddyButton(
                        label = "Open Eddy context",
                        onClick = { onOpenEddy(drill.patientContextRef ?: itemUuid) },
                    )
                }
                drill.web?.let { web ->
                    web.href?.let { href ->
                        item { WebLinkButton(web.label ?: "Open in Zephyrus") { uriHandler.openUri(href) } }
                    }
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
                    ActionRow(action.label, action.kind)
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
    val uriHandler = LocalUriHandler.current

    LaunchedEffect(bearer, vm.selectedRole.id, patientContextRef) {
        vm.loadPatientContext(bearer, patientContextRef)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("Patient context", onBack) },
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
                context.web?.let { web ->
                    web.href?.let { href ->
                        item { WebLinkButton(web.label ?: "Open in Zephyrus") { uriHandler.openUri(href) } }
                    }
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
                    ActionRow(action.label, action.kind)
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

private data class ActivityDayGroup(val key: String, val label: String, val events: List<ActivityEvent>)

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
        Text("Explorer domain", color = Z.inkMuted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
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
private fun ActivityFeedHeader(role: MobileRole) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text("Operational activity", color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
        Text("${role.title} updates from the shared care flow.", color = Z.inkMuted, fontSize = 13.sp)
        Text("Patient details stay minimized until you open an authorized context.", color = Z.inkMuted, fontSize = 12.sp)
    }
}

@Composable
private fun ActivityDayHeader(label: String, count: Int) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Text("$count update${if (count == 1) "" else "s"}", color = Z.inkMuted, fontSize = 12.sp)
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
                Text("Operational drill", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            StatusChip(drill.status.capacity)
        }
        Text(drill.explanation, color = Z.ink, fontSize = 14.sp)
    }
}

@Composable
private fun PatientHero(context: PatientOperationalContext) {
    Column(Modifier.fillMaxWidth().panel().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Person, contentDescription = null, tint = Z.primary, modifier = Modifier.size(24.dp))
            Spacer(Modifier.size(10.dp))
            Column(Modifier.weight(1f)) {
                Text(context.patient.display ?: "Authorized patient context", color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                Text("Authorized operational context", color = Z.inkMuted, fontSize = 12.sp)
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
                    Text("Patient context")
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
                    Text("Patient context")
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
                    Text("Patient context")
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
private fun ActionRow(label: String, kind: String) {
    Row(Modifier.fillMaxWidth().panel().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Z.primary, modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            Text(humanizeLocal(kind), color = Z.inkMuted, fontSize = 11.sp)
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
private fun WebLinkButton(label: String, onOpen: () -> Unit) {
    OutlinedButton(onClick = onOpen, modifier = Modifier.fillMaxWidth()) {
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(8.dp))
        Text(label.ifBlank { "Open in Zephyrus" })
    }
}

@Composable
private fun SectionTitle(title: String) {
    Text(title, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
}

@Composable
private fun LoadingPanel() {
    RetryableMessage(
        title = "Loading latest data",
        message = "This usually takes a moment.",
        tone = CapacityStatus.INFO,
        loading = true,
    )
}

@Composable
private fun EmptyPanel(text: String) {
    RetryableMessage(title = "Nothing here right now", message = text, tone = CapacityStatus.SUCCESS)
}

@Composable
private fun ErrorPanel(text: String, onRetry: (() -> Unit)? = null) {
    RetryableMessage(
        title = "Can't load this view",
        message = text,
        tone = CapacityStatus.WARNING,
        retryLabel = "Try again",
        onRetry = onRetry,
    )
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

private fun activityAck(event: ActivityEvent, onAck: () -> Unit): (() -> Unit)? =
    if (event.eventUuid.isBlank() || event.eventType == "alert.acknowledged") null else onAck

private fun activityGroups(events: List<ActivityEvent>): List<ActivityDayGroup> {
    val zone = ZoneId.systemDefault()
    val today = LocalDate.now(zone)

    return events
        .groupBy { event ->
            event.occurredAt
                ?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(zone).toLocalDate() }.getOrNull() }
                ?.toString()
                ?: "recent"
        }
        .map { (key, grouped) -> ActivityDayGroup(key, activityDayLabel(key, today), grouped) }
}

private fun activityDayLabel(key: String, today: LocalDate): String {
    if (key == "recent") return "Recent"

    val date = runCatching { LocalDate.parse(key) }.getOrNull() ?: return "Recent"

    return when (date) {
        today -> "Today"
        today.minusDays(1) -> "Yesterday"
        else -> date.format(DateTimeFormatter.ofPattern("MMM d"))
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
