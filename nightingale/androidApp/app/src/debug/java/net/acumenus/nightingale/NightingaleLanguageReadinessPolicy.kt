package net.acumenus.nightingale

import androidx.compose.ui.unit.LayoutDirection

/**
 * Android's ar-XB resources add bidirectional markers but do not reliably update
 * Compose's root layout direction during an instrumentation-owned activity.
 * Keep this correction Debug-only so the pseudolocale exercises actual mirroring.
 */
internal fun nightingaleLanguageReadinessLayoutDirection(
    platformDirection: LayoutDirection,
    localeTag: String,
): LayoutDirection =
    if (localeTag.equals("ar-XB", ignoreCase = true)) {
        LayoutDirection.Rtl
    } else {
        platformDirection
    }
