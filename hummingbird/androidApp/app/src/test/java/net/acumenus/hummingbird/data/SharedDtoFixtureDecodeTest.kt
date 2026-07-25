package net.acumenus.hummingbird.data

import net.acumenus.hummingbird.ui.theme.CapacityStatus
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
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

        assertTrue(first.id.startsWith("bedreq-"))
        assertEquals(CapacityStatus.CRITICAL, first.capacity)
        assertTrue(second.id.startsWith("transport-"))
        assertEquals(CapacityStatus.CRITICAL, second.capacity)
    }

    @Test
    fun decodesActivityFixture() {
        val event = api.parseActivityEvent(fixture("mobile-activity-feed.json").getJSONArray("data").getJSONObject(0))

        assertEquals("bed_request.created", event.eventType)
        assertEquals("rtdc", event.domain)
        assertTrue(event.patientContextRef?.startsWith("ptok_") == true)
        assertEquals("warning", event.statusValue)
        assertEquals("Warning", event.statusLabel)
    }

    @Test
    fun decodesPatientOperationalContextFixture() {
        val context = api.parsePatientContext(fixture("mobile-patient-operational-context.json").getJSONObject("data"))

        assertEquals("A2P", context.altitude)
        assertTrue(context.patient.patientContextRef?.startsWith("ptok_") == true)
        assertTrue(context.patient.phiMinimized)
        assertEquals(2, context.statusSpine.size)
        assertEquals(2, context.timeline.size)
        assertEquals(2, context.dependencies.size)
        assertEquals(2, context.actions.size)
    }

    @Test
    fun decodesEddyChatSuccessAndTheDocumentedUnavailableNotice() {
        val success = api.parseEddyChatReply(JSONObject("""
            {
              "conversation_id": "cb45f98e-c0d5-4fdd-8d29-6f51f66ab8ba",
              "message": {
                "role": "assistant",
                "content": "Review the current discharge barriers before the next huddle.",
                "provider": "ollama"
              }
            }
        """.trimIndent()))
        val unavailable = api.parseEddyChatReply(JSONObject("""
            { "message": "Eddy is temporarily unavailable. Please try again shortly." }
        """.trimIndent()))

        assertEquals("cb45f98e-c0d5-4fdd-8d29-6f51f66ab8ba", success.conversationId)
        assertEquals("assistant", success.message.role)
        assertEquals("ollama", success.message.provider)
        assertTrue(success.message.content.startsWith("Review the current discharge"))
        assertNull(unavailable.conversationId)
        assertEquals("assistant", unavailable.message.role)
        assertNull(unavailable.message.provider)
        assertEquals("Eddy is temporarily unavailable. Please try again shortly.", unavailable.message.content)
    }

    @Test
    fun decodesServerOnlyEddyConversationHistoryAndDraftActionMarker() {
        val summary = api.parseEddyConversationSummary(JSONObject("""
            {
              "id": "e75d595c-7e67-49f8-b0a2-8189e1c8491d",
              "title": "Discharge barriers",
              "surface": "hummingbird",
              "origin": "hummingbird",
              "updated_at": "2026-07-24T16:00:00Z"
            }
        """.trimIndent()))
        val detail = api.parseEddyConversationDetail(JSONObject("""
            {
              "id": "e75d595c-7e67-49f8-b0a2-8189e1c8491d",
              "title": "Discharge barriers",
              "surface": "hummingbird",
              "messages": [
                { "role": "user", "content": "What is blocking discharges?", "created_at": "2026-07-24T15:58:00Z" },
                {
                  "role": "assistant",
                  "content": "Two barriers need review.",
                  "provider": "ollama",
                  "created_at": "2026-07-24T16:00:00Z",
                  "proposed_action": { "action_type": "flag_barrier" }
                }
              ]
            }
        """.trimIndent()))

        assertEquals("e75d595c-7e67-49f8-b0a2-8189e1c8491d", summary.id)
        assertEquals("Discharge barriers", summary.title)
        assertEquals("hummingbird", summary.origin)
        assertEquals(2, detail.messages.size)
        assertEquals(EddyChatRole.USER, detail.messages.first().role)
        assertEquals(EddyChatRole.ASSISTANT, detail.messages.last().role)
        assertEquals("ollama", detail.messages.last().provider)
        assertTrue(detail.messages.last().hasProposedAction)
    }

    @Test
    fun eddyPathsAreAlwaysNoStoreAndDisableTheHttpCache() {
        val path = "/api/mobile/v1/eddy/conversations?persona=bed_manager"

        assertEquals("no-store", api.sensitiveNoStoreHeaders(path)["Cache-Control"])
        assertEquals("no-cache", api.sensitiveNoStoreHeaders(path)["Pragma"])
        assertTrue(api.shouldDisableHttpCaches(path))
    }

    @Test
    fun decodesEddyApprovalPreviewAndServerDecisionWithoutTreatingItAsAnEddyAction() {
        val preview = api.parseEddyApprovalPreview(JSONObject("""
            {
              "approval_uuid": "f2de3b42-5f41-4a34-9a91-c6292465bba1",
              "action_uuid": "dca4d2b5-0dca-49d6-a2e4-431aaf1bcb91",
              "action_type": "flag_barrier",
              "title": "Flag a discharge barrier",
              "surface": "rtdc",
              "tier": "T1",
              "risk": "medium",
              "requested_at": "2026-07-24T16:00:00Z",
              "rationale": "A discharge barrier needs review.",
              "runner_up": "Escalate to the charge nurse.",
              "params": { "unit": "5 East", "barrier_count": 2, "nested": { "hidden": true } },
              "preview": "Would flag a throughput/discharge barrier on 5 East for the next huddle."
            }
        """.trimIndent()))
        val decision = api.parseEddyApprovalDecision(JSONObject("""
            {
              "approval_uuid": "f2de3b42-5f41-4a34-9a91-c6292465bba1",
              "decision": "approved",
              "action_status": "approved"
            }
        """.trimIndent()))

        assertEquals("f2de3b42-5f41-4a34-9a91-c6292465bba1", preview.summary.approvalUuid)
        assertEquals("flag_barrier", preview.summary.actionType)
        assertEquals("Would flag a throughput/discharge barrier on 5 East for the next huddle.", preview.preview)
        assertEquals("5 East", preview.params.first { it.name == "unit" }.value)
        assertEquals("Operational detail", preview.params.first { it.name == "nested" }.value)
        assertEquals("approved", decision.decision)
        assertEquals("approved", decision.actionStatus)
    }

    @Test
    fun eddyApprovalPathsAreNoStoreAndExplicitDecisionKeysWin() {
        val path = "/api/mobile/v1/eddy/approvals/f2de3b42-5f41-4a34-9a91-c6292465bba1/decision?persona=capacity_lead"
        val replayKey = "5ac78f64-66f8-4db3-a871-6f143e14ea34"

        assertEquals("no-store", api.sensitiveNoStoreHeaders(path)["Cache-Control"])
        assertEquals("no-cache", api.sensitiveNoStoreHeaders(path)["Pragma"])
        assertTrue(api.shouldDisableHttpCaches(path))
        assertEquals(
            replayKey,
            api.requestIdempotencyKey("POST", path, "{\"decision\":\"approved\"}", replayKey),
        )
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

        // Phase 2: scheduled_or_case carries its room; other kinds stay roomless.
        val orCase = window.projections.first { it.kind == "scheduled_or_case" }
        assertEquals("OR 3", orCase.room)
        assertTrue(window.projections.filter { it.kind != "scheduled_or_case" }.all { it.room == null })
        // bed_statuses is absent at house scope — the parser must tolerate that.
        assertTrue(window.bedStatuses.isEmpty())
        // Phase 3: links.web feeds the PI clip deep link.
        assertTrue(window.webLink!!.contains("/rtdc/patient-flow-navigator"))
    }

    @Test
    fun decodesEvsFlowWindowFixtureWithBedStatuses() {
        val window = api.parseFlowWindow(fixture("mobile-flow-window-evs.json"))

        assertEquals("evs", window.lens.roleId)
        assertEquals("floor", window.scope.type)
        assertTrue(window.bedStatuses.isNotEmpty())
        val first = window.bedStatuses.first()
        assertEquals("MICU-01", first.label)
        assertEquals("occupied", first.status)
        assertTrue(window.bedStatuses.all { it.status in setOf("available", "occupied", "blocked", "dirty") })
        assertTrue(window.projections.any { it.kind == "evs_due" && it.bedId != null })
    }

    @Test
    fun decodesFlowFloorsFixture() {
        val doc = api.parseFlowFloors(fixture("mobile-flow-floors.json"))

        assertTrue(doc.floors.isNotEmpty())
        assertTrue(Regex("^v1-[0-9a-f]{12}$").matches(doc.version))
        val floor3 = doc.floors.first { it.floor == 3 }
        assertEquals(4, floor3.bounds.size)
        assertEquals(4, floor3.spaces.size)
        val bed = floor3.spaces.first { it.category == "bed" }
        assertEquals(693, bed.bedId)
        assertEquals(26, bed.unitId)
        assertEquals(4, bed.rect.size)
    }

    @Test
    fun mobilePostIdempotencyKeysAreDeterministic() {
        val first = api.mobileIdempotencyKey(
            "POST",
            "/api/mobile/v1/rtdc/barriers/42/resolve",
            "{}",
        )
        val replay = api.mobileIdempotencyKey(
            "POST",
            "/api/mobile/v1/rtdc/barriers/42/resolve",
            "{}",
        )
        val differentBody = api.mobileIdempotencyKey(
            "POST",
            "/api/mobile/v1/rtdc/barriers/42/resolve",
            "{\"reason\":\"changed\"}",
        )
        val lifecycleV3 = api.mobileIdempotencyKey(
            "POST",
            "/api/mobile/v1/transport/requests/42/status",
            "{\"status\":\"dispatched\",\"lifecycle_version\":3}",
        )
        val lifecycleV5 = api.mobileIdempotencyKey(
            "POST",
            "/api/mobile/v1/transport/requests/42/status",
            "{\"status\":\"dispatched\",\"lifecycle_version\":5}",
        )

        assertEquals(first, replay)
        assertTrue(first!!.startsWith("hb-"))
        assertTrue(first != differentBody)
        assertTrue(lifecycleV3 != lifecycleV5)
        assertEquals(null, api.mobileIdempotencyKey("GET", "/api/mobile/v1/activity", null))
        assertEquals(null, api.mobileIdempotencyKey("POST", "/api/auth/token", "{}",))
    }

    @Test
    fun decodesCanonicalStaffingCandidateSafetyState() {
        val candidate = api.parseStaffingCandidate(JSONObject("""
            {
              "staff_member_id": 42,
              "display_name": "Avery Adams",
              "role_label": "Staff Nurse",
              "eligible": false,
              "eligibility_state": "conflicted",
              "reason_codes": ["overlapping_shift_assignment"],
              "overlapping_assignments": 1
            }
        """.trimIndent()))

        assertEquals(42, candidate.staffMemberId)
        assertEquals("Avery Adams", candidate.displayName)
        assertEquals("conflicted", candidate.eligibilityState)
        assertEquals(listOf("overlapping_shift_assignment"), candidate.reasonCodes)
        assertEquals(1, candidate.overlappingAssignments)
    }

    @Test
    fun decodesGovernedTransportJobAndCursorState() {
        val queue = api.parseTransportQueue(fixture("mobile-transport-queue.json"))

        val job = queue.jobs.single()
        assertEquals(false, job.claimedByMe)
        assertTrue(job.availableToClaim)
        assertEquals(listOf("assigned"), job.allowedTransitions)
        assertEquals(1, job.lifecycleVersion)
        assertNull(queue.nextCursor)
        assertEquals(false, queue.hasMore)
    }

    private fun fixture(filename: String): JSONObject =
        JSONObject(File(repoRoot(), "docs/hummingbird/api-contract/fixtures/$filename").readText())

    private fun repoRoot(): File {
        var cursor = File(System.getProperty("user.dir") ?: ".").absoluteFile
        while (true) {
            if (File(cursor, "docs/hummingbird/api-contract/fixtures").isDirectory) {
                return cursor
            }
            cursor = cursor.parentFile ?: break
        }
        error("Unable to locate repository root from ${System.getProperty("user.dir")}")
    }
}
