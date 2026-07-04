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

    @Test
    fun decodesFlowWindowFixture() {
        val window = api.parseFlowWindow(fixture("mobile-flow-window.json"))

        assertEquals("bed_manager", window.lens.roleId)
        assertEquals("house", window.scope.type)
        assertTrue(window.spacesFloors.isNotEmpty())
        assertEquals(11, window.spacesFloors.size)
        assertEquals("MICU", window.spacesFloors.first { it.floor == 3 }.units.first().abbr)
        assertEquals("admit", window.events.first().kind)
        assertEquals("prod.operational_events", window.events.first().provenanceSource)
        assertTrue(window.projections.isNotEmpty())
        assertTrue(window.projections.all { it.confidence.isNotBlank() })
        assertTrue(window.projections.all { it.provenanceService.isNotBlank() })
        val surge = window.projections.first { it.kind == "surge_probability" }
        assertEquals("probable", surge.confidence)
        assertEquals(0.8, surge.provenanceReliability!!, 1e-9)
        val census = window.projections.first { it.kind == "predicted_census" }
        assertEquals(0, census.bandLower)
        assertEquals(2, census.bandUpper)
    }

    @Test
    fun decodesFlowFloorsFixture() {
        val doc = api.parseFlowFloors(fixture("mobile-flow-floors.json"))

        assertTrue(doc.floors.isNotEmpty())
        assertEquals("v1-346c77a48494", doc.version)
        val floor3 = doc.floors.first { it.floor == 3 }
        assertEquals(4, floor3.bounds.size)
        assertEquals(4, floor3.spaces.size)
        val bed = floor3.spaces.first { it.category == "bed" }
        assertEquals(693, bed.bedId)
        assertEquals(26, bed.unitId)
        assertEquals(4, bed.rect.size)
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
