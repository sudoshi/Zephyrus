package net.acumenus.hummingbird.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer

class TransportSecurityPolicyTest {
    @Test
    fun productionAllowsOnlyExactHttpsOrigin() {
        val environment = StaffTransportEnvironment.PRODUCTION

        assertTrue(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://zephyrus.acumenus.net",
            environment,
        ))
        assertTrue(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://zephyrus.acumenus.net:443/",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://zephyrus.acumenus.net",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://api.acumenus.net",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://zephyrus.acumenus.net:8443",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://zephyrus.acumenus.net/api",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://user:secret@zephyrus.acumenus.net",
            environment,
        ))
    }

    @Test
    fun productionAllowsOnlyExactSecureRealtimeOrigin() {
        val environment = StaffTransportEnvironment.PRODUCTION

        assertTrue(StaffTransportSecurityPolicy.permitsWebSocket(
            "wss",
            "zephyrus.acumenus.net",
            443,
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsWebSocket(
            "ws",
            "zephyrus.acumenus.net",
            443,
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsWebSocket(
            "wss",
            "reverb.acumenus.net",
            443,
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsWebSocket(
            "wss",
            "zephyrus.acumenus.net",
            8443,
            environment,
        ))
    }

    @Test
    fun developmentCleartextIsLoopbackOrEmulatorOnly() {
        val environment = StaffTransportEnvironment.DEVELOPMENT

        assertTrue(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://localhost:8001",
            environment,
        ))
        assertTrue(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://127.0.0.1:8001",
            environment,
        ))
        assertTrue(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://10.0.2.2:8001",
            environment,
        ))
        assertTrue(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://staff-dev.example.test",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://192.168.1.35:8001",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://[::1]:8001",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "http://staff-dev.example.test",
            environment,
        ))
        assertTrue(StaffTransportSecurityPolicy.permitsWebSocket(
            "ws",
            "10.0.2.2",
            8080,
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsWebSocket(
            "ws",
            "192.168.1.35",
            8080,
            environment,
        ))
    }

    @Test
    fun currentBuildConfigurationSatisfiesPolicy() {
        StaffTransportSecurityPolicy.requireBuildConfiguration()
    }

    @Test
    fun invalidPortsAreRejectedBeforeTransportConstruction() {
        val environment = StaffTransportEnvironment.DEVELOPMENT

        assertFalse(StaffTransportSecurityPolicy.permitsHttpBaseUrl(
            "https://localhost:65536",
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsWebSocket(
            "ws",
            "localhost",
            0,
            environment,
        ))
        assertFalse(StaffTransportSecurityPolicy.permitsWebSocket(
            "ws",
            "localhost",
            65_536,
            environment,
        ))
    }

    @Test
    fun apiClientDoesNotFollowRedirectResponses() = runBlocking {
        MockWebServer().use { server ->
            server.enqueue(
                MockResponse()
                    .setResponseCode(302)
                    .addHeader("Location", server.url("/redirect-target")),
            )
            server.enqueue(
                MockResponse()
                    .setResponseCode(200)
                    .setBody("""{"data":{"id":1,"name":"Redirected","email":"unsafe@example.test","role":{"code":"house","name":"House"}}}"""),
            )
            val api = ApiClient(
                baseUrl = server.url("/").toString().trimEnd('/'),
                tokenCoordinator = StaffTokenCoordinator(),
                transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
            )

            val failure = runCatching { api.me("staff-bearer") }.exceptionOrNull()

            assertTrue(failure is ApiException)
            assertEquals(302, (failure as ApiException).statusCode)
            assertEquals(1, server.requestCount)
        }
    }
}
