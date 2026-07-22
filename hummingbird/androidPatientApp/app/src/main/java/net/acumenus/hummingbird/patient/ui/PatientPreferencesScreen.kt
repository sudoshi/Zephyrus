package net.acumenus.hummingbird.patient.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.patient.PatientPreferencesState
import net.acumenus.hummingbird.patient.data.PatientPreferencesUpdate

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PatientPreferencesScreen(
    state: PatientPreferencesState,
    onDismiss: () -> Unit,
    onSave: (PatientPreferencesUpdate) -> Unit,
) {
    BackHandler(onBack = onDismiss)
    PatientScenicBackground(scene = PatientScene.LOADING_OR_EMPTY) {
        Scaffold(
            containerColor = androidx.compose.ui.graphics.Color.Transparent,
            topBar = {
                TopAppBar(
                    title = { Text("Preferences") },
                    navigationIcon = {
                        IconButton(onClick = onDismiss) {
                            Icon(
                                Icons.AutoMirrored.Outlined.ArrowBack,
                                contentDescription = "Back to Hummingbird",
                            )
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = MaterialTheme.colorScheme.surface.copy(alpha = 0.94f),
                    ),
                )
            },
        ) { contentPadding ->
            when (state) {
                PatientPreferencesState.Hidden -> Unit
                is PatientPreferencesState.Unavailable -> PatientPreferencesUnavailable(
                    message = state.message,
                    modifier = Modifier.padding(contentPadding),
                )
                is PatientPreferencesState.Ready -> PatientPreferencesForm(
                    state = state,
                    contentPadding = contentPadding,
                    onSave = onSave,
                )
            }
        }
    }
}

@Composable
private fun PatientPreferencesUnavailable(message: String, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        Text(
            text = "Preferences are unavailable",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.semantics { heading() },
        )
        Text(message, modifier = Modifier.padding(top = 12.dp))
        Text(
            "Your care view and urgent-help guidance are unchanged.",
            modifier = Modifier.padding(top = 12.dp),
            style = MaterialTheme.typography.bodyMedium,
        )
    }
}

@Composable
private fun PatientPreferencesForm(
    state: PatientPreferencesState.Ready,
    contentPadding: PaddingValues,
    onSave: (PatientPreferencesUpdate) -> Unit,
) {
    val preferences = state.preferences
    var textSize by remember(preferences) { mutableStateOf(preferences.textSize ?: "standard") }
    var reducedMotion by remember(preferences) { mutableStateOf(preferences.reducedMotion ?: false) }
    var highContrast by remember(preferences) { mutableStateOf(preferences.highContrast ?: false) }
    var notificationPreview by remember(preferences) { mutableStateOf(preferences.notificationPreview ?: "hidden") }
    var preferredChannel by remember(preferences) { mutableStateOf(preferences.preferredChannel ?: "push") }

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(contentPadding)
            .testTag("patient-preferences"),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Text(
                text = "Make Hummingbird feel right for you",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.semantics { heading() },
            )
        }
        item {
            PreferenceInfoCard(
                "These account choices do not change your care plan, clinical orders, care-team workflow, or urgent-help instructions.",
            )
        }
        item {
            PreferenceSectionCard(title = "Reading and movement") {
                Text("Text size", style = MaterialTheme.typography.titleSmall)
                PreferenceChoiceRow(
                    options = listOf(
                        "standard" to "Standard",
                        "large" to "Large",
                        "extra_large" to "Extra large",
                    ),
                    selected = textSize,
                    enabled = !state.saving,
                    onSelected = { textSize = it },
                    testTagPrefix = "patient-preference-text-size",
                )
                PreferenceToggle(
                    label = "Reduce motion",
                    checked = reducedMotion,
                    enabled = !state.saving,
                    onCheckedChange = { reducedMotion = it },
                    testTag = "patient-preference-reduced-motion",
                )
                PreferenceToggle(
                    label = "Prefer high contrast",
                    checked = highContrast,
                    enabled = !state.saving,
                    onCheckedChange = { highContrast = it },
                    testTag = "patient-preference-high-contrast",
                )
                Text(
                    "Hummingbird also respects the accessibility settings on this device.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        item {
            PreferenceSectionCard(title = "Notifications") {
                Text("Notification preview", style = MaterialTheme.typography.titleSmall)
                PreferenceChoiceRow(
                    options = listOf("hidden" to "Hide details", "generic" to "General preview"),
                    selected = notificationPreview,
                    enabled = !state.saving,
                    onSelected = { notificationPreview = it },
                    testTagPrefix = "patient-preference-notification-preview",
                )
                Text("Preferred delivery", style = MaterialTheme.typography.titleSmall)
                PreferenceChoiceRow(
                    options = listOf("push" to "App notification", "email" to "Email"),
                    selected = preferredChannel,
                    enabled = !state.saving,
                    onSelected = { preferredChannel = it },
                    testTagPrefix = "patient-preference-delivery-channel",
                )
                Text(
                    "This records a preference. It does not guarantee delivery, replace bedside communication, or change emergency guidance.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        state.message?.let { message ->
            item { PreferenceInfoCard(message, tag = "patient-preferences-status") }
        }
        item {
            Button(
                onClick = {
                    onSave(
                        PatientPreferencesUpdate(
                            textSize = textSize,
                            reducedMotion = reducedMotion,
                            highContrast = highContrast,
                            notificationPreview = notificationPreview,
                            preferredChannel = preferredChannel,
                        ),
                    )
                },
                enabled = !state.saving,
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("save-patient-preferences"),
            ) {
                Text(if (state.saving) "Saving preferences…" else "Save preferences")
            }
        }
    }
}

@Composable
private fun PreferenceSectionCard(title: String, content: @Composable () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            content()
        }
    }
}

@Composable
private fun PreferenceChoiceRow(
    options: List<Pair<String, String>>,
    selected: String,
    enabled: Boolean,
    onSelected: (String) -> Unit,
    testTagPrefix: String,
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (value, label) ->
            val isSelected = value == selected
            OutlinedButton(
                onClick = { onSelected(value) },
                enabled = enabled,
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("$testTagPrefix-$value"),
            ) {
                Text(if (isSelected) "$label selected" else label)
            }
        }
    }
}

@Composable
private fun PreferenceToggle(
    label: String,
    checked: Boolean,
    enabled: Boolean,
    onCheckedChange: (Boolean) -> Unit,
    testTag: String,
) {
    androidx.compose.foundation.layout.Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(
            checked = checked,
            onCheckedChange = onCheckedChange,
            enabled = enabled,
            modifier = Modifier.testTag(testTag),
        )
    }
}

@Composable
private fun PreferenceInfoCard(message: String, tag: String? = null) {
    Card(
        modifier = if (tag == null) Modifier.fillMaxWidth() else Modifier.fillMaxWidth().testTag(tag),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.secondaryContainer),
    ) {
        Text(
            message,
            modifier = Modifier.padding(16.dp),
            style = MaterialTheme.typography.bodyMedium,
        )
    }
}
