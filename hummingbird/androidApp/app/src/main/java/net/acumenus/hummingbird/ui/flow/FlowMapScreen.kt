package net.acumenus.hummingbird.ui.flow

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import net.acumenus.hummingbird.data.AuthViewModel
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowTimelineEvent
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.components.RetryableMessage
import net.acumenus.hummingbird.ui.components.panel
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

/**
 * The Flow Window (Phase 1): scope header, house stack / floor plate, Chronobar,
 * persona timeline lanes, and a selection/provenance strip. For charge nurses the
 * first composition runs the start-of-shift replay from the last 07:00/19:00 detent.
 */
@Composable
fun FlowMapScreen(
    auth: AuthViewModel,
    persona: String,
    scope: String? = null,
    modifier: Modifier = Modifier,
) {
    val vm: FlowViewModel = viewModel()
    val bearer = auth.accessToken ?: ""

    LaunchedEffect(bearer, persona, scope) {
        while (true) {
            vm.load(bearer, persona, scope)
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
                    onRetry = { vm.load(bearer, persona, scope) },
                )
            }
            return@Column
        }

        FlowScopeHeader(
            window = window,
            selectedFloor = vm.selectedFloor,
            onBackToHouse = { vm.selectFloor(null) },
        )

        Box(Modifier.weight(1f).fillMaxWidth()) {
            val floorNumber = vm.selectedFloor ?: window.scope.floor
            if (window.scope.type == "house" && floorNumber == null) {
                HouseStack(
                    floors = window.spacesFloors,
                    onSelectFloor = vm::selectFloor,
                )
            } else {
                val resolvedFloor = floorNumber ?: window.spacesFloors.firstOrNull()?.floor
                val geometry = vm.floors?.floors?.firstOrNull { it.floor == resolvedFloor }
                val rollup = window.spacesFloors.firstOrNull { it.floor == resolvedFloor }
                if (geometry != null) {
                    FloorPlateCanvas(
                        floor = geometry,
                        rollup = rollup,
                        ghosts = vm.ghostsUpTo(vm.scrubT),
                        selectedPlateId = (vm.selection as? FlowSelection.Plate)?.plate?.id,
                        onSelectPlate = { plate ->
                            vm.selection = plate?.let { p ->
                                // A bed carrying a ghost surfaces the projection (provenance chip).
                                vm.ghostsUpTo(vm.scrubT).firstOrNull { it.bedId != null && it.bedId == p.bedId }
                                    ?.let { FlowSelection.Ghost(it) }
                                    ?: FlowSelection.Plate(p)
                            }
                        },
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

        FlowSelectionStrip(
            selection = vm.selection,
            window = window,
            nowMs = window.nowMs,
            scrubT = vm.scrubT,
            censusAt = vm::censusAt,
        )
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
        if (selectedFloor != null && window.scope.type == "house") {
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
