package net.acumenus.hummingbird.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHasNoClickAction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import net.acumenus.hummingbird.data.ForYouItem
import net.acumenus.hummingbird.data.PatientCommunicationForYou
import net.acumenus.hummingbird.ui.theme.HummingbirdTheme
import net.acumenus.hummingbird.ui.theme.Z
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class ForYouPatientCommunicationUiTest {
    @get:Rule
    val compose = createComposeRule()

    private val workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192"

    @Test
    fun authorizedAttentionOpensExactCommunicationWithoutRenderingPhiOrInlineActions() {
        var opened: String? = null
        val item = attentionItem(
            title = "Jane Doe sent a message",
            subtitle = "A message body must not appear in For You",
        )

        compose.setContent {
            HummingbirdTheme {
                Box(Modifier.fillMaxSize().background(Z.bg).padding(16.dp)) {
                    PatientCommunicationAttentionRow(item = item, onOpen = { opened = it })
                }
            }
        }

        compose.onNodeWithText(PatientCommunicationForYou.TITLE)
            .assertIsDisplayed()
            .assertHasClickAction()
            .performClick()
        compose.onNodeWithText(PatientCommunicationForYou.SUBTITLE).assertIsDisplayed()
        compose.onNodeWithText("Immediate attention").assertIsDisplayed()
        compose.onNodeWithText("Jane Doe sent a message").assertDoesNotExist()
        compose.onNodeWithText("A message body must not appear in For You").assertDoesNotExist()
        compose.onNodeWithContentDescription("Open patient message").assertIsDisplayed()
        assertTrue(compose.onAllNodesWithText("Claim").fetchSemanticsNodes().isEmpty())
        assertTrue(compose.onAllNodesWithText("Reply").fetchSemanticsNodes().isEmpty())
        compose.runOnIdle { assertEquals(workItemUuid, opened) }
    }

    @Test
    fun malformedAttentionIdHasNoNavigationAction() {
        var opened = false

        compose.setContent {
            HummingbirdTheme {
                Box(Modifier.fillMaxSize().background(Z.bg).padding(16.dp)) {
                    PatientCommunicationAttentionRow(
                        item = attentionItem(id = "patient-communication-not-a-uuid"),
                        onOpen = { opened = true },
                    )
                }
            }
        }

        compose.onNodeWithText(PatientCommunicationForYou.TITLE)
            .assertIsDisplayed()
            .assertHasNoClickAction()
        compose.onNodeWithContentDescription("Open patient message").assertDoesNotExist()
        compose.runOnIdle { assertTrue(!opened) }
    }

    @Test
    fun missingCanonicalCapabilityDoesNotComposeAttentionRow() {
        var opened = false

        compose.setContent {
            HummingbirdTheme {
                Box(Modifier.fillMaxSize().background(Z.bg).padding(16.dp)) {
                    AuthorizedPatientCommunicationAttentionRow(
                        item = attentionItem(),
                        canViewPatientCommunications = false,
                        onOpen = { opened = true },
                    )
                }
            }
        }

        compose.onNodeWithText(PatientCommunicationForYou.TITLE).assertDoesNotExist()
        compose.onNodeWithText(PatientCommunicationForYou.SUBTITLE).assertDoesNotExist()
        compose.onNodeWithContentDescription("Open patient message").assertDoesNotExist()
        compose.runOnIdle { assertTrue(!opened) }
    }

    @Test
    fun attentionRowRemainsReadableAtTwoHundredPercentFontInDarkTheme() {
        var observedBackground: Color? = null

        compose.setContent {
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale = 2f)) {
                HummingbirdTheme {
                    val background = MaterialTheme.colorScheme.background
                    SideEffect { observedBackground = background }
                    Box(Modifier.fillMaxSize().background(background).padding(16.dp)) {
                        PatientCommunicationAttentionRow(
                            item = attentionItem(tier = "warning"),
                            onOpen = {},
                        )
                    }
                }
            }
        }

        compose.onNodeWithText(PatientCommunicationForYou.TITLE).assertIsDisplayed()
        compose.onNodeWithText(PatientCommunicationForYou.SUBTITLE).assertIsDisplayed()
        compose.onNodeWithText("Urgent").assertIsDisplayed()
        compose.runOnIdle { assertEquals(Z.bg, observedBackground) }
    }

    private fun attentionItem(
        id: String = "patient-communication-$workItemUuid",
        tier: String = "critical",
        title: String = PatientCommunicationForYou.TITLE,
        subtitle: String = PatientCommunicationForYou.SUBTITLE,
    ) = ForYouItem(
        id = id,
        type = PatientCommunicationForYou.TYPE,
        domain = PatientCommunicationForYou.DOMAIN,
        tier = tier,
        title = title,
        subtitle = subtitle,
        unit = null,
        at = null,
        patientContextRef = null,
    )
}
