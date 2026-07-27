import XCTest
@testable import HummingbirdPatient

final class PatientAPIClientTests: XCTestCase {
    private var session: URLSession!
    private var client: PatientAPIClient!

    override func setUp() {
        super.setUp()
        PatientURLProtocolStub.reset()
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [PatientURLProtocolStub.self]
        session = URLSession(configuration: configuration)
        client = PatientAPIClient(
            baseURL: URL(string: "https://patient.example.test")!,
            session: session
        )
    }

    override func tearDown() {
        session.invalidateAndCancel()
        PatientURLProtocolStub.reset()
        client = nil
        session = nil
        super.tearDown()
    }

    func testEnrollmentLoginRefreshAndRevokeUseOnlyExactPatientAuthRoutes() async throws {
        PatientURLProtocolStub.install { request in
            switch request.url?.path {
            case "/api/patient/v1/auth/enroll/challenge/verify":
                let body = try XCTUnwrap(Self.bodyData(from: request))
                let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
                XCTAssertEqual(json["challenge_uuid"] as? String, "019f0000-0000-7000-8000-000000000051")
                XCTAssertEqual(json["verification_code"] as? String, "438201")
                return Self.success(request, json: Self.tokenEnvelope(access: "enrollment-access", refresh: "enrollment-refresh"))
            case "/api/patient/v1/auth/token":
                let body = try XCTUnwrap(Self.bodyData(from: request))
                let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
                XCTAssertEqual(json["email"] as? String, "sample@example.test")
                XCTAssertEqual(json["password"] as? String, "patient-password")
                return Self.success(request, json: Self.tokenEnvelope(access: "login-access", refresh: "login-refresh"))
            case "/api/patient/v1/auth/token/refresh":
                XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer refresh-token")
                return Self.success(request, json: Self.tokenEnvelope(access: "rotated-access", refresh: "rotated-refresh"))
            case "/api/patient/v1/auth/token/revoke":
                XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer rotated-refresh")
                return Self.success(request, json: Self.envelope(data: #"{"revoked":true}"#))
            default:
                XCTFail("Unexpected route: \(request.url?.path ?? "nil")")
                return Self.failure(request, status: 404)
            }
        }

        let device = PatientDeviceDescriptor(
            uuid: "019f0000-0000-7000-8000-000000000052",
            platform: "ios",
            name: "Test iPhone",
            appVersion: "0.1.0",
            osVersion: "17.0"
        )
        let enrollment = PatientEnrollmentInput(
            challengeUUID: "019f0000-0000-7000-8000-000000000051",
            challengeToken: "single-use-challenge",
            verificationCode: "438201",
            displayName: "Sam Example",
            email: "sample@example.test",
            password: "patient-password",
            passwordConfirmation: "patient-password"
        )

        let enrolled = try await client.enroll(enrollment, device: device)
        let signedIn = try await client.signIn(
            email: "sample@example.test",
            password: "patient-password",
            device: device
        )
        let refreshed = try await client.refresh(refreshToken: "refresh-token")
        try await client.revoke(token: "rotated-refresh")

        XCTAssertEqual(enrolled.accessToken, "enrollment-access")
        XCTAssertEqual(signedIn.accessToken, "login-access")
        XCTAssertEqual(refreshed.accessToken, "rotated-access")
        let requests = PatientURLProtocolStub.recordedRequests()
        XCTAssertEqual(requests.map(\.httpMethod), ["POST", "POST", "POST", "POST"])
        XCTAssertEqual(requests.compactMap(\.url?.path), [
            "/api/patient/v1/auth/enroll/challenge/verify",
            "/api/patient/v1/auth/token",
            "/api/patient/v1/auth/token/refresh",
            "/api/patient/v1/auth/token/revoke",
        ])
        XCTAssertTrue(requests.allSatisfy { $0.value(forHTTPHeaderField: "Cache-Control") == "no-store" })
    }

    func testReadOnlyExperienceRequestsUseBearerAccessAndDecodeThePatientProjections() async throws {
        let encounterUUID = "019f0000-0000-7000-8000-000000000010"
        PatientURLProtocolStub.install { request in
            XCTAssertEqual(request.httpMethod, "GET")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer patient-access")
            switch request.url?.path {
            case "/api/patient/v1/me":
                return Self.success(request, json: Self.profileEnvelope)
            case "/api/patient/v1/encounters":
                return Self.success(request, json: Self.encountersEnvelope(encounterUUID: encounterUUID))
            case "/api/patient/v1/encounters/\(encounterUUID)/today":
                return Self.success(request, json: Self.todayEnvelope(encounterUUID: encounterUUID))
            case "/api/patient/v1/encounters/\(encounterUUID)/pathway":
                return Self.success(request, json: Self.pathwayEnvelope(encounterUUID: encounterUUID))
            case "/api/patient/v1/encounters/\(encounterUUID)/pathway/events":
                return Self.success(request, json: Self.pathwayEventsEnvelope(encounterUUID: encounterUUID))
            case "/api/patient/v1/encounters/\(encounterUUID)/discharge-readiness":
                return Self.success(request, json: Self.dischargeReadinessEnvelope(encounterUUID: encounterUUID))
            case "/api/patient/v1/encounters/\(encounterUUID)/rounds/summary":
                return Self.success(request, json: Self.roundsSummaryEnvelope(encounterUUID: encounterUUID))
            case "/api/patient/v1/encounters/\(encounterUUID)/care-team":
                return Self.success(request, json: Self.careTeamEnvelope(encounterUUID: encounterUUID))
            default:
                XCTFail("Unexpected route: \(request.url?.path ?? "nil")")
                return Self.failure(request, status: 404)
            }
        }

        async let profile = client.profile(accessToken: "patient-access")
        async let encounters = client.encounters(accessToken: "patient-access")
        async let today = client.today(encounterUUID: encounterUUID, accessToken: "patient-access")
        async let pathway = client.pathway(encounterUUID: encounterUUID, accessToken: "patient-access")
        async let pathwayEvents = client.pathwayEvents(encounterUUID: encounterUUID, accessToken: "patient-access")
        async let dischargeReadiness = client.dischargeReadiness(encounterUUID: encounterUUID, accessToken: "patient-access")
        async let roundsSummary = client.roundsSummary(encounterUUID: encounterUUID, accessToken: "patient-access")
        async let careTeam = client.careTeam(encounterUUID: encounterUUID, accessToken: "patient-access")
        let result = try await (profile, encounters, today, pathway, pathwayEvents, dischargeReadiness, roundsSummary, careTeam)

        XCTAssertEqual(result.0.data.displayName, "Sam Example")
        XCTAssertEqual(result.1.data.encounters.first?.encounterUUID, encounterUUID)
        XCTAssertEqual(result.2.data.content.headline, "Your plan for today")
        XCTAssertEqual(result.3.data.content.stages?.first?.title, "Getting stronger")
        XCTAssertEqual(result.3.data.revisionNotice?.kind, .correction)
        XCTAssertEqual(
            result.3.data.revisionNotice?.message,
            "Your care team updated this information. Please use the details shown here."
        )
        XCTAssertEqual(result.4.data.content.events?.first?.category, .test)
        XCTAssertEqual(result.4.data.content.events?.first?.status, "completed")
        XCTAssertEqual(result.5.data.content.criteria?.first?.status, "pending")
        XCTAssertEqual(result.6.data.content.topics?.first?.status, "current")
        XCTAssertEqual(result.7.data.content.members?.first?.displayName, "Jordan Lee, RN")

        let requests = PatientURLProtocolStub.recordedRequests()
        XCTAssertEqual(Set(requests.compactMap(\.url?.path)), [
            "/api/patient/v1/me",
            "/api/patient/v1/encounters",
            "/api/patient/v1/encounters/\(encounterUUID)/today",
            "/api/patient/v1/encounters/\(encounterUUID)/pathway",
            "/api/patient/v1/encounters/\(encounterUUID)/pathway/events",
            "/api/patient/v1/encounters/\(encounterUUID)/discharge-readiness",
            "/api/patient/v1/encounters/\(encounterUUID)/rounds/summary",
            "/api/patient/v1/encounters/\(encounterUUID)/care-team",
        ])
    }

    func testPreferenceUpdateUsesExactPatientRouteBodyAndBearerAccess() async throws {
        PatientURLProtocolStub.install { request in
            XCTAssertEqual(request.url?.path, "/api/patient/v1/me/preferences")
            XCTAssertEqual(request.httpMethod, "PUT")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer patient-access")
            let body = try XCTUnwrap(Self.bodyData(from: request))
            let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
            XCTAssertEqual(json["locale"] as? String, "en-US")
            XCTAssertEqual(json["timezone"] as? String, "America/New_York")
            XCTAssertEqual(json["text_size"] as? String, "extra_large")
            XCTAssertEqual(json["reduced_motion"] as? Bool, true)
            XCTAssertEqual(json["high_contrast"] as? Bool, true)
            XCTAssertEqual(json["notification_preview"] as? String, "hidden")
            XCTAssertEqual(json["preferred_channel"] as? String, "none")
            XCTAssertEqual(Set(json.keys), [
                "locale",
                "timezone",
                "text_size",
                "reduced_motion",
                "high_contrast",
                "notification_preview",
                "preferred_channel",
            ])
            return Self.success(request, json: Self.profileEnvelope)
        }

        let result = try await client.updatePreferences(
            PatientPreferencesInput(
                locale: "en-US",
                timezone: "America/New_York",
                textSize: .extraLarge,
                reducedMotion: true,
                highContrast: true,
                notificationPreview: .hidden,
                preferredChannel: PatientPreferredChannel.none
            ),
            accessToken: "patient-access"
        )

        XCTAssertEqual(result.data.principalType, "patient")
        XCTAssertEqual(PatientURLProtocolStub.recordedRequests().count, 1)
    }

    func testSessionListAndRevocationUseExactNoCachePatientRoutes() async throws {
        let currentSessionUUID = "019f0000-0000-7000-8000-000000000081"
        let otherSessionUUID = "019f0000-0000-7000-8000-000000000082"
        PatientURLProtocolStub.install { request in
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer patient-access")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
            XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalAndRemoteCacheData)

            switch (request.httpMethod, request.url?.path) {
            case ("GET", "/api/patient/v1/me/sessions"):
                let data = #"{"sessions":[\#(Self.patientSessionSummary(sessionUUID: currentSessionUUID, current: true)),\#(Self.patientSessionSummary(sessionUUID: otherSessionUUID, current: false))]}"#
                return Self.success(request, json: Self.envelope(data: data))
            case ("DELETE", "/api/patient/v1/me/sessions/\(otherSessionUUID)"):
                let data = #"{"session_uuid":"\#(otherSessionUUID)","revoked":true,"already_revoked":false}"#
                return Self.success(request, json: Self.envelope(data: data))
            default:
                XCTFail("Unexpected session route: \(request.httpMethod ?? "nil") \(request.url?.path ?? "nil")")
                return Self.failure(request, status: 404)
            }
        }

        let listed = try await client.sessions(accessToken: "patient-access")
        let revoked = try await client.revokeSession(
            sessionUUID: otherSessionUUID,
            accessToken: "patient-access"
        )

        XCTAssertEqual(listed.data.sessions.count, 2)
        XCTAssertEqual(listed.data.sessions.filter(\.current).count, 1)
        XCTAssertEqual(listed.data.sessions.first?.device.platform, .ios)
        XCTAssertTrue(revoked.data.revoked)
        XCTAssertFalse(revoked.data.alreadyRevoked)
        XCTAssertEqual(
            PatientURLProtocolStub.recordedRequests().map { "\($0.httpMethod ?? "") \($0.url?.path ?? "")" },
            [
                "GET /api/patient/v1/me/sessions",
                "DELETE /api/patient/v1/me/sessions/\(otherSessionUUID)",
            ]
        )
    }

    func testSessionListRejectsMoreThanOneHundredRows() async throws {
        let repeated = Self.patientSessionSummary(
            sessionUUID: "019f0000-0000-7000-8000-000000000081",
            current: true
        )
        let rows = Array(repeating: repeated, count: 101).joined(separator: ",")
        PatientURLProtocolStub.install { request in
            Self.success(request, json: Self.envelope(data: #"{"sessions":[\#(rows)]}"#))
        }

        do {
            _ = try await client.sessions(accessToken: "patient-access")
            XCTFail("Expected the over-bounded registry response to fail closed")
        } catch {
            XCTAssertEqual(error as? PatientAPIError, .invalidResponse)
        }
        XCTAssertEqual(PatientURLProtocolStub.recordedRequests().count, 1)
    }

    func testSessionRevocationRejectsInvalidUUIDBeforeTransport() async throws {
        PatientURLProtocolStub.install { request in
            XCTFail("Invalid session UUID must fail before transport: \(request)")
            return Self.failure(request, status: 500)
        }

        do {
            _ = try await client.revokeSession(
                sessionUUID: "../another-principal",
                accessToken: "patient-access"
            )
            XCTFail("Expected invalid boundary")
        } catch {
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
        XCTAssertTrue(PatientURLProtocolStub.recordedRequests().isEmpty)
    }

    func testMessagingReadOperationsUseExactPatientRoutesAndDecodeOnlyPatientVisibleFields() async throws {
        let encounterUUID = "019f0000-0000-7000-8000-000000000010"
        let threadUUID = "019f0000-0000-7000-8000-000000000061"
        PatientURLProtocolStub.install { request in
            XCTAssertEqual(request.httpMethod, "GET")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer patient-access")
            switch request.url?.path {
            case "/api/patient/v1/encounters/\(encounterUUID)/message-topics":
                return Self.success(request, json: Self.messageTopicsEnvelope)
            case "/api/patient/v1/encounters/\(encounterUUID)/threads":
                return Self.success(request, json: Self.messageThreadsEnvelope(threadUUID: threadUUID))
            case "/api/patient/v1/threads/\(threadUUID)":
                return Self.success(request, json: Self.messageThreadDetailEnvelope(threadUUID: threadUUID))
            default:
                XCTFail("Unexpected messaging route: \(request.url?.path ?? "nil")")
                return Self.failure(request, status: 404)
            }
        }

        let topics = try await client.messageTopics(
            encounterUUID: encounterUUID,
            accessToken: "patient-access"
        )
        let threads = try await client.messageThreads(
            encounterUUID: encounterUUID,
            accessToken: "patient-access"
        )
        let detail = try await client.messageThread(
            threadUUID: threadUUID,
            accessToken: "patient-access"
        )

        XCTAssertEqual(topics.data.topics.first?.code, "care_plan_question")
        XCTAssertEqual(topics.data.immediateHelp.version, "urgent-guidance-v1")
        XCTAssertEqual(threads.data.threads.first?.threadUUID, threadUUID)
        XCTAssertEqual(threads.data.threads.first?.ownershipState, .acknowledged)
        XCTAssertEqual(detail.data.thread.messages.first?.senderDisplayRole, .patient)
        XCTAssertEqual(detail.data.thread.messages.last?.senderDisplayRole, .careTeam)
        XCTAssertEqual(detail.data.immediateHelp, topics.data.immediateHelp)
        XCTAssertEqual(
            PatientURLProtocolStub.recordedRequests().compactMap(\.url?.path),
            [
                "/api/patient/v1/encounters/\(encounterUUID)/message-topics",
                "/api/patient/v1/encounters/\(encounterUUID)/threads",
                "/api/patient/v1/threads/\(threadUUID)",
            ]
        )
    }

    func testMessagingMutationsUseExactBodiesBearerAndDistinctUUIDIdempotencyHeaders() async throws {
        let encounterUUID = "019f0000-0000-7000-8000-000000000010"
        let threadUUID = "019f0000-0000-7000-8000-000000000061"
        let createClientUUID = "019f0000-0000-7000-8000-000000000071"
        let createIdempotencyUUID = "019f0000-0000-7000-8000-000000000072"
        let sendClientUUID = "019f0000-0000-7000-8000-000000000073"
        let sendIdempotencyUUID = "019f0000-0000-7000-8000-000000000074"
        let correctionClientUUID = "019f0000-0000-7000-8000-000000000076"
        let correctionIdempotencyUUID = "019f0000-0000-7000-8000-000000000077"
        let retractionClientUUID = "019f0000-0000-7000-8000-000000000078"
        let retractionIdempotencyUUID = "019f0000-0000-7000-8000-000000000079"
        let closeIdempotencyUUID = "019f0000-0000-7000-8000-000000000075"

        PatientURLProtocolStub.install { request in
            XCTAssertEqual(request.httpMethod, "POST")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer patient-access")
            let body = try XCTUnwrap(Self.bodyData(from: request))
            let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])

            switch request.url?.path {
            case "/api/patient/v1/encounters/\(encounterUUID)/threads":
                XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), createIdempotencyUUID)
                XCTAssertNotEqual(createIdempotencyUUID, createClientUUID)
                XCTAssertEqual(Set(json.keys), ["topic_code", "message", "client_message_uuid", "urgent_guidance_version"])
                XCTAssertEqual(json["topic_code"] as? String, "care_plan_question")
                XCTAssertEqual(json["message"] as? String, "What should I expect before my walk?")
                XCTAssertEqual(json["client_message_uuid"] as? String, createClientUUID)
                XCTAssertEqual(json["urgent_guidance_version"] as? String, "urgent-guidance-v1")
                return Self.success(request, json: Self.envelope(data: #"{"thread":\#(Self.messageThreadSummary(threadUUID: threadUUID))}"#))

            case "/api/patient/v1/threads/\(threadUUID)/messages":
                XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), sendIdempotencyUUID)
                XCTAssertNotEqual(sendIdempotencyUUID, sendClientUUID)
                XCTAssertEqual(Set(json.keys), ["message", "client_message_uuid", "thread_version", "urgent_guidance_version"])
                XCTAssertEqual(json["message"] as? String, "Thank you. Is there anything I should bring?")
                XCTAssertEqual(json["client_message_uuid"] as? String, sendClientUUID)
                XCTAssertEqual(json["thread_version"] as? Int, 2)
                XCTAssertEqual(json["urgent_guidance_version"] as? String, "urgent-guidance-v1")
                let data = #"{"thread":\#(Self.messageThreadSummary(threadUUID: threadUUID)),"message":\#(Self.patientMessageJSON)}"#
                return Self.success(request, json: Self.envelope(data: data))

            case "/api/patient/v1/threads/\(threadUUID)/messages/019f0000-0000-7000-8000-000000000062/amend":
                let data = #"{"thread":\#(Self.messageThreadSummary(threadUUID: threadUUID)),"message":\#(Self.patientMessageJSON)}"#
                XCTAssertEqual(json["action"] as? String, "correction")
                XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), correctionIdempotencyUUID)
                XCTAssertEqual(Set(json.keys), ["action", "message", "client_message_uuid", "thread_version", "urgent_guidance_version"])
                XCTAssertEqual(json["message"] as? String, "Correction: please explain the safe timing first.")
                XCTAssertEqual(json["client_message_uuid"] as? String, correctionClientUUID)
                XCTAssertEqual(json["thread_version"] as? Int, 3)
                XCTAssertEqual(json["urgent_guidance_version"] as? String, "urgent-guidance-v1")
                return Self.success(request, json: Self.envelope(data: data))

            case "/api/patient/v1/threads/\(threadUUID)/messages/019f0000-0000-7000-8000-000000000063/amend":
                let data = #"{"thread":\#(Self.messageThreadSummary(threadUUID: threadUUID)),"message":\#(Self.patientMessageJSON)}"#
                XCTAssertEqual(json["action"] as? String, "retraction")
                XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), retractionIdempotencyUUID)
                XCTAssertEqual(Set(json.keys), ["action", "client_message_uuid", "thread_version", "urgent_guidance_version"])
                XCTAssertEqual(json["client_message_uuid"] as? String, retractionClientUUID)
                XCTAssertEqual(json["thread_version"] as? Int, 3)
                XCTAssertEqual(json["urgent_guidance_version"] as? String, "urgent-guidance-v1")
                return Self.success(request, json: Self.envelope(data: data))

            case "/api/patient/v1/threads/\(threadUUID)/close":
                XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), closeIdempotencyUUID)
                XCTAssertEqual(Set(json.keys), ["thread_version", "close_reason"])
                XCTAssertEqual(json["thread_version"] as? Int, 3)
                XCTAssertEqual(json["close_reason"] as? String, "question_answered")
                return Self.success(request, json: Self.envelope(data: #"{"thread":\#(Self.closedMessageThreadSummary(threadUUID: threadUUID))}"#))

            default:
                XCTFail("Unexpected messaging mutation route: \(request.url?.path ?? "nil")")
                return Self.failure(request, status: 404)
            }
        }

        let created = try await client.createMessageThread(
            encounterUUID: encounterUUID,
            input: PatientMessageThreadCreateInput(
                topicCode: "care_plan_question",
                message: "What should I expect before my walk?",
                clientMessageUUID: createClientUUID,
                urgentGuidanceVersion: "urgent-guidance-v1"
            ),
            idempotencyKey: createIdempotencyUUID,
            accessToken: "patient-access"
        )
        let sent = try await client.sendMessage(
            threadUUID: threadUUID,
            input: PatientMessageCreateInput(
                message: "Thank you. Is there anything I should bring?",
                clientMessageUUID: sendClientUUID,
                threadVersion: 2,
                urgentGuidanceVersion: "urgent-guidance-v1"
            ),
            idempotencyKey: sendIdempotencyUUID,
            accessToken: "patient-access"
        )
        let corrected = try await client.amendMessage(
            threadUUID: threadUUID,
            messageUUID: "019f0000-0000-7000-8000-000000000062",
            input: PatientMessageAmendmentInput(
                action: .correction,
                message: "Correction: please explain the safe timing first.",
                clientMessageUUID: correctionClientUUID,
                threadVersion: 3,
                urgentGuidanceVersion: "urgent-guidance-v1"
            ),
            idempotencyKey: correctionIdempotencyUUID,
            accessToken: "patient-access"
        )
        let retracted = try await client.amendMessage(
            threadUUID: threadUUID,
            messageUUID: "019f0000-0000-7000-8000-000000000063",
            input: PatientMessageAmendmentInput(
                action: .retraction,
                message: nil,
                clientMessageUUID: retractionClientUUID,
                threadVersion: 3,
                urgentGuidanceVersion: "urgent-guidance-v1"
            ),
            idempotencyKey: retractionIdempotencyUUID,
            accessToken: "patient-access"
        )
        let closed = try await client.closeMessageThread(
            threadUUID: threadUUID,
            input: PatientMessageThreadCloseInput(
                threadVersion: 3,
                closeReason: .questionAnswered
            ),
            idempotencyKey: closeIdempotencyUUID,
            accessToken: "patient-access"
        )

        XCTAssertEqual(created.data.thread.threadUUID, threadUUID)
        XCTAssertEqual(sent.data.message.messageUUID, "019f0000-0000-7000-8000-000000000062")
        XCTAssertEqual(corrected.data.message.messageUUID, "019f0000-0000-7000-8000-000000000062")
        XCTAssertEqual(retracted.data.message.messageUUID, "019f0000-0000-7000-8000-000000000062")
        XCTAssertEqual(closed.data.thread.status, .closed)
        XCTAssertTrue(PatientURLProtocolStub.recordedRequests().allSatisfy {
            $0.value(forHTTPHeaderField: "Cache-Control") == "no-store"
        })
    }

    func testMessagingRejectsInvalidIdempotencyUUIDBeforeStartingARequest() async throws {
        PatientURLProtocolStub.install { request in
            XCTFail("Invalid idempotency input must fail before transport: \(request)")
            return Self.failure(request, status: 500)
        }

        do {
            _ = try await client.createMessageThread(
                encounterUUID: "019f0000-0000-7000-8000-000000000010",
                input: PatientMessageThreadCreateInput(
                    topicCode: "care_plan_question",
                    message: "A safe nonurgent question",
                    clientMessageUUID: "019f0000-0000-7000-8000-000000000071",
                    urgentGuidanceVersion: "urgent-guidance-v1"
                ),
                idempotencyKey: "not-a-uuid",
                accessToken: "patient-access"
            )
            XCTFail("Expected invalid boundary")
        } catch {
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
        XCTAssertTrue(PatientURLProtocolStub.recordedRequests().isEmpty)
    }

    func testEducationClarificationUsesOnlyReleasedItemPathAndContentOnlyBody() async throws {
        let encounterUUID = "019f0000-0000-7000-8000-000000000010"
        let educationItemUUID = "019f0000-0000-7000-8000-000000000011"
        let clientMessageUUID = "019f0000-0000-7000-8000-000000000012"
        let idempotencyUUID = "019f0000-0000-7000-8000-000000000013"

        PatientURLProtocolStub.install { request in
            XCTAssertEqual(request.httpMethod, "POST")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer patient-access")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), idempotencyUUID)
            XCTAssertEqual(
                request.url?.path,
                "/api/patient/v1/encounters/\(encounterUUID)/education/\(educationItemUUID)/clarifications"
            )
            let body = try XCTUnwrap(Self.bodyData(from: request))
            let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
            XCTAssertEqual(Set(json.keys), ["message", "client_message_uuid", "urgent_guidance_version"])
            XCTAssertEqual(json["message"] as? String, "Could you explain the safe timing in simpler words?")
            XCTAssertEqual(json["client_message_uuid"] as? String, clientMessageUUID)
            XCTAssertNil(json["completion"])
            XCTAssertNil(json["consent"])
            XCTAssertNil(json["assessment"])
            return Self.success(
                request,
                json: Self.envelope(data: #"{"thread":\#(Self.messageThreadSummary(threadUUID: educationItemUUID))}"#)
            )
        }

        let result = try await client.requestEducationClarification(
            encounterUUID: encounterUUID,
            educationItemUUID: educationItemUUID,
            input: PatientEducationClarificationInput(
                message: "Could you explain the safe timing in simpler words?",
                clientMessageUUID: clientMessageUUID,
                urgentGuidanceVersion: "urgent-guidance-v1"
            ),
            idempotencyKey: idempotencyUUID,
            accessToken: "patient-access"
        )

        XCTAssertEqual(result.data.thread.threadUUID, educationItemUUID)
        XCTAssertTrue(PatientURLProtocolStub.recordedRequests().allSatisfy {
            $0.value(forHTTPHeaderField: "Cache-Control") == "no-store"
        })
    }

    func testMessagingConflictPreservesThePatientErrorCodeForARequiredRefetch() async throws {
        PatientURLProtocolStub.install { request in
            Self.failure(
                request,
                status: 409,
                json: #"{"error":{"code":"stale_thread_version","message":"This conversation changed."}}"#
            )
        }

        do {
            _ = try await client.sendMessage(
                threadUUID: "019f0000-0000-7000-8000-000000000061",
                input: PatientMessageCreateInput(
                    message: "A nonurgent follow-up",
                    clientMessageUUID: "019f0000-0000-7000-8000-000000000073",
                    threadVersion: 2,
                    urgentGuidanceVersion: "urgent-guidance-v1"
                ),
                idempotencyKey: "019f0000-0000-7000-8000-000000000074",
                accessToken: "patient-access"
            )
            XCTFail("Expected conflict")
        } catch {
            XCTAssertEqual(
                error as? PatientAPIError,
                .server(
                    statusCode: 409,
                    code: "stale_thread_version",
                    message: "This conversation changed."
                )
            )
        }
    }

    func testAuthRejectsAResponseThatDoesNotGrantTheExactPatientAbility() async throws {
        PatientURLProtocolStub.install { request in
            let body = #"{"token_type":"Bearer","access_token":"staff-access","refresh_token":"staff-refresh","expires_in":900,"session_uuid":"019f0000-0000-7000-8000-000000000050","abilities":["mobile:access"]}"#
            return Self.success(request, json: Self.envelope(data: body))
        }

        do {
            _ = try await client.signIn(
                email: "sample@example.test",
                password: "patient-password",
                device: PatientDeviceDescriptor(
                    uuid: "019f0000-0000-7000-8000-000000000052",
                    platform: "ios",
                    name: "Test iPhone",
                    appVersion: "0.1.0",
                    osVersion: "17.0"
                )
            )
            XCTFail("Expected a fail-closed patient token validation error")
        } catch {
            XCTAssertEqual(error as? PatientAPIError, .invalidResponse)
        }
    }

    func testUnauthorizedAndMissingResponsesMapToSessionAndProjectionErrors() async throws {
        PatientURLProtocolStub.install { request in
            if request.url?.path == "/api/patient/v1/me" {
                return Self.failure(
                    request,
                    status: 401,
                    json: #"{"error":{"code":"access_expired","message":"Access token expired."}}"#
                )
            }
            return Self.failure(
                request,
                status: 404,
                json: #"{"error":{"code":"projection_not_found","message":"No projection released."}}"#
            )
        }

        do {
            _ = try await client.profile(accessToken: "expired")
            XCTFail("Expected unauthorized")
        } catch {
            XCTAssertEqual(
                error as? PatientAPIError,
                .unauthorized(code: "access_expired", message: "Access token expired.")
            )
        }

        do {
            _ = try await client.today(
                encounterUUID: "019f0000-0000-7000-8000-000000000010",
                accessToken: "patient-access"
            )
            XCTFail("Expected not found")
        } catch {
            XCTAssertEqual(error as? PatientAPIError, .notFound)
        }
    }

    private static func success(_ request: URLRequest, json: String) -> (HTTPURLResponse, Data) {
        response(request, status: 200, json: json)
    }

    private static func failure(
        _ request: URLRequest,
        status: Int,
        json: String = #"{"error":{"code":"not_found","message":"Not found."}}"#
    ) -> (HTTPURLResponse, Data) {
        response(request, status: status, json: json)
    }

    private static func response(
        _ request: URLRequest,
        status: Int,
        json: String
    ) -> (HTTPURLResponse, Data) {
        let response = HTTPURLResponse(
            url: request.url!,
            statusCode: status,
            httpVersion: "HTTP/1.1",
            headerFields: ["Content-Type": "application/json"]
        )!
        return (response, Data(json.utf8))
    }

    private static func bodyData(from request: URLRequest) -> Data? {
        if let body = request.httpBody { return body }
        guard let stream = request.httpBodyStream else { return nil }

        stream.open()
        defer { stream.close() }
        var data = Data()
        let capacity = 4_096
        let buffer = UnsafeMutablePointer<UInt8>.allocate(capacity: capacity)
        defer { buffer.deallocate() }
        while stream.hasBytesAvailable {
            let count = stream.read(buffer, maxLength: capacity)
            guard count >= 0 else { return nil }
            if count == 0 { break }
            data.append(buffer, count: count)
        }
        return data
    }

    private static func envelope(data: String, version: String = "null") -> String {
        #"{"data":\#(data),"meta":{"as_of":"2026-07-19T14:22:31.000000Z","stale":false,"version":\#(version)},"links":{}}"#
    }

    private static func tokenEnvelope(access: String, refresh: String) -> String {
        envelope(data: #"{"token_type":"Bearer","access_token":"\#(access)","refresh_token":"\#(refresh)","expires_in":900,"session_uuid":"019f0000-0000-7000-8000-000000000050","abilities":["patient:access"]}"#)
    }

    private static let profileEnvelope = envelope(data: #"{"principal_uuid":"019f0000-0000-7000-8000-000000000001","principal_type":"patient","display_name":"Sam Example","email":null,"phone_e164":null,"email_verified":false,"phone_verified":false,"locale":"en-US","timezone":"America/New_York","preferences":{}}"#)

    private static let immediateHelpJSON = #"{"version":"urgent-guidance-v1","text":"Messages are not monitored for emergencies. Use your bedside call button or speak with a staff member when you need help now."}"#

    private static let messageTopicJSON = #"{"code":"care_plan_question","label":"Question about my care plan","description":"Ask a nonurgent question about a released step in your care plan.","expected_response_window":"Your care team usually responds during the current care shift."}"#

    private static let patientMessageJSON = #"{"message_uuid":"019f0000-0000-7000-8000-000000000062","sender_display_role":"You","message_kind":"message","body":"What should I expect before my walk?","relates_to_message_uuid":null,"delivery_state":"acknowledged","sent_at":"2026-07-19T14:45:00.000000Z"}"#

    private static let careTeamMessageJSON = #"{"message_uuid":"019f0000-0000-7000-8000-000000000063","sender_display_role":"Care team","message_kind":"message","body":"We will review safe support with you before you begin.","relates_to_message_uuid":null,"delivery_state":"responded","sent_at":"2026-07-19T15:12:00.000000Z"}"#

    private static let messageTopicsEnvelope = envelope(
        data: #"{"topics":[\#(messageTopicJSON)],"immediate_help":\#(immediateHelpJSON)}"#
    )

    private static func messageThreadsEnvelope(threadUUID: String) -> String {
        envelope(
            data: #"{"threads":[\#(messageThreadSummary(threadUUID: threadUUID))],"immediate_help":\#(immediateHelpJSON)}"#
        )
    }

    private static func messageThreadDetailEnvelope(threadUUID: String) -> String {
        let summary = messageThreadSummary(threadUUID: threadUUID)
        let flattenedDetail = String(summary.dropLast())
            + #", "messages":[\#(patientMessageJSON),\#(careTeamMessageJSON)]}"#
        return envelope(
            data: #"{"thread":\#(flattenedDetail),"immediate_help":\#(immediateHelpJSON)}"#
        )
    }

    private static func messageThreadSummary(threadUUID: String) -> String {
        #"{"thread_uuid":"\#(threadUUID)","topic":{"code":"care_plan_question","label":"Question about my care plan","description":"Ask a nonurgent question about a released step in your care plan."},"status":"open","ownership_state":"acknowledged","expected_response_window":"Your care team usually responds during the current care shift.","version":2,"last_message_at":"2026-07-19T15:12:00.000000Z","created_at":"2026-07-19T14:45:00.000000Z","closed_at":null,"close_reason":null}"#
    }

    private static func closedMessageThreadSummary(threadUUID: String) -> String {
        #"{"thread_uuid":"\#(threadUUID)","topic":{"code":"care_plan_question","label":"Question about my care plan","description":"Ask a nonurgent question about a released step in your care plan."},"status":"closed","ownership_state":"closed","expected_response_window":"Your care team usually responds during the current care shift.","version":4,"last_message_at":"2026-07-19T15:12:00.000000Z","created_at":"2026-07-19T14:45:00.000000Z","closed_at":"2026-07-19T15:30:00.000000Z","close_reason":"question_answered"}"#
    }

    private static func patientSessionSummary(sessionUUID: String, current: Bool) -> String {
        #"{"session_uuid":"\#(sessionUUID)","current":\#(current),"status":"active","device":{"uuid":"019f0000-0000-7000-8000-000000000091","platform":"ios","name":"Patient iPhone","app_version":"0.1.0","os_version":"26.0"},"auth_method":"password","assurance_level":null,"last_seen_at":"2026-07-20T14:22:31.000000Z","expires_at":"2026-08-19T14:22:31.000000Z","created_at":"2026-07-19T14:22:31.000000Z"}"#
    }

    private static func encountersEnvelope(encounterUUID: String) -> String {
        envelope(data: #"{"encounters":[{"encounter_uuid":"\#(encounterUUID)","grant_uuid":"019f0000-0000-7000-8000-000000000011","relationship":"self","scopes":["today:read","pathway:read","care_team:read"],"valid_from":null,"expires_at":null,"version":1}]}"#, version: "1")
    }

    private static func todayEnvelope(encounterUUID: String) -> String {
        projectionEnvelope(
            encounterUUID: encounterUUID,
            kind: "today",
            content: #"{"headline":"Your plan for today","summary":"Released steps.","schedule":[],"next_steps":[],"questions":[],"notices":[]}"#
        )
    }

    private static func pathwayEnvelope(encounterUUID: String) -> String {
        projectionEnvelope(
            encounterUUID: encounterUUID,
            kind: "pathway",
            content: #"{"headline":"My Path","summary":"Released path.","current_stage":"Getting stronger","stages":[{"stage_uuid":"019f0000-0000-7000-8000-000000000031","title":"Getting stronger","status":"current","summary":"Recovering safely.","expected_range":null,"timing_confidence":"current","can_change":true}],"milestones":[],"goals":[],"education":[],"questions":[],"notices":[]}"#
        )
    }

    private static func pathwayEventsEnvelope(encounterUUID: String) -> String {
        projectionEnvelope(
            encounterUUID: encounterUUID,
            kind: "pathway_events",
            content: #"{"headline":"What has happened so far","summary":"Released timeline.","events":[{"event_uuid":"019f0000-0000-7000-8000-000000000032","title":"Admitted to the hospital","when":"Two days ago","category":"test","status":"completed","detail":"Your care team reviewed your history."}],"notices":["This is a summary."]}"#
        )
    }

    private static func dischargeReadinessEnvelope(encounterUUID: String) -> String {
        projectionEnvelope(
            encounterUUID: encounterUUID,
            kind: "discharge_readiness",
            content: #"{"headline":"Getting ready to leave","summary":"Released preparation summary.","estimated_range":"The next day or two","estimated_confidence":"estimated","criteria":[{"item_uuid":"019f0000-0000-7000-8000-000000000035","label":"Moving safely with the support you need","status":"pending","detail":"Your team will review this with you each day."}],"unresolved_needs":["A ride home arranged for the day you leave."],"medications":[{"item_uuid":"019f0000-0000-7000-8000-000000000036","name":"Your updated medicine list","purpose":"Review each medicine with your care team."}],"follow_up":[{"item_uuid":"019f0000-0000-7000-8000-000000000037","label":"Follow-up visit with your care team","when":"Within a week or two of leaving"}],"warning_signs":["Call your care team if symptoms get worse after you go home."],"contacts":[{"item_uuid":"019f0000-0000-7000-8000-000000000038","label":"Your care team","route":"speak_with_bedside_staff"}],"questions":[],"notices":["Details can change."]}"#
        )
    }

    private static func roundsSummaryEnvelope(encounterUUID: String) -> String {
        projectionEnvelope(
            encounterUUID: encounterUUID,
            kind: "rounds_summary",
            content: #"{"headline":"Your care-team conversation","summary":"Released plain-language summary.","round_window":"Earlier today","topics":[{"topic_uuid":"019f0000-0000-7000-8000-000000000039","title":"How you are doing","summary":"Your team reviewed progress and next steps.","status":"current"}],"next_steps":["Tell your bedside team what you would like explained."],"questions":[],"notices":["This summary can change."]}"#
        )
    }

    private static func careTeamEnvelope(encounterUUID: String) -> String {
        projectionEnvelope(
            encounterUUID: encounterUUID,
            kind: "care_team",
            content: #"{"headline":"Care Team","summary":"People helping you.","members":[{"member_uuid":"019f0000-0000-7000-8000-000000000041","display_name":"Jordan Lee, RN","role":"Bedside nurse","service":"5 East","responsibilities":["Coordinates bedside care."],"contact_route":"speak_with_bedside_staff"}],"communication_options":["speak_with_bedside_staff","call_button_for_urgent_help"],"questions":[],"notices":[]}"#
        )
    }

    private static func projectionEnvelope(encounterUUID: String, kind: String, content: String) -> String {
        envelope(data: #"{"projection_uuid":"019f0000-0000-7000-8000-000000000099","encounter_uuid":"\#(encounterUUID)","kind":"\#(kind)","content":\#(content),"uncertainty":{"level":"medium","explanation":"Plans can change.","can_change":true,"reviewed_at":"2026-07-19T14:20:00.000000Z"},"provenance":{"projection_method":"governed_projection","source_class":"clinical_operations","input_classes":["care_plan"],"review_state":"clinically_reviewed","producer_version":"patient-projection-v1"},"revision_notice":{"kind":"correction","message":"Your care team updated this information. Please use the details shown here."},"observed_at":"2026-07-19T14:18:00.000000Z","generated_at":"2026-07-19T14:19:00.000000Z","released_at":"2026-07-19T14:20:00.000000Z"}"#)
    }
}

private final class PatientURLProtocolStub: URLProtocol, @unchecked Sendable {
    typealias Handler = (URLRequest) throws -> (HTTPURLResponse, Data)

    private static let lock = NSLock()
    private static var handler: Handler?
    private static var requests: [URLRequest] = []

    static func install(_ handler: @escaping Handler) {
        lock.lock()
        self.handler = handler
        requests = []
        lock.unlock()
    }

    static func reset() {
        lock.lock()
        handler = nil
        requests = []
        lock.unlock()
    }

    static func recordedRequests() -> [URLRequest] {
        lock.lock()
        defer { lock.unlock() }
        return requests
    }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        Self.lock.lock()
        Self.requests.append(request)
        let handler = Self.handler
        Self.lock.unlock()

        guard let handler else {
            client?.urlProtocol(self, didFailWithError: PatientAPIError.invalidResponse)
            return
        }

        do {
            let (response, data) = try handler(request)
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}
