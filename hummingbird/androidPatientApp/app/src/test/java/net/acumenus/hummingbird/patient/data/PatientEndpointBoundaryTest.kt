package net.acumenus.hummingbird.patient.data

import net.acumenus.hummingbird.patient.BuildConfig
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody

class PatientEndpointBoundaryTest {
    @Test
    fun everyEndpointIsInsideThePatientApiRoot() {
        assertTrue(PatientEndpoints.staticPaths.isNotEmpty())
        PatientEndpoints.staticPaths.forEach { endpoint ->
            assertTrue(endpoint.startsWith(PatientEndpoints.ROOT + "/"))
            assertTrue(PatientEndpoints.requirePatientPath(endpoint) == endpoint)
        }
    }

    @Test
    fun staticInventoryContainsAllEightExactMethodPathOperations() {
        assertEquals(8, PatientEndpoints.staticOperations.size)
        assertTrue(
            PatientApiOperation(PatientHttpMethod.PUT, PatientEndpoints.PREFERENCES) in
                PatientEndpoints.staticOperations,
        )
        PatientEndpoints.staticOperations.forEach { operation ->
            assertEquals(
                operation.path,
                PatientEndpoints.requirePatientOperation(operation.method, operation.path),
            )
        }
        assertTrue(
            runCatching {
                PatientEndpoints.requirePatientOperation(
                    PatientHttpMethod.POST,
                    PatientEndpoints.PREFERENCES,
                )
            }.isFailure,
        )
    }

    @Test
    fun sourceInventoryContainsAllTwentyThreeConsumedPatientOnlyOperations() {
        assertEquals(23, PatientEndpoints.consumedOperationInventory.size)
        assertTrue("GET ${PatientEndpoints.SESSIONS}" in PatientEndpoints.consumedOperationInventory)
        assertTrue(
            "GET ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/discharge-readiness" in
                PatientEndpoints.consumedOperationInventory,
        )
        assertTrue(
            "GET ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/pathway/events" in
                PatientEndpoints.consumedOperationInventory,
        )
        assertTrue(
            "GET ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/rounds/summary" in
                PatientEndpoints.consumedOperationInventory,
        )
        assertTrue(
            "DELETE ${PatientEndpoints.ROOT}/me/sessions/{sessionUuid}" in
                PatientEndpoints.consumedOperationInventory,
        )
        assertEquals(
            setOf(
                "GET ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/message-topics",
                "GET ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/threads",
                "POST ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/threads",
                "POST ${PatientEndpoints.ROOT}/encounters/{encounterUuid}/education/{educationItemUuid}/clarifications",
                "GET ${PatientEndpoints.ROOT}/threads/{threadUuid}",
                "POST ${PatientEndpoints.ROOT}/threads/{threadUuid}/messages",
                "POST ${PatientEndpoints.ROOT}/threads/{threadUuid}/messages/{messageUuid}/amend",
                "POST ${PatientEndpoints.ROOT}/threads/{threadUuid}/close",
            ),
            PatientEndpoints.consumedOperationInventory.filter { operation ->
                operation.contains("message-topics") ||
                    operation.contains("threads") ||
                    operation.contains("/education/{educationItemUuid}/clarifications")
            }.toSet(),
        )
    }

    @Test
    fun preferenceClientUsesAuthenticatedPutAndDecodesProfileResponse() {
        var capturedMethod: String? = null
        var capturedPath: String? = null
        var capturedAuthorization: String? = null
        var capturedBody: String? = null
        val client = OkHttpClient.Builder()
            .addInterceptor { chain ->
                val request = chain.request()
                capturedMethod = request.method
                capturedPath = request.url.encodedPath
                capturedAuthorization = request.header("Authorization")
                capturedBody = request.body?.let { body ->
                    okio.Buffer().use { buffer ->
                        body.writeTo(buffer)
                        buffer.readUtf8()
                    }
                }
                Response.Builder()
                    .request(request)
                    .protocol(Protocol.HTTP_1_1)
                    .code(200)
                    .message("OK")
                    .body(profileEnvelope().toResponseBody())
                    .build()
            }
            .build()
        val api = PatientApiClient(
            configuration = PatientApiConfiguration(true, "https://example.test"),
            client = client,
        )

        val response = api.updatePreferences(
            "patient-access-test".toCharArray(),
            PatientPreferencesUpdate(reducedMotion = true, textSize = "large"),
        )

        assertEquals("PUT", capturedMethod)
        assertEquals(PatientEndpoints.PREFERENCES, capturedPath)
        assertEquals("Bearer patient-access-test", capturedAuthorization)
        assertTrue(capturedBody?.contains("\"reduced_motion\":true") == true)
        assertTrue(capturedBody?.contains("\"text_size\":\"large\"") == true)
        assertEquals("Sample Patient", response.data.displayName)
    }

    @Test
    fun educationClarificationUsesOnlyTheReleasedItemPathAndAContentOnlyBody() {
        var capturedMethod: String? = null
        var capturedPath: String? = null
        var capturedAuthorization: String? = null
        var capturedIdempotency: String? = null
        var capturedBody: String? = null
        val encounterUuid = "019f4d7a-3200-7000-8000-000000000123"
        val educationItemUuid = "019f4d7a-3200-7000-8000-000000000124"
        val client = OkHttpClient.Builder()
            .addInterceptor { chain ->
                val request = chain.request()
                capturedMethod = request.method
                capturedPath = request.url.encodedPath
                capturedAuthorization = request.header("Authorization")
                capturedIdempotency = request.header("Idempotency-Key")
                capturedBody = request.body?.let { body ->
                    okio.Buffer().use { buffer ->
                        body.writeTo(buffer)
                        buffer.readUtf8()
                    }
                }
                Response.Builder()
                    .request(request)
                    .protocol(Protocol.HTTP_1_1)
                    .code(201)
                    .message("Created")
                    .body(threadEnvelope().toResponseBody())
                    .build()
            }
            .build()
        val api = PatientApiClient(
            configuration = PatientApiConfiguration(true, "https://example.test"),
            client = client,
        )

        val response = api.requestEducationClarification(
            "patient-access-test".toCharArray(),
            encounterUuid,
            educationItemUuid,
            PatientEducationClarificationRequest(
                message = "Could you explain the safe timing in simpler words?",
                clientMessageUuid = "019f4d7a-3200-7000-8000-000000000125",
                urgentGuidanceVersion = "urgent-guidance-v1",
                idempotencyKey = "019f4d7a-3200-7000-8000-000000000126",
            ),
        )

        assertEquals("POST", capturedMethod)
        assertEquals(
            "${PatientEndpoints.ROOT}/encounters/$encounterUuid/education/$educationItemUuid/clarifications",
            capturedPath,
        )
        assertEquals("Bearer patient-access-test", capturedAuthorization)
        assertEquals("019f4d7a-3200-7000-8000-000000000126", capturedIdempotency)
        assertTrue(capturedBody?.contains("\"message\":\"Could you explain the safe timing in simpler words?\"") == true)
        assertTrue(capturedBody?.contains("\"client_message_uuid\"") == true)
        assertFalse(capturedBody?.contains("completion") == true)
        assertFalse(capturedBody?.contains("consent") == true)
        assertFalse(capturedBody?.contains("assessment") == true)
        assertEquals("019f4d7a-3200-7000-8000-000000000124", response.data.thread.threadUuid)
    }

    @Test
    fun projectionEndpointsRequireAnOpaqueUuidAndExactReadOnlySurface() {
        val encounterUuid = "019f4d7a-3200-7000-8000-000000000123"
        val paths = listOf(
            PatientEndpoints.today(encounterUuid),
            PatientEndpoints.pathway(encounterUuid),
            PatientEndpoints.pathwayEvents(encounterUuid),
            PatientEndpoints.dischargeReadiness(encounterUuid),
            PatientEndpoints.roundsSummary(encounterUuid),
            PatientEndpoints.careTeam(encounterUuid),
        )
        paths.forEach { assertEquals(it, PatientEndpoints.requirePatientPath(it)) }
        assertTrue(runCatching { PatientEndpoints.today("not-a-uuid") }.isFailure)
        assertTrue(
            runCatching {
                PatientEndpoints.requirePatientPath(
                    "${PatientEndpoints.ROOT}/encounters/$encounterUuid/messages",
                )
            }.isFailure,
        )
    }

    @Test
    fun messagingEndpointsRequireOpaqueUuidsAndExactMethods() {
        val encounterUuid = "019f4d7a-3200-7000-8000-000000000123"
        val threadUuid = "019f4d7a-3200-7000-8000-000000000124"
        val messageUuid = "019f4d7a-3200-7000-8000-000000000125"
        val operations = listOf(
            PatientApiOperation(PatientHttpMethod.GET, PatientEndpoints.messageTopics(encounterUuid)),
            PatientApiOperation(PatientHttpMethod.GET, PatientEndpoints.threads(encounterUuid)),
            PatientApiOperation(PatientHttpMethod.POST, PatientEndpoints.threads(encounterUuid)),
            PatientApiOperation(
                PatientHttpMethod.POST,
                PatientEndpoints.educationClarification(encounterUuid, messageUuid),
            ),
            PatientApiOperation(PatientHttpMethod.GET, PatientEndpoints.thread(threadUuid)),
            PatientApiOperation(PatientHttpMethod.POST, PatientEndpoints.messages(threadUuid)),
            PatientApiOperation(PatientHttpMethod.POST, PatientEndpoints.amendMessage(threadUuid, messageUuid)),
            PatientApiOperation(PatientHttpMethod.POST, PatientEndpoints.closeThread(threadUuid)),
        )

        operations.forEach { operation ->
            assertEquals(
                operation.path,
                PatientEndpoints.requirePatientOperation(operation.method, operation.path),
            )
        }
        assertTrue(runCatching { PatientEndpoints.thread("not-a-uuid") }.isFailure)
        assertTrue(runCatching { PatientEndpoints.amendMessage(threadUuid, "not-a-uuid") }.isFailure)
        assertTrue(runCatching { PatientEndpoints.educationClarification(encounterUuid, "not-a-uuid") }.isFailure)
        assertTrue(
            runCatching {
                PatientEndpoints.requirePatientOperation(
                    PatientHttpMethod.POST,
                    PatientEndpoints.messageTopics(encounterUuid),
                )
            }.isFailure,
        )
        assertTrue(
            runCatching {
                PatientEndpoints.requirePatientOperation(
                    PatientHttpMethod.GET,
                    PatientEndpoints.messages(threadUuid),
                )
            }.isFailure,
        )
    }

    @Test
    fun sessionEndpointsRequireCanonicalUuidExactDeleteAndNoStoreTransport() {
        val sessionUuid = "019f4d7a-3200-7000-8000-000000000131"
        val captured = mutableListOf<CapturedRequest>()
        val client = OkHttpClient.Builder()
            .addInterceptor { chain ->
                val request = chain.request()
                captured += CapturedRequest(
                    method = request.method,
                    path = request.url.encodedPath,
                    cacheControl = request.header("Cache-Control"),
                    pragma = request.header("Pragma"),
                )
                val body = if (request.method == "DELETE") {
                    sessionRevocationEnvelope(sessionUuid)
                } else {
                    sessionsEnvelope(sessionUuid)
                }
                Response.Builder()
                    .request(request)
                    .protocol(Protocol.HTTP_1_1)
                    .code(200)
                    .message("OK")
                    .body(body.toResponseBody())
                    .build()
            }
            .build()
        val api = PatientApiClient(
            PatientApiConfiguration(true, "https://example.test"),
            client,
        )

        val listed = api.patientSessions("patient-access-test".toCharArray())
        val revoked = api.revokePatientSession("patient-access-test".toCharArray(), sessionUuid)

        assertEquals(1, listed.data.sessions.size)
        assertEquals(sessionUuid, revoked.data.sessionUuid)
        assertEquals(listOf("GET", "DELETE"), captured.map(CapturedRequest::method))
        assertEquals(
            listOf(PatientEndpoints.SESSIONS, "${PatientEndpoints.SESSIONS}/$sessionUuid"),
            captured.map(CapturedRequest::path),
        )
        captured.forEach { request ->
            assertTrue(request.cacheControl?.contains("no-store") == true)
            assertTrue(request.cacheControl?.contains("no-cache") == true)
            assertEquals("no-cache", request.pragma)
        }
        assertTrue(runCatching { PatientEndpoints.session(sessionUuid.uppercase()) }.isFailure)
        assertTrue(runCatching { PatientEndpoints.session("not-a-uuid") }.isFailure)
        assertTrue(
            runCatching {
                PatientEndpoints.requirePatientOperation(
                    PatientHttpMethod.GET,
                    PatientEndpoints.session(sessionUuid),
                )
            }.isFailure,
        )
    }

    @Test
    fun threadCreationSendsUuidIdempotencyHeaderAndGuidanceVersion() {
        var capturedPath: String? = null
        var capturedIdempotencyKey: String? = null
        var capturedBody: String? = null
        val client = OkHttpClient.Builder()
            .addInterceptor { chain ->
                val request = chain.request()
                capturedPath = request.url.encodedPath
                capturedIdempotencyKey = request.header("Idempotency-Key")
                capturedBody = request.body?.let { body ->
                    okio.Buffer().use { buffer ->
                        body.writeTo(buffer)
                        buffer.readUtf8()
                    }
                }
                Response.Builder()
                    .request(request)
                    .protocol(Protocol.HTTP_1_1)
                    .code(201)
                    .message("Created")
                    .body(threadEnvelope().toResponseBody())
                    .build()
            }
            .build()
        val api = PatientApiClient(
            PatientApiConfiguration(true, "https://example.test"),
            client,
        )
        val request = PatientCreateThreadRequest(
            topicCode = "care_question",
            message = "Can someone explain today's plan?",
            clientMessageUuid = "019f4d7a-3200-7000-8000-000000000125",
            urgentGuidanceVersion = "approved-guidance-v3",
            idempotencyKey = "019f4d7a-3200-7000-8000-000000000126",
        )

        val result = api.createMessageThread(
            "patient-access-test".toCharArray(),
            "019f4d7a-3200-7000-8000-000000000123",
            request,
        )

        assertEquals(
            "${PatientEndpoints.ROOT}/encounters/019f4d7a-3200-7000-8000-000000000123/threads",
            capturedPath,
        )
        assertEquals(request.idempotencyKey, capturedIdempotencyKey)
        assertTrue(capturedBody?.contains("\"urgent_guidance_version\":\"approved-guidance-v3\"") == true)
        assertTrue(capturedBody?.contains("\"client_message_uuid\":\"${request.clientMessageUuid}\"") == true)
        assertFalse(capturedBody?.contains("idempotency_key") == true)
        assertEquals("Question for my care team", result.data.thread.topic.label)
    }

    @Test
    fun staffAndUnknownPathsAreRejected() {
        val staffMobilePath = "/api/" + "mobile/v1/home"
        val staffAuthPath = "/api/" + "auth/login"
        val lookalike = PatientEndpoints.ROOT + "-shadow/me"

        listOf(staffMobilePath, staffAuthPath, lookalike, "/api/patient/v2/me").forEach { path ->
            val result = runCatching { PatientEndpoints.requirePatientPath(path) }
            assertTrue("Expected rejection for $path", result.isFailure)
        }
    }

    @Test
    fun queryFragmentsAndUnregisteredPatientPathsAreRejected() {
        listOf(
            PatientEndpoints.PROFILE + "?expand=all",
            PatientEndpoints.PROFILE + "#fragment",
            PatientEndpoints.ROOT + "/admin",
        ).forEach { path ->
            assertTrue(runCatching { PatientEndpoints.requirePatientPath(path) }.isFailure)
        }
    }

    @Test
    fun defaultBuildKeepsPatientNetworkOff() {
        assertFalse(BuildConfig.PATIENT_API_ENABLED)
        assertFalse(PatientApiConfiguration.fromBuild().enabled)
    }

    @Test(expected = PatientApiDisabledException::class)
    fun disabledClientFailsBeforeMakingARequest() {
        PatientApiClient(
            configuration = PatientApiConfiguration(
                enabled = false,
                baseUrl = "https://example.test",
            ),
        ).profile(charArrayOf('x'))
    }

    @Test
    fun configurationRequiresCredentialFreeHttpsBaseUrl() {
        assertTrue(
            runCatching {
                PatientApiConfiguration(enabled = true, baseUrl = "http://example.test")
            }.isFailure,
        )
        assertTrue(
            runCatching {
                PatientApiConfiguration(enabled = true, baseUrl = "https://name:value@example.test")
            }.isFailure,
        )
        assertTrue(
            runCatching {
                PatientApiConfiguration(enabled = true, baseUrl = "https://example.test?unsafe=true")
            }.isFailure,
        )
        assertTrue(
            runCatching {
                PatientApiConfiguration(enabled = true, baseUrl = "https://example.test/unexpected-prefix")
            }.isFailure,
        )
    }


    private fun profileEnvelope(): String =
        """
        {
          "data": {
            "principal_uuid": "019f4d7a-3200-7000-8000-000000000002",
            "principal_type": "patient",
            "display_name": "Sample Patient",
            "email": null,
            "phone_e164": null,
            "email_verified": false,
            "phone_verified": false,
            "locale": "en-US",
            "timezone": "America/New_York",
            "preferences": {"reduced_motion": true, "text_size": "large"}
          },
          "meta": {"stale": false},
          "links": {}
        }
        """.trimIndent()

    private fun threadEnvelope(): String =
        """
        {
          "data": {
            "thread": {
              "thread_uuid": "019f4d7a-3200-7000-8000-000000000124",
              "topic": {
                "code": "care_question",
                "label": "Question for my care team",
                "description": "Ask a non-urgent question."
              },
              "status": "open",
              "ownership_state": "awaiting_team",
              "expected_response_window": "During this shift",
              "version": 1,
              "last_message_at": "2026-07-19T12:00:00Z",
              "created_at": "2026-07-19T12:00:00Z",
              "closed_at": null,
              "close_reason": null
            }
          },
          "meta": {"stale": false, "idempotency_replayed": false},
          "links": {}
        }
        """.trimIndent()

    private fun sessionsEnvelope(sessionUuid: String): String =
        """
        {
          "data": {
            "sessions": [{
              "session_uuid": "$sessionUuid",
              "current": true,
              "status": "active",
              "device": {
                "uuid": "019f4d7a-3200-7000-8000-000000000132",
                "platform": "android",
                "name": "Test phone",
                "app_version": "0.1.0",
                "os_version": "15"
              },
              "auth_method": "password",
              "assurance_level": "aal1",
              "last_seen_at": "2026-07-20T08:00:00Z",
              "expires_at": "2026-07-21T08:00:00Z",
              "created_at": "2026-07-19T08:00:00Z"
            }]
          },
          "meta": {"stale": false, "count": 1},
          "links": {}
        }
        """.trimIndent()

    private fun sessionRevocationEnvelope(sessionUuid: String): String =
        """
        {
          "data": {
            "session_uuid": "$sessionUuid",
            "revoked": true,
            "already_revoked": false
          },
          "meta": {"stale": false},
          "links": {}
        }
        """.trimIndent()

    private data class CapturedRequest(
        val method: String,
        val path: String,
        val cacheControl: String?,
        val pragma: String?,
    )
}
