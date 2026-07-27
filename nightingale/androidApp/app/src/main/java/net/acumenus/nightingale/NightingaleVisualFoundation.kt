package net.acumenus.nightingale

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.snap
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import java.time.LocalDate

internal val NightingaleLightColorScheme = lightColorScheme(
    primary = Color(0xFF365F49),
    onPrimary = Color.White,
    surface = Color(0xFFFFFCF5),
    onSurface = Color(0xFF24201B),
    surfaceVariant = Color(0xFFF1E9DC),
    onSurfaceVariant = Color(0xFF514A42),
)

internal val NightingaleDarkColorScheme = darkColorScheme(
    primary = Color(0xFFA8D5B8),
    onPrimary = Color(0xFF123323),
    surface = Color(0xFF17130F),
    onSurface = Color(0xFFF7F0E7),
    surfaceVariant = Color(0xFF2B2723),
    onSurfaceVariant = Color(0xFFD8CEC3),
)

internal fun nightingaleColorScheme(darkTheme: Boolean) =
    if (darkTheme) NightingaleDarkColorScheme else NightingaleLightColorScheme

@Composable
internal fun NightingaleFoundationScreen(
    privacyCovered: Boolean,
    presentationPreferences: NightingalePresentationPreferences,
    systemReduceMotion: Boolean,
    highContrast: Boolean,
    darkTheme: Boolean = isSystemInDarkTheme(),
    backgroundEpochDay: Long = LocalDate.now().toEpochDay(),
) {
    val platformDirection = LocalLayoutDirection.current
    val localeTag = LocalConfiguration.current.locales[0].toLanguageTag()
    val languageReadinessDirection =
        nightingaleLanguageReadinessLayoutDirection(platformDirection, localeTag)

    CompositionLocalProvider(
        LocalLayoutDirection provides languageReadinessDirection,
    ) {
        MaterialTheme(colorScheme = nightingaleColorScheme(darkTheme)) {
            val policy = nightingaleSceneAccessibilityPolicy(
                preferences = presentationPreferences.snapshot,
                fontScale = LocalDensity.current.fontScale,
                highContrast = highContrast,
                systemReduceMotion = systemReduceMotion,
            )

            val backgroundResourceId =
                NightingaleBackgroundCatalog.resourceIdForEpochDay(backgroundEpochDay)

            Box(modifier = Modifier.fillMaxSize()) {
                NightingaleScenicBackground(
                    policy = policy,
                    backgroundResourceId = backgroundResourceId,
                ) {
                    Column(
                        modifier = Modifier
                            .align(Alignment.TopCenter)
                            .widthIn(max = 520.dp)
                            .fillMaxWidth()
                            .fillMaxHeight()
                            .verticalScroll(rememberScrollState())
                            .testTag("nightingale-safe-shell")
                            .padding(horizontal = 28.dp, vertical = 56.dp),
                        verticalArrangement = Arrangement.Top,
                    ) {
                        NightingaleFoundationHeader(
                            policy = policy,
                        )
                        NightingaleFoundationStatusCard(
                            modifier = Modifier.padding(top = 24.dp),
                        )
                        NightingaleDisplayComfortCard(
                            presentationPreferences = presentationPreferences,
                            policy = policy,
                            modifier = Modifier.padding(top = 18.dp),
                        )
                    }
                }

                if (privacyCovered) {
                    NightingalePrivacyCover(
                        policy = policy,
                        backgroundResourceId = backgroundResourceId,
                    )
                }
            }
        }
    }
}

@Composable
private fun NightingaleScenicBackground(
    policy: NightingaleSceneAccessibilityPolicy,
    backgroundResourceId: Int,
    content: @Composable BoxScope.() -> Unit,
) {
    val surface = MaterialTheme.colorScheme.surface
    val imageAlpha by animateFloatAsState(
        targetValue = policy.imageAlpha,
        animationSpec = if (policy.reduceMotion) {
            snap()
        } else {
            tween(durationMillis = policy.transitionDurationMillis)
        },
        label = "nightingale-decorative-image-alpha",
    )
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(surface),
    ) {
        Image(
            painter = painterResource(backgroundResourceId),
            contentDescription = null,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .fillMaxSize()
                .alpha(imageAlpha),
        )
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(
                    Brush.verticalGradient(
                        colors = policy.scrimAlphas.map { surface.copy(alpha = it) },
                    ),
                ),
            content = content,
        )
    }
}

@Composable
private fun NightingaleFoundationHeader(
    policy: NightingaleSceneAccessibilityPolicy,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceVariant,
        ),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(modifier = Modifier.padding(20.dp)) {
            if (policy.showDecorativeImagery) {
                Image(
                    painter = painterResource(R.mipmap.ic_launcher_foreground),
                    contentDescription = null,
                    modifier = Modifier.size(88.dp),
                )
            }
            Text(
                text = stringResource(R.string.app_name),
                modifier = Modifier
                    .padding(top = if (policy.showDecorativeImagery) 16.dp else 0.dp)
                    .testTag("nightingale-product-heading")
                    .semantics { heading() },
                style = MaterialTheme.typography.displaySmall,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = stringResource(R.string.foundation_mission),
                modifier = Modifier
                    .padding(top = 12.dp)
                    .testTag("nightingale-foundation-mission"),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.titleMedium,
            )
        }
    }
}

@Composable
private fun NightingaleFoundationStatusCard(modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(modifier = Modifier.padding(20.dp)) {
            Text(
                text = stringResource(R.string.privacy_heading),
                modifier = Modifier
                    .testTag("nightingale-privacy-status-heading")
                    .semantics { heading() },
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = stringResource(R.string.foundation_unavailable),
                modifier = Modifier.padding(top = 10.dp),
                style = MaterialTheme.typography.bodyLarge,
            )
            Text(
                text = stringResource(R.string.foundation_no_patient_data),
                modifier = Modifier.padding(top = 10.dp),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

@Composable
private fun NightingaleDisplayComfortCard(
    presentationPreferences: NightingalePresentationPreferences,
    policy: NightingaleSceneAccessibilityPolicy,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(modifier = Modifier.padding(20.dp)) {
            Text(
                text = stringResource(R.string.display_comfort_heading),
                modifier = Modifier
                    .testTag("nightingale-display-comfort-heading")
                    .semantics { heading() },
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = stringResource(R.string.display_comfort_scope),
                modifier = Modifier.padding(top = 8.dp),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )

            NightingaleComfortToggle(
                label = stringResource(R.string.reduce_motion_label),
                checked = presentationPreferences.snapshot.reduceMotionRequested,
                onCheckedChange = {
                    presentationPreferences.setReduceMotionRequested(it)
                },
                modifier = Modifier
                    .padding(top = 18.dp)
                    .testTag("nightingale-reduce-motion-toggle"),
            )
            Text(
                text = if (policy.reduceMotion) {
                    stringResource(R.string.motion_reduced_status)
                } else {
                    stringResource(R.string.motion_standard_status)
                },
                modifier = Modifier
                    .padding(top = 4.dp)
                    .testTag("nightingale-motion-status")
                    .semantics { liveRegion = LiveRegionMode.Polite },
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )

            NightingaleComfortToggle(
                label = stringResource(R.string.hide_imagery_label),
                checked = presentationPreferences.snapshot.hideDecorativeImageryRequested,
                onCheckedChange = {
                    presentationPreferences.setHideDecorativeImageryRequested(it)
                },
                modifier = Modifier
                    .padding(top = 18.dp)
                    .testTag("nightingale-hide-imagery-toggle"),
            )
            Text(
                text = if (policy.showDecorativeImagery) {
                    stringResource(R.string.imagery_shown_status)
                } else {
                    stringResource(R.string.imagery_hidden_status)
                },
                modifier = Modifier
                    .padding(top = 4.dp)
                    .testTag("nightingale-imagery-status")
                    .semantics { liveRegion = LiveRegionMode.Polite },
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

@Composable
private fun NightingaleComfortToggle(
    label: String,
    checked: Boolean,
    onCheckedChange: (Boolean) -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = 48.dp)
            .toggleable(
                value = checked,
                role = Role.Switch,
                onValueChange = onCheckedChange,
            ),
        horizontalArrangement = Arrangement.spacedBy(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = label,
            modifier = Modifier.weight(1f),
            style = MaterialTheme.typography.bodyLarge,
        )
        Switch(
            checked = checked,
            onCheckedChange = null,
        )
    }
}

@Composable
private fun NightingalePrivacyCover(
    policy: NightingaleSceneAccessibilityPolicy,
    backgroundResourceId: Int,
) {
    val privacyCoverAccessibilityLabel =
        stringResource(R.string.privacy_cover_accessibility_label)
    Surface(
        modifier = Modifier
            .fillMaxSize()
            .testTag("nightingale-privacy-cover")
            .clearAndSetSemantics {
                contentDescription = privacyCoverAccessibilityLabel
            },
        color = MaterialTheme.colorScheme.surface,
    ) {
        NightingaleScenicBackground(
            policy = policy,
            backgroundResourceId = backgroundResourceId,
        ) {
            Card(
                modifier = Modifier
                    .align(Alignment.Center)
                    .padding(32.dp)
                    .widthIn(max = 440.dp),
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.surfaceVariant,
                ),
                shape = RoundedCornerShape(24.dp),
            ) {
                Column(
                    modifier = Modifier.padding(28.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(
                        text = stringResource(R.string.app_name),
                        style = MaterialTheme.typography.headlineMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        text = stringResource(R.string.privacy_cover_message),
                        modifier = Modifier.padding(top = 12.dp),
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.bodyLarge,
                    )
                }
            }
        }
    }
}
