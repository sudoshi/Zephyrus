package net.acumenus.hummingbird.ui.flow

import android.content.Intent
import android.provider.Settings
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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.IosShare
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Replay
import androidx.compose.material3.Icon
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.acumenus.hummingbird.data.FlowProjection
import net.acumenus.hummingbird.data.FlowFloorRollup
import net.acumenus.hummingbird.data.FlowWindowData
import net.acumenus.hummingbird.ui.components.panel
import net.acumenus.hummingbird.ui.theme.Z
import java.net.URLEncoder
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/*
 * Phase 3 — aggregate lens sections (§8 P9 executive, P6 capacity_lead,
 * P10 staffing_coordinator, P8 pi_lead, hospitalist/intensivist leverage lane).
 * All of it renders from the already-lensed window payload; the server decides
 * what each role receives (executive: snapshots+projections+spaces only).
 */

// MARK: — pure helpers (unit-tested)

/**
 * Floor rollups with occupancy re-derived from the snapshot checkpoints at
 * time t — what makes the house stack "breathe" during the executive/PI
 * replay. At/after now (or with no checkpoints yet) the live rollups win.
 */
fun floorsAt(window: FlowWindowData, t: Long): List<FlowFloorRollup> {
    if (t >= window.nowMs || window.snapshots.isEmpty()) return window.spacesFloors
    return window.spacesFloors.map { floor ->
        val units = floor.units.map { unit ->
            val snap = window.snapshots
                .filter { it.unitId == unit.unitId && it.tMs <= t }
                .maxByOrNull { it.tMs }
            if (snap == null) {
                unit
            } else {
                unit.copy(
                    staffed = snap.staffed,
                    occupied = snap.occupied,
                    available = snap.available,
                    blocked = snap.blocked,
                    occupancyPct = if (snap.staffed > 0) snap.occupied * 100 / snap.staffed else 0,
                )
            }
        }
        val staffed = units.sumOf { it.staffed }
        val occupied = units.sumOf { it.occupied }
        floor.copy(
            units = units,
            staffed = staffed,
            occupied = occupied,
            occupancyPct = if (staffed > 0) occupied * 100 / staffed else 0,
        )
    }
}

/** Total predicted arrivals in (now, now+24h] — the executive forecast strip headline. */
fun arrivalsNext24hTotal(window: FlowWindowData): Int =
    window.projections
        .filter { it.kind == "predicted_arrivals" && it.tMs > window.nowMs }
        .sumOf { it.value ?: 0 }

/** Worst positive staffing gap per floor: `staffing_shift_gap.unit_id` → floor via the rollups. */
fun worstGapByFloor(window: FlowWindowData): Map<Int, Int> {
    val unitToFloor = window.spacesFloors
        .flatMap { floor -> floor.units.map { it.unitId to floor.floor } }
        .toMap()
    return window.projections
        .filter { it.kind == "staffing_shift_gap" && (it.value ?: 0) > 0 }
        .mapNotNull { gap -> gap.unitId?.let(unitToFloor::get)?.let { floor -> floor to (gap.value ?: 0) } }
        .groupBy({ it.first }, { it.second })
        .mapValues { (_, gaps) -> gaps.max() }
}

/**
 * Discharge-leverage ranking (§8 hospitalist/intensivist): expected discharges
 * ordered definite > probable > possible, then earliest first.
 */
fun dischargeLeverageRows(projections: List<FlowProjection>): List<FlowProjection> =
    projections
        .filter { it.kind == "expected_discharge" }
        .sortedWith(compareBy({ confidenceRank(it.confidence) }, { it.tMs }))

private fun confidenceRank(confidence: String): Int = when (confidence) {
    "definite" -> 0
    "probable" -> 1
    else -> 2
}

/** PI replay speed: ~4 hours of record per second of playback. */
fun piReplayDurationMs(fromMs: Long, nowMs: Long): Long {
    val hours = (nowMs - fromMs).coerceAtLeast(0L) / 3_600_000.0
    return (hours / 4.0 * 1000.0).toLong().coerceAtLeast(1_000L)
}

/**
 * The v1 "clip window" payload: a plain-text evidence summary of the visible
 * range — scope, from–to, occupancy delta from snapshots, event counts by
 * kind — shared via the system sheet (no backend write).
 */
fun clipSummary(
    window: FlowWindowData,
    fromMs: Long,
    toMs: Long,
    zone: ZoneId = ZoneId.systemDefault(),
): String {
    val fmt = DateTimeFormatter.ofPattern("MMM d HH:mm")
    fun clock(ms: Long): String = fmt.format(Instant.ofEpochMilli(ms).atZone(zone))

    val series = occupancySeries(window)
    val startOcc = series.lastOrNull { it.tMs <= fromMs } ?: series.firstOrNull { it.tMs in fromMs..toMs }
    val endOcc = series.lastOrNull { it.tMs <= toMs }
    val occupancyLine = if (startOcc != null && endOcc != null) {
        val delta = endOcc.value - startOcc.value
        val sign = if (delta >= 0) "+" else "−"
        "Occupancy ${startOcc.value} → ${endOcc.value} ($sign${kotlin.math.abs(delta)})"
    } else {
        "Occupancy: no checkpoints in range"
    }

    val counts = window.events
        .filter { it.tMs in fromMs..toMs }
        .groupingBy { it.kind }
        .eachCount()
        .entries
        .sortedByDescending { it.value }
    val eventsLine = if (counts.isEmpty()) {
        "Events: none in range"
    } else {
        "Events: " + counts.joinToString(" · ") { "${it.key} ${it.value}" }
    }

    val link = clipWebLink(window, fromMs, toMs)
    return buildString {
        appendLine("Flow window clip — ${window.scope.label}")
        appendLine("${clock(fromMs)} → ${clock(toMs)}")
        appendLine(occupancyLine)
        appendLine(eventsLine)
        if (link != null) append(link)
    }.trimEnd()
}

/** `links.web` with the clipped range appended as `from`/`to` (ISO-8601). */
fun clipWebLink(window: FlowWindowData, fromMs: Long, toMs: Long): String? {
    val base = window.webLink ?: return null
    fun iso(ms: Long): String = URLEncoder.encode(
        DateTimeFormatter.ISO_OFFSET_DATE_TIME.format(Instant.ofEpochMilli(ms).atZone(ZoneId.of("UTC"))),
        "UTF-8",
    )
    val joiner = if (base.contains('?')) "&" else "?"
    return "$base${joiner}from=${iso(fromMs)}&to=${iso(toMs)}"
}

// MARK: — shared bits

/** System reduced-motion signal (animator scale 0) — same check LoginScreen uses. */
@Composable
fun rememberReduceMotion(): Boolean {
    val context = LocalContext.current
    return remember {
        Settings.Global.getFloat(context.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f
    }
}

@Composable
private fun FlowSectionLabel(text: String, modifier: Modifier = Modifier) {
    Text(
        text,
        color = Z.inkMuted,
        fontSize = 11.sp,
        fontWeight = FontWeight.SemiBold,
        modifier = modifier,
    )
}

// MARK: — P9 · Executive (time-lapse brief + forecast strip)

private enum class ExecPhase { Pending, Playing, Settled }

/**
 * The executive lens (§8 P9): on entry the Chronobar scrubs itself through the
 * last 24h over ~15s while the house stack breathes from the snapshot
 * checkpoints; it settles at now and reveals the forward half — the census
 * ribbon plus a compact forecast strip. Reduced motion lands at now directly;
 * "Replay" re-runs the lapse either way. No patient dots, no event lanes —
 * the payload for this lens carries neither.
 */
@Composable
internal fun ExecutiveFlowSection(
    vm: FlowViewModel,
    window: FlowWindowData,
    onSelect: (FlowSelection?) -> Unit,
) {
    val reduceMotion = rememberReduceMotion()
    var phase by rememberSaveable { mutableStateOf(if (reduceMotion) ExecPhase.Settled else ExecPhase.Pending) }

    LaunchedEffect(Unit) {
        if (phase == ExecPhase.Pending) {
            phase = ExecPhase.Playing
            vm.play(fromMs = window.fromMs, durationMs = 15_000L)
        }
    }
    LaunchedEffect(vm.playing) {
        if (phase == ExecPhase.Playing && !vm.playing) phase = ExecPhase.Settled
    }

    Column(Modifier.fillMaxSize().padding(horizontal = 12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            FlowSectionLabel(
                if (phase == ExecPhase.Settled) "Next 24 hours" else "Last 24 hours",
                modifier = Modifier.weight(1f),
            )
            TextButton(
                onClick = {
                    phase = ExecPhase.Playing
                    vm.play(fromMs = window.fromMs, durationMs = 15_000L)
                },
                modifier = Modifier.heightIn(min = 48.dp),
            ) {
                Icon(Icons.Filled.Replay, contentDescription = null, tint = Z.primary, modifier = Modifier.size(16.dp))
                Text(" Replay", color = Z.primary, fontSize = 13.sp, fontWeight = FontWeight.Medium)
            }
        }

        HouseStack(
            floors = floorsAt(window, vm.scrubT),
            onSelectFloor = {},
            modifier = Modifier.weight(1f),
        )

        if (phase == ExecPhase.Settled) {
            FlowCurve(
                window = window,
                scopeLabel = window.scope.label,
                scrubT = vm.scrubT,
                surgeMarker = window.projections.firstOrNull { it.kind == "surge_probability" },
                onSelectProjection = { onSelect(FlowSelection.Ghost(it)) },
                modifier = Modifier.height(150.dp),
            )
            ExecutiveForecastStrip(window = window, onSelect = onSelect)
        }
    }
}

/** Compact forecast strip: arrivals next 24h + surge probability, provenance on tap. */
@Composable
private fun ExecutiveForecastStrip(
    window: FlowWindowData,
    onSelect: (FlowSelection?) -> Unit,
) {
    val arrivals = remember(window) { arrivalsNext24hTotal(window) }
    val arrivalsSample = remember(window) {
        window.projections.firstOrNull { it.kind == "predicted_arrivals" && it.tMs > window.nowMs }
    }
    val surge = remember(window) { window.projections.firstOrNull { it.kind == "surge_probability" } }

    Row(
        Modifier.fillMaxWidth().padding(vertical = 8.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        ForecastChip(
            label = "Arrivals · next 24h",
            value = "≈$arrivals",
            qualifier = arrivalsSample?.confidence ?: "probable",
            modifier = Modifier.weight(1f),
            onTap = arrivalsSample?.let { sample -> { onSelect(FlowSelection.Ghost(sample)) } },
        )
        ForecastChip(
            label = "Surge probability",
            value = surge?.value?.let { "$it%" } ?: "—",
            qualifier = surge?.confidence ?: "possible",
            modifier = Modifier.weight(1f),
            onTap = surge?.let { s -> { onSelect(FlowSelection.Ghost(s)) } },
        )
    }
}

@Composable
private fun ForecastChip(
    label: String,
    value: String,
    qualifier: String,
    modifier: Modifier = Modifier,
    onTap: (() -> Unit)? = null,
) {
    Column(
        modifier
            .heightIn(min = 48.dp)
            .panel(corner = 10)
            .let { if (onTap != null) it.clickable(onClick = onTap) else it }
            .padding(horizontal = 10.dp, vertical = 8.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Text(label, color = Z.inkMuted, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(value, color = Z.ink, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, style = flowTabularNums)
            // Confidence vocabulary, never "will".
            Text(qualifier, color = Z.inkMuted, fontSize = 10.sp)
        }
    }
}

// MARK: — P6 · Capacity lead (curve-first, map-second)

/**
 * The capacity lens (§8 P6): the 48h occupancy-vs-staffed curve IS the primary
 * surface (band ahead, surge marker at its t); the house stack is a secondary
 * collapsible section. Tapping a floor filters the curve to that floor's
 * units client-side (snapshots carry unit_id); unit chips narrow further.
 */
@Composable
internal fun CapacityCurveSection(
    vm: FlowViewModel,
    window: FlowWindowData,
    onSelect: (FlowSelection?) -> Unit,
) {
    var mapExpanded by rememberSaveable { mutableStateOf(false) }
    var filterFloor by rememberSaveable { mutableStateOf<Int?>(null) }
    var filterUnit by rememberSaveable { mutableStateOf<Int?>(null) }

    val floorRollup = filterFloor?.let { f -> window.spacesFloors.firstOrNull { it.floor == f } }
    val unitIds: Set<Int>? = when {
        filterUnit != null -> setOf(filterUnit!!)
        floorRollup != null -> floorRollup.units.map { it.unitId }.toSet()
        else -> null
    }
    val scopeLabel = when {
        filterUnit != null -> floorRollup?.units?.firstOrNull { it.unitId == filterUnit }?.name
            ?: "Unit $filterUnit"
        floorRollup != null -> floorRollup.label
        else -> window.scope.label
    }

    Column(Modifier.fillMaxSize().padding(horizontal = 12.dp)) {
        FlowCurve(
            window = window,
            unitIds = unitIds,
            scopeLabel = "$scopeLabel · occupancy vs staffed",
            scrubT = vm.scrubT,
            surgeMarker = window.projections.firstOrNull { it.kind == "surge_probability" },
            onSelectProjection = { onSelect(FlowSelection.Ghost(it)) },
            modifier = Modifier.weight(1f).fillMaxWidth(),
        )

        if (floorRollup != null) {
            LazyRow(
                horizontalArrangement = Arrangement.spacedBy(6.dp),
                modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
            ) {
                item {
                    ScopeFilterChip("House", selected = false) {
                        filterFloor = null
                        filterUnit = null
                    }
                }
                item {
                    ScopeFilterChip(floorRollup.label, selected = filterUnit == null) { filterUnit = null }
                }
                items(floorRollup.units, key = { it.unitId }) { unit ->
                    ScopeFilterChip(unit.abbr.ifBlank { unit.name }, selected = filterUnit == unit.unitId) {
                        filterUnit = unit.unitId
                    }
                }
            }
        }

        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 48.dp)
                .clickable { mapExpanded = !mapExpanded },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FlowSectionLabel("House map", modifier = Modifier.weight(1f))
            Icon(
                if (mapExpanded) Icons.Filled.KeyboardArrowDown else Icons.Filled.KeyboardArrowUp,
                contentDescription = if (mapExpanded) "Collapse house map" else "Expand house map",
                tint = Z.inkMuted,
                modifier = Modifier.size(18.dp),
            )
        }
        if (mapExpanded) {
            Box(Modifier.fillMaxWidth().height(200.dp)) {
                HouseStack(
                    floors = window.spacesFloors,
                    onSelectFloor = { floor ->
                        filterFloor = floor
                        filterUnit = null
                    },
                )
            }
        }
    }
}

@Composable
private fun ScopeFilterChip(label: String, selected: Boolean, onClick: () -> Unit) {
    // 48dp touch target around a compact pill.
    Box(
        modifier = Modifier
            .heightIn(min = 48.dp)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            label,
            color = if (selected) Z.primary else Z.inkMuted,
            fontSize = 12.sp,
            fontWeight = FontWeight.Medium,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier
                .panel(corner = 8)
                .padding(horizontal = 10.dp, vertical = 7.dp),
        )
    }
}

// MARK: — P10 · Staffing coordinator (coverage vs the curve)

/**
 * The staffing lens (§8 P10): the census curve with `staffing_shift_gap` step
 * markers at their shift boundaries (warning tint only for real gaps), the
 * next boundary detent emphasized, and the house stack tinted by the worst
 * gap on each floor. Tapping a floor filters the curve to it.
 */
@Composable
internal fun StaffingCurveSection(
    vm: FlowViewModel,
    window: FlowWindowData,
    onSelect: (FlowSelection?) -> Unit,
) {
    var filterFloor by rememberSaveable { mutableStateOf<Int?>(null) }
    val floorRollup = filterFloor?.let { f -> window.spacesFloors.firstOrNull { it.floor == f } }
    val unitIds: Set<Int>? = floorRollup?.units?.map { it.unitId }?.toSet()
    val floorUnitIds = unitIds

    val gaps = remember(window, floorUnitIds) {
        window.projections.filter {
            it.kind == "staffing_shift_gap" && (floorUnitIds == null || it.unitId in floorUnitIds)
        }
    }
    val accents = remember(window) {
        worstGapByFloor(window).mapValues { (_, gap) ->
            FloorAccent(
                color = Z.statusWarning,
                alpha = (0.15f + 0.06f * gap).coerceAtMost(0.45f),
                label = "gap $gap",
            )
        }
    }

    Column(Modifier.fillMaxSize().padding(horizontal = 12.dp)) {
        FlowCurve(
            window = window,
            unitIds = unitIds,
            scopeLabel = "${floorRollup?.label ?: window.scope.label} · coverage vs census",
            scrubT = vm.scrubT,
            gapMarkers = gaps,
            emphasizeNextShift = true,
            onSelectProjection = { onSelect(FlowSelection.Ghost(it)) },
            modifier = Modifier.weight(0.48f).fillMaxWidth(),
        )
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 4.dp)) {
            FlowSectionLabel("Floors by worst gap", modifier = Modifier.weight(1f))
            if (filterFloor != null) {
                TextButton(onClick = { filterFloor = null }, modifier = Modifier.heightIn(min = 40.dp)) {
                    Text("House", color = Z.primary, fontSize = 12.sp, fontWeight = FontWeight.Medium)
                }
            }
        }
        HouseStack(
            floors = window.spacesFloors,
            onSelectFloor = { filterFloor = it },
            accents = accents,
            modifier = Modifier.weight(0.52f),
        )
    }
}

// MARK: — P8 · PI lead (replay + clip v1)

/**
 * The PI controls row (§8 P8): play/pause at ~4h/s over the past 24h (the
 * same playback driver the executive time-lapse uses) and a "Clip window"
 * button that shares the visible range — scope, from–to, occupancy delta,
 * event counts by kind, and the web deep link with `?from=&to=` appended.
 */
@Composable
internal fun PiControlsRow(
    vm: FlowViewModel,
    window: FlowWindowData,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val clipTo = vm.scrubT.coerceIn(window.fromMs, window.nowMs)

    Row(
        modifier
            .fillMaxWidth()
            .heightIn(min = 48.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        TextButton(
            onClick = {
                if (vm.playing) {
                    vm.pause()
                } else {
                    vm.play(
                        fromMs = window.fromMs,
                        durationMs = piReplayDurationMs(window.fromMs, window.nowMs),
                    )
                }
            },
            modifier = Modifier.heightIn(min = 48.dp),
        ) {
            Icon(
                if (vm.playing) Icons.Filled.Pause else Icons.Filled.PlayArrow,
                contentDescription = if (vm.playing) "Pause replay" else "Replay the past 24h at 4h per second",
                tint = Z.primary,
                modifier = Modifier.size(18.dp),
            )
            Text(
                if (vm.playing) " Pause" else " Replay 4h/s",
                color = Z.primary,
                fontSize = 13.sp,
                fontWeight = FontWeight.Medium,
            )
        }
        Text(
            "${flowClock(window.fromMs)} → ${flowClock(clipTo)}",
            color = Z.inkMuted,
            fontSize = 11.sp,
            style = flowTabularNums,
            modifier = Modifier.weight(1f),
        )
        TextButton(
            onClick = {
                val text = clipSummary(window, window.fromMs, clipTo)
                val send = Intent(Intent.ACTION_SEND).apply {
                    type = "text/plain"
                    putExtra(Intent.EXTRA_TEXT, text)
                }
                context.startActivity(Intent.createChooser(send, "Share flow clip"))
            },
            modifier = Modifier.heightIn(min = 48.dp),
        ) {
            Icon(Icons.Filled.IosShare, contentDescription = null, tint = Z.primary, modifier = Modifier.size(16.dp))
            Text(" Clip window", color = Z.primary, fontSize = 13.sp, fontWeight = FontWeight.Medium)
        }
    }
}

// MARK: — hospitalist / intensivist · discharge-leverage lane

/**
 * The discharge-leverage lane (§8 hospitalist/intensivist): expected
 * discharges ranked by confidence then time. Rows with a patient context ref
 * open the existing A2P navigation; rows without one are label-only.
 */
@Composable
internal fun DischargeLeverageLane(
    window: FlowWindowData,
    onOpenPatient: ((String) -> Unit)?,
    modifier: Modifier = Modifier,
) {
    val rows = remember(window) { dischargeLeverageRows(window.projections) }
    if (rows.isEmpty()) return

    Column(modifier = modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        FlowSectionLabel("Discharge leverage", modifier = Modifier.padding(top = 4.dp))
        Column(
            Modifier
                .fillMaxWidth()
                .heightIn(max = 156.dp)
                .verticalScroll(rememberScrollState()),
        ) {
            rows.forEachIndexed { index, row ->
                val ref = row.patientContextRef
                val tappable = ref != null && onOpenPatient != null
                Row(
                    Modifier
                        .fillMaxWidth()
                        .heightIn(min = 48.dp)
                        .let { base ->
                            if (tappable) base.clickable { onOpenPatient?.invoke(ref!!) } else base
                        },
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Text(
                        "${index + 1}",
                        color = Z.inkMuted,
                        fontSize = 11.sp,
                        style = flowTabularNums,
                    )
                    Text(
                        row.label,
                        color = Z.ink,
                        fontSize = 13.sp,
                        fontWeight = FontWeight.Medium,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                    // Confidence vocabulary at ghost alpha — never "will".
                    Text(
                        row.confidence,
                        color = Z.ink.copy(alpha = row.confidenceAlpha),
                        fontSize = 11.sp,
                    )
                    Text(
                        flowClock(row.tMs),
                        color = Z.inkMuted,
                        fontSize = 11.sp,
                        style = flowTabularNums,
                    )
                    if (tappable) {
                        Icon(
                            Icons.AutoMirrored.Filled.KeyboardArrowRight,
                            contentDescription = "Open patient context",
                            tint = Z.inkMuted,
                            modifier = Modifier.size(16.dp),
                        )
                    }
                }
            }
        }
    }
}
