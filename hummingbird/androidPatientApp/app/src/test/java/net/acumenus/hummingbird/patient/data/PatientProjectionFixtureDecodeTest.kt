package net.acumenus.hummingbird.patient.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class PatientProjectionFixtureDecodeTest {
    @Test
    fun decodesTodayFixture() {
        val today = PatientEnvelopeDecoder.today(fixture("patient-today.json"))

        assertEquals("today", today.data.kind)
        assertEquals("planned", today.data.content.schedule.first().status)
        assertEquals("other", today.data.content.schedule.first().category)
        assertEquals("result_pending", today.data.content.schedule.last().status)
        assertEquals("test", today.data.content.schedule.last().category)
        assertEquals("Reference inpatient unit", today.data.content.careLocation?.unitDisplayName)
        assertEquals("In the next day or two", today.data.content.dischargeOutlook?.estimatedRange)
        assertEquals("Tell your care team what you would like explained today.", today.data.content.questions.single())
        assertTrue(today.data.content.schedule.first().canChange)
        assertEquals("patient-state-vocabulary.v2-draft", today.meta.stateVocabularyVersion)
    }

    @Test
    fun decodesPathwayFixture() {
        val pathway = PatientEnvelopeDecoder.pathway(fixture("patient-pathway.json"))

        assertEquals("pathway", pathway.data.kind)
        assertEquals(4, pathway.data.content.stages.size)
        assertEquals("completed", pathway.data.content.stages.first().status)
        assertTrue(pathway.data.content.goals.any { it.authorType == "patient" })
    }

    @Test
    fun decodesPathwayEventsFixture() {
        val events = PatientEnvelopeDecoder.pathwayEvents(fixture("patient-pathway-events.json"))

        assertEquals("pathway_events", events.data.kind)
        assertEquals(4, events.data.content.events.size)
        assertTrue(events.data.content.events.any { it.category == "transport" })
    }

    @Test
    fun decodesForwardCompatiblePathwayEventsFixtureWithoutExposingUnknownVocabulary() {
        val events = PatientEnvelopeDecoder.pathwayEvents(
            fixture("patient-pathway-events-forward-compatible.json"),
        )

        val first = events.data.content.events.first()
        assertEquals("future_navigation", first.category)
        assertEquals("Status being confirmed", PatientStateVocabulary.label(
            first.category.orEmpty(),
            PatientStateDomain.PATHWAY_EVENT_CATEGORY,
        ))
        assertNull(first.detail)
        assertEquals(9_007_199_254_740_993L, events.meta.version)
        assertEquals(256, events.data.content.notices.size)
    }

    @Test
    fun decodesDischargeReadinessFixture() {
        val discharge = PatientEnvelopeDecoder.dischargeReadiness(fixture("patient-discharge-readiness.json"))

        assertEquals("discharge_readiness", discharge.data.kind)
        assertTrue(discharge.data.content.criteria.any { it.status == "pending" })
        assertEquals("speak_with_bedside_staff", discharge.data.content.contacts.single().route)
    }

    @Test
    fun decodesRoundsSummaryFixture() {
        val rounds = PatientEnvelopeDecoder.roundsSummary(fixture("patient-rounds-summary.json"))

        assertEquals("rounds_summary", rounds.data.kind)
        assertEquals("Earlier today", rounds.data.content.roundWindow)
        assertTrue(rounds.data.content.topics.any { it.status == "current" })
    }

    @Test
    fun decodesCareTeamFixture() {
        val careTeam = PatientEnvelopeDecoder.careTeam(fixture("patient-care-team.json"))

        assertEquals("care_team", careTeam.data.kind)
        assertEquals("Care coordination", careTeam.data.content.members.single().role)
        assertTrue(careTeam.data.content.communicationOptions.contains("call_button_for_urgent_help"))
    }

    private fun fixture(filename: String): String =
        JSONObject(File(repoRoot(), "docs/hummingbird/api-contract/fixtures/patient/$filename").readText()).toString()

    private fun repoRoot(): File {
        var cursor = File(System.getProperty("user.dir") ?: ".").absoluteFile
        while (true) {
            if (File(cursor, "docs/hummingbird/api-contract/fixtures/patient").isDirectory) {
                return cursor
            }
            cursor = cursor.parentFile ?: break
        }
        error("Unable to locate repository root from ${System.getProperty("user.dir")}")
    }
}
