package net.acumenus.nightingale

import android.content.Context
import android.content.pm.ActivityInfo
import android.content.res.Configuration
import android.os.ParcelFileDescriptor
import android.util.Base64
import android.view.WindowManager
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.lifecycle.Lifecycle
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.security.KeyStore
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NightingaleLaunchInstrumentedTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun launchShowsTheSafePatientFoundationMessage() {
        composeRule.onNodeWithTag("nightingale-safe-shell").assertIsDisplayed()
        composeRule.onNodeWithText("Live patient access is not available in this foundation build. Please ask your care team for current information.")
            .assertIsDisplayed()
    }

    @Test
    fun launchProtectsTheWindowFromCapture() {
        composeRule.activityRule.scenario.onActivity { activity ->
            assertTrue(
                activity.window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE != 0,
            )
        }
    }

    @Test
    fun lifecycleActivatesAndRemovesThePrivacyCover() {
        val scenario = composeRule.activityRule.scenario

        scenario.onActivity { activity ->
            assertFalse(activity.isPrivacyCoverActive)
            activity.volatileInputState.replaceDraftForComposition(
                "synthetic lifecycle canary",
            )
            assertTrue(activity.volatileInputState.hasDraft)
        }
        scenario.moveToState(Lifecycle.State.STARTED)
        scenario.onActivity { activity ->
            assertTrue(activity.isPrivacyCoverActive)
            assertFalse(activity.volatileInputState.hasDraft)
            assertTrue(
                activity.volatileInputState.lastClearReason ==
                    NightingaleVolatileInputClearReason.APPLICATION_INACTIVE,
            )
        }
        scenario.moveToState(Lifecycle.State.RESUMED)
        scenario.onActivity { activity -> assertFalse(activity.isPrivacyCoverActive) }
    }

    @Test
    fun syntheticProtectedStateCanaryIsEncryptedAndDeletedIdempotently() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val store = AndroidKeystoreNightingaleProtectedStateStore(context)
        runCatching { store.deleteAll() }

        val canary = "synthetic-nightingale-keystore-canary".encodeToByteArray()
        try {
            store.writeFutureSessionBinding(canary)
            assertArrayEquals(canary, store.readFutureSessionBinding())

            val storedRecord = context.getSharedPreferences(
                NightingaleProtectedStateNamespace.PREFERENCES_FILE,
                Context.MODE_PRIVATE,
            ).getString(NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING, null)
            assertTrue(storedRecord != null)
            assertFalse(storedRecord!!.contains(canary.decodeToString()))

            val deleted = store.deleteAll()
            assertTrue(deleted.complete)
            assertFalse(deleted.wasAlreadyAbsent)
            assertNull(store.readFutureSessionBinding())
            assertFalse(androidKeyStoreContainsNightingaleKey())

            val alreadyAbsent = store.deleteAll()
            assertTrue(alreadyAbsent.complete)
            assertTrue(alreadyAbsent.wasAlreadyAbsent)
        } finally {
            runCatching { store.deleteAll() }
        }
    }

    @Test
    fun tamperedProtectedStateFailsClosed() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val store = AndroidKeystoreNightingaleProtectedStateStore(context)
        runCatching { store.deleteAll() }

        try {
            store.writeFutureSessionBinding(
                "synthetic-tamper-detection-canary".encodeToByteArray(),
            )
            val preferences = context.getSharedPreferences(
                NightingaleProtectedStateNamespace.PREFERENCES_FILE,
                Context.MODE_PRIVATE,
            )
            val encoded = checkNotNull(
                preferences.getString(
                    NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING,
                    null,
                ),
            )
            val tampered = Base64.decode(encoded, Base64.NO_WRAP).also { bytes ->
                bytes[bytes.lastIndex] = (bytes.last().toInt() xor 1).toByte()
            }
            assertTrue(
                preferences.edit()
                    .putString(
                        NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING,
                        Base64.encodeToString(tampered, Base64.NO_WRAP),
                    )
                    .commit(),
            )

            val failure = runCatching { store.readFutureSessionBinding() }.exceptionOrNull()
            assertTrue(failure is NightingaleProtectedStateUnavailableException)
        } finally {
            runCatching { store.deleteAll() }
        }
    }

    @Test
    fun displayComfortControlsPersistAndExplainTheirEffect() {
        setPresentationPreferences(reduceMotion = false, hideImagery = false)
        try {
            composeRule.onNodeWithText(
                "A calming Nightingale background is shown softly behind the page.",
            ).performScrollTo().assertIsDisplayed()

            composeRule.onNodeWithTag("nightingale-reduce-motion-toggle")
                .performScrollTo()
                .assertIsOff()
                .performClick()
                .assertIsOn()
            composeRule.onNodeWithText(
                "Motion is reduced. Nightingale changes views without decorative movement.",
            ).assertIsDisplayed()

            composeRule.onNodeWithTag("nightingale-hide-imagery-toggle")
                .performScrollTo()
                .assertIsOff()
                .performClick()
                .assertIsOn()
            composeRule.onNodeWithText(
                "Decorative imagery is hidden. Essential text and controls remain available.",
            ).assertIsDisplayed()

            composeRule.activityRule.scenario.recreate()
            composeRule.waitForIdle()

            composeRule.onNodeWithTag("nightingale-reduce-motion-toggle")
                .performScrollTo()
                .assertIsOn()
            composeRule.onNodeWithTag("nightingale-hide-imagery-toggle")
                .performScrollTo()
                .assertIsOn()

            val context = InstrumentationRegistry.getInstrumentation().targetContext
            val keys = context.getSharedPreferences(
                NightingalePresentationPreferenceNamespace.PREFERENCES_FILE,
                Context.MODE_PRIVATE,
            ).all.keys
            assertTrue(keys == NightingalePresentationPreferenceNamespace.allKeys)
        } finally {
            setPresentationPreferences(reduceMotion = false, hideImagery = false)
        }
    }

    @Test
    fun largestTextLandscapeKeepsContentOrderedReachableAndTouchable() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        executeShellCommand("settings put system font_scale 2.0")
        try {
            composeRule.activityRule.scenario.onActivity { activity ->
                activity.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            }
            composeRule.waitUntil(timeoutMillis = 10_000) {
                composeRule.activity.resources.configuration.orientation ==
                    Configuration.ORIENTATION_LANDSCAPE &&
                    composeRule.activity.resources.configuration.fontScale >= 2f
            }

            val orderedTags = listOf(
                "nightingale-product-heading",
                "nightingale-privacy-status-heading",
                "nightingale-display-comfort-heading",
                "nightingale-reduce-motion-toggle",
                "nightingale-hide-imagery-toggle",
            )
            val semanticTags = mutableListOf<String>()
            collectTestTags(
                composeRule.onRoot(useUnmergedTree = true).fetchSemanticsNode(),
                semanticTags,
            )
            assertEquals(orderedTags, semanticTags.filter(orderedTags::contains))

            val minimumTargetPixels = with(composeRule.activity.resources.displayMetrics) {
                48f * density
            }
            listOf(
                "nightingale-reduce-motion-toggle",
                "nightingale-hide-imagery-toggle",
            ).forEach { tag ->
                val node = composeRule.onNodeWithTag(tag)
                node.performScrollTo().assertIsDisplayed()
                assertTrue(
                    node.fetchSemanticsNode().boundsInRoot.height >= minimumTargetPixels,
                )
                node.performClick().assertIsOn()
            }
        } finally {
            executeShellCommand("settings put system font_scale 1.0")
            composeRule.activityRule.scenario.onActivity { activity ->
                activity.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
            }
        }
    }

    private fun setPresentationPreferences(
        reduceMotion: Boolean,
        hideImagery: Boolean,
    ) {
        composeRule.activityRule.scenario.onActivity { activity ->
            assertTrue(
                activity.presentationPreferences.setReduceMotionRequested(reduceMotion),
            )
            assertTrue(
                activity.presentationPreferences.setHideDecorativeImageryRequested(
                    hideImagery,
                ),
            )
        }
        composeRule.waitForIdle()
    }

    private fun androidKeyStoreContainsNightingaleKey(): Boolean =
        KeyStore.getInstance("AndroidKeyStore").run {
            load(null)
            containsAlias(NightingaleProtectedStateNamespace.KEYSTORE_ALIAS)
        }

    private fun executeShellCommand(command: String) {
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation
            .executeShellCommand(command)
        ParcelFileDescriptor.AutoCloseInputStream(descriptor)
            .bufferedReader()
            .use { it.readText() }
    }

    private fun collectTestTags(node: SemanticsNode, destination: MutableList<String>) {
        if (SemanticsProperties.TestTag in node.config) {
            destination.add(node.config[SemanticsProperties.TestTag])
        }
        node.children.forEach { child ->
            collectTestTags(child, destination)
        }
    }
}
