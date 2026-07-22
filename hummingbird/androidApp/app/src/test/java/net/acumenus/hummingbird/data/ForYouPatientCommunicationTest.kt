package net.acumenus.hummingbird.data

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ForYouPatientCommunicationTest {
    private val api = ApiClient("https://zephyrus.example.test")
    private val workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192"

    @Test
    fun `decoder minimizes communication attention data and retains online view routing`() {
        val decoded = api.parseForYouItem(
            JSONObject()
                .put("id", "patient-communication-$workItemUuid")
                .put("type", PatientCommunicationForYou.TYPE)
                .put("domain", PatientCommunicationForYou.DOMAIN)
                .put("tier", "stat")
                .put("title", "Jane Doe sent a message")
                .put("subtitle", "A message body must never render here")
                .put("unit", "5 East")
                .put("patient_context_ref", "ptok_must_not_be_retained")
                .put("body", "not part of the mobile model")
                .put("identity", "not part of the mobile model")
                .put(
                    "actions",
                    JSONArray().put(JSONObject().put("kind", "view").put("online_only", true)),
                ),
        )

        assertEquals(PatientCommunicationForYou.TITLE, decoded.title)
        assertEquals(PatientCommunicationForYou.SUBTITLE, decoded.subtitle)
        assertEquals("critical", decoded.tier)
        assertNull(decoded.unit)
        assertNull(decoded.patientContextRef)
        assertEquals(workItemUuid, PatientCommunicationForYou.workItemUuid(decoded))

        val exposedFields = ForYouItem::class.java.declaredFields.map { it.name }.toSet()
        assertFalse("body" in exposedFields)
        assertFalse("identity" in exposedFields)
    }

    @Test
    fun `every role queue filter retains a server-authorized communication attention item`() {
        val item = attentionItem()

        QueueFilter.entries.forEach { filter ->
            assertTrue(
                "$filter must retain an authorized patient communication",
                ForYouQueuePolicy.keep(
                    filter = filter,
                    item = item,
                    unitName = "A deliberately different unit",
                    unitsByName = emptyMap(),
                ),
            )
            assertTrue(
                ForYouQueuePolicy.keepVisible(
                    canViewPatientCommunications = true,
                    filter = filter,
                    item = item,
                    unitName = "A deliberately different unit",
                    unitsByName = emptyMap(),
                ),
            )
            assertFalse(
                ForYouQueuePolicy.keepVisible(
                    canViewPatientCommunications = false,
                    filter = filter,
                    item = item,
                    unitName = null,
                    unitsByName = emptyMap(),
                ),
            )
        }
    }

    @Test
    fun `any restricted candidate is sanitized and only the original exact triple can route`() {
        val oversharedCandidates = listOf(
            JSONObject()
                .put("id", "bedreq-42")
                .put("type", "barrier")
                .put("domain", PatientCommunicationForYou.DOMAIN),
            JSONObject()
                .put("id", "patient-communication-$workItemUuid")
                .put("type", "barrier")
                .put("domain", "rtdc"),
            JSONObject()
                .put("id", "patient-communication-$workItemUuid")
                .put("type", PatientCommunicationForYou.TYPE)
                .put("domain", "rtdc"),
        )

        oversharedCandidates.forEach { candidate ->
            candidate
                .put("tier", "warning")
                .put("title", "Jane Doe sent a medication question")
                .put("subtitle", "The raw message body must not render")
                .put("unit", "5 East")
                .put("patient_context_ref", "ptok_must_not_be_retained")
            val decoded = api.parseForYouItem(candidate)

            assertEquals(PatientCommunicationForYou.TYPE, decoded.type)
            assertEquals(PatientCommunicationForYou.TITLE, decoded.title)
            assertEquals(PatientCommunicationForYou.SUBTITLE, decoded.subtitle)
            assertNull(decoded.domain)
            assertNull(decoded.unit)
            assertNull(decoded.patientContextRef)
            assertNull(PatientCommunicationForYou.workItemUuid(decoded))
        }
    }

    @Test
    fun `unrelated legacy item still uses its operational copy`() {
        val decoded = api.parseForYouItem(
            JSONObject()
                .put("id", "barrier-42")
                .put("type", "barrier")
                .put("domain", "rtdc")
                .put("tier", "warning")
                .put("title", "Operational barrier")
                .put("subtitle", "Needs an owner")
                .put("unit", "5 East"),
        )

        assertEquals("barrier", decoded.type)
        assertEquals("Operational barrier", decoded.title)
        assertEquals("Needs an owner", decoded.subtitle)
        assertEquals("5 East", decoded.unit)
    }

    @Test
    fun `routing accepts only the exact communication domain prefix and canonical UUID`() {
        assertEquals(workItemUuid, PatientCommunicationForYou.workItemUuid(attentionItem()))

        listOf(
            attentionItem(id = "patient-communication-not-a-uuid"),
            attentionItem(id = "patient-communication-$workItemUuid/reply"),
            attentionItem(id = "patient-communication-${workItemUuid.uppercase()}"),
            attentionItem(id = "patient-communication-"),
            attentionItem(id = "bedreq-$workItemUuid"),
            attentionItem(domain = "rtdc"),
            attentionItem(type = "barrier"),
        ).forEach { malformed ->
            assertNull(PatientCommunicationForYou.workItemUuid(malformed))
        }
    }

    @Test
    fun `attention urgency is derived from normalized tier without patient data`() {
        assertEquals("Immediate attention", PatientCommunicationForYou.urgencyLabel("critical"))
        assertEquals("Urgent", PatientCommunicationForYou.urgencyLabel("warning"))
        assertEquals("Within response target", PatientCommunicationForYou.urgencyLabel("success"))
        assertEquals("Needs review", PatientCommunicationForYou.urgencyLabel("info"))
    }

    private fun attentionItem(
        id: String = "patient-communication-$workItemUuid",
        type: String = PatientCommunicationForYou.TYPE,
        domain: String = PatientCommunicationForYou.DOMAIN,
    ) = ForYouItem(
        id = id,
        type = type,
        domain = domain,
        tier = "critical",
        title = PatientCommunicationForYou.TITLE,
        subtitle = PatientCommunicationForYou.SUBTITLE,
        unit = null,
        at = "2026-07-20T08:00:00-04:00",
        patientContextRef = null,
    )
}
