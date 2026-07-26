package net.acumenus.hummingbird.patient.ui

import net.acumenus.hummingbird.patient.data.PatientMessageThread
import net.acumenus.hummingbird.patient.data.PatientMessageThreadTopic
import org.junit.Assert.assertEquals
import org.junit.Test

class PatientMessagingPresentationTest {
    @Test
    fun threadStatesUseTheSamePatientLanguageAsIosWithoutRoutingMetadata() {
        mapOf(
            "awaiting_team" to "Waiting for your care team",
            "assigned" to "With your care team",
            "acknowledged" to "Seen by your care team",
            "responded" to "Care team responded",
            "rerouted" to "Finding the right care team member",
            "escalated" to "Receiving added attention",
            "closed" to "Conversation closed",
        ).forEach { (ownershipState, expectedLabel) ->
            assertEquals(expectedLabel, thread(ownershipState = ownershipState).patientVisibleState())
        }
    }

    @Test
    fun closedAndUnknownThreadStatesStayPatientSafe() {
        assertEquals(
            "Conversation closed",
            thread(status = "closed", ownershipState = "unexpected").patientVisibleState(),
        )
        assertEquals(
            "Status being confirmed",
            thread(ownershipState = "unexpected").patientVisibleState(),
        )
    }

    @Test
    fun messageDeliveryStatesRemainSpecificOnlyWhenTheContractRecognizesThem() {
        mapOf(
            "sent" to "Sent",
            "delivered" to "Delivered",
            "assigned" to "With your care team",
            "acknowledged" to "Seen by your care team",
            "responded" to "Responded",
            "closed" to "Closed",
            "unexpected" to "Status being confirmed",
        ).forEach { (deliveryState, expectedLabel) ->
            assertEquals(expectedLabel, deliveryState.patientVisibleDeliveryState())
        }
    }

    private fun thread(
        status: String = "open",
        ownershipState: String,
    ) = PatientMessageThread(
        threadUuid = "01982e0c-709a-7ef0-9000-000000000002",
        topic = PatientMessageThreadTopic(
            code = "care_question",
            label = "Question for my care team",
            description = "A non-urgent question.",
        ),
        status = status,
        ownershipState = ownershipState,
        expectedResponseWindow = "During this shift",
        version = 1,
        lastMessageAt = "2026-07-25T12:00:00Z",
        createdAt = "2026-07-25T12:00:00Z",
        closedAt = null,
        closeReason = null,
        messages = emptyList(),
    )
}
