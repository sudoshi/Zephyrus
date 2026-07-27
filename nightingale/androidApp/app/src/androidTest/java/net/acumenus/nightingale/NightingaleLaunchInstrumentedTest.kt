package net.acumenus.nightingale

import android.content.Context
import android.content.pm.ActivityInfo
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.app.LocaleManager
import android.os.ParcelFileDescriptor
import android.os.LocaleList
import android.security.NetworkSecurityPolicy
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
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.unit.LayoutDirection
import androidx.lifecycle.Lifecycle
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.security.KeyStore
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
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
    fun installedFoundationDeniesNetworkPermissionCleartextAndBackup() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val packageManager = context.packageManager
        val applicationInfo = packageManager.getApplicationInfo(context.packageName, 0)

        assertEquals("net.acumenus.nightingale", context.packageName)
        assertEquals(
            PackageManager.PERMISSION_DENIED,
            packageManager.checkPermission(
                "android.permission.INTERNET",
                context.packageName,
            ),
        )
        assertFalse(NetworkSecurityPolicy.getInstance().isCleartextTrafficPermitted)
        assertEquals(0, applicationInfo.flags and ApplicationInfo.FLAG_ALLOW_BACKUP)
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

    @Test
    fun headingsSwitchActionsAndPoliteStatusAnnouncementsAreExact() {
        val root = composeRule.onRoot(useUnmergedTree = true).fetchSemanticsNode()
        val nodes = mutableListOf<SemanticsNode>()
        collectNodes(root, nodes)

        val headingTags = nodes
            .filter { SemanticsProperties.Heading in it.config }
            .mapNotNull { node: SemanticsNode ->
                node.config.getOrElseNullable(SemanticsProperties.TestTag) { null }
            }
        assertEquals(
            listOf(
                "nightingale-product-heading",
                "nightingale-privacy-status-heading",
                "nightingale-display-comfort-heading",
            ),
            headingTags,
        )

        val liveRegions = nodes
            .mapNotNull { node: SemanticsNode ->
                val liveRegion: LiveRegionMode =
                    node.config.getOrElseNullable(SemanticsProperties.LiveRegion) { null }
                    ?: return@mapNotNull null
                val tag: String =
                    node.config.getOrElseNullable(SemanticsProperties.TestTag) { null }
                        ?: return@mapNotNull null
                tag to liveRegion
            }
        assertEquals(
            listOf(
                "nightingale-motion-status" to LiveRegionMode.Polite,
                "nightingale-imagery-status" to LiveRegionMode.Polite,
            ),
            liveRegions,
        )

        listOf(
            "nightingale-reduce-motion-toggle",
            "nightingale-hide-imagery-toggle",
        ).forEach { tag ->
            val control = composeRule.onNodeWithTag(tag).fetchSemanticsNode()
            assertEquals(Role.Switch, control.config[SemanticsProperties.Role])
            assertTrue(SemanticsActions.OnClick in control.config)
        }
    }

    @Test
    fun debugPseudoLocalesExpandAndMirrorWithoutLosingControls() {
        val localeManager = composeRule.activity.getSystemService(LocaleManager::class.java)
        val originalLocales = localeManager.applicationLocales
        val sourceMission = composeRule.activity.getString(R.string.foundation_mission)

        try {
            setApplicationLocales(localeManager, LocaleList.forLanguageTags("en-XA"))
            val expandedMission =
                composeRule.activity.getString(R.string.foundation_mission)
            assertNotEquals(sourceMission, expandedMission)
            assertTrue(expandedMission.length > sourceMission.length)
            composeRule.onNodeWithTag("nightingale-foundation-mission")
                .assertIsDisplayed()
            composeRule.onNodeWithTag("nightingale-hide-imagery-toggle")
                .performScrollTo()
                .assertIsOff()

            setApplicationLocales(localeManager, LocaleList.forLanguageTags("ar-XB"))
            val shell = composeRule.onNodeWithTag(
                "nightingale-safe-shell",
                useUnmergedTree = true,
            ).fetchSemanticsNode()
            assertEquals(LayoutDirection.Rtl, shell.layoutInfo.layoutDirection)

            val orderedTags = listOf(
                "nightingale-product-heading",
                "nightingale-privacy-status-heading",
                "nightingale-display-comfort-heading",
                "nightingale-reduce-motion-toggle",
                "nightingale-hide-imagery-toggle",
            )
            val semanticTags = mutableListOf<String>()
            collectTestTags(shell, semanticTags)
            assertEquals(orderedTags, semanticTags.filter(orderedTags::contains))

            composeRule.onNodeWithTag("nightingale-hide-imagery-toggle")
                .performScrollTo()
                .assertIsOff()
                .performClick()
                .assertIsOn()
        } finally {
            setApplicationLocales(localeManager, originalLocales)
            setPresentationPreferences(reduceMotion = false, hideImagery = false)
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

    private fun setApplicationLocales(
        localeManager: LocaleManager,
        locales: LocaleList,
    ) {
        localeManager.applicationLocales = locales
        composeRule.waitUntil(timeoutMillis = 10_000) {
            val appliedLocales = localeManager.applicationLocales
            val applicationSelectionMatches =
                appliedLocales.toLanguageTags() == locales.toLanguageTags()
            val resumedActivity = runCatching {
                composeRule.activity.takeIf {
                    composeRule.activityRule.scenario.state ==
                        Lifecycle.State.RESUMED
                }
            }.getOrNull()
            val resourcesMatch = if (resumedActivity == null) {
                false
            } else {
                locales.isEmpty ||
                    resumedActivity.resources.configuration.locales[0]
                        .toLanguageTag() == locales[0].toLanguageTag()
            }

            applicationSelectionMatches && resourcesMatch
        }
        composeRule.waitForIdle()
    }

    private fun collectNodes(node: SemanticsNode, destination: MutableList<SemanticsNode>) {
        destination.add(node)
        node.children.forEach { child ->
            collectNodes(child, destination)
        }
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
