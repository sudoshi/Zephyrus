package net.acumenus.hummingbird.data

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.util.UUID

class PatientCommunicationsContractTest {
    private val api = ApiClient(
        baseUrl = "https://zephyrus.example.test",
        transportEnvironment = StaffTransportEnvironment.DEVELOPMENT,
    )

    @Test
    fun `work item decoder follows restricted OpenAPI shape without internal routing fields`() {
        val item = api.parsePatientCommunicationWorkItem(workItemFixture())

        assertEquals("Medication question", item.topic.label)
        assertEquals("5 East", item.unit?.label)
        assertEquals("5 East care team", item.pool.label)
        assertEquals(7, item.workItemVersion)
        assertEquals(11, item.threadVersion)
        assertTrue(item.isEscalationDue)
        assertEquals("I have a question about tonight's medicine.", item.messages.single().body)

        val exposedFields = PatientCommunicationWorkItem::class.java.declaredFields.map { it.name }.toSet()
        assertFalse("routingPolicyVersion" in exposedFields)
        assertFalse("responsibilityPoolRefDigest" in exposedFields)
        assertFalse("metadata" in exposedFields)
    }

    @Test
    fun `mutation decoder retains only work item patient visible message and replay evidence`() {
        val data = JSONObject()
            .put("work_item", workItemFixture())
            .put(
                "message",
                JSONObject()
                    .put("message_uuid", "019f7cb6-4d44-73e1-b28c-82bea62c4193")
                    .put("sender_display_role", "Care team")
                    .put("visibility", "patient_visible")
                    .put("message_kind", "message")
                    .put("body", "We will review this with you at the bedside.")
                    .put("delivery_state", "responded")
                    .put("sent_at", "2026-07-19T16:00:00-04:00"),
            )
            .put("event_uuid", "019f7cb6-4d44-73e1-b28c-82bea62c4194")
            .put("replayed", true)

        val result = api.parsePatientCommunicationMutation(data)

        assertTrue(result.replayed)
        assertEquals("Care team", result.message?.senderDisplayRole)
        assertEquals("patient_visible", result.message?.visibility)
        assertEquals("We will review this with you at the bedside.", result.message?.body)
    }

    @Test
    fun `mutation decoder preserves explicit nil reroute replay evidence without inventing work item`() {
        val result = api.parsePatientCommunicationMutation(
            JSONObject()
                .put("work_item", JSONObject.NULL)
                .put("message", JSONObject.NULL)
                .put("event_uuid", "019f7cb6-4d44-73e1-b28c-82bea62c4194")
                .put("replayed", true),
        )

        assertNull(result.workItem)
        assertNull(result.message)
        assertEquals("019f7cb6-4d44-73e1-b28c-82bea62c4194", result.eventUuid)
        assertTrue(result.replayed)
    }

    @Test
    fun `mutation decoder rejects malformed nullable message instead of treating it as absent`() {
        val malformed = JSONObject()
            .put("work_item", JSONObject.NULL)
            .put("message", "not-an-object")
            .put("event_uuid", "019f7cb6-4d44-73e1-b28c-82bea62c4194")
            .put("replayed", true)

        assertRejected { api.parsePatientCommunicationMutation(malformed) }
    }

    @Test
    fun `mutation decoder rejects missing mistyped or additional envelope fields`() {
        val canonical = JSONObject()
            .put("work_item", JSONObject.NULL)
            .put("message", JSONObject.NULL)
            .put("event_uuid", "019f7cb6-4d44-73e1-b28c-82bea62c4194")
            .put("replayed", true)

        assertRejected {
            api.parsePatientCommunicationMutation(JSONObject(canonical.toString()).apply { remove("replayed") })
        }
        assertRejected {
            api.parsePatientCommunicationMutation(
                JSONObject(canonical.toString()).put("replayed", "true"),
            )
        }
        assertRejected {
            api.parsePatientCommunicationMutation(
                JSONObject(canonical.toString()).put("unexpected", "drift"),
            )
        }
    }

    @Test
    fun `patient communication commands use canonical explicit UUIDs and optimistic versions`() {
        val idempotencyKey = PatientCommunicationCommandIds.next()
        val clientMessageUuid = PatientCommunicationCommandIds.next()
        val path = "/api/mobile/v1/patient-communications/threads/019f7cb6-4d44-73e1-b28c-82bea62c4192/reply"
        val body = api.patientCommunicationReplyBody(
            workItemVersion = 7,
            threadVersion = 11,
            message = "A patient-visible response.",
            clientMessageUuid = clientMessageUuid,
        )
        val json = JSONObject(body)

        UUID.fromString(idempotencyKey)
        UUID.fromString(clientMessageUuid)
        assertEquals(7, json.getInt("work_item_version"))
        assertEquals(11, json.getInt("thread_version"))
        assertEquals(clientMessageUuid, json.getString("client_message_uuid"))
        assertEquals(
            idempotencyKey,
            api.requestIdempotencyKey("POST", path, body, idempotencyKey),
        )
        assertTrue(api.mobileIdempotencyKey("POST", path, body)!!.startsWith("hb-"))
        assertTrue(idempotencyKey != api.mobileIdempotencyKey("POST", path, body))
        assertTrue(api.shouldDisableHttpCaches(path))
        assertEquals("no-store", api.patientCommunicationNoStoreHeaders(path)["Cache-Control"])
        assertEquals("no-cache", api.patientCommunicationNoStoreHeaders(path)["Pragma"])
        listOf(
            "/api/mobile/v1/for-you",
            "/api/mobile/v1/for-you?persona=charge_nurse",
        ).forEach { forYouPath ->
            assertTrue(api.shouldDisableHttpCaches(forYouPath))
            assertEquals(
                "no-store",
                api.patientCommunicationNoStoreHeaders(forYouPath)["Cache-Control"],
            )
            assertEquals(
                "no-cache",
                api.patientCommunicationNoStoreHeaders(forYouPath)["Pragma"],
            )
        }
        assertFalse(api.shouldDisableHttpCaches("/api/mobile/v1/rtdc/census"))
        assertFalse(api.shouldDisableHttpCaches("/api/mobile/v1/for-you-archive"))
        assertTrue(api.patientCommunicationNoStoreHeaders("/api/mobile/v1/rtdc/census").isEmpty())
    }

    @Test
    fun `claim and close payloads include both current versions and approved reason`() {
        val claim = JSONObject(api.patientCommunicationClaimBody(3, 5))
        val close = JSONObject(
            api.patientCommunicationCloseBody(
                4,
                6,
                PatientCommunicationCloseReason.PatientRequested,
            ),
        )

        assertEquals(3, claim.getInt("work_item_version"))
        assertEquals(5, claim.getInt("thread_version"))
        assertEquals(4, close.getInt("work_item_version"))
        assertEquals(6, close.getInt("thread_version"))
        assertEquals("patient_requested", close.getString("reason_code"))
        assertNull(close.optString("client_message_uuid").takeIf(String::isNotBlank))
    }

    @Test
    fun `route candidates decode exact opaque bounded contract without patient data`() {
        val candidates = api.parsePatientCommunicationRouteCandidates(
            routeCandidatesFixture(),
            expectedWorkItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4192",
        )

        assertEquals(7, candidates.workItemVersion)
        assertEquals(11, candidates.threadVersion)
        assertTrue(candidates.actions.canRelease)
        assertTrue(candidates.actions.canReassign)
        assertTrue(candidates.actions.canReroute)
        assertEquals("Return to team queue", candidates.reasonOptions.release.single().label)
        assertEquals("Avery Morgan", candidates.reassignCandidates.single().label)
        assertEquals("responder", candidates.reassignCandidates.single().membershipRole)
        assertEquals("6 West care team", candidates.rerouteCandidates.single().label)
        assertEquals("unit", candidates.rerouteCandidates.single().scopeType)
        assertEquals("6 West", candidates.rerouteCandidates.single().unit?.label)

        val exposedCandidateFields =
            PatientCommunicationRouteCandidates::class.java.declaredFields.map { it.name }.toSet()
        assertFalse("patientName" in exposedCandidateFields)
        assertFalse("patientContextRef" in exposedCandidateFields)
        assertFalse("message" in exposedCandidateFields)
        assertFalse("scopeLabel" in PatientCommunicationRerouteCandidate::class.java.declaredFields.map { it.name })
    }

    @Test
    fun `route candidate decoder fails closed on drift unknown enums duplicates and excess rows`() {
        val unknownReason = routeCandidatesFixture().apply {
            getJSONObject("reason_options").getJSONArray("release").getJSONObject(0)
                .put("code", "free_form_reason")
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(unknownReason) }

        val unknownRole = routeCandidatesFixture().apply {
            getJSONArray("reassign_candidates").getJSONObject(0).put("membership_role", "observer")
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(unknownRole) }

        val unknownScope = routeCandidatesFixture().apply {
            getJSONArray("reroute_candidates").getJSONObject(0).put("scope_type", "region")
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(unknownScope) }

        val nonCanonicalUuid = routeCandidatesFixture().apply {
            put("work_item_uuid", "019F7CB6-4D44-73E1-B28C-82BEA62C4192")
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(nonCanonicalUuid) }

        val duplicate = routeCandidatesFixture().apply {
            val candidates = getJSONArray("reassign_candidates")
            candidates.put(JSONObject(candidates.getJSONObject(0).toString()))
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(duplicate) }

        val tooMany = routeCandidatesFixture().apply {
            val candidates = getJSONArray("reassign_candidates")
            repeat(PatientCommunicationRoutingPolicy.MAX_CANDIDATES) { index ->
                candidates.put(
                    JSONObject()
                        .put("membership_uuid", canonicalUuid(index + 100))
                        .put("label", "Responder ${index + 2}")
                        .put("membership_role", "responder"),
                )
            }
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(tooMany) }

        val rawAssignedUser = routeCandidatesFixture().apply { put("assigned_user_id", 42) }
        assertRejected { api.parsePatientCommunicationRouteCandidates(rawAssignedUser) }

        val rawPoolId = routeCandidatesFixture().apply {
            getJSONArray("reroute_candidates").getJSONObject(0).put("responsibility_pool_id", 17)
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(rawPoolId) }

        val freeFormScope = routeCandidatesFixture().apply {
            getJSONArray("reroute_candidates").getJSONObject(0).put("scope_label", "Raw scope")
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(freeFormScope) }

        val malformedUnit = routeCandidatesFixture().apply {
            getJSONArray("reroute_candidates").getJSONObject(0).put("unit", "6 West")
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(malformedUnit) }

        val disabledWithCandidates = routeCandidatesFixture().apply {
            getJSONObject("actions").put("can_reassign", false)
        }
        assertRejected { api.parsePatientCommunicationRouteCandidates(disabledWithCandidates) }

        listOf(
            routeCandidatesFixture().apply {
                getJSONObject("actions").put("assigned_user_id", 42)
            },
            routeCandidatesFixture().apply {
                getJSONObject("reason_options").put("other", org.json.JSONArray())
            },
            routeCandidatesFixture().apply {
                getJSONObject("reason_options").getJSONArray("release").getJSONObject(0)
                    .put("description", "Raw policy copy")
            },
            routeCandidatesFixture().apply {
                getJSONArray("reassign_candidates").getJSONObject(0).put("user_id", 42)
            },
            routeCandidatesFixture().apply {
                getJSONArray("reroute_candidates").getJSONObject(0).getJSONObject("unit")
                    .put("facility_id", 3)
            },
        ).forEach { drifted ->
            assertRejected { api.parsePatientCommunicationRouteCandidates(drifted) }
        }
    }

    @Test
    fun `disabled routing actions accept only empty corresponding candidate arrays`() {
        val data = routeCandidatesFixture().apply {
            getJSONObject("actions")
                .put("can_reassign", false)
                .put("can_reroute", false)
            put("reassign_candidates", org.json.JSONArray())
            put("reroute_candidates", org.json.JSONArray())
        }

        val candidates = api.parsePatientCommunicationRouteCandidates(data)

        assertFalse(candidates.actions.canReassign)
        assertFalse(candidates.actions.canReroute)
        assertTrue(candidates.reassignCandidates.isEmpty())
        assertTrue(candidates.rerouteCandidates.isEmpty())
    }

    @Test
    fun `enabled routing action requires a validated reason and opaque target`() {
        val candidates = api.parsePatientCommunicationRouteCandidates(routeCandidatesFixture())
        val membershipUuid = candidates.reassignCandidates.single().membershipUuid
        val poolUuid = candidates.rerouteCandidates.single().poolUuid

        assertTrue(
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates,
                PatientCommunicationRoutingAction.Release,
                "return_to_team",
                null,
            ),
        )
        assertTrue(
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates,
                PatientCommunicationRoutingAction.Reassign,
                "supervisor_assignment",
                membershipUuid,
            ),
        )
        assertTrue(
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates,
                PatientCommunicationRoutingAction.Reroute,
                "wrong_team",
                poolUuid,
            ),
        )
        assertFalse(
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates,
                PatientCommunicationRoutingAction.Reassign,
                "wrong_team",
                membershipUuid,
            ),
        )
        assertFalse(
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates,
                PatientCommunicationRoutingAction.Reroute,
                "wrong_team",
                "019f7cb6-4d44-73e1-b28c-82bea62c4999",
            ),
        )
        assertFalse(
            PatientCommunicationRoutingPolicy.canSubmit(
                candidates.copy(actions = candidates.actions.copy(canReassign = false)),
                PatientCommunicationRoutingAction.Reassign,
                "supervisor_assignment",
                membershipUuid,
            ),
        )
    }

    @Test
    fun `route candidates require the exact displayed work item and thread version tuple`() {
        val item = api.parsePatientCommunicationWorkItem(workItemFixture())
        val candidates = api.parsePatientCommunicationRouteCandidates(routeCandidatesFixture())

        assertTrue(PatientCommunicationRoutingPolicy.matchesDisplayedItem(candidates, item))
        assertFalse(
            PatientCommunicationRoutingPolicy.matchesDisplayedItem(
                candidates.copy(workItemVersion = item.workItemVersion + 1),
                item,
            ),
        )
        assertFalse(
            PatientCommunicationRoutingPolicy.matchesDisplayedItem(
                candidates.copy(threadVersion = item.threadVersion + 1),
                item,
            ),
        )
        assertFalse(
            PatientCommunicationRoutingPolicy.matchesDisplayedItem(
                candidates.copy(workItemUuid = "019f7cb6-4d44-73e1-b28c-82bea62c4999"),
                item,
            ),
        )
    }

    @Test
    fun `routing mutation bodies contain only exact versions reason and opaque selector`() {
        val release = JSONObject(api.patientCommunicationReleaseBody(7, 11, "return_to_team"))
        val reassign = JSONObject(
            api.patientCommunicationReassignBody(
                7,
                11,
                "019f7cb6-4d44-73e1-b28c-82bea62c4200",
                "supervisor_assignment",
            ),
        )
        val reroute = JSONObject(
            api.patientCommunicationRerouteBody(
                7,
                11,
                "019f7cb6-4d44-73e1-b28c-82bea62c4300",
                "wrong_team",
            ),
        )

        assertEquals(setOf("work_item_version", "thread_version", "reason_code"), release.keysSet())
        assertEquals(
            setOf("work_item_version", "thread_version", "reason_code", "target_membership_uuid"),
            reassign.keysSet(),
        )
        assertEquals(
            setOf("work_item_version", "thread_version", "reason_code", "target_pool_uuid"),
            reroute.keysSet(),
        )
        assertEquals("supervisor_assignment", reassign.getString("reason_code"))
        assertEquals(
            "019f7cb6-4d44-73e1-b28c-82bea62c4200",
            reassign.getString("target_membership_uuid"),
        )
        assertEquals("019f7cb6-4d44-73e1-b28c-82bea62c4300", reroute.getString("target_pool_uuid"))
        assertRejected { api.patientCommunicationReleaseBody(0, 11, "return_to_team") }
        assertRejected { api.patientCommunicationReleaseBody(7, 11, "wrong_team") }
    }

    @Test
    fun `all routing reads and mutations disable HTTP caching`() {
        val prefix = "/api/mobile/v1/patient-communications/threads/019f7cb6-4d44-73e1-b28c-82bea62c4192"
        listOf(
            "$prefix/route-candidates",
            "$prefix/release",
            "$prefix/reassign",
            "$prefix/reroute",
        ).forEach { path ->
            assertTrue(api.shouldDisableHttpCaches(path))
            assertEquals("no-store", api.patientCommunicationNoStoreHeaders(path)["Cache-Control"])
            assertEquals("no-cache", api.patientCommunicationNoStoreHeaders(path)["Pragma"])
        }
    }

    @Test
    fun `navigation and actions use canonical server capability hints`() {
        val viewAndRespond = me(canView = true, canRespond = true)
        val viewOnly = me(canView = true, canRespond = false)
        val roleWithoutCapability = me(roles = listOf("charge_nurse"))

        assertTrue(PatientCommunicationAccess.isEligible(viewAndRespond))
        assertTrue(PatientCommunicationAccess.canRespond(viewAndRespond))
        assertTrue(PatientCommunicationAccess.isEligible(viewOnly))
        assertFalse(PatientCommunicationAccess.canRespond(viewOnly))
        // Role labels and admin presentation never substitute for the server's
        // effective capability calculation.
        assertFalse(PatientCommunicationAccess.isEligible(roleWithoutCapability))
        assertFalse(PatientCommunicationAccess.isEligible(null))
    }

    @Test
    fun `me decoder fails closed and reads split view and respond capability fields`() {
        val allowed = api.parseMeData(
            JSONObject(
                """
                {
                  "id": 42,
                  "name": "Test User",
                  "username": "test-user",
                  "roles": ["charge_nurse"],
                  "workflow_preference": null,
                  "is_admin": false,
                  "can": {
                    "view_patient_communications": true,
                    "respond_patient_communications": false
                  }
                }
                """.trimIndent(),
            ),
        )
        val missingCan = api.parseMeData(
            JSONObject(
                """
                {
                  "id": 43,
                  "name": "Legacy Response",
                  "username": "legacy",
                  "roles": ["admin"],
                  "workflow_preference": null,
                  "is_admin": true
                }
                """.trimIndent(),
            ),
        )

        assertTrue(allowed.canViewPatientCommunications)
        assertFalse(allowed.canRespondPatientCommunications)
        assertFalse(missingCan.canViewPatientCommunications)
        assertFalse(missingCan.canRespondPatientCommunications)
    }

    @Test
    fun `SLA presentation prioritizes escalation then response due`() {
        val escalated = api.parsePatientCommunicationWorkItem(workItemFixture())
        val responseDue = escalated.copy(isEscalationDue = false, isResponseDue = true)
        val withinTarget = escalated.copy(isEscalationDue = false, isResponseDue = false)

        assertEquals(PatientCommunicationAttention.EscalationDue, PatientCommunicationPresentation.attention(escalated))
        assertEquals(PatientCommunicationAttention.ResponseDue, PatientCommunicationPresentation.attention(responseDue))
        assertEquals(PatientCommunicationAttention.AwaitingResponse, PatientCommunicationPresentation.attention(withinTarget))
    }

    @Test
    fun `pool owned rerouted and escalated unassigned work remain claimable`() {
        val item = api.parsePatientCommunicationWorkItem(workItemFixture())

        assertTrue(PatientCommunicationPresentation.isClaimable(item.copy(ownershipState = "pool_owned")))
        assertTrue(PatientCommunicationPresentation.isClaimable(item.copy(ownershipState = "rerouted")))
        assertTrue(PatientCommunicationPresentation.isClaimable(item.copy(ownershipState = "escalated")))
        assertFalse(PatientCommunicationPresentation.isClaimable(item.copy(ownershipState = "assigned")))
        assertFalse(PatientCommunicationPresentation.isClaimable(item.copy(assignedToMe = true)))
        assertFalse(PatientCommunicationPresentation.isClaimable(item.copy(status = "closed")))
    }

    @Test
    fun `conflicts require refetch without resend and only uncertain failures allow explicit exact retry`() {
        assertEquals(
            PatientCommunicationRecovery.RefetchWithoutResend,
            PatientCommunicationMutationRecoveryPolicy.after(409),
        )
        assertEquals(
            PatientCommunicationRecovery.ExplicitExactRetryAvailable,
            PatientCommunicationMutationRecoveryPolicy.after(null),
        )
        assertEquals(
            PatientCommunicationRecovery.ExplicitExactRetryAvailable,
            PatientCommunicationMutationRecoveryPolicy.after(503),
        )
        assertEquals(
            PatientCommunicationRecovery.DiscardCommand,
            PatientCommunicationMutationRecoveryPolicy.after(422),
        )
    }

    private fun routeCandidatesFixture(): JSONObject = JSONObject(
        """
        {
          "work_item_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4192",
          "work_item_version": 7,
          "thread_version": 11,
          "actions": {
            "can_release": true,
            "can_reassign": true,
            "can_reroute": true
          },
          "reason_options": {
            "release": [{"code": "return_to_team", "label": "Return to team queue"}],
            "reassign": [{"code": "supervisor_assignment", "label": "Supervisor assignment"}],
            "reroute": [{"code": "wrong_team", "label": "Wrong responsibility team"}]
          },
          "reassign_candidates": [
            {
              "membership_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4200",
              "label": "Avery Morgan",
              "membership_role": "responder"
            }
          ],
          "reroute_candidates": [
            {
              "pool_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4300",
              "label": "6 West care team",
              "scope_type": "unit",
              "unit": {"id": 86, "label": "6 West"}
            }
          ]
        }
        """.trimIndent(),
    )

    private fun canonicalUuid(seed: Int): String =
        "00000000-0000-4000-8000-${seed.toString().padStart(12, '0')}"

    private fun assertRejected(block: () -> Unit) {
        try {
            block()
            fail("Expected the contract to fail closed.")
        } catch (_: IllegalArgumentException) {
            // Expected strict validation failure.
        }
    }

    private fun JSONObject.keysSet(): Set<String> = keys().asSequence().toSet()

    private fun me(
        roles: List<String> = emptyList(),
        isAdmin: Boolean = false,
        canView: Boolean = false,
        canRespond: Boolean = false,
    ) = MeData(
        id = 42,
        name = "Test User",
        username = "test-user",
        roles = roles,
        workflowPreference = null,
        isAdmin = isAdmin,
        canViewPatientCommunications = canView,
        canRespondPatientCommunications = canRespond,
    )

    private fun workItemFixture(): JSONObject = JSONObject(
        """
        {
          "work_item_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4192",
          "thread_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4191",
          "patient_context_ref": "ptok_test_only",
          "topic": {"code": "medication_question", "label": "Medication question"},
          "unit": {"id": 85, "label": "5 East"},
          "pool": {"pool_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4190", "label": "5 East care team"},
          "status": "open",
          "ownership_state": "pool_owned",
          "assigned_to_me": false,
          "work_item_version": 7,
          "thread_version": 11,
          "last_message_at": "2026-07-19T14:00:00-04:00",
          "due_at": "2026-07-19T14:30:00-04:00",
          "escalate_at": "2026-07-19T15:00:00-04:00",
          "is_response_due": true,
          "is_escalation_due": true,
          "closed_at": null,
          "messages": [
            {
              "message_uuid": "019f7cb6-4d44-73e1-b28c-82bea62c4189",
              "sender_display_role": "Patient",
              "visibility": "patient_visible",
              "message_kind": "message",
              "body": "I have a question about tonight's medicine.",
              "delivery_state": "assigned",
              "sent_at": "2026-07-19T14:00:00-04:00"
            }
          ],
          "has_earlier_messages": false,
          "routing_policy_version": "must-not-enter-client-model",
          "responsibility_pool_ref_digest": "must-not-enter-client-model",
          "metadata": {"must_not": "enter client model"}
        }
        """.trimIndent(),
    )
}
