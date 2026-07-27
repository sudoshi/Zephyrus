package net.acumenus.hummingbird.patient.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import org.json.JSONObject

class PatientEnvelopeDecoderTest {
    @Test
    fun decodesCurrentTokenEnvelope() {
        val envelope = PatientEnvelopeDecoder.tokenPair(
            """
            {
              "data": {
                "token_type": "Bearer",
                "access_token": "test-access-value",
                "refresh_token": "test-refresh-value",
                "expires_in": 900,
                "session_uuid": "019f4d7a-3200-7000-8000-000000000001",
                "abilities": ["patient:access"]
              },
              "meta": {
                "as_of": "2026-07-19T12:00:00Z",
                "stale": false,
                "version": null
              },
              "links": {}
            }
            """.trimIndent(),
        )

        assertEquals("Bearer", envelope.data.tokenType)
        assertEquals(900, envelope.data.expiresInSeconds)
        assertEquals(listOf("patient:access"), envelope.data.abilities)
        assertFalse(envelope.meta.stale)
        assertNull(envelope.meta.version)
    }

    @Test
    fun decodesCurrentProfileEnvelopeWithoutAssumingNullableContacts() {
        val envelope = PatientEnvelopeDecoder.profile(
            """
            {
              "data": {
                "principal_uuid": "019f4d7a-3200-7000-8000-000000000002",
                "principal_type": "patient",
                "display_name": "Sample Patient",
                "email": null,
                "phone_e164": null,
                "email_verified": false,
                "phone_verified": false,
                "locale": "en-US",
                "timezone": "America/New_York",
                "preferences": {
                  "text_size": "large",
                  "reduced_motion": true,
                  "high_contrast": true,
                  "notification_preview": "generic",
                  "preferred_channel": "push"
                }
              },
              "meta": {"as_of": "2026-07-19T12:01:00Z", "stale": false, "version": 4},
              "links": {}
            }
            """.trimIndent(),
        )

        assertEquals("Sample Patient", envelope.data.displayName)
        assertNull(envelope.data.email)
        assertEquals(4L, envelope.meta.version)
        assertEquals("large", envelope.data.preferences.textSize)
        assertEquals(true, envelope.data.preferences.reducedMotion)
        assertEquals(true, envelope.data.preferences.highContrast)
        assertEquals("generic", envelope.data.preferences.notificationPreview)
        assertEquals("push", envelope.data.preferences.preferredChannel)
    }

    @Test
    fun preferenceUpdateOmitsNullsAndUsesContractFieldNames() {
        val json = PatientPreferencesUpdate(
            locale = "es-US",
            textSize = "extra_large",
            reducedMotion = true,
            highContrast = false,
            preferredChannel = "none",
        ).json()

        assertEquals(setOf(
            "locale",
            "text_size",
            "reduced_motion",
            "high_contrast",
            "preferred_channel",
        ), json.keys().asSequence().toSet())
        assertEquals("extra_large", json.getString("text_size"))
        assertTrue(json.getBoolean("reduced_motion"))
        assertFalse(json.getBoolean("high_contrast"))
    }

    @Test
    fun decodesOnlyGovernedEncounterHandles() {
        val envelope = PatientEnvelopeDecoder.encounters(
            """
            {
              "data": {
                "encounters": [{
                  "encounter_uuid": "019f4d7a-3200-7000-8000-000000000003",
                  "grant_uuid": "019f4d7a-3200-7000-8000-000000000004",
                  "relationship": "self",
                  "scopes": ["today:read", "pathway:read"],
                  "valid_from": "2026-07-19T11:00:00Z",
                  "expires_at": null,
                  "version": 1
                }]
              },
              "meta": {"as_of": "2026-07-19T12:02:00Z", "stale": false, "version": 1, "count": 1},
              "links": {}
            }
            """.trimIndent(),
        )

        assertEquals(1, envelope.data.encounters.size)
        assertEquals(listOf("today:read", "pathway:read"), envelope.data.encounters.single().scopes)
        assertNull(envelope.data.encounters.single().expiresAt)
        assertEquals(1, envelope.meta.count)
    }

    @Test
    fun decodesTodayProjectionWithFreshnessUncertaintyAndProvenance() {
        val envelope = PatientEnvelopeDecoder.today(
            projectionJson(
                kind = "today",
                content = """
                {
                  "headline": "Your plan for today",
                  "summary": "A released summary.",
                  "schedule": [{
                    "item_uuid": "019f4d7a-3200-7000-8000-000000000011",
                    "label": "Care team rounds",
                    "detail": "Review your plan.",
                    "status": "planned",
                    "time_window": "This morning",
                    "timing_confidence": "estimated",
                    "preparation": null,
                    "can_change": true
                  }],
                  "next_steps": ["Ask questions during rounds."],
                  "notices": ["Timing can change."]
                }
                """.trimIndent(),
            ),
        )

        assertEquals("today", envelope.data.kind)
        assertEquals("Care team rounds", envelope.data.content.schedule.single().label)
        assertNull(envelope.data.content.schedule.single().preparation)
        assertEquals("medium", envelope.data.uncertainty.level)
        assertEquals("clinically_reviewed", envelope.data.provenance.reviewState)
        assertEquals("correction", envelope.data.revisionNotice?.kind)
        assertEquals(
            "Your care team updated this information. Please use the details shown here.",
            envelope.data.revisionNotice?.message,
        )
        assertEquals("current", envelope.meta.sourceFreshness?.status)
        assertEquals("patient-disclosure-v1", envelope.meta.policyVersion)
        assertEquals("patient-state-vocabulary.v1-draft", envelope.meta.stateVocabularyVersion)
        assertEquals("request-test", envelope.meta.requestId)
        assertEquals("/api/patient/v1/encounters/test/today", envelope.links["self"])
    }

    @Test
    fun acceptsAnOmittedRevisionNoticeAndRejectsAnUnexpectedNoticeKind() {
        val body = JSONObject(
            projectionJson(
                kind = "today",
                content = """{"headline":"Today","summary":"Released."}""",
            ),
        )
        body.getJSONObject("data").remove("revision_notice")

        assertNull(PatientEnvelopeDecoder.today(body.toString()).data.revisionNotice)

        body.getJSONObject("data").put(
            "revision_notice",
            JSONObject()
                .put("kind", "retraction")
                .put("message", "Do not render this."),
        )
        try {
            PatientEnvelopeDecoder.today(body.toString())
            fail("Unexpected revision notice kinds must fail closed.")
        } catch (error: IllegalArgumentException) {
            assertEquals("patient_projection_revision_notice_kind_invalid", error.message)
        }
    }

    @Test
    fun decodesPathwayProjectionWithoutInventingOptionalTiming() {
        val envelope = PatientEnvelopeDecoder.pathway(
            projectionJson(
                kind = "pathway",
                content = """
                {
                  "headline": "My Path",
                  "summary": "A released path.",
                  "current_stage": "Monitoring",
                  "stages": [{
                    "stage_uuid": "019f4d7a-3200-7000-8000-000000000012",
                    "title": "Monitoring",
                    "status": "current",
                    "summary": "Your team is checking your response.",
                    "expected_range": null,
                    "timing_confidence": "estimated",
                    "can_change": true
                  }],
                  "milestones": [{
                    "milestone_uuid": "019f4d7a-3200-7000-8000-000000000021",
                    "title": "Safe next step",
                    "status": "planned",
                    "detail": "Your team is preparing the next step.",
                    "timing": null,
                    "timing_confidence": "estimated",
                    "can_change": true
                  }],
                  "goals": [{
                    "goal_uuid": "019f4d7a-3200-7000-8000-000000000022",
                    "author_type": "patient",
                    "label": "Understand my plan",
                    "explanation": "Ask for plain-language explanations.",
                    "status": "in_progress",
                    "target_range": null
                  }],
                  "education": [{
                    "item_uuid": "019f4d7a-3200-7000-8000-000000000023",
                    "title": "Preparing for home",
                    "summary": "Review the released next-step information."
                  }],
                  "questions": ["What should I ask before I leave?"],
                  "notices": ["Timing can change."]
                }
                """.trimIndent(),
            ),
        )

        assertEquals("Monitoring", envelope.data.content.currentStage)
        assertNull(envelope.data.content.stages.single().expectedRange)
        assertTrue(envelope.data.content.stages.single().canChange)
        assertEquals("Safe next step", envelope.data.content.milestones.single().title)
        assertNull(envelope.data.content.milestones.single().timing)
        assertEquals("patient", envelope.data.content.goals.single().authorType)
        assertEquals("Preparing for home", envelope.data.content.education.single().title)
        assertEquals(listOf("What should I ask before I leave?"), envelope.data.content.questions)
    }

    @Test
    fun decodesCareTeamProjectionWithOnlyGovernedContactRoutes() {
        val envelope = PatientEnvelopeDecoder.careTeam(
            projectionJson(
                kind = "care_team",
                content = """
                {
                  "headline": "Your care team",
                  "summary": "People assigned to your stay.",
                  "members": [{
                    "member_uuid": "019f4d7a-3200-7000-8000-000000000013",
                    "display_name": "Care Coordinator",
                    "role": "Care coordination",
                    "service": "Hospital medicine",
                    "responsibilities": ["Coordinates your care plan."],
                    "contact_route": "speak_with_bedside_staff"
                  }],
                  "communication_options": ["speak_with_bedside_staff", "call_button_for_urgent_help"],
                  "notices": ["No emergency messaging."]
                }
                """.trimIndent(),
            ),
        )

        val member = envelope.data.content.members.single()
        assertEquals("Care Coordinator", member.displayName)
        assertEquals("speak_with_bedside_staff", member.contactRoute)
        assertEquals(
            listOf("speak_with_bedside_staff", "call_button_for_urgent_help"),
            envelope.data.content.communicationOptions,
        )
    }

    @Test
    fun decodesReleasedDischargeReadinessWithoutInferringAConfirmedDeparture() {
        val envelope = PatientEnvelopeDecoder.dischargeReadiness(
            projectionJson(
                kind = "discharge_readiness",
                content = """
                {
                  "headline": "Getting ready to leave",
                  "summary": "Your team will confirm the details before you leave.",
                  "estimated_range": "The next day or two",
                  "estimated_confidence": "estimated",
                  "criteria": [{
                    "item_uuid": "019f4d7a-3200-7000-8000-000000000024",
                    "label": "Moving safely with the support you need",
                    "status": "pending",
                    "detail": "Your team will review this with you each day."
                  }],
                  "unresolved_needs": ["A ride home arranged for the day you leave."],
                  "medications": [{
                    "item_uuid": "019f4d7a-3200-7000-8000-000000000025",
                    "name": "Your updated medicine list",
                    "purpose": "Review each medicine with your care team."
                  }],
                  "follow_up": [{
                    "item_uuid": "019f4d7a-3200-7000-8000-000000000026",
                    "label": "Follow-up visit with your care team",
                    "when": "Within a week or two of leaving"
                  }],
                  "warning_signs": ["Call your care team if symptoms get worse after you go home."],
                  "contacts": [{
                    "item_uuid": "019f4d7a-3200-7000-8000-000000000027",
                    "label": "Your care team",
                    "route": "speak_with_bedside_staff"
                  }],
                  "questions": [],
                  "notices": ["Details can change."]
                }
                """.trimIndent(),
            ),
        )

        assertEquals("estimated", envelope.data.content.estimatedConfidence)
        assertEquals("pending", envelope.data.content.criteria.single().status)
        assertEquals("Your updated medicine list", envelope.data.content.medications.single().name)
        assertEquals("Within a week or two of leaving", envelope.data.content.followUp.single().whenLabel)
        assertEquals("speak_with_bedside_staff", envelope.data.content.contacts.single().route)
    }

    @Test
    fun decodesReleasedPathwayTimelineWithoutImplyingACompleteClinicalRecord() {
        val envelope = PatientEnvelopeDecoder.pathwayEvents(
            projectionJson(
                kind = "pathway_events",
                content = """
                {
                  "headline": "What has happened so far",
                  "summary": "A simple timeline of key moments.",
                  "events": [{
                    "event_uuid": "019f4d7a-3200-7000-8000-000000000028",
                    "title": "Admitted to the hospital",
                    "when": "Two days ago",
                    "category": "test",
                    "status": "completed",
                    "detail": "Your care team reviewed your history and started your plan."
                  }],
                  "notices": ["This timeline is a summary and may not include every detail."]
                }
                """.trimIndent(),
            ),
        )

        assertEquals("Admitted to the hospital", envelope.data.content.events.single().title)
        assertEquals("Two days ago", envelope.data.content.events.single().whenLabel)
        assertEquals("test", envelope.data.content.events.single().category)
        assertEquals("completed", envelope.data.content.events.single().status)
        assertTrue(envelope.data.content.notices.single().contains("summary"))
    }

    @Test
    fun decodesReleasedRoundsSummaryWithoutExposingTheUnderlyingConversation() {
        val envelope = PatientEnvelopeDecoder.roundsSummary(
            projectionJson(
                kind = "rounds_summary",
                content = """
                {
                  "headline": "Your care-team conversation",
                  "summary": "Released plain-language summary.",
                  "round_window": "Earlier today",
                  "topics": [{
                    "topic_uuid": "019f4d7a-3200-7000-8000-000000000029",
                    "title": "How you are doing",
                    "summary": "Your team reviewed progress and next steps.",
                    "status": "current"
                  }],
                  "next_steps": ["Tell your bedside team what you would like explained."],
                  "questions": [],
                  "notices": ["This summary can change."]
                }
                """.trimIndent(),
            ),
        )

        assertEquals("Earlier today", envelope.data.content.roundWindow)
        assertEquals("How you are doing", envelope.data.content.topics.single().title)
        assertEquals("current", envelope.data.content.topics.single().status)
    }

    @Test
    fun decodesMessagingTopicsWithServerProvidedImmediateHelp() {
        val envelope = PatientEnvelopeDecoder.messageTopics(
            """
            {
              "data": {
                "topics": [{
                  "code": "care_question",
                  "label": "Question for my care team",
                  "description": "Ask a non-urgent question.",
                  "expected_response_window": "During this shift"
                }],
                "immediate_help": {
                  "version": "approved-guidance-v3",
                  "text": "Use your bedside call button or tell a staff member for immediate help."
                }
              },
              "meta": {"policy_version": "messaging-policy-v2", "stale": false},
              "links": {}
            }
            """.trimIndent(),
        )

        assertEquals("care_question", envelope.data.topics.single().code)
        assertEquals("approved-guidance-v3", envelope.data.immediateHelp.version)
        assertTrue(envelope.data.immediateHelp.text.contains("call button"))
        assertEquals("messaging-policy-v2", envelope.meta.policyVersion)
    }

    @Test
    fun decodesThreadConversationWithoutRoutingMetadata() {
        val envelope = PatientEnvelopeDecoder.messageThread(
            messagingThreadEnvelope(
                messages = """
                [{
                  "message_uuid": "019f4d7a-3200-7000-8000-000000000042",
                  "sender_display_role": "Care team",
                  "message_kind": "message",
                  "body": "We will discuss your goals during rounds.",
                  "relates_to_message_uuid": null,
                  "delivery_state": "delivered",
                  "sent_at": "2026-07-19T12:05:00Z",
                  "responsibility_pool_ref_digest": "must-be-ignored"
                }, {
                  "message_uuid": "019f4d7a-3200-7000-8000-000000000043",
                  "sender_display_role": "You",
                  "message_kind": "retraction",
                  "body": null,
                  "relates_to_message_uuid": "019f4d7a-3200-7000-8000-000000000042",
                  "delivery_state": "sent",
                  "sent_at": "2026-07-19T12:06:00Z"
                }]
                """.trimIndent(),
            ),
        )

        val thread = envelope.data.thread
        assertEquals("awaiting_team", thread.ownershipState)
        assertEquals(2, thread.messages.size)
        assertEquals("Care team", thread.messages.first().senderDisplayRole)
        assertNull(thread.messages.last().body)
        assertEquals("retraction", thread.messages.last().messageKind)
    }

    @Test
    fun decodesPendingThreadListWithItsImmediateHelp() {
        val threadRoot = org.json.JSONObject(messagingThreadEnvelope(messages = "[]"))
        val thread = threadRoot.getJSONObject("data").getJSONObject("thread")
        threadRoot.put(
            "data",
            org.json.JSONObject()
                .put("threads", org.json.JSONArray().put(thread))
                .put(
                    "immediate_help",
                    org.json.JSONObject()
                        .put("version", "approved-guidance-v3")
                        .put(
                            "text",
                            "Use your bedside call button or tell a staff member for immediate help.",
                        ),
                ),
        )

        val envelope = PatientEnvelopeDecoder.messageThreads(threadRoot.toString())

        assertEquals(1, envelope.data.threads.size)
        assertEquals("Question for my care team", envelope.data.threads.single().topic.label)
        assertEquals("approved-guidance-v3", envelope.data.immediateHelp.version)
    }

    @Test
    fun decodesSentMessageAndIdempotencyReplayMetadata() {
        val root = org.json.JSONObject(messagingThreadEnvelope(messages = "[]"))
        val data = root.getJSONObject("data")
        data.put(
            "message",
            org.json.JSONObject(
                """
                {
                  "message_uuid": "019f4d7a-3200-7000-8000-000000000044",
                  "sender_display_role": "You",
                  "message_kind": "message",
                  "body": "One more question.",
                  "relates_to_message_uuid": null,
                  "delivery_state": "sent",
                  "sent_at": "2026-07-19T12:07:00Z"
                }
                """.trimIndent(),
            ),
        )
        root.getJSONObject("meta").put("idempotency_replayed", true)

        val envelope = PatientEnvelopeDecoder.sentMessage(root.toString())

        assertEquals("One more question.", envelope.data.message.body)
        assertEquals(true, envelope.meta.idempotencyReplayed)
    }

    @Test
    fun messagingMutationBodiesKeepIdempotencyInTheHeaderOnly() {
        val create = PatientCreateThreadRequest(
            topicCode = "care_question",
            message = "Question",
            clientMessageUuid = "019f4d7a-3200-7000-8000-000000000050",
            urgentGuidanceVersion = "guidance-v1",
            idempotencyKey = "019f4d7a-3200-7000-8000-000000000051",
        ).json()
        val send = PatientSendMessageRequest(
            message = "Reply",
            clientMessageUuid = "019f4d7a-3200-7000-8000-000000000052",
            threadVersion = 3,
            urgentGuidanceVersion = "guidance-v1",
            idempotencyKey = "019f4d7a-3200-7000-8000-000000000053",
        ).json()
        val close = PatientCloseThreadRequest(
            threadVersion = 4,
            closeReason = "no_longer_needed",
            idempotencyKey = "019f4d7a-3200-7000-8000-000000000054",
        ).json()

        assertFalse(create.has("idempotency_key"))
        assertEquals("guidance-v1", create.getString("urgent_guidance_version"))
        assertEquals(3, send.getInt("thread_version"))
        assertFalse(send.has("idempotency_key"))
        assertEquals("no_longer_needed", close.getString("close_reason"))
        assertFalse(close.has("idempotency_key"))
    }

    @Test
    fun decodesPatientErrorWithoutThrowingOnInvalidBodies() {
        assertEquals(
            "account_inactive",
            PatientEnvelopeDecoder.error(
                """{"error":{"code":"account_inactive","message":"Account unavailable."}}""",
            )?.code,
        )
        assertTrue(PatientEnvelopeDecoder.error("not-json") == null)
    }

    private fun projectionJson(kind: String, content: String): String =
        """
        {
          "data": {
            "projection_uuid": "019f4d7a-3200-7000-8000-000000000030",
            "encounter_uuid": "019f4d7a-3200-7000-8000-000000000020",
            "kind": "$kind",
            "content": $content,
            "uncertainty": {
              "level": "medium",
              "explanation": "Timing can change as your care needs change.",
              "can_change": true,
              "reviewed_at": "2026-07-19T11:58:00Z"
            },
            "provenance": {
              "projection_method": "governed_projection",
              "source_class": "inpatient_care_plan",
              "input_classes": ["care_plan"],
              "review_state": "clinically_reviewed",
              "producer_version": "v1"
            },
            "revision_notice": {
              "kind": "correction",
              "message": "Your care team updated this information. Please use the details shown here."
            },
            "observed_at": "2026-07-19T11:59:00Z",
            "generated_at": "2026-07-19T11:59:30Z",
            "released_at": "2026-07-19T12:00:00Z"
          },
          "meta": {
            "source_freshness": {"status": "current", "observed_at": "2026-07-19T11:59:00Z"},
            "policy_version": "patient-disclosure-v1",
            "state_vocabulary_version": "patient-state-vocabulary.v1-draft",
            "version": 3,
            "as_of": "2026-07-19T12:00:00Z",
            "stale": false,
            "request_id": "request-test",
            "generated_at": "2026-07-19T12:00:01Z"
          },
          "links": {"self": "/api/patient/v1/encounters/test/$kind"}
        }
        """.trimIndent()

    private fun messagingThreadEnvelope(messages: String): String =
        """
        {
          "data": {
            "thread": {
              "thread_uuid": "019f4d7a-3200-7000-8000-000000000040",
              "topic": {
                "code": "care_question",
                "label": "Question for my care team",
                "description": "Ask a non-urgent question."
              },
              "status": "open",
              "ownership_state": "awaiting_team",
              "expected_response_window": "During this shift",
              "version": 2,
              "last_message_at": "2026-07-19T12:05:00Z",
              "created_at": "2026-07-19T12:00:00Z",
              "closed_at": null,
              "close_reason": null,
              "messages": $messages,
              "responsibility_pool_ref_digest": "must-be-ignored"
            }
          },
          "meta": {"policy_version": "messaging-policy-v2", "stale": false},
          "links": {}
        }
        """.trimIndent()
}
