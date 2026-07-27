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
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
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
                    NightingaleFoundationStatusCard(modifier = Modifier.padding(top = 24.dp))
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
                text = NightingaleProductBoundary.productName,
                modifier = Modifier
                    .padding(top = if (policy.showDecorativeImagery) 16.dp else 0.dp)
                    .testTag("nightingale-product-heading")
                    .semantics { heading() },
                style = MaterialTheme.typography.displaySmall,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "A calm place to understand, prepare, and connect with your care team.",
                modifier = Modifier.padding(top = 12.dp),
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
                text = "Your privacy comes first",
                modifier = Modifier.testTag("nightingale-privacy-status-heading"),
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "Live patient access is not available in this foundation build. Please ask your care team for current information.",
                modifier = Modifier.padding(top = 10.dp),
                style = MaterialTheme.typography.bodyLarge,
            )
            Text(
                text = "No patient information is stored or requested by this build.",
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
                text = "Display comfort",
                modifier = Modifier
                    .testTag("nightingale-display-comfort-heading")
                    .semantics { heading() },
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "These settings are stored by Nightingale, not your care account. They never change your care information.",
                modifier = Modifier.padding(top = 8.dp),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )

            NightingaleComfortToggle(
                label = "Reduce motion in Nightingale",
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
                    "Motion is reduced. Nightingale changes views without decorative movement."
                } else {
                    "Gentle transitions are enabled. Nightingale also follows your system Reduce Motion setting."
                },
                modifier = Modifier
                    .padding(top = 4.dp)
                    .testTag("nightingale-motion-status"),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )

            NightingaleComfortToggle(
                label = "Hide decorative imagery",
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
                    "A calming Nightingale background is shown softly behind the page."
                } else {
                    "Decorative imagery is hidden. Essential text and controls remain available."
                },
                modifier = Modifier
                    .padding(top = 4.dp)
                    .testTag("nightingale-imagery-status"),
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
    Surface(
        modifier = Modifier
            .fillMaxSize()
            .testTag("nightingale-privacy-cover")
            .clearAndSetSemantics {
                contentDescription =
                    "Privacy cover. Your care information is hidden while Nightingale is not active."
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
                        text = "Nightingale",
                        style = MaterialTheme.typography.headlineMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        text = "Your care information is covered while the app is not active.",
                        modifier = Modifier.padding(top = 12.dp),
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.bodyLarge,
                    )
                }
            }
        }
    }
}
