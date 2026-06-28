package net.acumenus.hummingbird.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.ui.theme.Z

/** Quiet-lift resting surface (surface + 1px border), matching the web Surface/Card. */
fun Modifier.panel(corner: Int = 14): Modifier = this
    .clip(RoundedCornerShape(corner.dp))
    .background(Z.surface)
    .border(1.dp, Z.border, RoundedCornerShape(corner.dp))
