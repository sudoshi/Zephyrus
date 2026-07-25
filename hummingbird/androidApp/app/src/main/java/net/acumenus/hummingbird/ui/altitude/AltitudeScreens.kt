package net.acumenus.hummingbird.ui.altitude

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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.platform.testTag
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
import net.acumenus.hummingbird.data.EddyChatRole
import net.acumenus.hummingbird.data.EddyChatTurn
import net.acumenus.hummingbird.data.EddyApprovalPreview
import net.acumenus.hummingbird.data.EddyApprovalSummary
import net.acumenus.hummingbird.data.EddyApprovalDecisionResult
import net.acumenus.hummingbird.data.EddyConversationDetail
import net.acumenus.hummingbird.data.EddyConversationMessage
import net.acumenus.hummingbird.data.EddyConversationSummary
import net.acumenus.hummingbird.data.EddyContext
import net.acumenus.hummingbird.data.ForYouItem
import net.acumenus.hummingbird.data.MobileRole
import net.acumenus.hummingbird.data.MobileRoleCatalog
import net.acumenus.hummingbird.data.PatientListRow
import net.acumenus.hummingbird.data.PatientOperationalContext
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.StatusChip
import net.acumenus.hummingbird.ui.components.formatOperationalAge
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.flow.FlowBoardMode
import net.acumenus.hummingbird.ui.flow.FlowMapScreen
import net.acumenus.hummingbird.ui.flow.ListMapSegment
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z
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
    var boardMode by remember { mutableStateOf(FlowBoardMode.List) }

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
        Column(Modifier.padding(inner).fillMaxSize()) {
        ListMapSegment(
            mode = boardMode,
            onSelect = { boardMode = it },
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp),
        )
        if (boardMode == FlowBoardMode.Map) {
            FlowMapScreen(
                auth = auth,
                persona = vm.selectedRole.id,
                modifier = Modifier.weight(1f).fillMaxWidth(),
                // Discharge-leverage lane rows (hospitalist/intensivist) drill
                // into the existing A2P patient context.
                onOpenPatient = onOpenPatient,
            )
        } else {
        HbRefreshable(
            refreshing = vm.loading,
            onRefresh = { vm.loadHome(bearer) },
            modifier = Modifier.weight(1f),
        ) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
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
                item { SectionTitle("Right now") }
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
                item { SectionTitle("Recent team activity") }
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
                item { SectionTitle("Recent team activity") }
                if (workspace.activity.isEmpty()) {
                    item { EmptyPanel("No recent team activity here.") }
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
        topBar = { DetailTopBar("Details", onBack) },
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
    onOpenHistory: () -> Unit,
    onOpenApprovals: () -> Unit,
) {
    var draft by remember(scopeRef) { mutableStateOf("") }

    LaunchedEffect(bearer, vm.selectedRole.id, scopeRef) {
        vm.beginEddyChat(scopeRef)
        vm.loadEddyContext(bearer, scopeRef)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            DetailTopBar("Eddy", onBack) {
                IconButton(onClick = onOpenApprovals) {
                    Icon(Icons.Filled.CheckCircle, contentDescription = "Pending Eddy approvals")
                }
                IconButton(onClick = onOpenHistory) {
                    Icon(Icons.Filled.History, contentDescription = "Conversation history")
                }
            }
        },
        bottomBar = {
            EddyChatComposer(
                draft = draft,
                sending = vm.eddyChatLoading,
                error = vm.eddyChatError,
                onDraftChange = { draft = it },
                onSend = { message ->
                    vm.sendEddyMessage(bearer, scopeRef, message)
                    draft = ""
                },
            )
        },
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
                item { SectionTitle("Suggested operational questions") }
                if (context.questionsSupported.isEmpty()) {
                    item { EmptyPanel("No suggested questions returned.") }
                }
                items(context.questionsSupported) { question ->
                    OutlinedButton(
                        onClick = { draft = humanizeLocal(question) },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text(humanizeLocal(question), modifier = Modifier.fillMaxWidth()) }
                }
                if (vm.eddyChatTurns.isNotEmpty()) {
                    item { SectionTitle("Conversation") }
                    items(vm.eddyChatTurns) { turn -> EddyChatBubble(turn) }
                }
            }
        }
    }
}

/** Testable, no-cache-only staff Eddy composer. It exposes no action or approval control. */
@Composable
internal fun EddyChatComposer(
    draft: String,
    sending: Boolean,
    error: String?,
    onDraftChange: (String) -> Unit,
    onSend: (String) -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .background(Z.surface)
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        error?.let { ErrorPanel(it) }
        OutlinedTextField(
            value = draft,
            onValueChange = { if (it.length <= 8_000) onDraftChange(it) },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Ask Eddy about operations") },
            supportingText = {
                Text("Use the authorized context; do not add unnecessary patient details. Eddy suggests; people decide.")
            },
            enabled = !sending,
            minLines = 1,
            maxLines = 4,
        )
        Button(
            onClick = {
                val message = draft.trim()
                if (message.isNotEmpty()) onSend(message)
            },
            enabled = draft.isNotBlank() && !sending,
            modifier = Modifier.fillMaxWidth(),
            colors = ButtonDefaults.buttonColors(containerColor = Z.primary),
        ) {
            Text(if (sending) "Eddy is assessing…" else "Ask Eddy")
        }
    }
}

@Composable
private fun EddyChatBubble(turn: EddyChatTurn) {
    val isUser = turn.role == EddyChatRole.USER
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (isUser) Arrangement.End else Arrangement.Start) {
        Column(
            modifier = if (isUser) {
                Modifier
                    .fillMaxWidth(0.82f)
                    .background(Z.primary, RoundedCornerShape(16.dp))
                    .padding(12.dp)
            } else {
                Modifier.fillMaxWidth(0.82f).panel().padding(12.dp)
            },
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            if (turn.pending) {
                Text("Eddy is assessing…", color = Z.inkMuted, fontSize = 13.sp)
            } else {
                Text(turn.text, color = if (isUser) Z.bg else Z.ink, fontSize = 14.sp)
                if (!isUser && turn.provider != null) {
                    Text("Via ${turn.provider}", color = Z.inkMuted, fontSize = 11.sp)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EddyConversationHistoryScreen(
    vm: AltitudeViewModel,
    bearer: String,
    onBack: () -> Unit,
    onOpenConversation: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id) {
        vm.loadEddyConversationHistory(bearer)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            DetailTopBar("Eddy history", onBack) {
                IconButton(onClick = { vm.loadEddyConversationHistory(bearer) }) {
                    Icon(Icons.Filled.Refresh, contentDescription = "Refresh conversation history")
                }
            }
        },
    ) { inner ->
        EddyConversationHistoryContent(
            history = vm.eddyConversationHistory,
            loading = vm.eddyHistoryLoading,
            error = vm.eddyHistoryError,
            modifier = Modifier.padding(inner),
            onOpenConversation = onOpenConversation,
        )
    }
}

/** Native presentation of the server-side-only conversation list; it writes no device cache. */
@Composable
internal fun EddyConversationHistoryContent(
    history: List<EddyConversationSummary>,
    loading: Boolean,
    error: String?,
    modifier: Modifier = Modifier,
    onOpenConversation: (String) -> Unit,
) {
    LazyColumn(
        modifier = modifier,
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text(
                "Your authorized Eddy history is read from the server and is not retained offline on this device.",
                color = Z.inkMuted,
                fontSize = 13.sp,
            )
        }
        error?.let { item { ErrorPanel(it) } }
        if (loading && history.isEmpty()) {
            item { LoadingPanel() }
        } else if (!loading && history.isEmpty() && error == null) {
            item { EmptyPanel("No Eddy conversations are available.") }
        } else {
            items(history, key = { it.id }) { conversation ->
                EddyConversationRow(conversation, onOpen = { onOpenConversation(conversation.id) })
            }
        }
    }
}

@Composable
private fun EddyConversationRow(conversation: EddyConversationSummary, onOpen: () -> Unit) {
    OutlinedButton(onClick = onOpen, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(conversation.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            val source = if (conversation.origin == "hummingbird") "Hummingbird" else "Zephyrus"
            Text("$source · ${humanizeLocal(conversation.surface)}${relTime(conversation.updatedAt)?.let { " · $it" } ?: ""}", color = Z.inkMuted, fontSize = 12.sp)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EddyConversationDetailScreen(
    vm: AltitudeViewModel,
    bearer: String,
    conversationId: String,
    onBack: () -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id, conversationId) {
        vm.loadEddyConversation(bearer, conversationId)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("Eddy conversation", onBack) },
    ) { inner ->
        EddyConversationDetailContent(
            conversation = vm.eddyConversationDetail,
            loading = vm.eddyHistoryLoading,
            error = vm.eddyHistoryError,
            modifier = Modifier.padding(inner),
        )
    }
}

@Composable
internal fun EddyConversationDetailContent(
    conversation: EddyConversationDetail?,
    loading: Boolean,
    error: String?,
    modifier: Modifier = Modifier,
) {
    LazyColumn(
        modifier = modifier,
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        error?.let { item { ErrorPanel(it) } }
        if (conversation == null && loading) {
            item { LoadingPanel() }
        } else if (conversation != null) {
            item {
                Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(conversation.title, color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                    Text("Read-only server history · ${humanizeLocal(conversation.surface)}", color = Z.inkMuted, fontSize = 12.sp)
                }
            }
            if (conversation.messages.isEmpty()) {
                item { EmptyPanel("No messages are available in this conversation.") }
            } else {
                items(conversation.messages) { message -> EddyConversationMessageBubble(message) }
            }
        }
    }
}

@Composable
private fun EddyConversationMessageBubble(message: EddyConversationMessage) {
    val isUser = message.role == EddyChatRole.USER
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (isUser) Arrangement.End else Arrangement.Start) {
        Column(
            modifier = if (isUser) {
                Modifier.fillMaxWidth(0.82f).background(Z.primary, RoundedCornerShape(16.dp)).padding(12.dp)
            } else {
                Modifier.fillMaxWidth(0.82f).panel().padding(12.dp)
            },
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Text(message.content, color = if (isUser) Z.bg else Z.ink, fontSize = 14.sp)
            val attribution = listOfNotNull(
                if (isUser) "You" else message.provider?.let { "Via $it" } ?: "Eddy",
                relTime(message.createdAt),
            ).joinToString(" · ")
            Text(attribution, color = if (isUser) Z.bg.copy(alpha = 0.76f) else Z.inkMuted, fontSize = 11.sp)
            if (message.hasProposedAction) {
                Text(
                    "A draft action remains subject to separate human review; this history view cannot approve it.",
                    color = if (isUser) Z.bg.copy(alpha = 0.86f) else Z.inkMuted,
                    fontSize = 11.sp,
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EddyApprovalsScreen(
    vm: AltitudeViewModel,
    bearer: String,
    onBack: () -> Unit,
    onOpenApproval: (String) -> Unit,
) {
    LaunchedEffect(bearer, vm.selectedRole.id) {
        vm.loadEddyApprovals(bearer)
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            DetailTopBar("Eddy approvals", onBack) {
                IconButton(onClick = { vm.loadEddyApprovals(bearer) }) {
                    Icon(Icons.Filled.Refresh, contentDescription = "Refresh Eddy approvals")
                }
            }
        },
    ) { inner ->
        EddyApprovalsContent(
            approvals = vm.eddyApprovals,
            loading = vm.eddyApprovalsLoading,
            error = vm.eddyApprovalsError,
            modifier = Modifier.padding(inner),
            onOpenApproval = onOpenApproval,
        )
    }
}

/** A no-store-only inbox of server-authorized candidates for a qualified human decision. */
@Composable
internal fun EddyApprovalsContent(
    approvals: List<EddyApprovalSummary>,
    loading: Boolean,
    error: String?,
    modifier: Modifier = Modifier,
    onOpenApproval: (String) -> Unit,
) {
    LazyColumn(
        modifier = modifier,
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text("Human decision queue", color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                Text(
                    "Eddy may propose an operational action. A qualified person must review the live server preview and explicitly decide; actions are never queued offline.",
                    color = Z.inkMuted,
                    fontSize = 13.sp,
                )
            }
        }
        error?.let { item { ErrorPanel(it) } }
        if (loading && approvals.isEmpty()) {
            item { LoadingPanel() }
        } else if (!loading && approvals.isEmpty() && error == null) {
            item { EmptyPanel("No pending Eddy approvals are available.") }
        } else {
            items(approvals, key = { it.approvalUuid }) { approval ->
                EddyApprovalRow(approval, onOpen = { onOpenApproval(approval.approvalUuid) })
            }
        }
    }
}

@Composable
private fun EddyApprovalRow(approval: EddyApprovalSummary, onOpen: () -> Unit) {
    OutlinedButton(onClick = onOpen, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(approval.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(
                "${approval.tier} · ${(approval.risk ?: "review").uppercase()} · ${humanizeLocal(approval.surface)}",
                color = Z.inkMuted,
                fontSize = 12.sp,
            )
            Text("Open live preview before deciding", color = Z.primary, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EddyApprovalDetailScreen(
    vm: AltitudeViewModel,
    bearer: String,
    approvalId: String,
    onBack: () -> Unit,
) {
    var pendingDecision by remember(approvalId) { mutableStateOf<String?>(null) }

    LaunchedEffect(bearer, vm.selectedRole.id, approvalId) {
        vm.loadEddyApproval(bearer, approvalId)
    }

    val preview = vm.eddyApprovalPreview
    val outcome = vm.eddyApprovalDecision
    val decision = pendingDecision
    if (decision != null && preview != null && outcome == null) {
        val approving = decision == "approved"
        AlertDialog(
            onDismissRequest = { pendingDecision = null },
            title = { Text(if (approving) "Record approval?" else "Record rejection?") },
            text = {
                Text(
                    "You are about to ${if (approving) "approve" else "reject"} this live operational proposal. " +
                        "This is your human decision, not Eddy's; it is sent now and is never queued for later offline delivery.",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    pendingDecision = null
                    vm.decideEddyApproval(bearer, approvalId, decision)
                }) {
                    Text(if (approving) "Record approval" else "Record rejection")
                }
            },
            dismissButton = {
                TextButton(onClick = { pendingDecision = null }) { Text("Cancel") }
            },
        )
    }

    Scaffold(
        containerColor = Z.bg,
        topBar = { DetailTopBar("Review Eddy proposal", onBack) },
        bottomBar = {
            if (preview != null && outcome == null) {
                EddyApprovalDecisionBar(
                    working = vm.eddyApprovalWorking,
                    onApprove = { pendingDecision = "approved" },
                    onReject = { pendingDecision = "rejected" },
                )
            }
        },
    ) { inner ->
        EddyApprovalDetailContent(
            preview = preview,
            outcome = outcome,
            loading = vm.eddyApprovalsLoading,
            error = vm.eddyApprovalsError,
            modifier = Modifier.padding(inner),
        )
    }
}

/** Read-only preview plus an explicit human decision boundary; no local command queue exists. */
@Composable
internal fun EddyApprovalDetailContent(
    preview: EddyApprovalPreview?,
    outcome: EddyApprovalDecisionResult?,
    loading: Boolean,
    error: String?,
    modifier: Modifier = Modifier,
) {
    LazyColumn(
        modifier = modifier,
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        error?.let { item { ErrorPanel(it) } }
        if (preview == null && outcome == null && loading) {
            item { LoadingPanel() }
        } else if (outcome != null) {
            item {
                Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Decision recorded", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                    Text(
                        "The server recorded your human ${outcome.decision} decision. Eddy did not make this decision.",
                        color = Z.inkMuted,
                        fontSize = 13.sp,
                    )
                }
            }
        } else if (preview != null) {
            item {
                Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    Text(preview.summary.title, color = Z.ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                    Text(
                        "${preview.summary.tier} · ${(preview.summary.risk ?: "review").uppercase()} · ${humanizeLocal(preview.summary.surface)}",
                        color = Z.inkMuted,
                        fontSize = 12.sp,
                    )
                    preview.preview?.let { Text(it, color = Z.ink, fontSize = 14.sp) }
                    Text("Live server preview · not retained offline", color = Z.primary, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            preview.rationale?.let { rationale ->
                item {
                    Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Text("Why Eddy proposed this", color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                        Text(rationale, color = Z.inkMuted, fontSize = 13.sp)
                    }
                }
            }
            preview.runnerUp?.let { runnerUp ->
                item {
                    Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Text("Alternative considered", color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                        Text(runnerUp, color = Z.inkMuted, fontSize = 13.sp)
                    }
                }
            }
            if (preview.params.isNotEmpty()) {
                item {
                    Column(Modifier.fillMaxWidth().panel().padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                        Text("Operational details", color = Z.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                        preview.params.forEach { parameter ->
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                                Text(humanizeLocal(parameter.name), color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(0.42f))
                                Text(parameter.value, color = Z.ink, fontSize = 12.sp, modifier = Modifier.weight(0.58f))
                            }
                        }
                    }
                }
            }
            item {
                Text(
                    "Before you decide, confirm the current operational situation. The server will validate your current authorization and persona; a decision needs a live connection and is never sent automatically.",
                    color = Z.inkMuted,
                    fontSize = 13.sp,
                    modifier = Modifier.testTag("eddy-approval-live-decision-boundary"),
                )
            }
        }
    }
}

@Composable
private fun EddyApprovalDecisionBar(
    working: Boolean,
    onApprove: () -> Unit,
    onReject: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().background(Z.surface).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(
            onClick = onApprove,
            enabled = !working,
            modifier = Modifier.fillMaxWidth(),
            colors = ButtonDefaults.buttonColors(containerColor = Z.primary),
        ) {
            Text(if (working) "Recording decision…" else "Approve after review")
        }
        OutlinedButton(onClick = onReject, enabled = !working, modifier = Modifier.fillMaxWidth()) {
            Text("Reject after review")
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
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
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
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.weight(1f)) {
                Text(workspace.summary.label, color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                // Worker language only — the altitude coordinate stays out of the pixels.
                Text("${workspace.persona.title} · ${humanizeLocal(workspace.domain)}", color = Z.inkMuted, fontSize = 12.sp)
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
            // Provenance metadata stays backstage (it lives in the drill); the glance only
            // speaks up when the number can't be trusted.
            val stale = tile.provenance.firstOrNull { it.label.equals("Stale", ignoreCase = true) }
                ?.value?.equals("No", ignoreCase = true) == false
            if (stale) {
                Text("Data may be stale", color = Z.statusWarning, fontSize = 11.sp)
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
private fun DetailTopBar(
    title: String,
    onBack: () -> Unit,
    actions: @Composable RowScope.() -> Unit = {},
) {
    TopAppBar(
        title = { Text(title, fontWeight = FontWeight.SemiBold) },
        navigationIcon = {
            IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
            }
        },
        actions = actions,
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
    return formatOperationalAge(inst)
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
