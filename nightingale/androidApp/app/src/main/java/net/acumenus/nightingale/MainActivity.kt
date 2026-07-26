package net.acumenus.nightingale

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            NightingaleFoundationScreen()
        }
    }
}

/** The compile-time guard for the safe, pre-pilot Nightingale foundation. */
object NightingaleProductBoundary {
    const val productName = "Nightingale"
    const val livePatientAccessEnabled = false
    const val staffEndpointsPermitted = false
}

@Composable
private fun NightingaleFoundationScreen() {
    MaterialTheme {
        Surface(modifier = Modifier.fillMaxSize()) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .testTag("nightingale-safe-shell")
                    .padding(32.dp),
                verticalArrangement = Arrangement.Center,
            ) {
                Text(
                    text = NightingaleProductBoundary.productName,
                    style = MaterialTheme.typography.displaySmall,
                )
                Text(
                    text = "A patient-centered care experience.",
                    modifier = Modifier.padding(top = 16.dp),
                    style = MaterialTheme.typography.titleMedium,
                )
                Text(
                    text = "Live patient access is not available in this foundation build. Please ask your care team for current information.",
                    modifier = Modifier.padding(top = 12.dp),
                    style = MaterialTheme.typography.bodyLarge,
                )
            }
        }
    }
}
