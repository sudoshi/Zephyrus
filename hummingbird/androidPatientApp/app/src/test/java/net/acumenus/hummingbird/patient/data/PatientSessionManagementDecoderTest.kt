package net.acumenus.hummingbird.patient.data

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PatientSessionManagementDecoderTest {
    private val sessionUuid = "019f4d7a-3200-7000-8000-000000000131"

    @Test
    fun decodesOnlyContractSafeSessionAndDeviceMetadata() {
        val envelope = PatientEnvelopeDecoder.patientSessions(
            envelopeWithSessions(
                JSONArray().put(
                    sessionJson(sessionUuid)
                        .put("server_internal_id", 4242)
                        .put("token_digest", "must-not-enter-model"),
                ),
            ),
        )

        val session = envelope.data.sessions.single()
        assertEquals(sessionUuid, session.sessionUuid)
        assertTrue(session.current)
        assertEquals("Pixel test phone", session.device.name)
        assertEquals("android", session.device.platform)
        assertEquals("aal1", session.assuranceLevel)
        assertEquals("2026-07-20T08:00:00Z", session.lastSeenAt)

        assertEquals(
            setOf(
                "sessionUuid",
                "current",
                "status",
                "device",
                "authMethod",
                "assuranceLevel",
                "lastSeenAt",
                "expiresAt",
                "createdAt",
            ),
            PatientDeviceSession::class.java.declaredFields
                .map { it.name }
                .filterNot { it.firstOrNull() == '$' }
                .toSet(),
        )
        assertFalse(PatientDeviceSession::class.java.declaredFields.any { it.name.contains("token") })
    }

    @Test
    fun nullableDeviceAndTimeFieldsRemainNullableForSafeUiFallbacks() {
        val value = sessionJson(sessionUuid)
        value.put("device", JSONObject()
            .put("uuid", JSONObject.NULL)
            .put("platform", JSONObject.NULL)
            .put("name", JSONObject.NULL)
            .put("app_version", JSONObject.NULL)
            .put("os_version", JSONObject.NULL))
        value.put("assurance_level", JSONObject.NULL)
        value.put("last_seen_at", JSONObject.NULL)
        value.put("expires_at", JSONObject.NULL)
        value.put("created_at", JSONObject.NULL)

        val session = PatientEnvelopeDecoder.patientSessions(
            envelopeWithSessions(JSONArray().put(value)),
        ).data.sessions.single()

        assertNull(session.device.uuid)
        assertNull(session.device.name)
        assertNull(session.assuranceLevel)
        assertNull(session.lastSeenAt)
        assertNull(session.expiresAt)
        assertNull(session.createdAt)
    }

    @Test
    fun rejectsMoreThanOneHundredRowsAndMalformedOpaqueUuids() {
        val tooMany = JSONArray()
        repeat(101) { index ->
            tooMany.put(sessionJson("019f4d7a-3200-7000-8000-${index.toString().padStart(12, '0')}"))
        }
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessions(envelopeWithSessions(tooMany))
            }.isFailure,
        )

        val malformedSession = sessionJson(sessionUuid).put("session_uuid", "not-a-uuid")
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessions(
                    envelopeWithSessions(JSONArray().put(malformedSession)),
                )
            }.isFailure,
        )

        val uppercaseSession = sessionJson(sessionUuid.uppercase())
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessions(
                    envelopeWithSessions(JSONArray().put(uppercaseSession)),
                )
            }.isFailure,
        )
    }

    @Test
    fun rejectsContractDriftOversizedMetadataControlCharactersAndInvalidTimes() {
        val driftedRows = listOf(
            sessionJson(sessionUuid).put("status", "revoked"),
            sessionJson(sessionUuid).put("auth_method", "staff_sso"),
            sessionJson(sessionUuid).apply {
                getJSONObject("device").put("platform", "desktop")
            },
            sessionJson(sessionUuid).apply {
                getJSONObject("device").put("name", "n".repeat(191))
            },
            sessionJson(sessionUuid).apply {
                getJSONObject("device").put("app_version", "a".repeat(81))
            },
            sessionJson(sessionUuid).apply {
                getJSONObject("device").put("os_version", "o".repeat(81))
            },
            sessionJson(sessionUuid).put("assurance_level", "s".repeat(33)),
            sessionJson(sessionUuid).apply {
                getJSONObject("device").put("name", "Injected\nname")
            },
            sessionJson(sessionUuid).put("last_seen_at", "soon"),
            sessionJson(sessionUuid).put("expires_at", "2026-07-21 08:00:00"),
            sessionJson(sessionUuid).put("created_at", "July 19"),
        )

        driftedRows.forEach(::assertSessionRejected)
    }

    @Test
    fun rejectsDuplicateSessionsAndMoreThanOneCurrentMarker() {
        val duplicateRows = JSONArray()
            .put(sessionJson(sessionUuid))
            .put(sessionJson(sessionUuid).put("current", false))
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessions(envelopeWithSessions(duplicateRows))
            }.isFailure,
        )

        val multipleCurrentRows = JSONArray()
            .put(sessionJson(sessionUuid))
            .put(sessionJson("019f4d7a-3200-7000-8000-000000000133"))
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessions(envelopeWithSessions(multipleCurrentRows))
            }.isFailure,
        )
    }

    @Test
    fun revocationDecoderRetainsOnlyGovernedResultFields() {
        val result = PatientEnvelopeDecoder.patientSessionRevocation(
            JSONObject()
                .put(
                    "data",
                    JSONObject()
                        .put("session_uuid", sessionUuid)
                        .put("revoked", true)
                        .put("already_revoked", true)
                        .put("internal_reason", "not exposed"),
                )
                .put("meta", JSONObject().put("stale", false))
                .put("links", JSONObject())
                .toString(),
        ).data

        assertEquals(sessionUuid, result.sessionUuid)
        assertTrue(result.revoked)
        assertTrue(result.alreadyRevoked)
        assertEquals(
            setOf("sessionUuid", "revoked", "alreadyRevoked"),
            PatientSessionRevocation::class.java.declaredFields
                .map { it.name }
                .filterNot { it.firstOrNull() == '$' }
                .toSet(),
        )
    }

    @Test
    fun revocationDecoderRequiresCanonicalHandleAndConfirmedRevocation() {
        val notRevoked = JSONObject()
            .put(
                "data",
                JSONObject()
                    .put("session_uuid", sessionUuid)
                    .put("revoked", false)
                    .put("already_revoked", false),
            )
            .put("meta", JSONObject().put("stale", false))
            .put("links", JSONObject())
            .toString()
        assertTrue(
            runCatching { PatientEnvelopeDecoder.patientSessionRevocation(notRevoked) }.isFailure,
        )

        val uppercaseHandle = JSONObject(notRevoked)
            .apply {
                getJSONObject("data")
                    .put("session_uuid", sessionUuid.uppercase())
                    .put("revoked", true)
            }
            .toString()
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessionRevocation(uppercaseHandle)
            }.isFailure,
        )
    }

    private fun envelopeWithSessions(sessions: JSONArray): String = JSONObject()
        .put("data", JSONObject().put("sessions", sessions))
        .put("meta", JSONObject().put("stale", false).put("count", sessions.length()))
        .put("links", JSONObject())
        .toString()

    private fun assertSessionRejected(session: JSONObject) {
        assertTrue(
            runCatching {
                PatientEnvelopeDecoder.patientSessions(
                    envelopeWithSessions(JSONArray().put(session)),
                )
            }.isFailure,
        )
    }

    private fun sessionJson(uuid: String): JSONObject = JSONObject()
        .put("session_uuid", uuid)
        .put("current", true)
        .put("status", "active")
        .put(
            "device",
            JSONObject()
                .put("uuid", "019f4d7a-3200-7000-8000-000000000132")
                .put("platform", "android")
                .put("name", "Pixel test phone")
                .put("app_version", "0.1.0")
                .put("os_version", "15"),
        )
        .put("auth_method", "password")
        .put("assurance_level", "aal1")
        .put("last_seen_at", "2026-07-20T08:00:00Z")
        .put("expires_at", "2026-07-21T08:00:00Z")
        .put("created_at", "2026-07-19T08:00:00Z")
}
