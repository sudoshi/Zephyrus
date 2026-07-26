package net.acumenus.nightingale

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

internal object NightingalePresentationPreferenceNamespace {
    const val PREFERENCES_FILE = "net.acumenus.nightingale.presentation.v1"
    const val REDUCE_MOTION = "reduce-motion"
    const val HIDE_DECORATIVE_IMAGERY = "hide-decorative-imagery"

    val allKeys = setOf(REDUCE_MOTION, HIDE_DECORATIVE_IMAGERY)
}

internal data class NightingalePresentationPreferenceSnapshot(
    val reduceMotionRequested: Boolean,
    val hideDecorativeImageryRequested: Boolean,
)

internal data class NightingaleSceneAccessibilityPolicy(
    val reduceMotion: Boolean,
    val showDecorativeImagery: Boolean,
    val imageAlpha: Float,
    val scrimAlphas: List<Float>,
    val transitionDurationMillis: Int,
)

internal fun nightingaleSceneAccessibilityPolicy(
    preferences: NightingalePresentationPreferenceSnapshot,
    fontScale: Float,
    highContrast: Boolean,
    systemReduceMotion: Boolean,
): NightingaleSceneAccessibilityPolicy {
    val reduceMotion = systemReduceMotion || preferences.reduceMotionRequested
    val showDecorativeImagery =
        !preferences.hideDecorativeImageryRequested && !highContrast
    val imageAlpha = when {
        !showDecorativeImagery -> 0f
        fontScale >= 1.3f -> 0.08f
        else -> 0.16f
    }
    val scrimAlphas = when {
        highContrast -> listOf(1f, 1f, 1f)
        fontScale >= 1.3f -> listOf(0.92f, 0.96f, 0.99f)
        else -> listOf(0.72f, 0.86f, 0.96f)
    }

    return NightingaleSceneAccessibilityPolicy(
        reduceMotion = reduceMotion,
        showDecorativeImagery = showDecorativeImagery,
        imageAlpha = imageAlpha,
        scrimAlphas = scrimAlphas,
        transitionDurationMillis = if (reduceMotion) 0 else 180,
    )
}

internal class NightingalePresentationPreferences(context: Context) {
    private val preferences = context.getSharedPreferences(
        NightingalePresentationPreferenceNamespace.PREFERENCES_FILE,
        Context.MODE_PRIVATE,
    )

    var snapshot by mutableStateOf(readSnapshot())
        private set

    fun setReduceMotionRequested(requested: Boolean): Boolean {
        val committed = preferences.edit()
            .putBoolean(
                NightingalePresentationPreferenceNamespace.REDUCE_MOTION,
                requested,
            )
            .commit()
        if (committed) {
            snapshot = snapshot.copy(reduceMotionRequested = requested)
        }
        return committed
    }

    fun setHideDecorativeImageryRequested(requested: Boolean): Boolean {
        val committed = preferences.edit()
            .putBoolean(
                NightingalePresentationPreferenceNamespace.HIDE_DECORATIVE_IMAGERY,
                requested,
            )
            .commit()
        if (committed) {
            snapshot = snapshot.copy(hideDecorativeImageryRequested = requested)
        }
        return committed
    }

    private fun readSnapshot() = NightingalePresentationPreferenceSnapshot(
        reduceMotionRequested = preferences.getBoolean(
            NightingalePresentationPreferenceNamespace.REDUCE_MOTION,
            false,
        ),
        hideDecorativeImageryRequested = preferences.getBoolean(
            NightingalePresentationPreferenceNamespace.HIDE_DECORATIVE_IMAGERY,
            false,
        ),
    )
}
