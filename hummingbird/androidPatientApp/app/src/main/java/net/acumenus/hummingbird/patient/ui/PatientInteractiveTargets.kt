package net.acumenus.hummingbird.patient.ui

import androidx.compose.foundation.layout.sizeIn
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

/**
 * Minimum interactive area for patient actions that affect care access,
 * a care-team conversation, or device security. This rendering guard does not
 * replace usability testing with people who use alternative input methods.
 */
internal val PatientMinimumInteractiveTarget = 48.dp

internal fun Modifier.patientMinimumInteractiveTarget(): Modifier = sizeIn(
    minWidth = PatientMinimumInteractiveTarget,
    minHeight = PatientMinimumInteractiveTarget,
)
