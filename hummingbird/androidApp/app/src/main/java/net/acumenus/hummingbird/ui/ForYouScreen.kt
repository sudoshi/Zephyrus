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
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Apartment
import androidx.compose.material.icons.filled.Block
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Forum
import androidx.compose.material.icons.filled.Hotel
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Person
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
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.CensusUnit
import net.acumenus.hummingbird.data.ForYouItem
import net.acumenus.hummingbird.data.ForYouViewModel
import net.acumenus.hummingbird.data.MobileRole
import net.acumenus.hummingbird.data.MobileRoleCatalog
import net.acumenus.hummingbird.data.PatientCommunicationForYou
import net.acumenus.hummingbird.data.QueueFilter
import net.acumenus.hummingbird.ui.components.HbRefreshable
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.formatOperationalAge
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z
import java.time.OffsetDateTime

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ForYouScreen(
    auth: AuthViewModel,
    selectedRole: MobileRole = MobileRoleCatalog.default,
    selectedUnitName: String? = null,
    canViewPatientCommunications: Boolean = false,
    forceError: Boolean = false,
    onOpenProfile: () -> Unit = {},
    onOpenDrill: ((String) -> Unit)? = null,
    onOpenPatientCommunication: ((String) -> Unit)? = null,
    onOpenPatient: ((String) -> Unit)? = null,
    onOpenUnit: ((CensusUnit, String?) -> Unit)? = null,
) {
    val vm: ForYouViewModel = viewModel()
    val lifecycleOwner = LocalLifecycleOwner.current
    val bearer = auth.accessToken ?: ""
    val visibleItems = vm.filteredItems(
        selectedRole,
        selectedUnitName,
        canViewPatientCommunications,
    )

    LaunchedEffect(canViewPatientCommunications) {
        vm.updatePatientCommunicationAccess(canViewPatientCommunications)
    }
    LaunchedEffect(
        bearer,
        selectedRole.id,
        selectedUnitName,
        canViewPatientCommunications,
        forceError,
    ) {
        if (!forceError) {
            while (true) {
                vm.load(bearer, selectedRole, canViewPatientCommunications)
                kotlinx.coroutines.delay(15000)
            }
        }
    }
    DisposableEffect(lifecycleOwner, vm) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_STOP) vm.clearNoCacheState()
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            vm.clearNoCacheState()
        }
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }

    Scaffold(
        containerColor = Z.bg,
        topBar = {
            TopAppBar(
                title = { Text("For You", fontWeight = FontWeight.SemiBold) },
                actions = {
                    IconButton(onClick = onOpenProfile) {
                        Icon(Icons.Filled.Person, contentDescription = "Profile")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Z.bg,
                    titleContentColor = Z.ink,
                    actionIconContentColor = Z.ink,
                ),
            )
        },
    ) { inner ->
        HbRefreshable(
            refreshing = vm.loading,
            onRefresh = {
                vm.load(bearer, selectedRole, canViewPatientCommunications)
            },
            modifier = Modifier.padding(inner),
        ) {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                Column {
                    Text(queueTitle(selectedRole), color = Z.ink, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                    if (!(vm.items.isEmpty() && vm.error != null)) {
                        Text(
                            "${visibleItems.size} item${if (visibleItems.size == 1) "" else "s"} to action",
                            color = Z.inkMuted, fontSize = 13.sp,
                        )
                    }
                }
            }
            if (forceError) {
                item {
                    ForcedErrorPanel()
                }
            } else if (vm.items.isEmpty() && vm.loading) {
                item {
                    RetryableMessage(
                        title = "Loading your queue",
                        message = "This usually takes a moment.",
                        tone = CapacityStatus.INFO,
                        loading = true,
                    )
                }
            } else if (vm.items.isEmpty() && vm.error != null) {
                item {
                    RetryableMessage(
                        title = "Can't load your queue",
                        message = vm.error ?: "Can't reach the server. Check your connection and try again.",
                        tone = CapacityStatus.WARNING,
                        retryLabel = "Try again",
                        onRetry = {
                            vm.load(bearer, selectedRole, canViewPatientCommunications)
                        },
                    )
                }
            } else if (visibleItems.isEmpty() && !vm.loading) {
                item {
                    Column(
                        Modifier.fillMaxWidth().padding(top = 32.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Filled.CheckCircle, null, tint = Z.statusSuccess, modifier = Modifier.size(40.dp))
                        Text("All clear", color = Z.ink, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                        Text(emptyQueue(selectedRole), color = Z.inkMuted, fontSize = 13.sp)
                    }
                }
            }
            if (!forceError) {
                items(visibleItems, key = { it.id }) { item ->
                    ForYouRow(
                        item = item,
                        unit = vm.unitFor(item),
                        webLink = vm.webLink,
                        busy = item.id in vm.workingItemIds,
                        canViewPatientCommunications = canViewPatientCommunications,
                        onOpenDrill = onOpenDrill,
                        onOpenPatientCommunication = onOpenPatientCommunication,
                        onOpenPatient = onOpenPatient,
                        onOpenUnit = onOpenUnit,
                        actions = actionsFor(item, vm, bearer, selectedRole),
                    )
                }
            }
        }
        }
    }
}

@Composable
private fun ForcedErrorPanel() {
    RetryableMessage(
        title = "Can't load your queue",
        message = "Can't reach the server. Check your connection and try again.",
        tone = CapacityStatus.WARNING,
    )
}

@Composable
private fun ForYouRow(
    item: ForYouItem,
    unit: CensusUnit?,
    webLink: String?,
    busy: Boolean,
    canViewPatientCommunications: Boolean,
    onOpenDrill: ((String) -> Unit)?,
    onOpenPatientCommunication: ((String) -> Unit)?,
    onOpenPatient: ((String) -> Unit)?,
    onOpenUnit: ((CensusUnit, String?) -> Unit)?,
    actions: List<ForYouAction>,
) {
    if (PatientCommunicationForYou.isType(item.type)) {
        AuthorizedPatientCommunicationAttentionRow(
            item = item,
            canViewPatientCommunications = canViewPatientCommunications,
            onOpen = onOpenPatientCommunication,
        )
        return
    }

    val status = item.capacity
    val canDrill = supportsDrill(item.id) && onOpenDrill != null
    val canOpenUnit = unit != null && onOpenUnit != null
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .clickable(enabled = canDrill || canOpenUnit) {
                if (canDrill) {
                    onOpenDrill?.invoke(item.id)
                } else if (unit != null) {
                    onOpenUnit?.invoke(unit, webLink)
                }
            },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(status.color))
        Icon(iconFor(item.type), null, tint = status.color, modifier = Modifier.padding(start = 12.dp).size(22.dp))
        Column(Modifier.weight(1f).padding(12.dp)) {
            Text(item.title, color = Z.ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
            Text(item.subtitle, color = Z.inkMuted, fontSize = 13.sp)
            metaLine(item)?.let { Text(it, color = Z.inkMuted, fontSize = 11.sp) }
        }
        if (actions.isNotEmpty()) {
            Column(
                modifier = Modifier.padding(end = 8.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                actions.forEach { action ->
                    OutlinedButton(
                        onClick = action.onClick,
                        enabled = !busy,
                        modifier = Modifier.heightIn(min = 40.dp),
                        colors = ButtonDefaults.outlinedButtonColors(contentColor = action.tone.color),
                    ) {
                        Text(if (busy) "Working" else action.label, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        } else if (item.patientContextRef != null && onOpenPatient != null) {
            OutlinedButton(
                onClick = { item.patientContextRef?.let(onOpenPatient) },
                modifier = Modifier.padding(end = 8.dp).heightIn(min = 48.dp),
                colors = ButtonDefaults.outlinedButtonColors(contentColor = Z.primary),
            ) {
                Text("Context", fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        } else if (canDrill || canOpenUnit) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = Z.inkMuted, modifier = Modifier.padding(end = 8.dp))
        }
    }
}

@Composable
internal fun AuthorizedPatientCommunicationAttentionRow(
    item: ForYouItem,
    canViewPatientCommunications: Boolean,
    onOpen: ((String) -> Unit)?,
) {
    if (canViewPatientCommunications) {
        PatientCommunicationAttentionRow(item = item, onOpen = onOpen)
    }
}

/** A deliberately PHI-free attention row; thread content is fetched only after validated routing. */
@Composable
internal fun PatientCommunicationAttentionRow(
    item: ForYouItem,
    onOpen: ((String) -> Unit)?,
) {
    val status = item.capacity
    val workItemUuid = PatientCommunicationForYou.workItemUuid(item)
    val canOpen = workItemUuid != null && onOpen != null
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .panel()
            .then(
                if (canOpen) {
                    Modifier.clickable { workItemUuid?.let { onOpen?.invoke(it) } }
                } else {
                    Modifier
                },
            ),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(status.color))
        Icon(
            Icons.Filled.Forum,
            contentDescription = null,
            tint = status.color,
            modifier = Modifier.padding(start = 12.dp).size(22.dp),
        )
        Column(Modifier.weight(1f).padding(12.dp)) {
            Text(
                PatientCommunicationForYou.TITLE,
                color = Z.ink,
                fontSize = 15.sp,
                fontWeight = FontWeight.SemiBold,
            )
            Text(PatientCommunicationForYou.SUBTITLE, color = Z.inkMuted, fontSize = 13.sp)
            Text(
                PatientCommunicationForYou.urgencyLabel(item.tier),
                color = status.color,
                fontSize = 11.sp,
                fontWeight = FontWeight.SemiBold,
            )
            relTime(item.at)?.let { Text(it, color = Z.inkMuted, fontSize = 11.sp) }
        }
        if (canOpen) {
            Icon(
                Icons.AutoMirrored.Filled.KeyboardArrowRight,
                contentDescription = "Open patient message",
                tint = Z.inkMuted,
                modifier = Modifier.padding(end = 8.dp),
            )
        }
    }
}

private fun iconFor(type: String): ImageVector = when (type) {
    "bed_request" -> Icons.Filled.Hotel
    "barrier" -> Icons.Filled.Block
    "capacity" -> Icons.Filled.Apartment
    "ops_approval" -> Icons.Filled.CheckCircle
    "staffing_request" -> Icons.Filled.Person
    else -> Icons.Filled.Notifications
}

private data class ForYouAction(val label: String, val tone: CapacityStatus = CapacityStatus.INFO, val onClick: () -> Unit)

private fun actionsFor(item: ForYouItem, vm: ForYouViewModel, bearer: String, role: MobileRole): List<ForYouAction> = when {
    PatientCommunicationForYou.isType(item.type) -> emptyList()
    item.id.startsWith("barrier-") -> listOf(ForYouAction("Resolve") { vm.resolveBarrier(bearer, item, role) })
    item.id.startsWith("transport-") -> listOf(ForYouAction("Claim") { vm.claimTransport(bearer, item, role) })
    item.id.startsWith("evs-") -> listOf(ForYouAction("Claim") { vm.claimEvsTurn(bearer, item, role) })
    item.id.startsWith("ops-approval-") -> listOf(
        ForYouAction("Approve", CapacityStatus.SUCCESS) { vm.approveOpsAction(bearer, item, role) },
        ForYouAction("Reject", CapacityStatus.WARNING) { vm.rejectOpsAction(bearer, item, role) },
    )
    else -> emptyList()
}

private fun supportsDrill(id: String): Boolean =
    id.startsWith("bedreq-") ||
        id.startsWith("barrier-") ||
        id.startsWith("transport-") ||
        id.startsWith("evs-") ||
        id.startsWith("staffing-")

private fun metaLine(item: ForYouItem): String? {
    val parts = listOfNotNull(item.unit, relTime(item.at))
    return if (parts.isEmpty()) null else parts.joinToString(" · ")
}

private fun queueTitle(role: MobileRole): String = when (role.id) {
    "charge_nurse", "bedside_nurse" -> "On your unit"
    "hospitalist" -> "Your service"
    "intensivist" -> "Critical care"
    "bed_manager" -> "Placement queue"
    "house_supervisor", "executive" -> "Escalations"
    "evs" -> "Turn priority"
    "transport" -> "Requests"
    "or_nurse", "periop_manager" -> "OR"
    "capacity_lead" -> "Approvals and alerts"
    "staffing_coordinator" -> "Staffing"
    "pi_lead" -> "Improvement"
    else -> "Needs you now"
}

private fun emptyQueue(role: MobileRole): String = when (role.queueFilter) {
    QueueFilter.MyUnit -> "Nothing needs action on your unit right now."
    QueueFilter.CriticalCare -> "No critical-care items need action right now."
    QueueFilter.Placements -> "No pending placements or full units."
    QueueFilter.Escalations -> "No open escalations right now."
    QueueFilter.Turns -> "No cleaning tasks queued yet."
    QueueFilter.None -> "No ${queueTitle(role).lowercase()} items need action yet."
    QueueFilter.All -> "Nothing needs your decision right now."
}

private fun relTime(at: String?): String? {
    if (at == null) return null
    val inst = runCatching { OffsetDateTime.parse(at).toInstant() }.getOrNull() ?: return null
    return formatOperationalAge(inst)
}
