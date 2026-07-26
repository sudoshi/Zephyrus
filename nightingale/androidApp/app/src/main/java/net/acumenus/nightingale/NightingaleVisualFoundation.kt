package net.acumenus.nightingale

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.snap
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
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
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp

private val NightingaleColorScheme = lightColorScheme(
    primary = Color(0xFF365F49),
    onPrimary = Color.White,
    surface = Color(0xFFFFFCF5),
    onSurface = Color(0xFF24201B),
    surfaceVariant = Color(0xFFF1E9DC),
    onSurfaceVariant = Color(0xFF514A42),
)

@Composable
internal fun NightingaleFoundationScreen(
    privacyCovered: Boolean,
    presentationPreferences: NightingalePresentationPreferences,
    systemReduceMotion: Boolean,
    highContrast: Boolean,
) {
    MaterialTheme(colorScheme = NightingaleColorScheme) {
        val policy = nightingaleSceneAccessibilityPolicy(
            preferences = presentationPreferences.snapshot,
            fontScale = LocalDensity.current.fontScale,
            highContrast = highContrast,
            systemReduceMotion = systemReduceMotion,
        )

        Box(modifier = Modifier.fillMaxSize()) {
            NightingaleScenicBackground(policy = policy) {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .verticalScroll(rememberScrollState())
                        .testTag("nightingale-safe-shell")
                        .padding(horizontal = 28.dp, vertical = 56.dp),
                    verticalArrangement = Arrangement.Center,
                ) {
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
                            .padding(top = 16.dp)
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
                    NightingaleFoundationStatusCard(modifier = Modifier.padding(top = 24.dp))
                    NightingaleDisplayComfortCard(
                        presentationPreferences = presentationPreferences,
                        policy = policy,
                        modifier = Modifier.padding(top = 18.dp),
                    )
                }
            }

            if (privacyCovered) {
                NightingalePrivacyCover(policy = policy)
            }
        }
    }
}

@Composable
private fun NightingaleScenicBackground(
    policy: NightingaleSceneAccessibilityPolicy,
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
            painter = painterResource(R.mipmap.ic_launcher_foreground),
            contentDescription = null,
            contentScale = ContentScale.Fit,
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
private fun NightingaleFoundationStatusCard(modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(modifier = Modifier.padding(20.dp)) {
            Text(
                text = "Your privacy comes first",
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
                modifier = Modifier.semantics { heading() },
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "These settings are stored by Nightingale, not your care account. They never change your care information.",
                modifier = Modifier.padding(top = 8.dp),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodySmall,
            )

            Text(
                text = "Reduce motion in Nightingale",
                modifier = Modifier.padding(top = 18.dp),
                style = MaterialTheme.typography.bodyLarge,
            )
            Switch(
                checked = presentationPreferences.snapshot.reduceMotionRequested,
                onCheckedChange = {
                    presentationPreferences.setReduceMotionRequested(it)
                },
                modifier = Modifier
                    .testTag("nightingale-reduce-motion-toggle")
                    .semantics {
                        contentDescription = "Reduce motion in Nightingale"
                    },
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

            Text(
                text = "Hide decorative imagery",
                modifier = Modifier.padding(top = 18.dp),
                style = MaterialTheme.typography.bodyLarge,
            )
            Switch(
                checked = presentationPreferences.snapshot.hideDecorativeImageryRequested,
                onCheckedChange = {
                    presentationPreferences.setHideDecorativeImageryRequested(it)
                },
                modifier = Modifier
                    .testTag("nightingale-hide-imagery-toggle")
                    .semantics {
                        contentDescription = "Hide decorative imagery"
                    },
            )
            Text(
                text = if (policy.showDecorativeImagery) {
                    "The Nightingale artwork is shown softly behind the page."
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
private fun NightingalePrivacyCover(policy: NightingaleSceneAccessibilityPolicy) {
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
        NightingaleScenicBackground(policy = policy) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(32.dp),
                verticalArrangement = Arrangement.Center,
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
