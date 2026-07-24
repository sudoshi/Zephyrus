package net.acumenus.hummingbird.data

import android.content.SharedPreferences
import kotlinx.coroutines.runBlocking
import net.acumenus.hummingbird.ui.shouldClearLocalStaffSession
import net.acumenus.hummingbird.ui.shouldRefetchAfterStaffSessionMutation
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.UUID

class StaffSessionManagementTest {
    private val device = StaffAuthDevice(
        installationUuid = "11111111-1111-4111-8111-111111111111",
        platform = "android",
        name = "Unit tablet",
        appVersion = "0.1.0",
        osVersion = "Android 16",
    )

    @Test
    fun authenticationBodyBindsExactDeviceWithoutClientOwnedEnvironment() {
        val api = ApiClient(
            baseUrl = "https://example.invalid",
            tokenCoordinator = StaffTokenCoordinator(),
            transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
        )

        val body = api.staffTokenBody("clinician", "not-logged", device)
        assertEquals("clinician", body.getString("username"))
        val encoded = body.getJSONObject("device")
        assertEquals(device.installationUuid, encoded.getString("installation_uuid"))
        assertEquals("android", encoded.getString("platform"))
        assertEquals("Unit tablet", encoded.getString("name"))
        assertFalse(encoded.has("environment"))
        assertFalse(encoded.has("access_token"))
        assertFalse(encoded.has("refresh_token"))
    }

    @Test
    fun installationIdentityIsCanonicalStableAndFailsClosedWhenCommitFails() {
        val prefs = InMemorySharedPreferences()
        val generated = UUID.fromString("11111111-1111-4111-8111-111111111111")

        val first = StaffDeviceIdentity.stableInstallationUuid(prefs) { generated }
        val second = StaffDeviceIdentity.stableInstallationUuid(prefs) {
            UUID.fromString("22222222-2222-4222-8222-222222222222")
        }
        assertEquals(generated.toString(), first)
        assertEquals(first, second)

        assertThrows(StaffDeviceIdentityUnavailableException::class.java) {
            StaffDeviceIdentity.stableInstallationUuid(
                InMemorySharedPreferences(failCommit = true),
            ) { generated }
        }
    }

    @Test
    fun deviceRequestMetadataIsTrimmedUnicodeSafeAndServerBounded() {
        val bounded = StaffAuthDevice(
            installationUuid = device.installationUuid,
            platform = "android",
            name = "  ${"🐦".repeat(140)}  ",
            appVersion = "   ",
            osVersion = "o".repeat(100),
        ).toJson()

        assertEquals(120, bounded.getString("name").codePointCount(0, bounded.getString("name").length))
        assertFalse(bounded.has("app_version"))
        assertEquals(80, bounded.getString("os_version").length)
        assertEquals(device.installationUuid, bounded.getString("installation_uuid"))
    }

    @Test
    fun sessionEndpointsUseNoStoreNeverDeriveMutationIdempotencyAndParseSafeProjection() = runBlocking {
        MockWebServer().use { server ->
            server.enqueue(
                MockResponse().setBody(
                    """
                    {
                      "data": {
                        "sessions": [{
                          "session_uuid": "11111111-1111-4111-8111-111111111111",
                          "current": true,
                          "status": "active",
                          "device": {
                            "platform": "android",
                            "name": "Unit tablet",
                            "app_version": "0.1.0",
                            "os_version": "Android 16"
                          },
                          "environment": "production",
                          "last_seen_at": "2026-07-23T22:55:00Z",
                          "expires_at": "2026-08-22T22:55:00Z",
                          "created_at": "2026-07-23T22:00:00Z"
                        }]
                      },
                      "meta": {"stale": false},
                      "links": {}
                    }
                    """.trimIndent(),
                ),
            )
            server.enqueue(
                MockResponse().setBody(
                    """
                    {
                      "data": {
                        "session_uuid": "11111111-1111-4111-8111-111111111111",
                        "revoked": true,
                        "already_revoked": false,
                        "current": true
                      },
                      "meta": {"stale": false},
                      "links": {}
                    }
                    """.trimIndent(),
                ),
            )
            val api = ApiClient(
                baseUrl = server.url("/").toString().removeSuffix("/"),
                tokenCoordinator = StaffTokenCoordinator(),
                transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
            )

            val sessions = api.staffSessions("access")
            assertEquals(1, sessions.size)
            assertTrue(sessions.single().current)
            assertEquals("Unit tablet", sessions.single().device.name)

            val listRequest = server.takeRequest()
            assertEquals("GET", listRequest.method)
            assertEquals("/api/mobile/v1/me/sessions", listRequest.path)
            assertEquals("no-store", listRequest.getHeader("Cache-Control"))
            assertEquals("no-cache", listRequest.getHeader("Pragma"))
            assertNull(listRequest.getHeader("Idempotency-Key"))

            val revoked = api.revokeStaffSession(
                bearer = "access",
                sessionUuid = sessions.single().sessionUuid,
            )
            assertTrue(revoked.current)
            assertTrue(revoked.revoked)

            val deleteRequest = server.takeRequest()
            assertEquals("DELETE", deleteRequest.method)
            assertEquals(
                "/api/mobile/v1/me/sessions/11111111-1111-4111-8111-111111111111",
                deleteRequest.path,
            )
            assertEquals("no-store", deleteRequest.getHeader("Cache-Control"))
            assertNull(deleteRequest.getHeader("Idempotency-Key"))
        }
    }

    @Test
    fun parserIgnoresRestrictedServerFieldsAndClearPolicyIsTerminalOnly() {
        val api = ApiClient(
            baseUrl = "https://example.invalid",
            tokenCoordinator = StaffTokenCoordinator(),
            transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
        )
        val source = JSONObject(
            """
            {
              "session_uuid": "11111111-1111-4111-8111-111111111111",
              "current": false,
              "status": "active",
              "device": {
                "platform": "ios",
                "name": null,
                "app_version": null,
                "os_version": null
              },
              "environment": "production",
              "last_seen_at": "2026-07-23T22:55:00Z",
              "expires_at": "2026-08-22T22:55:00Z",
              "created_at": "2026-07-23T22:00:00Z",
              "installation_uuid": "must-not-project",
              "token_family_uuid": "must-not-project",
              "ip_address": "192.0.2.1",
              "user_agent": "must-not-project"
            }
            """.trimIndent(),
        )

        val parsed = api.parseStaffSession(source)
        assertEquals("ios", parsed.device.platform)
        assertFalse(parsed.current)
        assertFalse(shouldClearLocalStaffSession(result = null, statusCode = 404))
        assertTrue(shouldClearLocalStaffSession(statusCode = 401))
        assertTrue(shouldClearLocalStaffSession(statusCode = 403))
        assertTrue(shouldRefetchAfterStaffSessionMutation(statusCode = 401))
        assertFalse(shouldRefetchAfterStaffSessionMutation(statusCode = 403))
        assertFalse(shouldRefetchAfterStaffSessionMutation(statusCode = 404))
        assertTrue(
            shouldClearLocalStaffSession(
                result = StaffSessionRevocation(
                    sessionUuid = parsed.sessionUuid,
                    revoked = true,
                    alreadyRevoked = false,
                    current = true,
                ),
            ),
        )
        for (invalid in listOf(
            JSONObject(
                """
                {
                  "session_uuid": "11111111-1111-4111-8111-111111111111",
                  "revoked": false,
                  "already_revoked": false,
                  "current": true
                }
                """.trimIndent(),
            ),
            JSONObject(
                """
                {
                  "session_uuid": "22222222-2222-4222-8222-222222222222",
                  "revoked": true,
                  "already_revoked": false,
                  "current": true
                }
                """.trimIndent(),
            ),
        )) {
            assertThrows(org.json.JSONException::class.java) {
                api.parseStaffSessionRevocation(
                    invalid,
                    expectedSessionUuid = parsed.sessionUuid,
                )
            }
        }
    }

    @Test
    fun parserFailsClosedWhenTheRequiredSessionArrayOrDeviceObjectIsMissing() {
        val api = ApiClient(
            baseUrl = "https://example.invalid",
            tokenCoordinator = StaffTokenCoordinator(),
            transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
        )
        assertThrows(org.json.JSONException::class.java) {
            api.parseStaffSessions(JSONObject())
        }

        val missingDevice = JSONObject(
            """
            {
              "sessions": [{
                "session_uuid": "11111111-1111-4111-8111-111111111111",
                "current": true,
                "status": "active",
                "environment": "production",
                "last_seen_at": "2026-07-23T22:55:00Z",
                "expires_at": "2026-08-22T22:55:00Z",
                "created_at": "2026-07-23T22:00:00Z"
              }]
            }
            """.trimIndent(),
        )
        assertThrows(org.json.JSONException::class.java) {
            api.parseStaffSessions(missingDevice)
        }

        val noCurrent = JSONObject(
            """
            {
              "sessions": [{
                "session_uuid": "11111111-1111-4111-8111-111111111111",
                "current": false,
                "status": "active",
                "device": {
                  "platform": "ios",
                  "name": null,
                  "app_version": null,
                  "os_version": null
                },
                "environment": "production",
                "last_seen_at": "2026-07-23T22:55:00Z",
                "expires_at": "2026-08-22T22:55:00Z",
                "created_at": "2026-07-23T22:00:00Z"
              }]
            }
            """.trimIndent(),
        )
        assertThrows(org.json.JSONException::class.java) {
            api.parseStaffSessions(noCurrent)
        }
    }

    @Test
    fun staffSessionPathsAreNoStoreButOrdinaryOperationsRemainCacheEligible() {
        val api = ApiClient(
            baseUrl = "https://example.invalid",
            tokenCoordinator = StaffTokenCoordinator(),
            transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
        )
        for (path in listOf(
            "/api/mobile/v1/me/sessions",
            "/api/mobile/v1/me/sessions/11111111-1111-4111-8111-111111111111",
        )) {
            assertTrue(api.shouldDisableHttpCaches(path))
            assertEquals("no-store", api.sensitiveNoStoreHeaders(path)["Cache-Control"])
        }
        assertFalse(api.shouldDisableHttpCaches("/api/mobile/v1/rtdc/census"))
        assertTrue(api.sensitiveNoStoreHeaders("/api/mobile/v1/rtdc/census").isEmpty())
    }

    @Test
    fun sessionIdentifiersMustMatchTheLowercaseVersionedContractBeforeNetwork() {
        val api = ApiClient(
            baseUrl = "https://example.invalid",
            tokenCoordinator = StaffTokenCoordinator(),
            transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
        )
        assertEquals(
            device.installationUuid,
            api.canonicalStaffSessionUuid(device.installationUuid),
        )
        for (invalid in listOf(
            "11111111-1111-4111-8111-11111111111A",
            "00000000-0000-0000-0000-000000000000",
            "not-a-uuid",
        )) {
            assertThrows(ApiException::class.java) {
                api.canonicalStaffSessionUuid(invalid)
            }
        }
    }
}

private class InMemorySharedPreferences(
    private val failCommit: Boolean = false,
) : SharedPreferences {
    private val values = mutableMapOf<String, Any?>()

    override fun getAll(): MutableMap<String, *> = values.toMutableMap()
    override fun getString(key: String?, defValue: String?): String? =
        values[key] as? String ?: defValue
    override fun getStringSet(key: String?, defValues: MutableSet<String>?): MutableSet<String>? =
        @Suppress("UNCHECKED_CAST") ((values[key] as? Set<String>)?.toMutableSet() ?: defValues)
    override fun getInt(key: String?, defValue: Int): Int = values[key] as? Int ?: defValue
    override fun getLong(key: String?, defValue: Long): Long = values[key] as? Long ?: defValue
    override fun getFloat(key: String?, defValue: Float): Float = values[key] as? Float ?: defValue
    override fun getBoolean(key: String?, defValue: Boolean): Boolean =
        values[key] as? Boolean ?: defValue
    override fun contains(key: String?): Boolean = values.containsKey(key)
    override fun registerOnSharedPreferenceChangeListener(
        listener: SharedPreferences.OnSharedPreferenceChangeListener?,
    ) = Unit
    override fun unregisterOnSharedPreferenceChangeListener(
        listener: SharedPreferences.OnSharedPreferenceChangeListener?,
    ) = Unit

    override fun edit(): SharedPreferences.Editor = object : SharedPreferences.Editor {
        private val pending = mutableMapOf<String, Any?>()
        private val removals = mutableSetOf<String>()
        private var clear = false

        override fun putString(key: String?, value: String?): SharedPreferences.Editor = apply {
            requireNotNull(key)
            pending[key] = value
        }
        override fun putStringSet(
            key: String?,
            values: MutableSet<String>?,
        ): SharedPreferences.Editor = apply {
            requireNotNull(key)
            pending[key] = values?.toSet()
        }
        override fun putInt(key: String?, value: Int): SharedPreferences.Editor = apply {
            requireNotNull(key)
            pending[key] = value
        }
        override fun putLong(key: String?, value: Long): SharedPreferences.Editor = apply {
            requireNotNull(key)
            pending[key] = value
        }
        override fun putFloat(key: String?, value: Float): SharedPreferences.Editor = apply {
            requireNotNull(key)
            pending[key] = value
        }
        override fun putBoolean(key: String?, value: Boolean): SharedPreferences.Editor = apply {
            requireNotNull(key)
            pending[key] = value
        }
        override fun remove(key: String?): SharedPreferences.Editor = apply {
            requireNotNull(key)
            removals += key
        }
        override fun clear(): SharedPreferences.Editor = apply { clear = true }
        override fun commit(): Boolean {
            if (failCommit) return false
            if (clear) values.clear()
            removals.forEach(values::remove)
            values.putAll(pending)
            return true
        }
        override fun apply() {
            commit()
        }
    }
}
