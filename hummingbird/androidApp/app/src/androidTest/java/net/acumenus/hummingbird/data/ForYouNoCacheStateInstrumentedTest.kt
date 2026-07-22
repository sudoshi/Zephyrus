package net.acumenus.hummingbird.data

import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ForYouNoCacheStateInstrumentedTest {
    private val workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192"
    private val communicationId = "patient-communication-$workItemUuid"

    @Test
    fun capabilityRevocationAndRefreshFailurePurgeNoCacheState() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        lateinit var viewModel: ForYouViewModel

        instrumentation.runOnMainSync {
            viewModel = ForYouViewModel(ApplicationProvider.getApplicationContext())
            val communication = item(
                id = communicationId,
                type = PatientCommunicationForYou.TYPE,
                domain = PatientCommunicationForYou.DOMAIN,
            )
            val operational = item(id = "barrier-42", type = "barrier", domain = "rtdc")

            viewModel.acceptLoadedQueue(listOf(communication, operational))
            assertEquals(listOf("barrier-42"), viewModel.items.map(ForYouItem::id))

            viewModel.updatePatientCommunicationAccess(true)
            viewModel.acceptLoadedQueue(listOf(communication, operational))
            viewModel.acceptCensusLookup(census())
            viewModel.beginAction(communicationId)
            assertTrue(viewModel.items.any { it.id == communicationId })
            assertEquals("https://zephyrus.example.test/rtdc", viewModel.webLink)
            assertTrue(communicationId in viewModel.workingItemIds)

            viewModel.updatePatientCommunicationAccess(false)
            assertFalse(viewModel.items.any { it.id.contains(workItemUuid) })
            assertFalse(viewModel.workingItemIds.any { it.contains(workItemUuid) })
            assertEquals(listOf("barrier-42"), viewModel.items.map(ForYouItem::id))

            viewModel.beginAction("barrier-42")
            viewModel.handleQueueLoadFailure(ApiException("Temporary refresh failure", 503))
            assertTrue(viewModel.items.isEmpty())
            assertTrue(viewModel.unitsByName.isEmpty())
            assertNull(viewModel.webLink)
            assertTrue(viewModel.workingItemIds.isEmpty())
            assertFalse(viewModel.loading)
            assertEquals("Temporary refresh failure", viewModel.error)
        }
    }

    private fun item(id: String, type: String, domain: String?) = ForYouItem(
        id = id,
        type = type,
        domain = domain,
        tier = "warning",
        title = "Safe operational title",
        subtitle = "Safe operational subtitle",
        unit = null,
        at = null,
        patientContextRef = null,
    )

    private fun census() = CensusResult(
        units = listOf(
            CensusUnit(
                unitId = 85,
                name = "5 East",
                type = "medical_surgical",
                staffedBedCount = 24,
                occupied = 22,
                available = 2,
                blocked = 0,
                canAdmit = 2,
                bedNeed = 0,
                status = "success",
            ),
        ),
        asOf = "2026-07-20T08:00:00-04:00",
        stale = false,
        webLink = "https://zephyrus.example.test/rtdc",
    )
}
