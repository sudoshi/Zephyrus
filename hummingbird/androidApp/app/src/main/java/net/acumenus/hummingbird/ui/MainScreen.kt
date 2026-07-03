package net.acumenus.hummingbird.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AltitudeViewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.CensusUnit
import net.acumenus.hummingbird.data.EvsTurn
import net.acumenus.hummingbird.data.HomeKind
import net.acumenus.hummingbird.data.HouseBrief
import net.acumenus.hummingbird.data.MobileRoleCatalog
import net.acumenus.hummingbird.data.MobileRole
import net.acumenus.hummingbird.data.ORRoom
import net.acumenus.hummingbird.data.OpsApproval
import net.acumenus.hummingbird.data.Opportunity
import net.acumenus.hummingbird.data.PdsaCycle
import net.acumenus.hummingbird.data.Placement
import net.acumenus.hummingbird.data.StaffingReq
import net.acumenus.hummingbird.data.TransportJob
import net.acumenus.hummingbird.ui.altitude.ActivityFeedScreen
import net.acumenus.hummingbird.ui.altitude.AltitudeHomeScreen
import net.acumenus.hummingbird.ui.altitude.DebugAltitudeExplorerScreen
import net.acumenus.hummingbird.ui.altitude.DrillDetailScreen
import net.acumenus.hummingbird.ui.altitude.EddyContextScreen
import net.acumenus.hummingbird.ui.altitude.PatientContextScreen
import net.acumenus.hummingbird.ui.capacity.ApprovalDetailScreen
import net.acumenus.hummingbird.ui.capacity.CapacityDemandScreen
import net.acumenus.hummingbird.ui.capacity.HouseCapacityScreen
import net.acumenus.hummingbird.ui.capacity.PlacementDetailScreen
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.evs.BedTurnsScreen
import net.acumenus.hummingbird.ui.evs.TurnDetailScreen
import net.acumenus.hummingbird.ui.executive.HouseBriefScreen
import net.acumenus.hummingbird.ui.executive.StrainDetailScreen
import net.acumenus.hummingbird.ui.improvement.ImprovementScreen
import net.acumenus.hummingbird.ui.improvement.OpportunityDetailScreen
import net.acumenus.hummingbird.ui.improvement.PdsaDetailScreen
import net.acumenus.hummingbird.ui.or.CaseDetailScreen
import net.acumenus.hummingbird.ui.or.ORBoardScreen
import net.acumenus.hummingbird.ui.staffing.StaffingRequestDetailScreen
import net.acumenus.hummingbird.ui.staffing.StaffingScreen
import net.acumenus.hummingbird.ui.theme.Z
import net.acumenus.hummingbird.ui.transport.TransportJobDetailScreen
import net.acumenus.hummingbird.ui.transport.TransportJobsScreen

private enum class HummingbirdTab { Home, ForYou, Activity }

data class HummingbirdLaunchConfig(
    val roleId: String? = null,
    val tab: String? = null,
    val openUnitId: Int? = null,
    val openTarget: String? = null,
    val forceError: Boolean = false,
    val debugExplorer: Boolean = false,
)

private sealed interface AltitudeDetail {
    data class Drill(val itemUuid: String) : AltitudeDetail
    data class Patient(val patientContextRef: String) : AltitudeDetail
    data class Eddy(val scopeRef: String) : AltitudeDetail
    data class Transport(val job: TransportJob, val webLink: String?) : AltitudeDetail
    data class Evs(val turn: EvsTurn, val webLink: String?) : AltitudeDetail
    data class ORCase(val room: ORRoom, val webLink: String?) : AltitudeDetail
    data class Approval(val approval: OpsApproval, val webLink: String?) : AltitudeDetail
    data class Strain(val brief: HouseBrief) : AltitudeDetail
    data class StaffingRequest(val request: StaffingReq, val webLink: String?) : AltitudeDetail
    data class Pdsa(val cycle: PdsaCycle) : AltitudeDetail
    data class ImprovementOpportunity(val opportunity: Opportunity) : AltitudeDetail
    data class PlacementDecision(val placement: Placement) : AltitudeDetail
    data class Unit(val unit: CensusUnit, val webLink: String?) : AltitudeDetail
    data object Profile : AltitudeDetail
    data object ProfileConfirmation : AltitudeDetail
    data object DebugExplorer : AltitudeDetail
}

@Composable
fun MainScreen(
    auth: AuthViewModel,
    launchConfig: HummingbirdLaunchConfig = HummingbirdLaunchConfig(),
) {
    val vm: AltitudeViewModel = viewModel()
    var topTab by remember { mutableStateOf(tabFromLaunch(launchConfig.tab)) }
    var detail by remember { mutableStateOf<AltitudeDetail?>(null) }
    var appliedLaunchConfig by remember { mutableStateOf(false) }
    val bearer = auth.accessToken ?: ""
    val selectedRole = vm.selectedRole
    val homeKind = selectedRole.homeKind

    LaunchedEffect(launchConfig) {
        if (!appliedLaunchConfig) {
            appliedLaunchConfig = true
            MobileRoleCatalog.roles.firstOrNull { it.id == launchConfig.roleId }?.let(vm::selectRole)
            detail = if (launchConfig.debugExplorer) AltitudeDetail.DebugExplorer else launchDetail(launchConfig)
        }
    }
    LaunchedEffect(auth.me?.id, launchConfig.roleId) {
        if (launchConfig.roleId == null) {
            vm.loadProfileForUser(auth.me)
        }
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }
    BackHandler(enabled = detail != null) { detail = null }

    Scaffold(
        containerColor = Z.bg,
        bottomBar = {
            if (detail == null) {
                NavigationBar(containerColor = Z.surface) {
                    val colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = Z.primary,
                        selectedTextColor = Z.primary,
                        indicatorColor = Z.primary.copy(alpha = 0.15f),
                        unselectedIconColor = Z.inkMuted,
                        unselectedTextColor = Z.inkMuted,
                    )
                    NavigationBarItem(
                        selected = topTab == HummingbirdTab.Home,
                        onClick = { topTab = HummingbirdTab.Home },
                        icon = { Icon(iconForRole(selectedRole), contentDescription = null) },
                        label = { Text(homeKind.tabLabel) },
                        colors = colors,
                    )
                    NavigationBarItem(
                        selected = topTab == HummingbirdTab.ForYou,
                        onClick = { topTab = HummingbirdTab.ForYou },
                        icon = { Icon(Icons.Filled.CheckCircle, contentDescription = null) },
                        label = { Text("For You") },
                        colors = colors,
                    )
                    NavigationBarItem(
                        selected = topTab == HummingbirdTab.Activity,
                        onClick = { topTab = HummingbirdTab.Activity },
                        icon = { Icon(Icons.Filled.Notifications, contentDescription = null) },
                        label = { Text("Activity") },
                        colors = colors,
                    )
                }
            }
        },
    ) { inner ->
        Box(Modifier.fillMaxSize().padding(inner)) {
            val currentDetail = detail
            if (currentDetail == null) {
                when (topTab) {
                    HummingbirdTab.Home -> when (homeKind) {
                        HomeKind.TransportJobs -> TransportJobsScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenJob = { job, webLink -> detail = AltitudeDetail.Transport(job, webLink) },
                        )
                        HomeKind.EvsTurns -> BedTurnsScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenTurn = { turn, webLink -> detail = AltitudeDetail.Evs(turn, webLink) },
                        )
                        HomeKind.HouseCapacity -> HouseCapacityScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenPlacement = { placement -> detail = AltitudeDetail.PlacementDecision(placement) },
                        )
                        HomeKind.OrBoard -> ORBoardScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenRoom = { room, webLink -> detail = AltitudeDetail.ORCase(room, webLink) },
                        )
                        HomeKind.CapacityDemand -> CapacityDemandScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenApproval = { approval, webLink -> detail = AltitudeDetail.Approval(approval, webLink) },
                        )
                        HomeKind.HouseBrief -> HouseBriefScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenStrain = { brief -> detail = AltitudeDetail.Strain(brief) },
                        )
                        HomeKind.Staffing -> StaffingScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenRequest = { request, webLink -> detail = AltitudeDetail.StaffingRequest(request, webLink) },
                        )
                        HomeKind.Improvement -> ImprovementScreen(
                            auth = auth,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenCycle = { cycle -> detail = AltitudeDetail.Pdsa(cycle) },
                            onOpenOpportunity = { opportunity -> detail = AltitudeDetail.ImprovementOpportunity(opportunity) },
                        )
                        HomeKind.Census -> AltitudeHomeScreen(
                            auth = auth,
                            vm = vm,
                            bearer = bearer,
                            forceError = launchConfig.forceError,
                            onOpenProfile = { detail = AltitudeDetail.Profile },
                            onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                            onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                            onOpenEddy = { detail = AltitudeDetail.Eddy(it) },
                        )
                    }
                    HummingbirdTab.ForYou -> ForYouScreen(
                        auth = auth,
                        selectedRole = selectedRole,
                        selectedUnitName = vm.confirmedProfile.unitName,
                        forceError = launchConfig.forceError,
                        onOpenProfile = { detail = AltitudeDetail.Profile },
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                        onOpenUnit = { unit, webLink -> detail = AltitudeDetail.Unit(unit, webLink) },
                    )
                    HummingbirdTab.Activity -> ActivityFeedScreen(
                        auth = auth,
                        vm = vm,
                        bearer = bearer,
                        forceError = launchConfig.forceError,
                        onOpenProfile = { detail = AltitudeDetail.Profile },
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                }
            } else {
                when (currentDetail) {
                    is AltitudeDetail.Drill -> DrillDetailScreen(
                        vm = vm,
                        bearer = bearer,
                        itemUuid = currentDetail.itemUuid,
                        onBack = { detail = null },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                        onOpenEddy = { detail = AltitudeDetail.Eddy(it) },
                    )
                    is AltitudeDetail.Patient -> PatientContextScreen(
                        vm = vm,
                        bearer = bearer,
                        patientContextRef = currentDetail.patientContextRef,
                        onBack = { detail = null },
                        onOpenEddy = { detail = AltitudeDetail.Eddy(it) },
                    )
                    is AltitudeDetail.Eddy -> EddyContextScreen(
                        vm = vm,
                        bearer = bearer,
                        scopeRef = currentDetail.scopeRef,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.Transport -> TransportJobDetailScreen(
                        auth = auth,
                        job = currentDetail.job,
                        webLink = currentDetail.webLink,
                        onBack = { detail = null },
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                    is AltitudeDetail.Evs -> TurnDetailScreen(
                        auth = auth,
                        turn = currentDetail.turn,
                        webLink = currentDetail.webLink,
                        onBack = { detail = null },
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                    is AltitudeDetail.PlacementDecision -> PlacementDetailScreen(
                        auth = auth,
                        placement = currentDetail.placement,
                        onBack = { detail = null },
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                    is AltitudeDetail.ORCase -> CaseDetailScreen(
                        room = currentDetail.room,
                        webLink = currentDetail.webLink,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.Approval -> ApprovalDetailScreen(
                        auth = auth,
                        approval = currentDetail.approval,
                        webLink = currentDetail.webLink,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.Strain -> StrainDetailScreen(
                        brief = currentDetail.brief,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.StaffingRequest -> StaffingRequestDetailScreen(
                        auth = auth,
                        request = currentDetail.request,
                        webLink = currentDetail.webLink,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.Pdsa -> PdsaDetailScreen(
                        cycle = currentDetail.cycle,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.ImprovementOpportunity -> OpportunityDetailScreen(
                        opportunity = currentDetail.opportunity,
                        onBack = { detail = null },
                    )
                    is AltitudeDetail.Unit -> UnitDetailScreen(
                        unit = currentDetail.unit,
                        webLink = currentDetail.webLink,
                        onBack = { detail = null },
                    )
                    AltitudeDetail.Profile -> ProfileSettingsScreen(
                        auth = auth,
                        vm = vm,
                        onBack = { detail = null },
                        onOpenProfileConfirmation = { detail = AltitudeDetail.ProfileConfirmation },
                        onOpenDebugExplorer = { detail = AltitudeDetail.DebugExplorer },
                        onSignOut = auth::logout,
                    )
                    AltitudeDetail.ProfileConfirmation -> ProfileConfirmationScreen(
                        auth = auth,
                        vm = vm,
                        bearer = bearer,
                        onBack = { detail = AltitudeDetail.Profile },
                        onComplete = { detail = AltitudeDetail.Profile },
                    )
                    AltitudeDetail.DebugExplorer -> DebugAltitudeExplorerScreen(
                        auth = auth,
                        vm = vm,
                        bearer = bearer,
                        onBack = { detail = null },
                        onOpenDrill = { detail = AltitudeDetail.Drill(it) },
                        onOpenPatient = { detail = AltitudeDetail.Patient(it) },
                    )
                }
            }
        }
    }
}

private fun iconForRole(role: MobileRole): ImageVector = when (role.androidIconName) {
    "bar_chart", "business_center", "event_note" -> Icons.Filled.Dashboard
    "cleaning_services", "directions_walk", "sync" -> Icons.Filled.CheckCircle
    "group", "groups", "medical_services", "stethoscope", "monitor_heart",
    "bed", "apartment", "local_hospital" -> Icons.Filled.Home
    else -> Icons.Filled.Home
}

private fun tabFromLaunch(tab: String?): HummingbirdTab = when (tab?.lowercase()) {
    "foryou", "for_you", "for-you" -> HummingbirdTab.ForYou
    "activity" -> HummingbirdTab.Activity
    else -> HummingbirdTab.Home
}

private fun launchDetail(config: HummingbirdLaunchConfig): AltitudeDetail? {
    config.openTarget?.let { target ->
        val parts = target.split(':', limit = 2)
        val kind = parts.getOrNull(0)?.lowercase()
        val value = parts.getOrNull(1)?.takeIf { it.isNotBlank() }
        return when (kind) {
            "patient" -> value?.let(AltitudeDetail::Patient)
            "eddy" -> value?.let(AltitudeDetail::Eddy)
            "drill" -> value?.let(AltitudeDetail::Drill)
            else -> AltitudeDetail.Drill(target)
        }
    }

    return config.openUnitId?.let { AltitudeDetail.Drill("unit-$it") }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ProfileSettingsScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    onBack: () -> Unit,
    onOpenProfileConfirmation: () -> Unit,
    onOpenDebugExplorer: () -> Unit,
    onSignOut: () -> Unit,
) {
    val selectedRole = vm.selectedRole
    val confirmedProfile = vm.confirmedProfile
    val confirmedRole = confirmedProfile.roleId?.let(MobileRoleCatalog::byId)
    val canSwitchRoles = auth.me?.isAdmin == true || auth.me?.username?.contains("demo", ignoreCase = true) == true

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Profile", fontWeight = FontWeight.SemiBold) },
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
            item {
                ProfileSection("Role confirmation") {
                    ProfileRow("Confirmed role", confirmedRole?.title ?: "Not confirmed")
                    HorizontalDivider(color = Z.border)
                    ProfileRow("Current selection", selectedRole.title)
                    HorizontalDivider(color = Z.border)
                    ProfileRow("Home", selectedRole.homeKind.tabLabel)
                    HorizontalDivider(color = Z.border)
                    ProfileRow("Glance question", selectedRole.question)
                    OutlinedButton(
                        onClick = onOpenProfileConfirmation,
                        modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                    ) {
                        Text("Confirm shift profile", fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            item {
                ProfileSection("Unit assignment") {
                    ProfileRow("Assignment scope", assignmentScope(selectedRole))
                    HorizontalDivider(color = Z.border)
                    ProfileRow("Confirmed unit", confirmedProfile.unitName ?: "Not confirmed")
                    HorizontalDivider(color = Z.border)
                    ProfileRow("Default domain", selectedRole.defaultDomain)
                }
            }
            item {
                ProfileSection("Notification preferences") {
                    Text(
                        "Tier preferences and quiet hours arrive with push setup.",
                        color = Z.inkMuted,
                        fontSize = 13.sp,
                    )
                }
            }
            item {
                ProfileSection("Account") {
                    Button(
                        onClick = onSignOut,
                        colors = ButtonDefaults.buttonColors(
                            containerColor = Z.statusCritical,
                            contentColor = Color.White,
                        ),
                        modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                    ) {
                        Text("Sign out", fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            if (canSwitchRoles) {
                item {
                    ProfileSection("Debug role switcher") {
                        Text(
                            "Demo and admin users can preview personas without changing the production role assignment.",
                            color = Z.inkMuted,
                            fontSize = 13.sp,
                        )
                        OutlinedButton(
                            onClick = onOpenDebugExplorer,
                            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                        ) {
                            Text("Open debug altitude explorer", fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
                items(MobileRoleCatalog.roles, key = { it.id }) { role ->
                    OutlinedButton(
                        onClick = {
                            vm.selectRole(role)
                            onBack()
                        },
                        modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                    ) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(iconForRole(role), contentDescription = null, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.size(8.dp))
                            Column(Modifier.weight(1f)) {
                                Text(role.title, color = Z.ink, fontWeight = FontWeight.SemiBold)
                                Text(role.question, color = Z.inkMuted, fontSize = 12.sp)
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
private fun ProfileConfirmationScreen(
    auth: AuthViewModel,
    vm: AltitudeViewModel,
    bearer: String,
    onBack: () -> Unit,
    onComplete: () -> Unit,
) {
    var step by remember { mutableStateOf(0) }
    var selectedRole by remember { mutableStateOf(vm.selectedRole) }
    var selectedUnit by remember { mutableStateOf<CensusUnit?>(null) }
    var houseWide by remember { mutableStateOf(!selectedRole.unitBound) }
    val me = auth.me

    LaunchedEffect(step, selectedRole.id, bearer) {
        if (step == 1 && selectedRole.unitBound) {
            vm.loadProfileUnits(bearer)
        }
    }
    LaunchedEffect(selectedRole.id) {
        if (selectedRole.unitBound) {
            houseWide = false
        } else {
            houseWide = true
            selectedUnit = null
        }
    }
    LaunchedEffect(vm.profileUnits, vm.confirmedProfile.unitId) {
        val unitId = vm.confirmedProfile.unitId
        if (unitId != null && selectedUnit == null) {
            selectedUnit = vm.profileUnits.firstOrNull { it.unitId == unitId }
        }
    }

    val canAdvance = step == 0 || houseWide || selectedUnit != null || !selectedRole.unitBound

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("Confirm shift profile", fontWeight = FontWeight.SemiBold) },
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
            item {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Welcome, ${firstName(me?.name)}", color = Z.ink, fontSize = 24.sp, fontWeight = FontWeight.SemiBold)
                    Text(
                        if (step == 0) "Confirm your role for this shift" else "Where are you working today?",
                        color = Z.inkMuted,
                        fontSize = 14.sp,
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        StepBar(active = true)
                        StepBar(active = step >= 1)
                    }
                }
            }
            if (step == 0) {
                if (!me?.roles.isNullOrEmpty()) {
                    item {
                        ProfileSection("Assigned in Zephyrus") {
                            Text("Zephyrus has you as: ${me?.roles?.joinToString(", ")}", color = Z.inkMuted, fontSize = 13.sp)
                        }
                    }
                }
                items(MobileRoleCatalog.roles, key = { it.id }) { role ->
                    ProfileChoiceButton(
                        title = role.title,
                        subtitle = role.subtitle,
                        selected = role.id == selectedRole.id,
                        onClick = { selectedRole = role },
                    )
                }
            } else {
                item {
                    ProfileChoiceButton(
                        title = "House-wide",
                        subtitle = "All units",
                        selected = houseWide,
                        onClick = {
                            houseWide = true
                            selectedUnit = null
                        },
                    )
                }
                if (selectedRole.unitBound) {
                    if (vm.loadingProfileUnits) {
                        item {
                            Row(
                                modifier = Modifier.fillMaxWidth().panel().padding(16.dp),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                CircularProgressIndicator(color = Z.primary, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                                Text("Loading units", color = Z.inkMuted, fontSize = 13.sp)
                            }
                        }
                    }
                    items(vm.profileUnits, key = { it.unitId }) { unit ->
                        ProfileChoiceButton(
                            title = unit.name,
                            subtitle = unit.type.replace('_', ' ').replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() },
                            selected = !houseWide && selectedUnit?.unitId == unit.unitId,
                            onClick = {
                                houseWide = false
                                selectedUnit = unit
                            },
                        )
                    }
                }
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    if (step == 1) {
                        OutlinedButton(
                            onClick = { step = 0 },
                            modifier = Modifier.weight(1f).heightIn(min = 48.dp),
                        ) {
                            Text("Back", fontWeight = FontWeight.SemiBold)
                        }
                    }
                    Button(
                        onClick = {
                            if (step == 0) {
                                step = 1
                            } else {
                                me?.let { user ->
                                    vm.confirmProfile(user.id, selectedRole, if (houseWide) null else selectedUnit)
                                    onComplete()
                                }
                            }
                        },
                        enabled = canAdvance && me != null,
                        colors = ButtonDefaults.buttonColors(containerColor = Z.primary, contentColor = Color.White),
                        modifier = Modifier.weight(1f).heightIn(min = 48.dp),
                    ) {
                        Text(if (step == 0) "Next" else "Start shift", fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
    }
}

@Composable
private fun StepBar(active: Boolean) {
    Box(
        modifier = Modifier
            .size(width = 28.dp, height = 4.dp)
            .background(if (active) Z.primary else Z.border),
    )
}

@Composable
private fun ProfileChoiceButton(
    title: String,
    subtitle: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    OutlinedButton(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(if (selected) "Selected" else "Select", color = if (selected) Z.primary else Z.inkMuted, fontSize = 12.sp)
            Column(Modifier.weight(1f)) {
                Text(title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
                Text(subtitle, color = Z.inkMuted, fontSize = 12.sp)
            }
        }
    }
}

@Composable
private fun ProfileSection(title: String, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier = Modifier.fillMaxWidth().panel().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(title, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
        content()
    }
}

@Composable
private fun ProfileRow(label: String, value: String) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Text(label, color = Z.inkMuted, fontSize = 12.sp, modifier = Modifier.weight(0.42f))
        Text(value, color = Z.ink, fontSize = 13.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(0.58f))
    }
}

private fun assignmentScope(role: MobileRole): String =
    if (role.unitBound) "Unit-bound role; confirm unit in onboarding/profile." else "House-wide role"

private fun firstName(name: String?): String =
    name?.split(' ')?.firstOrNull()?.takeIf { it.isNotBlank() } ?: "there"
