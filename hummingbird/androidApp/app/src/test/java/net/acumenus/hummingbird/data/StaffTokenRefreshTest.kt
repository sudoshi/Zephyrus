package net.acumenus.hummingbird.data

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import java.util.Collections
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

class StaffTokenRefreshTest {
    private lateinit var server: MockWebServer
    private lateinit var store: RecordingTokenStore
    private lateinit var coordinator: StaffTokenCoordinator
    private lateinit var api: ApiClient

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
        store = RecordingTokenStore()
        coordinator = StaffTokenCoordinator(store)
        api = ApiClient(server.url("/").toString().removeSuffix("/"), coordinator)
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `401 GET rotates once and replays only the read with the new access token`() = runBlocking {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 600_000))
        server.enqueue(jsonResponse(401, """{"error":{"code":"unauthenticated","message":"Expired."}}"""))
        server.enqueue(tokenResponse("new-access", "new-refresh"))
        server.enqueue(meResponse())

        val me = api.me("old-access")

        assertEquals("staff-user", me.username)
        val firstRead = server.takeRequest()
        val refresh = server.takeRequest()
        val replay = server.takeRequest()
        assertEquals("/api/mobile/v1/me", firstRead.path)
        assertEquals("Bearer old-access", firstRead.getHeader("Authorization"))
        assertEquals("/api/auth/token/refresh", refresh.path)
        assertEquals("POST", refresh.method)
        assertEquals("Bearer old-refresh", refresh.getHeader("Authorization"))
        assertEquals("/api/mobile/v1/me", replay.path)
        assertEquals("GET", replay.method)
        assertEquals("Bearer new-access", replay.getHeader("Authorization"))
        assertEquals("new-refresh", store.saved?.refreshToken)
    }

    @Test
    fun `401 after the single GET replay clears the rejected generation`() = runBlocking {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 600_000))
        server.enqueue(jsonResponse(401, """{"error":{"code":"unauthenticated","message":"Expired."}}"""))
        server.enqueue(tokenResponse("new-access", "new-refresh"))
        server.enqueue(jsonResponse(401, """{"error":{"code":"unauthenticated","message":"Expired."}}"""))

        val failure = runCatching { api.me("old-access") }.exceptionOrNull()

        assertTrue(failure is ApiException)
        assertEquals(401, (failure as ApiException).statusCode)
        assertEquals(3, server.requestCount)
        assertNull(coordinator.snapshot())
        assertNull(store.saved)
    }

    @Test
    fun `mutation 401 is never refreshed or replayed automatically`() = runBlocking {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 600_000))
        server.enqueue(jsonResponse(401, """{"error":{"code":"unauthenticated","message":"Expired."}}"""))

        val failure = runCatching { api.resolveBarrier("old-access", 17) }.exceptionOrNull()

        assertTrue(failure is ApiException)
        assertEquals(401, (failure as ApiException).statusCode)
        assertEquals(1, server.requestCount)
        val mutation = server.takeRequest()
        assertEquals("POST", mutation.method)
        assertEquals("/api/mobile/v1/rtdc/barriers/17/resolve", mutation.path)
    }

    @Test
    fun `near-expiry mutation refreshes before the first write is transmitted`() = runBlocking {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 30_000))
        server.enqueue(tokenResponse("new-access", "new-refresh"))
        server.enqueue(jsonResponse(200, """{"data":{"resolved":true}}"""))

        assertTrue(api.resolveBarrier("old-access", 17))

        val refresh = server.takeRequest()
        val mutation = server.takeRequest()
        assertEquals("/api/auth/token/refresh", refresh.path)
        assertEquals("Bearer old-refresh", refresh.getHeader("Authorization"))
        assertEquals("/api/mobile/v1/rtdc/barriers/17/resolve", mutation.path)
        assertEquals("POST", mutation.method)
        assertEquals("Bearer new-access", mutation.getHeader("Authorization"))
        assertEquals(2, server.requestCount)
    }

    @Test
    fun `concurrent unauthorized reads share one refresh rotation`() {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 600_000))
        val refreshCalls = AtomicInteger()
        val start = CountDownLatch(1)
        val done = CountDownLatch(8)
        val results = Collections.synchronizedList(mutableListOf<String>())
        val failures = Collections.synchronizedList(mutableListOf<Throwable>())

        repeat(8) {
            Thread {
                try {
                    start.await()
                    results += coordinator.bearerAfterUnauthorized("old-access") {
                        refreshCalls.incrementAndGet()
                        Thread.sleep(50)
                        session("new-access", "new-refresh", expiresInMs = 600_000)
                    }
                } catch (error: Throwable) {
                    failures += error
                } finally {
                    done.countDown()
                }
            }.start()
        }

        start.countDown()
        assertTrue(done.await(5, TimeUnit.SECONDS))
        assertTrue(failures.isEmpty())
        assertEquals(1, refreshCalls.get())
        assertEquals(List(8) { "new-access" }, results.sorted())
    }

    @Test
    fun `terminal refresh failure clears the protected session`() {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 600_000))

        val failure = runCatching {
            coordinator.bearerAfterUnauthorized("old-access") {
                throw ApiException("Server detail must not escape.", 401)
            }
        }.exceptionOrNull()

        assertTrue(failure is ApiException)
        assertEquals(401, (failure as ApiException).statusCode)
        assertEquals("Your session has expired. Please sign in again.", failure.message)
        assertNull(coordinator.snapshot())
        assertNull(store.saved)
    }

    @Test
    fun `server detected refresh reuse clears session without leaking diagnostic detail`() = runBlocking {
        coordinator.install(session("old-access", "reused-refresh", expiresInMs = 600_000))
        server.enqueue(jsonResponse(401, """{"error":{"code":"unauthenticated","message":"Expired."}}"""))
        server.enqueue(
            jsonResponse(
                401,
                """{"error":{"code":"invalid_refresh_token","message":"Server-only family diagnostic."}}""",
            ),
        )

        val failure = runCatching { api.me("old-access") }.exceptionOrNull()

        assertTrue(failure is ApiException)
        assertEquals(401, (failure as ApiException).statusCode)
        assertEquals("Your session has expired. Please sign in again.", failure.message)
        assertEquals(2, server.requestCount)
        val originalRead = server.takeRequest()
        val refresh = server.takeRequest()
        assertEquals("/api/mobile/v1/me", originalRead.path)
        assertEquals("/api/auth/token/refresh", refresh.path)
        assertEquals("Bearer reused-refresh", refresh.getHeader("Authorization"))
        assertNull(coordinator.snapshot())
        assertNull(store.saved)
    }

    @Test
    fun `transient proactive refresh failure uses the still-valid access token`() {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 30_000))

        val bearer = coordinator.bearerBeforeRequest("old-access") {
            throw ApiException("Service unavailable.", 503)
        }

        assertEquals("old-access", bearer)
        assertEquals("old-refresh", coordinator.snapshot()?.refreshToken)
    }

    @Test
    fun `protected persistence failure clears the consumed generation`() {
        coordinator.install(session("old-access", "old-refresh", expiresInMs = 30_000))
        store.rejectSaves = true

        val failure = runCatching {
            coordinator.bearerBeforeRequest("old-access") {
                session("new-access", "new-refresh", expiresInMs = 600_000)
            }
        }.exceptionOrNull()

        assertTrue(failure is ApiException)
        assertEquals(401, (failure as ApiException).statusCode)
        assertNull(coordinator.snapshot())
        assertNull(store.saved)
    }

    private fun session(access: String, refresh: String, expiresInMs: Long): StaffTokenSession =
        StaffTokenSession(
            accessToken = access,
            refreshToken = refresh,
            accessExpiresAtEpochMs = System.currentTimeMillis() + expiresInMs,
        )

    private fun tokenResponse(access: String, refresh: String): MockResponse =
        jsonResponse(
            200,
            """
            {
              "token_type":"Bearer",
              "access_token":"$access",
              "refresh_token":"$refresh",
              "expires_in":1800,
              "abilities":["mobile:read","mobile:act"]
            }
            """.trimIndent(),
        )

    private fun meResponse(): MockResponse =
        jsonResponse(
            200,
            """
            {
              "data":{
                "id":7,
                "name":"Staff User",
                "username":"staff-user",
                "roles":["bedside_nurse"],
                "workflow_preference":"rtdc",
                "is_admin":false,
                "can":{}
              },
              "meta":{"stale":false},
              "links":{}
            }
            """.trimIndent(),
        )

    private fun jsonResponse(code: Int, body: String): MockResponse =
        MockResponse()
            .setResponseCode(code)
            .setHeader("Content-Type", "application/json")
            .setBody(body)

    private class RecordingTokenStore : StaffTokenStore {
        @Volatile var saved: StaffTokenSession? = null
        @Volatile var rejectSaves = false
        override fun load(): StaffTokenSession? = saved
        override fun save(session: StaffTokenSession): Boolean {
            if (rejectSaves) return false
            saved = session
            return true
        }
        override fun clear() {
            saved = null
        }
    }
}
