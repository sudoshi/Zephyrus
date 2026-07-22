import XCTest
@testable import HummingbirdPatient

final class PatientAPIModelTests: XCTestCase {
    private let decoder = JSONDecoder()

    func testProfileEnvelopeDecodesCurrentBackendShapeAndNullVersion() throws {
        let json = #"""
        {
          "data": {
            "principal_uuid": "019f0000-0000-7000-8000-000000000001",
            "principal_type": "patient",
            "display_name": "Sample Patient",
            "email": "sample@example.test",
            "phone_e164": null,
            "email_verified": true,
            "phone_verified": false,
            "locale": "en-US",
            "timezone": "America/New_York",
            "preferences": {
              "text_size": "large",
              "reduced_motion": true,
              "high_contrast": true,
              "notification_preview": "hidden",
              "preferred_channel": "none"
            }
          },
          "meta": {
            "as_of": "2026-07-19T14:22:31.000000Z",
            "stale": false,
            "version": null
          },
          "links": {}
        }
        """#.data(using: .utf8)!

        let envelope = try decoder.decode(PatientEnvelope<PatientProfile>.self, from: json)
        XCTAssertEqual(envelope.data.displayName, "Sample Patient")
        XCTAssertEqual(envelope.data.principalType, "patient")
        XCTAssertEqual(envelope.data.preferences.textSize, .large)
        XCTAssertEqual(envelope.data.preferences.notificationPreview, .hidden)
        XCTAssertNil(envelope.meta.version)
        XCTAssertNotNil(envelope.meta.asOfDate)
        XCTAssertTrue(envelope.links.isEmpty)
    }

    func testEncounterEnvelopeDecodesIntegerVersionAndSafeHandles() throws {
        let json = #"""
        {
          "data": {
            "encounters": [{
              "encounter_uuid": "019f0000-0000-7000-8000-000000000010",
              "grant_uuid": "019f0000-0000-7000-8000-000000000011",
              "relationship": "self",
              "scopes": ["today:read", "pathway:read"],
              "valid_from": "2026-07-19T13:00:00.000000Z",
              "expires_at": null,
              "version": 3
            }]
          },
          "meta": {
            "as_of": "2026-07-19T14:22:31.000000Z",
            "stale": false,
            "version": 3,
            "count": 1
          },
          "links": {}
        }
        """#.data(using: .utf8)!

        let envelope = try decoder.decode(PatientEnvelope<PatientEncounterCollection>.self, from: json)
        XCTAssertEqual(envelope.data.encounters.count, 1)
        XCTAssertEqual(envelope.data.encounters[0].relationship, "self")
        XCTAssertEqual(envelope.data.encounters[0].scopes, ["today:read", "pathway:read"])
        XCTAssertEqual(envelope.meta.version, .integer(3))
    }

    func testTokenEnvelopeDecodesPatientAbilities() throws {
        let json = #"""
        {
          "data": {
            "token_type": "Bearer",
            "access_token": "test-access-token",
            "refresh_token": "test-refresh-token",
            "expires_in": 900,
            "session_uuid": "019f0000-0000-7000-8000-000000000020",
            "abilities": ["patient:access"]
          },
          "meta": {"as_of": "2026-07-19T14:22:31.000000Z", "stale": false, "version": null},
          "links": {}
        }
        """#.data(using: .utf8)!

        let envelope = try decoder.decode(PatientEnvelope<PatientTokenPair>.self, from: json)
        XCTAssertEqual(envelope.data.abilities, ["patient:access"])
        XCTAssertEqual(envelope.data.expiresIn, 900)
    }

    func testPathwayEnvelopeDecodesReleasedMilestonesGoalsAndEducation() throws {
        let json = #"""
        {
          "data": {
            "projection_uuid": "019f0000-0000-7000-8000-000000000030",
            "encounter_uuid": "019f0000-0000-7000-8000-000000000010",
            "kind": "pathway",
            "content": {
              "headline": "My Path",
              "summary": "Released pathway.",
              "current_stage": "Getting stronger",
              "stages": [],
              "milestones": [{
                "milestone_uuid": "019f0000-0000-7000-8000-000000000031",
                "title": "Review medicines before discharge",
                "status": "planned",
                "detail": "Review changes with your team.",
                "timing": "Before your next setting",
                "timing_confidence": "estimated",
                "can_change": true
              }],
              "goals": [{
                "goal_uuid": "019f0000-0000-7000-8000-000000000032",
                "author_type": "patient",
                "label": "Understand the next step",
                "explanation": "Ask what would help you feel ready.",
                "status": "planned",
                "target_range": null
              }],
              "education": [{
                "item_uuid": "019f0000-0000-7000-8000-000000000033",
                "title": "Preparing for home",
                "summary": "Review the released next-step information."
              }],
              "questions": [],
              "notices": []
            },
            "uncertainty": {"level": "medium", "explanation": "Plans can change.", "can_change": true, "reviewed_at": "2026-07-19T14:20:00.000000Z"},
            "provenance": {"projection_method": "governed_projection", "source_class": "clinical_operations", "input_classes": ["care_plan"], "review_state": "clinically_reviewed", "producer_version": "patient-projection-v1"},
            "observed_at": "2026-07-19T14:18:00.000000Z",
            "generated_at": "2026-07-19T14:19:00.000000Z",
            "released_at": "2026-07-19T14:20:00.000000Z"
          },
          "meta": {"as_of": "2026-07-19T14:22:31.000000Z", "stale": false, "version": 1},
          "links": {}
        }
        """#.data(using: .utf8)!

        let envelope = try decoder.decode(PatientPathwayProjectionEnvelope.self, from: json)
        let content = envelope.data.content
        XCTAssertEqual(content.milestones?.first?.title, "Review medicines before discharge")
        XCTAssertEqual(content.goals?.first?.authorType, "patient")
        XCTAssertEqual(content.education?.first?.title, "Preparing for home")
    }

    func testSessionEnvelopeDecodesOnlyTheGovernedPatientDeviceFields() throws {
        let json = #"""
        {
          "data": {
            "sessions": [{
              "session_uuid": "019f0000-0000-7000-8000-000000000081",
              "current": true,
              "status": "active",
              "device": {
                "uuid": null,
                "platform": "ios",
                "name": null,
                "app_version": "0.1.0",
                "os_version": "26.0"
              },
              "auth_method": "password",
              "assurance_level": null,
              "last_seen_at": "2026-07-20T14:22:31.000000Z",
              "expires_at": "2026-08-19T14:22:31.000000Z",
              "created_at": "2026-07-19T14:22:31.000000Z"
            }]
          },
          "meta": {"as_of": "2026-07-20T14:22:31.000000Z", "stale": false, "version": null},
          "links": {}
        }
        """#.data(using: .utf8)!

        let envelope = try decoder.decode(PatientEnvelope<PatientSessionCollection>.self, from: json)
        let session = try XCTUnwrap(envelope.data.sessions.first)
        XCTAssertEqual(session.sessionUUID, "019f0000-0000-7000-8000-000000000081")
        XCTAssertTrue(session.current)
        XCTAssertEqual(session.status, .active)
        XCTAssertEqual(session.device.platform, .ios)
        XCTAssertNil(session.device.uuid)
        XCTAssertNil(session.device.name)
        XCTAssertEqual(session.authMethod, .password)
        XCTAssertNil(session.assuranceLevel)
        XCTAssertNotNil(session.lastSeenDate)
        XCTAssertNotNil(session.expiresDate)
        XCTAssertNotNil(session.createdDate)
    }

    func testSessionModelRejectsMissingRequiredNullableDeviceField() throws {
        let json = #"""
        {
          "session_uuid": "019f0000-0000-7000-8000-000000000081",
          "current": true,
          "status": "active",
          "device": {
            "uuid": null,
            "platform": "ios",
            "name": "This iPhone",
            "app_version": "0.1.0"
          },
          "auth_method": "password",
          "assurance_level": null,
          "last_seen_at": "2026-07-20T14:22:31.000000Z",
          "expires_at": "2026-08-19T14:22:31.000000Z",
          "created_at": "2026-07-19T14:22:31.000000Z"
        }
        """#.data(using: .utf8)!

        XCTAssertThrowsError(try decoder.decode(PatientSessionSummary.self, from: json))
    }

    func testSessionRevocationResultDecodesIdempotentOutcome() throws {
        let json = #"{"session_uuid":"019f0000-0000-7000-8000-000000000081","revoked":true,"already_revoked":true}"#.data(using: .utf8)!
        let result = try decoder.decode(PatientSessionRevocationResult.self, from: json)
        XCTAssertTrue(result.revoked)
        XCTAssertTrue(result.alreadyRevoked)
    }

    func testTodayProjectionDecodesGovernedContentFreshnessUncertaintyAndProvenance() throws {
        let json = #"""
        {
          "data": {
            "projection_uuid": "019f0000-0000-7000-8000-000000000030",
            "encounter_uuid": "019f0000-0000-7000-8000-000000000031",
            "kind": "today",
            "content": {
              "headline": "Your plan for today",
              "summary": "Your care team released these next steps.",
              "schedule": [{
                "item_uuid": "019f0000-0000-7000-8000-000000000032",
                "label": "Care team rounds",
                "detail": "Bring your questions.",
                "status": "planned",
                "time_window": "This morning",
                "timing_confidence": "estimated",
                "can_change": true
              }],
              "next_steps": ["Write down your questions."],
              "notices": ["Timing can change."]
            },
            "uncertainty": {
              "level": "medium",
              "explanation": "Timing may change after reassessment.",
              "can_change": true,
              "reviewed_at": "2026-07-19T14:20:00.000000Z"
            },
            "provenance": {
              "projection_method": "governed_projection",
              "source_class": "clinical_operations",
              "input_classes": ["care_plan"],
              "review_state": "clinically_reviewed",
              "producer_version": "patient-projection-v1"
            },
            "observed_at": "2026-07-19T14:18:00.000000Z",
            "generated_at": "2026-07-19T14:19:00.000000Z",
            "released_at": "2026-07-19T14:20:00.000000Z"
          },
          "meta": {
            "as_of": "2026-07-19T14:20:00.000000Z",
            "stale": false,
            "version": 2,
            "source_freshness": {
              "status": "current",
              "observed_at": "2026-07-19T14:18:00.000000Z"
            },
            "policy_version": "patient-disclosure-v1",
            "state_vocabulary_version": "patient-state-vocabulary.v1-draft",
            "request_id": "request-1",
            "generated_at": "2026-07-19T14:20:01.000000Z"
          },
          "links": {}
        }
        """#.data(using: .utf8)!

        let envelope = try decoder.decode(PatientTodayProjectionEnvelope.self, from: json)
        XCTAssertEqual(envelope.data.kind, "today")
        XCTAssertEqual(envelope.data.content.schedule?.first?.label, "Care team rounds")
        XCTAssertEqual(envelope.data.uncertainty.level, "medium")
        XCTAssertTrue(envelope.data.uncertainty.canChange)
        XCTAssertEqual(envelope.data.provenance.reviewState, "clinically_reviewed")
        XCTAssertEqual(envelope.meta.sourceFreshness?.status, "current")
        XCTAssertEqual(envelope.meta.policyVersion, "patient-disclosure-v1")
        XCTAssertEqual(envelope.meta.stateVocabularyVersion, "patient-state-vocabulary.v1-draft")
        XCTAssertEqual(envelope.meta.version, .integer(2))
    }
}
