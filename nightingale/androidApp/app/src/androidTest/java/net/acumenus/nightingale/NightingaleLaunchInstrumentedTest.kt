package net.acumenus.nightingale

import android.content.Context
import android.util.Base64
import android.view.WindowManager
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.lifecycle.Lifecycle
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.security.KeyStore
import org.junit.Assert.assertArrayEquals
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

    private fun androidKeyStoreContainsNightingaleKey(): Boolean =
        KeyStore.getInstance("AndroidKeyStore").run {
            load(null)
            containsAlias(NightingaleProtectedStateNamespace.KEYSTORE_ALIAS)
        }
}
