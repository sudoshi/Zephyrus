package net.acumenus.hummingbird.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.ui.theme.Z

/** Quiet-lift resting surface (surface + 1px border + soft shadow), matching iOS Panel. */
fun Modifier.panel(corner: Int = 14): Modifier = this
    .shadow(elevation = 8.dp, shape = RoundedCornerShape(corner.dp), clip = false)
    .clip(RoundedCornerShape(corner.dp))
    .background(Z.surface)
    .border(1.dp, Z.border, RoundedCornerShape(corner.dp))
