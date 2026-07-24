package net.acumenus.hummingbird.ui

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.unit.Density
import net.acumenus.hummingbird.data.StaffSession
import net.acumenus.hummingbird.data.StaffSessionDevice
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class StaffSessionsUiTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun rendersOnlySafeMetadataAndRequiresConfirmationBeforeRemoteRevocation() {
        var confirmedUuid: String? = null
        val current = fixture(
            uuid = "11111111-1111-4111-8111-111111111111",
            current = true,
            platform = "android",
            name = "Rounds Android",
        )
        val remote = fixture(
            uuid = "22222222-2222-4222-8222-222222222222",
            current = false,
            platform = "ios",
            name = "Unit iPhone",
        )

        compose.setContent {
            var sessions by remember { mutableStateOf(listOf(current, remote)) }
            var pending by remember { mutableStateOf<StaffSession?>(null) }
            HummingbirdTheme {
                StaffSessionsContent(
                    state = StaffSessionsState.Ready(sessions),
                    pendingRevocation = pending,
                    workingSessionUuid = null,
                    onBack = {},
                    onRetry = {},
                    onSelectForRevocation = { pending = it },
                    onCancelRevocation = { pending = null },
                    onConfirmRevocation = {
                        confirmedUuid = pending?.sessionUuid
                        sessions = sessions.filterNot { it.sessionUuid == confirmedUuid }
                        pending = null
                    },
                )
            }
        }

        compose.onNodeWithText("Rounds Android").assertIsDisplayed()
        compose.onNodeWithText("This device").assertIsDisplayed()
        compose.onNodeWithText("Unit iPhone").assertIsDisplayed()
        assertRestrictedMetadataAbsent()

        compose.onNodeWithTag("staff-session-revoke-${remote.sessionUuid}")
            .performScrollTo()
            .performClick()
        compose.onNodeWithText("Revoke this session?").assertIsDisplayed()
        compose.onNodeWithText(
            "That device will need to sign in again. Other devices stay signed in.",
        ).assertIsDisplayed()
        compose.runOnIdle { assertEquals(null, confirmedUuid) }

        compose.onNodeWithTag("staff-session-confirm-revocation").performClick()
        compose.runOnIdle { assertEquals(remote.sessionUuid, confirmedUuid) }
        compose.onNodeWithText("Unit iPhone").assertDoesNotExist()
        compose.onNodeWithText("Rounds Android").assertIsDisplayed()
        assertRestrictedMetadataAbsent()
    }

    @Test
    fun remainsOperableAtTwoHundredPercentFontAndExplainsCurrentDeviceErasure() {
        val current = fixture(
            uuid = "11111111-1111-4111-8111-111111111111",
            current = true,
            platform = "android",
            name = "Rounds Android",
        )
        compose.setContent {
            val density = LocalDensity.current
            var pending by remember { mutableStateOf<StaffSession?>(null) }
            CompositionLocalProvider(
                LocalDensity provides Density(density.density, fontScale = 2f),
            ) {
                HummingbirdTheme {
                    StaffSessionsContent(
                        state = StaffSessionsState.Ready(listOf(current)),
                        pendingRevocation = pending,
                        workingSessionUuid = null,
                        onBack = {},
                        onRetry = {},
                        onSelectForRevocation = { pending = it },
                        onCancelRevocation = { pending = null },
                        onConfirmRevocation = {},
                    )
                }
            }
        }

        compose.onNodeWithTag("staff-session-revoke-${current.sessionUuid}")
            .performScrollTo()
            .assertIsDisplayed()
            .performClick()
        compose.onNodeWithText("Sign out this device?").assertIsDisplayed()
        compose.onNodeWithText(
            "Hummingbird will erase this device's protected credentials and cached operational data.",
        ).assertIsDisplayed()
        assertRestrictedMetadataAbsent()
    }

    private fun assertRestrictedMetadataAbsent() {
        for (forbidden in listOf(
            "token_family_uuid",
            "refresh_token_id",
            "installation_uuid",
            "access_token",
            "refresh_token",
            "ip_address",
            "user_agent",
            "ptok_",
            "MRN",
        )) {
            compose.onNodeWithText(forbidden, substring = true, ignoreCase = true)
                .assertDoesNotExist()
        }
    }

    private fun fixture(
        uuid: String,
        current: Boolean,
        platform: String,
        name: String,
    ) = StaffSession(
        sessionUuid = uuid,
        current = current,
        status = "active",
        device = StaffSessionDevice(
            platform = platform,
            name = name,
            appVersion = "0.1.0",
            osVersion = if (platform == "android") "Android 16" else "iOS 26.3",
        ),
        environment = "production",
        lastSeenAt = "2026-07-23T22:55:00Z",
        expiresAt = "2026-08-22T22:55:00Z",
        createdAt = "2026-07-23T22:00:00Z",
    )
}
