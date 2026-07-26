package net.acumenus.hummingbird.data

import kotlinx.coroutines.flow.toList
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class EddyStreamingTransportTest {
    @Test
    fun `stream request is no-store authorized and never idempotency replayable`() = runBlocking {
        MockWebServer().use { server ->
            server.enqueue(
                MockResponse()
                    .setResponseCode(200)
                    .setHeader("Content-Type", "text/event-stream")
                    .setBody(
                        """
                        data: {"conversation_id":"conversation-1"}

                        data: {"token":"Review "}

                        data: {"token":"the capacity board."}

                        data: {"complete":true,"clean_reply":"Review the capacity board.","provider":"ollama","proposed_action":{"action_type":"flag_barrier"}}

                        data: [DONE]

                        """.trimIndent() + "\n\n",
                    ),
            )
            server.start()
            val api = ApiClient(
                baseUrl = server.url("/").toString().removeSuffix("/"),
                tokenCoordinator = StaffTokenCoordinator(),
                transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
            )

            val events = api.eddyChatStream(
                bearer = "staff-token",
                message = "What needs review?",
                conversationId = null,
                persona = "bed_manager",
                pageContext = "house",
                pageComponent = "House capacity",
                pageData = mapOf("scope_ref" to "house"),
            ).toList()

            assertEquals(
                listOf(
                    EddyStreamEvent.ConversationStarted("conversation-1"),
                    EddyStreamEvent.Token("Review "),
                    EddyStreamEvent.Token("the capacity board."),
                    EddyStreamEvent.Complete("Review the capacity board.", "ollama"),
                    EddyStreamEvent.Done,
                ),
                events,
            )
            val request = server.takeRequest()
            assertEquals("POST", request.method)
            assertEquals("/api/mobile/v1/eddy/chat/stream?persona=bed_manager", request.path)
            assertEquals("Bearer staff-token", request.getHeader("Authorization"))
            assertEquals("text/event-stream", request.getHeader("Accept"))
            assertEquals("no-store", request.getHeader("Cache-Control"))
            assertEquals("no-cache", request.getHeader("Pragma"))
            assertNull(request.getHeader("Idempotency-Key"))
            val body = JSONObject(request.body.readUtf8())
            assertEquals("hummingbird", body.getString("surface"))
            assertEquals("house", body.getString("page_context"))
            assertEquals("house", body.getJSONObject("page_data").getString("scope_ref"))
        }
    }

    @Test
    fun `parser ignores malformed frames and never exposes proposed action markup`() {
        val parser = EddySseFrameParser()
        assertTrue(parser.consume("data: not-json").isEmpty())
        assertTrue(parser.consume("").isEmpty())
        assertTrue(parser.consume("event: ignored").isEmpty())
        assertTrue(parser.consume("data: {\"persisted\":true,\"proposed_action\":{\"action_type\":\"flag_barrier\"}}").isEmpty())
        assertTrue(parser.consume("").isEmpty())

        assertEquals("Review ", EddyStreamDisplayText.provisional("Review <propose_act"))
        assertEquals("Review ", EddyStreamDisplayText.provisional("Review <propose_action>{\"action_type\":\"flag_barrier\"}"))
        assertEquals("Reviewed.", EddyStreamDisplayText.terminal("Reviewed."))
        assertFalse(EddyStreamDisplayText.provisional("Review <propose_action>draft").contains("draft"))
    }
}
