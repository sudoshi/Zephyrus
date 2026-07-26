package net.acumenus.nightingale

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
import androidx.compose.material3.Text
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
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

internal data class NightingaleSceneAccessibilityPolicy(
    val imageAlpha: Float,
    val scrimAlphas: List<Float>,
)

internal fun nightingaleSceneAccessibilityPolicy(
    fontScale: Float,
    highContrast: Boolean = false,
): NightingaleSceneAccessibilityPolicy = when {
    highContrast -> NightingaleSceneAccessibilityPolicy(0f, listOf(1f, 1f, 1f))
    fontScale >= 1.3f -> NightingaleSceneAccessibilityPolicy(0.08f, listOf(0.92f, 0.96f, 0.99f))
    else -> NightingaleSceneAccessibilityPolicy(0.16f, listOf(0.72f, 0.86f, 0.96f))
}

@Composable
internal fun NightingaleFoundationScreen(privacyCovered: Boolean) {
    MaterialTheme(colorScheme = NightingaleColorScheme) {
        Box(modifier = Modifier.fillMaxSize()) {
            NightingaleScenicBackground {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .verticalScroll(rememberScrollState())
                        .testTag("nightingale-safe-shell")
                        .padding(horizontal = 28.dp, vertical = 56.dp),
                    verticalArrangement = Arrangement.Center,
                ) {
                    Image(
                        painter = painterResource(R.mipmap.ic_launcher_foreground),
                        contentDescription = null,
                        modifier = Modifier.size(88.dp),
                    )
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
                }
            }

            if (privacyCovered) {
                NightingalePrivacyCover()
            }
        }
    }
}

@Composable
private fun NightingaleScenicBackground(content: @Composable BoxScope.() -> Unit) {
    val surface = MaterialTheme.colorScheme.surface
    val policy = nightingaleSceneAccessibilityPolicy(LocalDensity.current.fontScale)
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
                .alpha(policy.imageAlpha),
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
private fun NightingalePrivacyCover() {
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
        NightingaleScenicBackground {
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
