package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.FlowFloor
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowTimelineEvent
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.evs.IsolationBadge
import net.acumenus.hummingbird.ui.theme.CapacityStatus
import net.acumenus.hummingbird.ui.theme.Z

/** Presentation mode of the workspace: the same data as a list or as the Flow map. */
enum class FlowBoardMode { List, Map }

/** The "List / Map" segmented control the workspace homes mount above their content. */
@Composable
fun ListMapSegment(
    mode: FlowBoardMode,
    onSelect: (FlowBoardMode) -> Unit,
    modifier: Modifier = Modifier,
) {
    val colors = SegmentedButtonDefaults.colors(
        activeContainerColor = Z.primary.copy(alpha = 0.18f),
        activeContentColor = Z.primary,
        activeBorderColor = Z.border,
        inactiveContainerColor = Z.surface,
        inactiveContentColor = Z.inkMuted,
        inactiveBorderColor = Z.border,
    )
    SingleChoiceSegmentedButtonRow(modifier = modifier.fillMaxWidth()) {
        FlowBoardMode.entries.forEachIndexed { index, entry ->
            SegmentedButton(
                selected = mode == entry,
                onClick = { onSelect(entry) },
                shape = SegmentedButtonDefaults.itemShape(index = index, count = FlowBoardMode.entries.size),
                colors = colors,
            ) {
                Text(entry.name, fontSize = 13.sp, fontWeight = FontWeight.Medium)
            }
        }
    }
}

/** Staleness caption shown when the window is served from the offline cache. */
@Composable
private fun OfflineCaption(asOfMs: Long) {
    val clock = remember(asOfMs) {
        java.time.format.DateTimeFormatter.ofPattern("HH:mm")
            .format(java.time.Instant.ofEpochMilli(asOfMs).atZone(java.time.ZoneId.systemDefault()))
    }
    Text(
        "Offline · showing data from $clock",
        color = Z.statusWarning,
        fontSize = 12.sp,
        fontWeight = FontWeight.Medium,
        modifier = Modifier.padding(horizontal = Z.s4, vertical = Z.s1),
    )
}

private fun isOrPersona(persona: String): Boolean =
    persona == "or_nurse" || persona == "periop_manager"

/** Phase-3 aggregate lenses: curve/replay surfaces instead of event lanes. */
private fun isAggregatePersona(persona: String): Boolean =
    persona == "executive" || persona == "capacity_lead" ||
        persona == "staffing_coordinator" || persona == "pi_lead"

private fun isDischargeLeveragePersona(persona: String): Boolean =
    persona == "hospitalist" || persona == "intensivist"

/**
 * The Flow Window: scope header, house stack / floor plate, Chronobar, persona
 * timeline lanes, and a selection/provenance strip. Persona layers on top:
 * transport gets route arcs + the off-map gutter, EVS gets the turn map
 * (bed-state tints + turn markers), OR lenses get room lanes as the primary
 * layer with the floor plate as a collapsible secondary section.
 */
@Composable
fun FlowMapScreen(
    auth: AuthViewModel,
    persona: String,
    scope: String? = null,
    modifier: Modifier = Modifier,
    /** Existing A2P navigation — the discharge-leverage lane rows use it when a ptok is present. */
    onOpenPatient: ((String) -> Unit)? = null,
) {
    val vm: FlowViewModel = viewModel()
    val bearer = auth.accessToken ?: ""

    // The OR floor: the floor whose plates contain a procedure room, resolved
    // from the floors payload; the window's own scope floor is the fallback.
    val procedureFloor = vm.floors?.floors?.firstOrNull { floor ->
        floor.spaces.any { it.category == "procedure_room" }
    }?.floor
    val requestedScope = when {
        scope != null -> scope
        // The turn map's bed_statuses layer only exists at floor/unit scope —
        // descending into a floor re-requests the window at that scope.
        persona == "evs" && vm.selectedFloor != null -> "floor:${vm.selectedFloor}"
        isOrPersona(persona) && procedureFloor != null -> "floor:$procedureFloor"
        else -> null
    }

    val userId = auth.me?.id
    LaunchedEffect(bearer, persona, requestedScope, userId) {
        while (true) {
            vm.load(bearer, persona, requestedScope, userId)
            kotlinx.coroutines.delay(20000)
        }
    }
    LaunchedEffect(vm.needsReauth) { if (vm.needsReauth) auth.logout() }
    LaunchedEffect(vm.window != null) {
        if (vm.window != null) vm.startOfShiftReplayIfNeeded(persona)
    }

    val window = vm.window
    Column(modifier = modifier.fillMaxSize()) {
        if (window == null) {
            if (vm.loading) {
                RetryableMessage(
                    title = "Loading the flow window",
                    message = "Assembling the last 24h and the next 24h.",
                    tone = CapacityStatus.INFO,
                    loading = true,
                )
            } else {
                RetryableMessage(
                    title = "Can't load the flow window",
                    message = vm.error ?: "Check your connection and try again.",
                    tone = CapacityStatus.WARNING,
                    retryLabel = "Try again",
                    onRetry = { vm.load(bearer, persona, requestedScope, userId) },
                )
            }
            return@Column
        }

        FlowScopeHeader(
            window = window,
            selectedFloor = vm.selectedFloor,
            onBackToHouse = { vm.selectFloor(null) },
        )

        vm.offlineAsOfMs?.let { asOf -> OfflineCaption(asOf) }

        // Transport route layer inputs — resolved once per window/floors refresh.
        val resolver = remember(window, vm.floors) {
            if (persona == "transport") FlowSpaceResolver(window, vm.floors) else null
        }
        val trips = if (resolver != null) {
            remember(window, resolver, vm.scrubT) { transportTrips(window, resolver, vm.scrubT) }
        } else {
            emptyList()
        }

        Box(Modifier.weight(1f).fillMaxWidth()) {
            val floorNumber = vm.selectedFloor ?: window.scope.floor
            when {
                isOrPersona(persona) -> {
                    val orFloor = procedureFloor ?: window.scope.floor ?: 1
                    OrRoomLanesSection(
                        vm = vm,
                        window = window,
                        persona = persona,
                        floorGeometry = vm.floors?.floors?.firstOrNull { it.floor == orFloor },
                        rollup = window.spacesFloors.firstOrNull { it.floor == orFloor },
                    )
                }
                // Aggregate lenses (Phase 3) own their whole map body.
                persona == "executive" -> ExecutiveFlowSection(
                    vm = vm,
                    window = window,
                    onSelect = { vm.selection = it },
                )
                persona == "capacity_lead" -> CapacityCurveSection(
                    vm = vm,
                    window = window,
                    onSelect = { vm.selection = it },
                )
                persona == "staffing_coordinator" -> StaffingCurveSection(
                    vm = vm,
                    window = window,
                    onSelect = { vm.selection = it },
                )
                window.scope.type == "house" && floorNumber == null -> {
                    HouseStack(
                        // PI replay: the stack breathes from the snapshot checkpoints at t.
                        floors = if (persona == "pi_lead") floorsAt(window, vm.scrubT) else window.spacesFloors,
                        onSelectFloor = vm::selectFloor,
                        arcs = houseTripArcs(trips),
                    )
                }
                else -> {
                    val resolvedFloor = floorNumber ?: window.spacesFloors.firstOrNull()?.floor
                    val geometry = vm.floors?.floors?.firstOrNull { it.floor == resolvedFloor }
                    val rollup = window.spacesFloors.firstOrNull { it.floor == resolvedFloor }
                    if (geometry != null) {
                        FloorLayerStack(
                            persona = persona,
                            geometry = geometry,
                            rollup = rollup,
                            window = window,
                            vm = vm,
                            trips = trips,
                        )
                    } else {
                        RetryableMessage(
                            title = "No floor plate yet",
                            message = "Floor ${resolvedFloor ?: "—"} has no mapped plates. Rollups stay available in the stack.",
                            tone = CapacityStatus.INFO,
                        )
                    }
                }
            }
        }

        if (persona == "transport") {
            OffMapTripGutter(
                trips = offMapTrips(trips),
                onSelect = { vm.selection = it },
            )
        }

        Chronobar(
            fromMs = window.fromMs,
            toMs = window.toMs,
            nowMs = window.nowMs,
            t = vm.scrubT,
            playing = vm.playing,
            onScrub = vm::scrubTo,
            onPlayPause = vm::togglePlayback,
            modifier = Modifier.padding(horizontal = 8.dp),
        )

        when {
            // OR lenses: the room lanes ARE the persona lanes.
            isOrPersona(persona) -> {}
            // PI: replay + clip controls instead of event lanes (§8 P8).
            persona == "pi_lead" -> PiControlsRow(
                vm = vm,
                window = window,
                modifier = Modifier.padding(horizontal = 12.dp),
            )
            // Executive/capacity/staffing carry their forward half in their
            // own sections (curve, forecast strip, gap steps) — no lanes.
            isAggregatePersona(persona) -> {}
            else -> {
                TimelineLanes(
                    fromMs = window.fromMs,
                    toMs = window.toMs,
                    nowMs = window.nowMs,
                    events = window.events,
                    projections = window.projections,
                    personaId = persona,
                    selection = vm.selection,
                    onSelect = { vm.selection = it },
                    modifier = Modifier.padding(horizontal = 12.dp),
                )
                if (isDischargeLeveragePersona(persona)) {
                    DischargeLeverageLane(
                        window = window,
                        onOpenPatient = onOpenPatient,
                        modifier = Modifier.padding(horizontal = 12.dp),
                    )
                }
            }
        }

        FlowSelectionStrip(
            selection = vm.selection,
            window = window,
            nowMs = window.nowMs,
            scrubT = vm.scrubT,
            censusAt = vm::censusAt,
        )
    }
}

/** The floor plate plus persona overlays (transport arcs / EVS turn map). */
@Composable
private fun FloorLayerStack(
    persona: String,
    geometry: FlowFloor,
    rollup: net.acumenus.hummingbird.data.FlowFloorRollup?,
    window: FlowWindowData,
    vm: FlowViewModel,
    trips: List<TransportTrip>,
) {
    val bedPaints = if (persona == "evs") {
        val fade = bedStateFade(vm.scrubT, window.nowMs)
        remember(window.bedStatuses, fade) { evsBedPaints(window.bedStatuses, fade) }
    } else {
        emptyMap()
    }
    // Transport/EVS ghosts are owned by their layers; other lenses keep the
    // scrub-accumulated bed ghosts of Phase 1.
    val baseGhosts = when (persona) {
        "transport", "evs" -> emptyList()
        else -> vm.ghostsUpTo(vm.scrubT)
    }

    Box(Modifier.fillMaxSize()) {
        FloorPlateCanvas(
            floor = geometry,
            rollup = rollup,
            ghosts = baseGhosts,
            selectedPlateId = (vm.selection as? FlowSelection.Plate)?.plate?.id,
            onSelectPlate = { plate ->
                vm.selection = plate?.let { p ->
                    // A bed carrying a ghost surfaces the projection (provenance chip).
                    val ghost = when (persona) {
                        "transport" -> window.projections.firstOrNull {
                            it.kind == "transport_due" && it.bedId != null && it.bedId == p.bedId
                        }
                        "evs" -> window.projections.firstOrNull {
                            it.kind == "evs_due" && it.bedId != null && it.bedId == p.bedId
                        }
                        else -> vm.ghostsUpTo(vm.scrubT).firstOrNull { it.bedId != null && it.bedId == p.bedId }
                    }
                    ghost?.let { FlowSelection.Ghost(it) } ?: FlowSelection.Plate(p)
                }
            },
            bedPaints = bedPaints,
        )
        if (persona == "transport") {
            FloorTripArcsOverlay(floor = geometry, trips = trips)
        }
        if (persona == "evs") {
            EvsTurnOverlay(floor = geometry, window = window, scrubT = vm.scrubT)
            if (window.bedStatuses.isNotEmpty() && bedStateIsStale(vm.scrubT, window.nowMs)) {
                Text(
                    "Bed states shown at now",
                    color = Z.inkMuted,
                    fontSize = 10.sp,
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .padding(bottom = 6.dp)
                        .panel(corner = 8)
                        .padding(horizontal = 8.dp, vertical = 3.dp),
                )
            }
        }
    }
}

/**
 * OR lenses' primary layer: room lanes against the Chronobar. The circulating
 * nurse gets a room picker that highlights one lane; the floor plate stays
 * available as a collapsible secondary section.
 */
@Composable
private fun OrRoomLanesSection(
    vm: FlowViewModel,
    window: FlowWindowData,
    persona: String,
    floorGeometry: FlowFloor?,
    rollup: net.acumenus.hummingbird.data.FlowFloorRollup?,
) {
    val lanes = remember(window) { buildRoomLanes(window) }
    var pickedRoom by rememberSaveable(persona) { mutableStateOf<String?>(null) }
    var plateExpanded by rememberSaveable { mutableStateOf(false) }

    Column(Modifier.fillMaxSize().padding(horizontal = 12.dp)) {
        if (persona == "or_nurse") {
            RoomPicker(
                rooms = lanes.map { it.room },
                picked = pickedRoom,
                onPick = { pickedRoom = it },
            )
        }

        RoomLanes(
            window = window,
            lanes = lanes,
            highlightRoom = if (persona == "or_nurse") pickedRoom else null,
            selection = vm.selection,
            onSelect = { vm.selection = it },
            modifier = Modifier.weight(1f),
        )

        if (floorGeometry != null) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .heightIn(min = 48.dp)
                    .clickable { plateExpanded = !plateExpanded },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    "Floor plate",
                    color = Z.inkMuted,
                    fontSize = 11.sp,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                Icon(
                    if (plateExpanded) Icons.Filled.KeyboardArrowDown else Icons.Filled.KeyboardArrowUp,
                    contentDescription = if (plateExpanded) "Collapse floor plate" else "Expand floor plate",
                    tint = Z.inkMuted,
                    modifier = Modifier.size(18.dp),
                )
            }
            if (plateExpanded) {
                Box(Modifier.fillMaxWidth().height(180.dp)) {
                    FloorPlateCanvas(
                        floor = floorGeometry,
                        rollup = rollup,
                        ghosts = emptyList(),
                        selectedPlateId = (vm.selection as? FlowSelection.Plate)?.plate?.id,
                        onSelectPlate = { plate ->
                            vm.selection = plate?.let { FlowSelection.Plate(it) }
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun RoomPicker(
    rooms: List<String>,
    picked: String?,
    onPick: (String?) -> Unit,
) {
    var open by remember { mutableStateOf(false) }
    Box {
        TextButton(onClick = { open = true }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(
                picked?.let { "Room · $it" } ?: "All rooms",
                color = Z.primary,
                fontSize = 13.sp,
                fontWeight = FontWeight.Medium,
            )
            Icon(
                Icons.Filled.ArrowDropDown,
                contentDescription = null,
                tint = Z.primary,
                modifier = Modifier.size(18.dp),
            )
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(
                text = { Text("All rooms", fontSize = 13.sp) },
                onClick = {
                    onPick(null)
                    open = false
                },
            )
            rooms.forEach { room ->
                DropdownMenuItem(
                    text = { Text(room, fontSize = 13.sp) },
                    onClick = {
                        onPick(room)
                        open = false
                    },
                )
            }
        }
    }
}

@Composable
private fun FlowScopeHeader(
    window: FlowWindowData,
    selectedFloor: Int?,
    onBackToHouse: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // Ascend is offered whenever the user descended AND the lens may see
        // the house (the scope itself may have been re-requested at floor level).
        if (selectedFloor != null && window.lens.scopesAllowed.contains("house")) {
            IconButton(onClick = onBackToHouse, modifier = Modifier.size(32.dp)) {
                Icon(
                    Icons.AutoMirrored.Filled.ArrowBack,
                    contentDescription = "Back to house",
                    tint = Z.ink,
                    modifier = Modifier.size(18.dp),
                )
            }
        }
        val floorRollup = selectedFloor?.let { f -> window.spacesFloors.firstOrNull { it.floor == f } }
        Text(
            floorRollup?.label ?: window.scope.label,
            color = Z.ink,
            fontSize = 15.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.weight(1f),
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        floorRollup?.let {
            Text(
                "${it.occupied}/${it.staffed} · ${it.occupancyPct}%",
                color = Z.inkMuted,
                fontSize = 12.sp,
                style = flowTabularNums,
            )
        }
    }
}

/** Detail strip: what the selected event/ghost/plate is — ghosts carry the provenance chip. */
@Composable
private fun FlowSelectionStrip(
    selection: FlowSelection?,
    window: FlowWindowData,
    nowMs: Long,
    scrubT: Long,
    censusAt: (Long, Int) -> net.acumenus.hummingbird.data.FlowSnapshot?,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 8.dp)
            .panel()
            .padding(12.dp)
            .heightIn(min = 44.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        when (selection) {
            is FlowSelection.Event -> EventDetail(selection.event, nowMs)
            is FlowSelection.Ghost -> GhostDetail(selection.projection, nowMs)
            is FlowSelection.Plate -> PlateDetail(selection.plate, window, scrubT, censusAt)
            null -> Text(
                "Scrub the bar to replay or look ahead. Tap a dot or plate for detail.",
                color = Z.inkMuted,
                fontSize = 12.sp,
            )
        }
    }
}

@Composable
private fun EventDetail(event: FlowTimelineEvent, nowMs: Long) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (event.label.contains("isolation", ignoreCase = true)) IsolationBadge()
        Text(event.label, color = Z.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Text(
            "${flowClock(event.tMs)} · ${flowOffsetLabel(event.tMs, nowMs)}",
            color = Z.inkMuted,
            fontSize = 11.sp,
            style = flowTabularNums,
        )
    }
    val route = listOfNotNull(event.fromSpace, event.toSpace).joinToString(" → ")
    if (route.isNotBlank()) {
        Text(route, color = Z.inkMuted, fontSize = 11.sp)
    }
    if (event.provenanceSource.isNotBlank()) {
        ProvenanceChip("Source: ${event.provenanceSource}")
    }
}

@Composable
private fun GhostDetail(ghost: FlowProjection, nowMs: Long) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (ghost.label.contains("isolation", ignoreCase = true)) IsolationBadge()
        Text(ghost.label, color = Z.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Text(
            "${flowClock(ghost.tMs)} · ${flowOffsetLabel(ghost.tMs, nowMs)}",
            color = Z.inkMuted,
            fontSize = 11.sp,
            style = flowTabularNums,
        )
    }
    val qualifiers = buildList {
        add(ghost.confidence) // "definite" / "probable" / "possible" — never "will"
        if (ghost.derived) add("derived")
        ghost.room?.let { add(it) }
        ghost.value?.let { v ->
            val band = if (ghost.bandLower != null && ghost.bandUpper != null) " (${ghost.bandLower}–${ghost.bandUpper})" else ""
            add("value $v$band")
        }
    }
    Text(qualifiers.joinToString(" · "), color = Z.inkMuted, fontSize = 11.sp, style = flowTabularNums)
    val reliability = ghost.provenanceReliability?.let { " · reliability ${"%.2f".format(it)}" } ?: ""
    ProvenanceChip("Source: ${ghost.provenanceService}$reliability")
}

@Composable
private fun PlateDetail(
    plate: net.acumenus.hummingbird.data.FlowPlate,
    window: FlowWindowData,
    scrubT: Long,
    censusAt: (Long, Int) -> net.acumenus.hummingbird.data.FlowSnapshot?,
) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(plate.label, color = Z.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Text(plate.category.replace('_', ' '), color = Z.inkMuted, fontSize = 11.sp)
    }
    // The turn map names the state — status is never by color alone.
    plate.bedId?.let { bedId ->
        window.bedStatuses.firstOrNull { it.bedId == bedId }?.let { bed ->
            Text("Now: ${bed.status}", color = Z.inkMuted, fontSize = 11.sp)
        }
    }
    plate.unitId?.let { unitId ->
        val snapshot = censusAt(scrubT, unitId)
        val rollup = window.spacesFloors.asSequence().flatMap { it.units }.firstOrNull { it.unitId == unitId }
        when {
            snapshot != null -> Text(
                "At ${flowClock(scrubT)}: ${snapshot.occupied}/${snapshot.staffed} occupied · ${snapshot.available} available · ${snapshot.blocked} blocked",
                color = Z.inkMuted,
                fontSize = 11.sp,
                style = flowTabularNums,
            )
            rollup != null -> Text(
                "Now: ${rollup.occupied}/${rollup.staffed} occupied · ${rollup.available} available · ${rollup.blocked} blocked",
                color = Z.inkMuted,
                fontSize = 11.sp,
                style = flowTabularNums,
            )
        }
    }
}

@Composable
private fun ProvenanceChip(text: String) {
    Text(
        text,
        color = Z.inkMuted,
        fontSize = 10.sp,
        modifier = Modifier
            .panel(corner = 8)
            .padding(horizontal = 8.dp, vertical = 3.dp),
    )
}
