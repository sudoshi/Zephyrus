package net.acumenus.hummingbird.patient

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.unit.Density
import net.acumenus.hummingbird.patient.data.PatientDeviceSession
import net.acumenus.hummingbird.patient.data.PatientSessionDevice
import net.acumenus.hummingbird.patient.ui.HummingbirdPatientTheme
import net.acumenus.hummingbird.patient.ui.PatientScene
import net.acumenus.hummingbird.patient.ui.PatientScenicBackground
import net.acumenus.hummingbird.patient.ui.PatientSessionManagementScreen
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class PatientSessionManagementInstrumentedTest {
    @get:Rule
    val compose = createComposeRule()

    private val currentUuid = "019f4d7a-3200-7000-8000-000000000130"
    private val otherUuid = "019f4d7a-3200-7000-8000-000000000131"

    @Test
    fun scenicBackgroundKeepsCareContentVisibleAtTwoHundredPercentFont() {
        compose.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                HummingbirdPatientTheme(darkTheme = true) {
                    PatientScenicBackground(scene = PatientScene.PATHWAY) {
                        androidx.compose.material3.Text("Released care pathway details remain readable")
                    }
                }
            }
        }

        compose.onNodeWithText("Released care pathway details remain readable")
            .assertIsDisplayed()
    }

    @Test
    fun normalDeviceListMarksCurrentUsesFallbacksAndSelectsExactOtherSession() {
        var selected: String? = null
        compose.setContent {
            HummingbirdPatientTheme(darkTheme = false) {
                PatientSessionManagementScreen(
                    state = PatientDeviceSessionsState.Ready(
                        sessions = listOf(
                            session(currentUuid, current = true, name = "This Pixel"),
                            session(otherUuid, name = null, platform = null),
                        ),
                    ),
                    onDismiss = {},
                    onRetry = {},
                    onSelectForRevocation = { selected = it },
                    onCancelRevocation = {},
                    onConfirmRevocation = {},
                )
            }
        }

        compose.onNodeWithText("Signed-in devices").assertIsDisplayed()
        compose.onNodeWithText("Current device").assertIsDisplayed()
        compose.onNodeWithText("This Pixel").assertIsDisplayed()
        compose.onNodeWithTag("device-sessions-list")
            .performScrollToNode(hasText("Unknown device"))
        compose.onNodeWithText("Unknown device").assertIsDisplayed()
        compose.onNodeWithText("Sign out device").performClick()
        compose.runOnIdle { assertEquals(otherUuid, selected) }
    }

    @Test
    fun confirmationWordingDistinguishesOtherAndCurrentDevices() {
        var confirmed = false
        compose.setContent {
            HummingbirdPatientTheme(darkTheme = false) {
                PatientSessionManagementScreen(
                    state = PatientDeviceSessionsState.Ready(
                        sessions = listOf(session(otherUuid, name = "Family tablet")),
                        selectedForRevocation = session(otherUuid, name = "Family tablet"),
                    ),
                    onDismiss = {},
                    onRetry = {},
                    onSelectForRevocation = {},
                    onCancelRevocation = {},
                    onConfirmRevocation = { confirmed = true },
                )
            }
        }

        compose.onNodeWithText("Sign out other device?").assertIsDisplayed()
        compose.onNodeWithText(
            "Family tablet will need to sign in again. This current device will stay signed in.",
        ).assertIsDisplayed()
        compose.onNodeWithText("Sign out other device").performClick()
        compose.runOnIdle { assertTrue(confirmed) }
    }

    @Test
    fun currentDeviceConfirmationExplainsImmediateReturnToSignIn() {
        compose.setContent {
            HummingbirdPatientTheme(darkTheme = false) {
                val current = session(currentUuid, current = true, name = "This Pixel")
                PatientSessionManagementScreen(
                    state = PatientDeviceSessionsState.Ready(
                        sessions = listOf(current),
                        selectedForRevocation = current,
                    ),
                    onDismiss = {},
                    onRetry = {},
                    onSelectForRevocation = {},
                    onCancelRevocation = {},
                    onConfirmRevocation = {},
                )
            }
        }

        compose.onNodeWithText("Sign out this device?").assertIsDisplayed()
        compose.onNodeWithText(
            "You will return to the Hummingbird Patient sign-in screen on this device. Other signed-in devices are not changed.",
        ).assertIsDisplayed()
    }

    @Test
    fun darkThemeDeviceListRemainsScrollableAtTwoHundredPercentFont() {
        var observedBackground: Color? = null
        compose.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                HummingbirdPatientTheme(darkTheme = true) {
                    val background = MaterialTheme.colorScheme.background
                    SideEffect { observedBackground = background }
                    Box(Modifier.fillMaxSize()) {
                        PatientSessionManagementScreen(
                            state = PatientDeviceSessionsState.Ready(
                                sessions = listOf(
                                    session(currentUuid, current = true, name = "This Pixel"),
                                    session(otherUuid, name = "Family tablet").copy(
                                        expiresAt = "2026-07-22T08:00:00Z",
                                    ),
                                ),
                            ),
                            onDismiss = {},
                            onRetry = {},
                            onSelectForRevocation = {},
                            onCancelRevocation = {},
                            onConfirmRevocation = {},
                        )
                    }
                }
            }
        }

        compose.onNodeWithTag("device-sessions-list")
            .performScrollToNode(hasText("Expires: Jul 22, 8:00 AM"))
        compose.onNodeWithText("Expires: Jul 22, 8:00 AM").assertIsDisplayed()
        compose.onNodeWithTag("device-sessions-list")
            .performScrollToNode(hasText("Sign out device"))
        compose.onNodeWithText("Sign out device").assertIsDisplayed()
        compose.runOnIdle { assertTrue(observedBackground?.luminance() ?: 1f < 0.5f) }
    }

    @Test
    fun defensiveUiNeverRendersUnknownServerEnumsOrSecurityMetadata() {
        val untrusted = session(otherUuid, name = "Injected\nName", platform = "staff_terminal")
            .copy(
                status = "internal_revocation_pending",
                authMethod = "staff_sso",
                assuranceLevel = "internal_aal99",
                device = PatientSessionDevice(
                    uuid = null,
                    platform = "staff_terminal",
                    name = "Injected\nName",
                    appVersion = "v".repeat(81),
                    osVersion = "private\tbuild",
                ),
            )
        compose.setContent {
            HummingbirdPatientTheme(darkTheme = false) {
                PatientSessionManagementScreen(
                    state = PatientDeviceSessionsState.Ready(sessions = listOf(untrusted)),
                    onDismiss = {},
                    onRetry = {},
                    onSelectForRevocation = {},
                    onCancelRevocation = {},
                    onConfirmRevocation = {},
                )
            }
        }

        compose.onNodeWithText("Unknown device").assertIsDisplayed()
        compose.onNodeWithText("Device details are not available.").assertIsDisplayed()
        compose.onNodeWithText("Active").assertIsDisplayed()
        compose.onNodeWithText("Authentication: Not available").assertIsDisplayed()
        compose.onAllNodesWithText("staff_terminal", substring = true).assertCountEquals(0)
        compose.onAllNodesWithText("staff_sso", substring = true).assertCountEquals(0)
        compose.onAllNodesWithText("internal_aal99", substring = true).assertCountEquals(0)
        compose.onAllNodesWithText("internal_revocation_pending", substring = true)
            .assertCountEquals(0)
    }

    private fun session(
        uuid: String,
        current: Boolean = false,
        name: String? = "Test phone",
        platform: String? = "android",
    ) = PatientDeviceSession(
        sessionUuid = uuid,
        current = current,
        status = "active",
        device = PatientSessionDevice(
            uuid = null,
            platform = platform,
            name = name,
            appVersion = "0.1.0",
            osVersion = "15",
        ),
        authMethod = "password",
        assuranceLevel = "aal1",
        lastSeenAt = "2026-07-20T08:00:00Z",
        expiresAt = "2026-07-21T08:00:00Z",
        createdAt = "2026-07-19T08:00:00Z",
    )
}
