package net.acumenus.hummingbird.patient.ui

import androidx.annotation.DrawableRes
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import net.acumenus.hummingbird.patient.R

enum class PatientScene(@DrawableRes val drawable: Int) {
    WELCOME(R.drawable.patient_hummingbird_airy_flight),
    TODAY(R.drawable.patient_hummingbird_airy_flight),
    PATHWAY(R.drawable.patient_hummingbird_warm_motion),
    CARE_TEAM(R.drawable.patient_hummingbird_care_connection),
    MESSAGES(R.drawable.patient_hummingbird_care_connection),
    LOADING_OR_EMPTY(R.drawable.patient_hummingbird_calm_green),
}

/**
 * Visual clarity always wins over the decorative scene. A large text scale
 * retains a faint, static Hummingbird image but uses an almost-opaque surface
 * veil so care content and controls remain the primary visual signal.
 */
internal data class PatientSceneAccessibilityPolicy(
    val imageAlpha: Float,
    val scrimAlphas: List<Float>,
)

internal fun patientSceneAccessibilityPolicy(
    fontScale: Float,
    highContrast: Boolean = false,
): PatientSceneAccessibilityPolicy = when {
    highContrast -> PatientSceneAccessibilityPolicy(
        imageAlpha = 0f,
        scrimAlphas = listOf(1f, 1f, 1f),
    )
    fontScale >= 1.3f -> PatientSceneAccessibilityPolicy(
        imageAlpha = 0.16f,
        scrimAlphas = listOf(0.88f, 0.94f, 0.99f),
    )
    else -> PatientSceneAccessibilityPolicy(
        imageAlpha = 0.46f,
        scrimAlphas = listOf(0.68f, 0.84f, 0.96f),
    )
}

/** Static, decorative imagery with a contrast-preserving surface veil. */
@Composable
internal fun PatientScenicBackground(
    scene: PatientScene,
    modifier: Modifier = Modifier,
    content: @Composable BoxScope.() -> Unit,
) {
    val surface = MaterialTheme.colorScheme.surface
    val fontScale = LocalDensity.current.fontScale
    val presentation = LocalPatientPresentationAccessibility.current
    val accessibilityPolicy = patientSceneAccessibilityPolicy(
        fontScale = fontScale,
        highContrast = presentation.highContrast,
    )
    Box(
        modifier = modifier
            .fillMaxSize()
            .background(surface),
    ) {
        Image(
            painter = painterResource(scene.drawable),
            contentDescription = null,
            contentScale = ContentScale.Crop,
            alpha = accessibilityPolicy.imageAlpha,
            modifier = Modifier.fillMaxSize(),
        )
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(
                    Brush.verticalGradient(
                        colors = listOf(
                            surface.copy(alpha = accessibilityPolicy.scrimAlphas[0]),
                            surface.copy(alpha = accessibilityPolicy.scrimAlphas[1]),
                            surface.copy(alpha = accessibilityPolicy.scrimAlphas[2]),
                        ),
                    ),
                ),
            content = content,
        )
    }
}
