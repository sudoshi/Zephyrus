package net.acumenus.hummingbird.data

import net.acumenus.hummingbird.ui.theme.CapacityStatus
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class SharedDtoFixtureDecodeTest {
    private val api = ApiClient()

    @Test
    fun decodesAltitudeHomeFixture() {
        val home = api.parseAltitudeHome(fixture("mobile-altitude-home.json").getJSONObject("data"))

        assertEquals("A0", home.altitude)
        assertEquals("bed_manager", home.persona.roleId)
        assertEquals("houseCapacity", home.persona.home)
        assertEquals(CapacityStatus.WARNING, home.status.capacity)
        assertEquals(CapacityStatus.CRITICAL, home.forYouHead.first().capacity)
        assertEquals("bed_request.created", home.activity.first().eventType)
    }

    @Test
    fun decodesForYouFixtureAndPrefersVisualStatus() {
        val data = fixture("mobile-for-you.json").getJSONArray("data")
        val first = api.parseForYouItem(data.getJSONObject(0))
        val second = api.parseForYouItem(data.getJSONObject(1))

        assertEquals("transport-17", first.id)
        assertEquals(CapacityStatus.WARNING, first.capacity)
        assertEquals("evs-23", second.id)
        assertEquals(CapacityStatus.INFO, second.capacity)
    }

    @Test
    fun decodesActivityFixture() {
        val event = api.parseActivityEvent(fixture("mobile-activity-feed.json").getJSONArray("data").getJSONObject(0))

        assertEquals("transport.progressed", event.eventType)
        assertEquals("transport", event.domain)
        assertEquals("ptok_transport_17", event.patientContextRef)
        assertEquals("warning", event.statusValue)
        assertEquals("At risk", event.statusLabel)
    }

    @Test
    fun decodesPatientOperationalContextFixture() {
        val context = api.parsePatientContext(fixture("mobile-patient-operational-context.json").getJSONObject("data"))

        assertEquals("A2P", context.altitude)
        assertEquals("ptok_demo_flow_42", context.patient.patientContextRef)
        assertTrue(context.patient.phiMinimized)
        assertEquals(2, context.statusSpine.size)
        assertEquals(2, context.timeline.size)
        assertEquals(2, context.dependencies.size)
        assertEquals(1, context.actions.size)
    }

    private fun fixture(filename: String): JSONObject =
        JSONObject(File(repoRoot(), "docs/hummingbird/api-contract/fixtures/$filename").readText())

    private fun repoRoot(): File {
        var cursor = File(System.getProperty("user.dir")).absoluteFile
        while (cursor.parentFile != null) {
            if (File(cursor, "docs/hummingbird/api-contract/fixtures").isDirectory) {
                return cursor
            }
            cursor = cursor.parentFile
        }
        error("Unable to locate repository root from ${System.getProperty("user.dir")}")
    }
}
