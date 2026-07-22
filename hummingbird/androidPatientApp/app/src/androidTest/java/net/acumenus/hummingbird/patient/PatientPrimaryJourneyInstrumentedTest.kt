package net.acumenus.hummingbird.patient

import android.content.Context
import android.content.Intent
import android.view.WindowManager
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ActivityScenario
import androidx.test.core.app.ApplicationProvider
import androidx.lifecycle.Lifecycle
import net.acumenus.hummingbird.patient.ui.PatientScene
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class PatientPrimaryJourneyInstrumentedTest {
    @get:Rule
    val composeRule = createEmptyComposeRule()

    @Test
    fun syntheticPreferencesAreVisiblePatientSafeAndNeverClaimToChangeCare() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val intent = Intent(context, MainActivity::class.java)
            .putExtra("HB_PATIENT_SCENARIO", "reference-inpatient")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        ActivityScenario.launch<MainActivity>(intent).use {
            composeRule.onNodeWithContentDescription("Preferences").performClick()
            composeRule.onNodeWithTag("patient-preferences").assertIsDisplayed()
            composeRule.onNodeWithText("These account choices do not change your care plan", substring = true)
                .assertIsDisplayed()
            composeRule.onNodeWithTag("patient-preference-text-size-extra_large").performClick()
            composeRule.onNodeWithTag("patient-preference-high-contrast").performClick()
            composeRule.onNodeWithTag("patient-preferences")
                .performScrollToNode(hasText("Save preferences"))
            composeRule.onNodeWithTag("save-patient-preferences").performClick()
            composeRule.onNodeWithContentDescription("Back to Hummingbird").performClick()
            composeRule.onNodeWithTag("patient-presentation-high-contrast").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Your reading preferences"))
            composeRule.onNodeWithTag("patient-presentation-preference-notice").assertIsDisplayed()
        }
    }

    @Test
    fun syntheticReferenceJourneyKeepsEveryPrimarySurfaceReadableAndSecure() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val intent = Intent(context, MainActivity::class.java)
            .putExtra("HB_PATIENT_SCENARIO", "reference-inpatient")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        ActivityScenario.launch<MainActivity>(intent).use { scenario ->
            composeRule.onNodeWithText("Hummingbird Patient").assertIsDisplayed()
            composeRule.onNodeWithText("Synthetic reference scenario — not a real patient record")
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Morning medicines"))
            composeRule.onNodeWithText("Morning medicines").assertIsDisplayed()

            composeRule.onNodeWithText("My Path").performClick()
            composeRule.onNodeWithText("Information updated").assertIsDisplayed()
            composeRule.onNodeWithText(
                "Your care team updated this information. Please use the details shown here.",
            ).assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("A guide, not a guarantee"))
            composeRule.onNodeWithText("A guide, not a guarantee").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Milestones your team released"))
            composeRule.onNodeWithText("Milestones your team released").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Goals for your care"))
            composeRule.onNodeWithText("Goals for your care").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Your goal"))
            composeRule.onNodeWithText("Your goal").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Share what matters to you"))
            composeRule.onNodeWithText("Share what matters to you").assertIsDisplayed()
            composeRule.onNodeWithText(
                "Sending a message does not automatically change your care plan or create a clinical order.",
                substring = true,
            ).assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Learning and preparation"))
            composeRule.onNodeWithText("Learning and preparation").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Preparing for the next setting"))
            composeRule.onNodeWithText("Preparing for the next setting").assertIsDisplayed()
            composeRule.onNodeWithText(
                "A request for an explanation does not record consent, completion, or that you understand the information.",
                substring = true,
            ).performScrollTo().assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("What has happened so far"))
            composeRule.onNodeWithText("What has happened so far").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Key moments your team released"))
            composeRule.onNodeWithText("Key moments your team released").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Admitted to the hospital"))
            composeRule.onNodeWithText("Admitted to the hospital").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Initial tests completed"))
            composeRule.onNodeWithText("Initial tests completed").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Preparing for a bedside procedure"))
            composeRule.onNodeWithText("Preparing for a bedside procedure").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Planning transportation after you leave"))
            composeRule.onNodeWithText("Planning transportation after you leave").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Getting ready to leave"))
            composeRule.onNodeWithText("Getting ready to leave").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("What needs to happen"))
            composeRule.onNodeWithText("What needs to happen").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Your care-team conversation"))
            composeRule.onNodeWithText("Your care-team conversation").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Topics your team released"))
            composeRule.onNodeWithText("Topics your team released").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("A released summary, not the full conversation"))
            composeRule.onNodeWithText("A released summary, not the full conversation").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Medicines to review"))
            composeRule.onNodeWithText("Medicines to review").assertIsDisplayed()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Your team confirms the details"))
            composeRule.onNodeWithText("Your team confirms the details").assertIsDisplayed()

            composeRule.onNodeWithText("Care Team").performClick()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("How to reach your team"))
            composeRule.onNodeWithText("How to reach your team").assertIsDisplayed()

            composeRule.onNodeWithText("Messages").performClick()
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("Message your care team"))
            composeRule.onNodeWithText("Message your care team").assertIsDisplayed()
            composeRule.onNodeWithText("Messages go to the responsible care-team pool", substring = true)
                .assertIsDisplayed()

            scenario.onActivity { activity ->
                assertTrue(
                    activity.window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE != 0,
                )
            }
        }
    }

    @Test
    fun syntheticMessagingKeepsImmediateHelpAboveComposeAndPendingThreads() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val intent = Intent(context, MainActivity::class.java)
            .putExtra("HB_PATIENT_SCENARIO", "reference-inpatient")
            .putExtra("HB_PATIENT_DESTINATION", "messages")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        ActivityScenario.launch<MainActivity>(intent).use {
            composeRule.onNodeWithTag("patient-content")
                .performScrollToNode(hasText("For immediate or urgent help"))
            composeRule.onNodeWithText("For immediate or urgent help").assertIsDisplayed()
            composeRule.onNodeWithText("Messages are not monitored for emergencies", substring = true)
                .assertIsDisplayed()

            composeRule.onNodeWithText("Start a non-urgent message")
                .performScrollTo()
                .performClick()
            composeRule.onNodeWithTag("message-topic-rounds_question")
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithTag("message-topic-care_preference")
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithTag("message-topic-patient_goal")
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithText("A personal goal for my stay")
                .assertIsDisplayed()
            composeRule.onNodeWithTag("message-topic-patient_goal")
                .assert(hasText("does not change your care plan", substring = true))
            composeRule.onNodeWithTag("message-topic-care_question")
                .performScrollTo()
                .performClick()
            composeRule.onNodeWithTag("new-message-input")
                .performScrollTo()
                .performTextInput("Please explain today's plan.")
            composeRule.onNodeWithText("Send message").performScrollTo().assertIsDisplayed()
            composeRule.onNodeWithText("Cancel").performScrollTo().performClick()

            composeRule.onNodeWithTag(
                "message-thread-01982e0c-709a-7ef0-9000-000000000002",
            )
                .performScrollTo()
                .performClick()
            composeRule.onNodeWithText("Could someone explain what the team plans", substring = true)
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithText("Your team plans to review your symptoms", substring = true)
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithTag("message-reply-input").performScrollTo().assertIsDisplayed()
            composeRule.onNodeWithText("Close conversation").performScrollTo().assertIsDisplayed()
            composeRule.onNodeWithTag("correct-message-01982e0c-709a-7ef0-9000-000000000003")
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithTag("withdraw-message-01982e0c-709a-7ef0-9000-000000000003")
                .assertIsDisplayed()
            composeRule.onNodeWithText("does not erase this message from the conversation history", substring = true)
                .assertIsDisplayed()

            composeRule.onNodeWithText("Back to conversations").performScrollTo().performClick()
            composeRule.onNodeWithTag(
                "message-thread-01982e0c-709a-7ef0-9000-000000000005",
            )
                .performScrollTo()
                .performClick()
            composeRule.onNodeWithText("shared with your care team for possible review", substring = true)
                .performScrollTo()
                .assertIsDisplayed()
            composeRule.onNodeWithText("may not be discussed in a particular round", substring = true)
                .assertIsDisplayed()
            composeRule.onNodeWithText("completed their review of the question you shared", substring = true)
                .performScrollTo()
                .assertIsDisplayed()

            composeRule.onNodeWithText("Back to conversations").performScrollTo().performClick()
            composeRule.onNodeWithTag(
                "message-thread-01982e0c-709a-7ef0-9000-000000000008",
            )
                .performScrollTo()
                .performClick()
            composeRule.onNodeWithText("Withdraw this question").performScrollTo().assertIsDisplayed()
            composeRule.onNodeWithText("Withdraw this question").performClick()
            composeRule.onNodeWithText("if it has not already been shared", substring = true)
                .performScrollTo()
                .assertIsDisplayed()
        }
    }

    @Test
    fun allScenicAssetsAreBundledStaticJpegs() {
        val context = ApplicationProvider.getApplicationContext<Context>()

        PatientScene.entries.forEach { scene ->
            context.resources.openRawResource(scene.drawable).use { stream ->
                val jpegSignature = ByteArray(2)
                assertEquals(2, stream.read(jpegSignature))
                assertEquals(0xFF.toByte(), jpegSignature[0])
                assertEquals(0xD8.toByte(), jpegSignature[1])
            }
        }
    }

    @Test
    fun loadingEmptyUnavailableAndRecoverableErrorRemainExplicit() {
        val expectedStates = mapOf(
            "loading" to "Checking your secure patient session",
            "empty" to "No active hospital stay is available",
            "unavailable" to "Patient access is temporarily unavailable",
            "recoverable-error" to "Hummingbird Patient could not connect securely",
        )

        expectedStates.forEach { (preview, expectedText) ->
            val intent = Intent(
                ApplicationProvider.getApplicationContext<Context>(),
                MainActivity::class.java,
            )
                .putExtra("HB_PATIENT_STATE", preview)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            ActivityScenario.launch<MainActivity>(intent).use {
                val node = composeRule.onNodeWithText(expectedText, substring = true)
                if (preview == "unavailable" || preview == "recoverable-error") {
                    node.performScrollTo()
                }
                node.assertIsDisplayed()
            }
        }
    }

    @Test
    fun privacyCoverAppearsWhileTheActivityIsBackgrounded() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val intent = Intent(context, MainActivity::class.java)
            .putExtra("HB_PATIENT_SCENARIO", "reference-inpatient")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        ActivityScenario.launch<MainActivity>(intent).use { scenario ->
            scenario.onActivity { activity ->
                assertEquals(false, activity.isPrivacyCoverActive)
            }

            scenario.moveToState(Lifecycle.State.CREATED)
            scenario.onActivity { activity ->
                assertEquals(true, activity.isPrivacyCoverActive)
            }

            scenario.moveToState(Lifecycle.State.RESUMED)
            scenario.onActivity { activity ->
                assertEquals(false, activity.isPrivacyCoverActive)
            }
        }
    }

    @Test
    fun signedInTopBarOpensManageDevicesWithoutDisruptingCoreExperience() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val intent = Intent(context, MainActivity::class.java)
            .putExtra("HB_PATIENT_SCENARIO", "reference-inpatient")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        ActivityScenario.launch<MainActivity>(intent).use {
            composeRule.onNodeWithContentDescription("Manage devices").performClick()
            composeRule.onNodeWithText("Manage devices").assertIsDisplayed()
            composeRule.onNodeWithText(
                "Manage devices is not available right now. You can keep using Hummingbird Patient.",
            ).assertIsDisplayed()
            composeRule.onNodeWithContentDescription("Back to Hummingbird").performClick()
            composeRule.onNodeWithText("Hummingbird Patient").assertIsDisplayed()
        }
    }
}
