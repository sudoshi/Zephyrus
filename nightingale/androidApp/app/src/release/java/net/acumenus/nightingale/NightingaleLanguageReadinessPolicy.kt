package net.acumenus.nightingale

import androidx.compose.ui.unit.LayoutDirection

/** Release builds always use the platform direction selected for a governed locale. */
internal fun nightingaleLanguageReadinessLayoutDirection(
    platformDirection: LayoutDirection,
    @Suppress("UNUSED_PARAMETER") localeTag: String,
): LayoutDirection = platformDirection
