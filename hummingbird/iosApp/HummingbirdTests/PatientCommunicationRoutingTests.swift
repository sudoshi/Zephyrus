import XCTest
@testable import Hummingbird

final class PatientCommunicationRoutingContractTests: XCTestCase {
    func testDecodesContentMinimizedMutationReplayWithoutLiveProjection() throws {
        let json = """
        {
          "data": {
            "work_item": null,
            "message": null,
            "event_uuid": "66666666-6666-4666-8666-666666666666",
            "replayed": true
          },
          "meta": {"as_of": "2026-07-20T12:00:00Z", "stale": false, "version": null,
            "classification": "patient_communication_restricted", "offline_writes_allowed": false},
          "links": {}
        }
        """
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let result = try decoder.decode(Envelope<PatientCommunicationMutationData>.self, from: Data(json.utf8)).data

        XCTAssertNil(result.workItem)
        XCTAssertNil(result.message)
        XCTAssertEqual(result.eventUuid, "66666666-6666-4666-8666-666666666666")
        XCTAssertTrue(result.replayed)
    }

    func testDecodesOnlyOpaqueBoundedSelectorsAndValidatedDisplayCopy() throws {
        let result = try Self.decode(Self.validCandidatesJSON())

        XCTAssertEqual(result.workItemUuid, Self.workItemUUID)
        XCTAssertEqual(result.workItemVersion, 4)
        XCTAssertEqual(result.threadVersion, 8)
        XCTAssertTrue(result.actions.canRelease)
        XCTAssertTrue(result.actions.canReassign)
        XCTAssertTrue(result.actions.canReroute)
        XCTAssertEqual(result.reasonOptions.reassign.map(\.code), ["coverage_change"])
        XCTAssertEqual(result.reassignCandidates.first?.membershipUuid, Self.membershipUUID)
        XCTAssertEqual(result.reassignCandidates.first?.label, "Jordan Lee")
        XCTAssertEqual(result.reassignCandidates.first?.membershipRole, .responder)
        XCTAssertEqual(result.rerouteCandidates.first?.poolUuid, Self.poolUUID)
        XCTAssertEqual(result.rerouteCandidates.first?.scopeType, .unit)
        XCTAssertEqual(result.rerouteCandidates.first?.unit?.label, "6 North")
    }

    func testUnknownReasonRoleAndScopeRejectEntireAuthorizationProjection() throws {
        let json = Self.validCandidatesJSON(
            releaseCode: "invented_release",
            membershipRole: "administrator",
            scopeType: "patient",
            includeUnit: false
        )
        XCTAssertThrowsError(try Self.decode(json))
    }

    func testRejectsNoncanonicalWorkItemUUIDAndOversizedReasonOptions() throws {
        XCTAssertThrowsError(try Self.decode(
            Self.validCandidatesJSON(workItemUUID: "AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA")
        ))

        let reasons = Array(repeating: "{\"code\":\"return_to_team\",\"label\":\"Return to team\"}", count: 13)
            .joined(separator: ",")
        XCTAssertThrowsError(try Self.decode(
            Self.validCandidatesJSON(releaseReasonsJSON: reasons)
        ))
    }

    func testRejectsUnknownInternalFieldsAtRootAndNestedCandidateBoundaries() throws {
        var root = try Self.object(Self.validCandidatesJSON())
        root["assigned_user_id"] = 42
        XCTAssertThrowsError(try Self.decode(root))

        var nested = try Self.object(Self.validCandidatesJSON())
        var candidates = try XCTUnwrap(nested["reassign_candidates"] as? [[String: Any]])
        candidates[0]["staff_user_id"] = 42
        nested["reassign_candidates"] = candidates
        XCTAssertThrowsError(try Self.decode(nested))
    }

    func testRejectsDuplicateSelectorsAndActionCandidateInconsistency() throws {
        var duplicate = try Self.object(Self.validCandidatesJSON())
        var candidates = try XCTUnwrap(duplicate["reassign_candidates"] as? [[String: Any]])
        candidates.append(candidates[0])
        duplicate["reassign_candidates"] = candidates
        XCTAssertThrowsError(try Self.decode(duplicate))

        var inconsistent = try Self.object(Self.validCandidatesJSON())
        var actions = try XCTUnwrap(inconsistent["actions"] as? [String: Any])
        actions["can_reassign"] = false
        inconsistent["actions"] = actions
        XCTAssertThrowsError(try Self.decode(inconsistent))
    }

    func testAllowsServerAllReasonOptionsWhenEveryActionIsFalseAndCandidatesAreEmpty() throws {
        var payload = try Self.object(Self.validCandidatesJSON())
        payload["actions"] = [
            "can_release": false,
            "can_reassign": false,
            "can_reroute": false,
        ]
        payload["reassign_candidates"] = []
        payload["reroute_candidates"] = []

        let result = try Self.decode(payload)

        XCTAssertFalse(result.actions.canRelease)
        XCTAssertFalse(result.actions.canReassign)
        XCTAssertFalse(result.actions.canReroute)
        XCTAssertEqual(result.reasonOptions.release.map(\.code), ["return_to_team"])
        XCTAssertEqual(result.reasonOptions.reassign.map(\.code), ["coverage_change"])
        XCTAssertEqual(result.reasonOptions.reroute.map(\.code), ["unit_transfer"])
    }

    static let workItemUUID = "11111111-1111-4111-8111-111111111111"
    static let membershipUUID = "44444444-4444-4444-8444-444444444444"
    static let poolUUID = "55555555-5555-4555-8555-555555555555"

    static func decode(_ json: String) throws -> PatientCommunicationRouteCandidatesData {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(PatientCommunicationRouteCandidatesData.self, from: Data(json.utf8))
    }

    static func decode(_ object: [String: Any]) throws -> PatientCommunicationRouteCandidatesData {
        let data = try JSONSerialization.data(withJSONObject: object, options: [.sortedKeys])
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(PatientCommunicationRouteCandidatesData.self, from: data)
    }

    static func object(_ json: String) throws -> [String: Any] {
        try XCTUnwrap(JSONSerialization.jsonObject(with: Data(json.utf8)) as? [String: Any])
    }

    static func validCandidatesJSON(
        workItemUUID: String = workItemUUID,
        workItemVersion: Int = 4,
        threadVersion: Int = 8,
        releaseCode: String = "return_to_team",
        membershipRole: String = "responder",
        scopeType: String = "unit",
        includeUnit: Bool = true,
        releaseReasonsJSON: String? = nil
    ) -> String {
        let releaseReasons = releaseReasonsJSON
            ?? "{\"code\":\"\(releaseCode)\",\"label\":\"Return to team queue\"}"
        let unit = includeUnit ? "{\"id\":86,\"label\":\"6 North\"}" : "null"
        return """
        {
          "work_item_uuid": "\(workItemUUID)",
          "work_item_version": \(workItemVersion),
          "thread_version": \(threadVersion),
          "actions": {"can_release": true, "can_reassign": true, "can_reroute": true},
          "reason_options": {
            "release": [\(releaseReasons)],
            "reassign": [{"code": "coverage_change", "label": "Coverage change"}],
            "reroute": [{"code": "unit_transfer", "label": "Unit transfer"}]
          },
          "reassign_candidates": [{
            "membership_uuid": "\(membershipUUID)",
            "label": "Jordan Lee",
            "membership_role": "\(membershipRole)"
          }],
          "reroute_candidates": [{
            "pool_uuid": "\(poolUUID)",
            "label": "6 North care team",
            "scope_type": "\(scopeType)",
            "unit": \(unit)
          }]
        }
        """
    }
}

@MainActor
final class PatientCommunicationRoutingAPIClientTests: XCTestCase {
    override func tearDown() {
        PatientCommunicationRoutingURLProtocol.handler = nil
        super.tearDown()
    }

    func testRouteCandidatesUsesExactPathAndNoStore() async throws {
        var captured: URLRequest?
        PatientCommunicationRoutingURLProtocol.handler = { request in
            captured = request
            return (200, Data(Self.candidatesEnvelope.utf8))
        }
        let client = Self.client()

        let result = try await client.patientCommunicationRouteCandidates(
            workItemUUID: PatientCommunicationRoutingContractTests.workItemUUID,
            bearer: "staff-token"
        )

        XCTAssertEqual(result.workItemVersion, 4)
        let request = try XCTUnwrap(captured)
        XCTAssertEqual(
            request.url?.path,
            "/api/mobile/v1/patient-communications/threads/\(PatientCommunicationRoutingContractTests.workItemUUID)/route-candidates"
        )
        XCTAssertEqual(request.httpMethod, "GET")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
        XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalCacheData)
    }

    func testReassignUsesExactReplayHeaderVersionsAndOpaqueMembershipOnly() async throws {
        let idempotencyKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        var captured: URLRequest?
        var capturedBody: Data?
        PatientCommunicationRoutingURLProtocol.handler = { request in
            captured = request
            capturedBody = Self.bodyData(from: request)
            return (200, Data(Self.mutationEnvelope.utf8))
        }

        _ = try await Self.client().reassignPatientCommunication(
            workItemUUID: PatientCommunicationRoutingContractTests.workItemUUID,
            workItemVersion: 4,
            threadVersion: 8,
            targetMembershipUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reasonCode: "coverage_change",
            idempotencyKey: idempotencyKey,
            bearer: "staff-token"
        )

        let request = try XCTUnwrap(captured)
        XCTAssertEqual(request.httpMethod, "POST")
        XCTAssertEqual(
            request.url?.path,
            "/api/mobile/v1/patient-communications/threads/\(PatientCommunicationRoutingContractTests.workItemUUID)/reassign"
        )
        XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), idempotencyKey.uuidString.lowercased())
        XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(capturedBody)) as? [String: Any])
        XCTAssertEqual(json["work_item_version"] as? Int, 4)
        XCTAssertEqual(json["thread_version"] as? Int, 8)
        XCTAssertEqual(json["target_membership_uuid"] as? String, PatientCommunicationRoutingContractTests.membershipUUID)
        XCTAssertEqual(json["reason_code"] as? String, "coverage_change")
        XCTAssertEqual(Set(json.keys), ["work_item_version", "thread_version", "target_membership_uuid", "reason_code"])
    }

    func testReleaseAndRerouteBodiesCannotCrossTargetKinds() async throws {
        var requests: [URLRequest] = []
        var bodies: [[String: Any]] = []
        PatientCommunicationRoutingURLProtocol.handler = { request in
            requests.append(request)
            let data = try XCTUnwrap(Self.bodyData(from: request))
            bodies.append(try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any]))
            return (200, Data(Self.mutationEnvelope.utf8))
        }
        let client = Self.client()

        _ = try await client.releasePatientCommunication(
            workItemUUID: PatientCommunicationRoutingContractTests.workItemUUID,
            workItemVersion: 4,
            threadVersion: 8,
            reasonCode: "return_to_team",
            idempotencyKey: UUID(),
            bearer: "token"
        )
        _ = try await client.reroutePatientCommunication(
            workItemUUID: PatientCommunicationRoutingContractTests.workItemUUID,
            workItemVersion: 4,
            threadVersion: 8,
            targetPoolUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reasonCode: "unit_transfer",
            idempotencyKey: UUID(),
            bearer: "token"
        )

        XCTAssertEqual(requests.map { $0.url?.lastPathComponent }, ["release", "reroute"])
        XCTAssertNil(bodies[0]["target_membership_uuid"])
        XCTAssertNil(bodies[0]["target_pool_uuid"])
        XCTAssertEqual(bodies[1]["target_pool_uuid"] as? String, PatientCommunicationRoutingContractTests.poolUUID)
        XCTAssertNil(bodies[1]["target_membership_uuid"])
    }

    func testRejectsUnknownReasonAndNoncanonicalTargetBeforeNetwork() async {
        var requestCount = 0
        PatientCommunicationRoutingURLProtocol.handler = { _ in
            requestCount += 1
            return (500, Data())
        }
        let client = Self.client()

        do {
            _ = try await client.reassignPatientCommunication(
                workItemUUID: PatientCommunicationRoutingContractTests.workItemUUID,
                workItemVersion: 4,
                threadVersion: 8,
                targetMembershipUUID: "AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA",
                reasonCode: "coverage_change",
                idempotencyKey: UUID(),
                bearer: "token"
            )
            XCTFail("Expected canonical UUID rejection")
        } catch {}

        do {
            _ = try await client.releasePatientCommunication(
                workItemUUID: PatientCommunicationRoutingContractTests.workItemUUID,
                workItemVersion: 4,
                threadVersion: 8,
                reasonCode: "invented_reason",
                idempotencyKey: UUID(),
                bearer: "token"
            )
            XCTFail("Expected reason allowlist rejection")
        } catch {}

        XCTAssertEqual(requestCount, 0)
    }

    private static func client() -> APIClient {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [PatientCommunicationRoutingURLProtocol.self]
        configuration.urlCache = nil
        configuration.httpCookieStorage = nil
        configuration.urlCredentialStorage = nil
        return APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration)
        )
    }

    private static func bodyData(from request: URLRequest) -> Data? {
        if let body = request.httpBody { return body }
        guard let stream = request.httpBodyStream else { return nil }
        stream.open()
        defer { stream.close() }
        var data = Data()
        var buffer = [UInt8](repeating: 0, count: 4_096)
        while stream.hasBytesAvailable {
            let count = stream.read(&buffer, maxLength: buffer.count)
            if count <= 0 { break }
            data.append(buffer, count: count)
        }
        return data
    }

    private static let candidatesEnvelope = """
    {
      "data": \(PatientCommunicationRoutingContractTests.validCandidatesJSON()),
      "meta": {"as_of": "2026-07-20T12:00:00Z", "stale": false, "version": null,
        "classification": "patient_communication_restricted", "offline_writes_allowed": false},
      "links": {}
    }
    """

    private static let mutationEnvelope = """
    {
      "data": {
        "work_item": {
          "work_item_uuid": "11111111-1111-4111-8111-111111111111",
          "thread_uuid": "22222222-2222-4222-8222-222222222222",
          "patient_context_ref": null,
          "topic": {"code": "discharge_planning", "label": "Discharge planning"},
          "unit": {"id": 85, "label": "5 East"},
          "pool": {"pool_uuid": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "label": "5 East care team"},
          "status": "open", "ownership_state": "assigned", "assigned_to_me": false,
          "work_item_version": 5, "thread_version": 9,
          "last_message_at": "2026-07-20T12:00:00Z",
          "due_at": "2026-07-20T13:00:00Z", "escalate_at": "2026-07-20T14:00:00Z",
          "is_response_due": false, "is_escalation_due": false, "closed_at": null
        },
        "message": null,
        "event_uuid": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
        "replayed": false
      },
      "meta": {"as_of": "2026-07-20T12:00:00Z", "stale": false, "version": null,
        "classification": "patient_communication_restricted", "offline_writes_allowed": false},
      "links": {}
    }
    """
}

@MainActor
final class PatientCommunicationRoutingViewModelTests: XCTestCase {
    func testCapabilityFalseDoesNotDiscoverOrMutateRouting() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        let viewModel = PatientCommunicationsViewModel(repository: repository)

        await viewModel.loadRouteCandidates(for: repository.item, canRespond: false, bearer: "token")
        let succeeded = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reason: repository.candidates.reasonOptions.reassign[0],
            canRespond: false,
            bearer: "token"
        )

        XCTAssertEqual(repository.candidateLoads, 0)
        XCTAssertTrue(repository.routingCalls.isEmpty)
        XCTAssertFalse(succeeded)
        XCTAssertNil(viewModel.routeCandidates)
    }

    func testCandidateVersionMismatchFailsClosed() async throws {
        let repository = PatientCommunicationRoutingFakeRepository(candidateWorkItemVersion: 5)
        let viewModel = PatientCommunicationsViewModel(repository: repository)

        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.routingErrorMessage?.contains("changed") == true)
    }

    func testTargetMustComeFromCurrentCandidateResponse() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let succeeded = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: "99999999-9999-4999-8999-999999999999",
            reason: repository.candidates.reasonOptions.reassign[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertTrue(repository.routingCalls.isEmpty)
    }

    func testConflictReconcilesReadsAndNeverRetriesMutation() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Changed", statusCode: 409)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let succeeded = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reason: repository.candidates.reasonOptions.reassign[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertEqual(repository.routingCalls.count, 1)
        XCTAssertEqual(repository.threadLoads, 1)
        XCTAssertEqual(repository.inboxLoads, 1)
        XCTAssertTrue(viewModel.actionMessage?.contains("changed since") == true)
    }

    func testFirstAttemptReroutePurgesDestinationProjectionAndInboxItem() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let succeeded = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertTrue(succeeded)
        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.actionMessage)
        XCTAssertEqual(
            viewModel.routingConfirmationMessage,
            "The reroute was confirmed. The conversation is now with the destination care team."
        )
        // Only the explicit pre-mutation inbox load occurred. Reroute success
        // cannot fetch or render a destination projection implicitly.
        XCTAssertEqual(repository.inboxLoads, 1)
    }

    func testPendingReplyLocksRoutingUntilExactOutcomeIsResolved() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.replyErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")

        let replied = await viewModel.reply(
            repository.item,
            message: "Sensitive patient-visible draft",
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(replied)
        XCTAssertTrue(viewModel.hasPendingReplyAttempt)
        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let rerouted = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(rerouted)
        XCTAssertTrue(viewModel.hasPendingReplyAttempt)
        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(repository.routingCalls.isEmpty)
    }

    func testLostCommittedReplyWithAdvancedProjectionReplaysOnlyExactTuple() async throws {
        let ids = [
            UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!,
            UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!,
        ]
        var iterator = ids.makeIterator()
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.replyErrors = [APIError(message: "Response lost after commit", statusCode: 503)]
        repository.commitReplyBeforeFirstError = true
        repository.committedReplyProjectionVersionDelta = 3
        let viewModel = PatientCommunicationsViewModel(
            repository: repository,
            makeUUID: { iterator.next()! }
        )
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        let body = "Patient-visible discharge answer"

        let first = await viewModel.reply(
            repository.item,
            message: body,
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(first)
        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
        XCTAssertEqual(viewModel.thread?.workItemVersion, repository.item.workItemVersion + 3)
        XCTAssertEqual(viewModel.thread?.threadVersion, repository.item.threadVersion + 3)
        XCTAssertEqual(repository.replyCalls.count, 1, "Reconciliation must never resend the write")

        let blockedFreshSend = await viewModel.reply(
            try XCTUnwrap(viewModel.thread),
            message: body,
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(blockedFreshSend)
        XCTAssertEqual(repository.replyCalls.count, 1)

        let replayed = await viewModel.retryPendingMutation(canRespond: true, bearer: "token")

        XCTAssertTrue(replayed)
        XCTAssertNil(viewModel.pendingMutationRetryAction)
        XCTAssertEqual(viewModel.actionMessage, "Your earlier reply was confirmed.")
        XCTAssertEqual(repository.replyCalls.count, 2)
        XCTAssertEqual(repository.replyCalls[0].workItemVersion, repository.replyCalls[1].workItemVersion)
        XCTAssertEqual(repository.replyCalls[0].threadVersion, repository.replyCalls[1].threadVersion)
        XCTAssertEqual(repository.replyCalls[0].body, repository.replyCalls[1].body)
        XCTAssertEqual(repository.replyCalls[0].clientMessageUUID, repository.replyCalls[1].clientMessageUUID)
        XCTAssertEqual(repository.replyCalls[0].idempotencyKey, repository.replyCalls[1].idempotencyKey)
    }

    func testClaimAndClose503RequireExplicitExactReplayKeys() async throws {
        let claimKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        let claimRepository = PatientCommunicationRoutingFakeRepository(
            ownershipState: "pool_owned",
            assignedToMe: false
        )
        claimRepository.claimErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let claimViewModel = PatientCommunicationsViewModel(
            repository: claimRepository,
            makeUUID: { claimKey }
        )

        await claimViewModel.claim(
            claimRepository.item,
            canRespond: true,
            bearer: "token"
        )
        XCTAssertEqual(claimViewModel.pendingMutationRetryAction, .claim)
        XCTAssertEqual(claimRepository.claimCalls.count, 1)

        let claimRetried = await claimViewModel.retryPendingMutation(canRespond: true, bearer: "token")
        XCTAssertTrue(claimRetried)
        XCTAssertEqual(claimRepository.claimCalls.count, 2)
        XCTAssertEqual(claimRepository.claimCalls[0].idempotencyKey, claimRepository.claimCalls[1].idempotencyKey)
        XCTAssertEqual(claimRepository.claimCalls[0].workItemVersion, claimRepository.claimCalls[1].workItemVersion)
        XCTAssertEqual(claimRepository.claimCalls[0].threadVersion, claimRepository.claimCalls[1].threadVersion)

        let closeKey = UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!
        let closeRepository = PatientCommunicationRoutingFakeRepository(
            ownershipState: "responded",
            assignedToMe: true
        )
        closeRepository.closeErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let closeViewModel = PatientCommunicationsViewModel(
            repository: closeRepository,
            makeUUID: { closeKey }
        )

        await closeViewModel.close(
            closeRepository.item,
            reason: .questionAnswered,
            canRespond: true,
            bearer: "token"
        )
        XCTAssertEqual(closeViewModel.pendingMutationRetryAction, .close)
        XCTAssertEqual(closeRepository.closeCalls.count, 1)

        let closeRetried = await closeViewModel.retryPendingMutation(canRespond: true, bearer: "token")
        XCTAssertTrue(closeRetried)
        XCTAssertEqual(closeRepository.closeCalls.count, 2)
        XCTAssertEqual(closeRepository.closeCalls[0].idempotencyKey, closeRepository.closeCalls[1].idempotencyKey)
        XCTAssertEqual(closeRepository.closeCalls[0].workItemVersion, closeRepository.closeCalls[1].workItemVersion)
        XCTAssertEqual(closeRepository.closeCalls[0].threadVersion, closeRepository.closeCalls[1].threadVersion)
        XCTAssertEqual(closeRepository.closeCalls[0].reason, closeRepository.closeCalls[1].reason)
    }

    func testRepeatedReply503KeepsSamePendingTupleUntilAConfirmedReplay() async throws {
        let ids = [
            UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!,
            UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!,
        ]
        var iterator = ids.makeIterator()
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.replyErrors = [
            APIError(message: "Unavailable", statusCode: 503),
            APIError(message: "Still unavailable", statusCode: 503),
        ]
        let viewModel = PatientCommunicationsViewModel(
            repository: repository,
            makeUUID: { iterator.next()! }
        )

        _ = await viewModel.reply(
            repository.item,
            message: "Patient-visible answer",
            canRespond: true,
            bearer: "token"
        )
        let second = await viewModel.retryPendingMutation(canRespond: true, bearer: "token")

        XCTAssertFalse(second)
        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
        XCTAssertEqual(repository.replyCalls.count, 2)
        XCTAssertEqual(repository.replyCalls[0].clientMessageUUID, repository.replyCalls[1].clientMessageUUID)
        XCTAssertEqual(repository.replyCalls[0].idempotencyKey, repository.replyCalls[1].idempotencyKey)

        let third = await viewModel.retryPendingMutation(canRespond: true, bearer: "token")

        XCTAssertTrue(third)
        XCTAssertNil(viewModel.pendingMutationRetryAction)
        XCTAssertEqual(repository.replyCalls.count, 3)
        for call in repository.replyCalls.dropFirst() {
            XCTAssertEqual(call.workItemVersion, repository.replyCalls[0].workItemVersion)
            XCTAssertEqual(call.threadVersion, repository.replyCalls[0].threadVersion)
            XCTAssertEqual(call.body, repository.replyCalls[0].body)
            XCTAssertEqual(call.clientMessageUUID, repository.replyCalls[0].clientMessageUUID)
            XCTAssertEqual(call.idempotencyKey, repository.replyCalls[0].idempotencyKey)
        }
    }

    func testDraftEditNotificationCannotEraseInFlightReplyTupleBeforeAmbiguousResponse() async throws {
        let identifiers = [
            UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!,
            UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!,
        ]
        var identifierIterator = identifiers.makeIterator()
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.pauseNextReplyRequest = true
        repository.replyErrors = [APIError(message: "Response lost", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(
            repository: repository,
            makeUUID: { identifierIterator.next()! }
        )
        let originalBody = "The exact patient-visible response"

        let firstRequest = Task {
            await viewModel.reply(
                repository.item,
                message: originalBody,
                canRespond: true,
                bearer: "token"
            )
        }
        for _ in 0..<20 where !repository.hasPausedReplyRequest { await Task.yield() }
        XCTAssertTrue(repository.hasPausedReplyRequest)
        XCTAssertTrue(viewModel.isWorking)
        XCTAssertTrue(viewModel.hasPendingReplyAttempt)

        // Mirrors TextEditor's onChange callback if a stale UI event is
        // delivered while the initial POST is still awaiting its response.
        viewModel.discardReplyAttempt()

        XCTAssertTrue(viewModel.hasPendingReplyAttempt)
        repository.resumePausedReplyRequest()
        let firstSucceeded = await firstRequest.value
        XCTAssertFalse(firstSucceeded)
        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)

        let replayed = await viewModel.retryPendingMutation(
            canRespond: true,
            bearer: "token"
        )

        XCTAssertTrue(replayed)
        XCTAssertEqual(repository.replyCalls.count, 2)
        XCTAssertEqual(repository.replyCalls[0].body, originalBody)
        XCTAssertEqual(repository.replyCalls[0].body, repository.replyCalls[1].body)
        XCTAssertEqual(
            repository.replyCalls[0].clientMessageUUID,
            repository.replyCalls[1].clientMessageUUID
        )
        XCTAssertEqual(
            repository.replyCalls[0].idempotencyKey,
            repository.replyCalls[1].idempotencyKey
        )
        XCTAssertEqual(
            repository.replyCalls[0].workItemVersion,
            repository.replyCalls[1].workItemVersion
        )
        XCTAssertEqual(
            repository.replyCalls[0].threadVersion,
            repository.replyCalls[1].threadVersion
        )
    }

    func testCommittedCloseIsConfirmedByAdvancedClosedRefreshBeforeInboxOmission() async throws {
        let repository = PatientCommunicationRoutingFakeRepository(
            ownershipState: "responded",
            assignedToMe: true
        )
        repository.closeErrors = [APIError(message: "Response lost after commit", statusCode: 503)]
        repository.commitCloseBeforeFirstError = true
        let viewModel = PatientCommunicationsViewModel(repository: repository)

        await viewModel.close(
            repository.item,
            reason: .questionAnswered,
            canRespond: true,
            bearer: "token"
        )

        XCTAssertNil(viewModel.pendingMutationRetryAction)
        XCTAssertEqual(viewModel.thread?.status, "closed")
        XCTAssertEqual(
            viewModel.actionMessage,
            "Your earlier closure was confirmed by the refreshed conversation."
        )
        XCTAssertEqual(repository.closeCalls.count, 1)
        let retried = await viewModel.retryPendingMutation(canRespond: true, bearer: "token")
        XCTAssertFalse(retried)
    }

    func testGenericPendingReplyIsPurgedByInboxAuthorizationAndOmissionBoundaries() async throws {
        for statusCode in [401, 403, 404] {
            let repository = PatientCommunicationRoutingFakeRepository()
            repository.replyErrors = [APIError(message: "Unavailable", statusCode: 503)]
            let viewModel = PatientCommunicationsViewModel(repository: repository)
            _ = await viewModel.reply(
                repository.item,
                message: "Sensitive patient-visible answer",
                canRespond: true,
                bearer: "token"
            )
            XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
            repository.inboxErrors = [APIError(message: "Denied", statusCode: statusCode)]

            await viewModel.loadInbox(bearer: "token")

            XCTAssertNil(viewModel.pendingMutationRetryAction, "status \(statusCode)")
            let retried = await viewModel.retryPendingMutation(canRespond: true, bearer: "token")
            XCTAssertFalse(retried, "status \(statusCode)")
            XCTAssertEqual(repository.replyCalls.count, 1, "status \(statusCode)")
        }

        let omissionRepository = PatientCommunicationRoutingFakeRepository()
        omissionRepository.replyErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let omissionViewModel = PatientCommunicationsViewModel(repository: omissionRepository)
        _ = await omissionViewModel.reply(
            omissionRepository.item,
            message: "Sensitive patient-visible answer",
            canRespond: true,
            bearer: "token"
        )
        omissionRepository.inboxItemsOverride = []

        await omissionViewModel.loadInbox(bearer: "token")

        XCTAssertNil(omissionViewModel.pendingMutationRetryAction)
        let omissionRetried = await omissionViewModel.retryPendingMutation(canRespond: true, bearer: "token")
        XCTAssertFalse(omissionRetried)
        XCTAssertEqual(omissionRepository.replyCalls.count, 1)
    }

    func testRetainedInboxTransitionPurgesStaleDetailAndRefetchesExactlyOnce() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(
            for: repository.item,
            canRespond: true,
            bearer: "token"
        )
        let initialPurgeGeneration = viewModel.sensitiveContentPurgeGeneration
        let transitioned = repository.simulateServerTransition(
            ownershipState: "pool_owned",
            assignedToMe: false,
            poolUUID: "99999999-9999-4999-8999-999999999999",
            poolLabel: "6 West care team",
            unitID: 86,
            unitLabel: "6 West"
        )

        await viewModel.loadInbox(bearer: "token")

        XCTAssertEqual(repository.inboxLoads, 2)
        XCTAssertEqual(repository.threadLoads, 2, "Retained row drift must issue one authorized detail refresh")
        XCTAssertEqual(viewModel.thread?.workItemVersion, transitioned.workItemVersion)
        XCTAssertEqual(viewModel.thread?.threadVersion, transitioned.threadVersion)
        XCTAssertEqual(viewModel.thread?.pool.poolUuid, transitioned.pool.poolUuid)
        XCTAssertEqual(viewModel.thread?.unit?.id, 86)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertEqual(viewModel.sensitiveContentPurgeGeneration, initialPurgeGeneration + 1)
        XCTAssertTrue(repository.claimCalls.isEmpty)
        XCTAssertTrue(repository.replyCalls.isEmpty)
        XCTAssertTrue(repository.closeCalls.isEmpty)
        XCTAssertTrue(repository.routingCalls.isEmpty)
    }

    func testRetainedInboxTransitionPreservesAmbiguousExactReplyTuple() async throws {
        let identifiers = [
            UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!,
            UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!,
        ]
        var identifierIterator = identifiers.makeIterator()
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.replyErrors = [APIError(message: "Response unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(
            repository: repository,
            makeUUID: { identifierIterator.next()! }
        )
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        let body = "The exact patient-visible answer"

        let firstSucceeded = await viewModel.reply(
            repository.item,
            message: body,
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(firstSucceeded)
        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
        repository.simulateServerTransition(
            ownershipState: "pool_owned",
            assignedToMe: false,
            poolUUID: "99999999-9999-4999-8999-999999999999",
            poolLabel: "6 West care team",
            unitID: 86,
            unitLabel: "6 West"
        )

        await viewModel.loadInbox(bearer: "token")
        // Mirrors the programmatic draft clear triggered by the privacy-purge
        // generation in the detail view. It must not erase an exact retry.
        viewModel.discardReplyAttempt()

        XCTAssertEqual(viewModel.pendingMutationRetryAction, .reply)
        let replayed = await viewModel.retryPendingMutation(canRespond: true, bearer: "token")
        XCTAssertTrue(replayed)
        XCTAssertEqual(repository.replyCalls.count, 2)
        XCTAssertEqual(repository.replyCalls[0].body, repository.replyCalls[1].body)
        XCTAssertEqual(repository.replyCalls[0].clientMessageUUID, repository.replyCalls[1].clientMessageUUID)
        XCTAssertEqual(repository.replyCalls[0].idempotencyKey, repository.replyCalls[1].idempotencyKey)
        XCTAssertEqual(repository.replyCalls[0].workItemVersion, repository.replyCalls[1].workItemVersion)
        XCTAssertEqual(repository.replyCalls[0].threadVersion, repository.replyCalls[1].threadVersion)
    }

    func testRetainedInboxTransitionFencesOlderInFlightDetailResponse() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        repository.pauseNextThreadLoad = true
        let staleLoad = Task {
            await viewModel.loadThread(
                workItemUUID: repository.item.workItemUuid,
                bearer: "token"
            )
        }
        for _ in 0..<20 where !repository.hasPausedThreadLoad { await Task.yield() }
        XCTAssertTrue(repository.hasPausedThreadLoad)
        let transitioned = repository.simulateServerTransition(
            ownershipState: "pool_owned",
            assignedToMe: false,
            poolUUID: "99999999-9999-4999-8999-999999999999",
            poolLabel: "6 West care team",
            unitID: 86,
            unitLabel: "6 West"
        )

        await viewModel.loadInbox(bearer: "token")
        XCTAssertEqual(viewModel.thread?.workItemVersion, transitioned.workItemVersion)
        repository.resumePausedThreadLoad()
        await staleLoad.value

        XCTAssertEqual(repository.threadLoads, 3)
        XCTAssertEqual(viewModel.thread?.workItemVersion, transitioned.workItemVersion)
        XCTAssertEqual(viewModel.thread?.pool.poolUuid, transitioned.pool.poolUuid)
        XCTAssertEqual(viewModel.thread?.unit?.id, 86)
    }

    func testMalformedRerouteProjectionIsAmbiguousAndPurgesSourceState() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingResponseVersionDelta = 0
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let succeeded = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertNil(viewModel.routingConfirmationMessage)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertTrue(viewModel.actionMessage?.contains("Patient content was hidden") == true)
    }

    func testRerouteProjectionForDifferentPoolIsAmbiguousAndPurgesSourceState() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingResponsePoolUUIDOverride = "99999999-9999-4999-8999-999999999999"
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let succeeded = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertNil(viewModel.routingConfirmationMessage)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertEqual(repository.routingCalls.count, 1)
    }

    func testExplicitRetryAfterAmbiguousFailureReusesExactIdempotencyKey() async throws {
        let fixedKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Offline", statusCode: nil)]
        let viewModel = PatientCommunicationsViewModel(repository: repository, makeUUID: { fixedKey })
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        let reason = repository.candidates.reasonOptions.reassign[0]

        let first = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reason: reason,
            canRespond: true,
            bearer: "token"
        )
        let second = await viewModel.retryPendingRouting(
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(first)
        XCTAssertTrue(second)
        XCTAssertEqual(repository.routingCalls.count, 2)
        XCTAssertEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
        XCTAssertEqual(repository.routingCalls[0].workItemVersion, repository.routingCalls[1].workItemVersion)
        XCTAssertEqual(repository.routingCalls[0].threadVersion, repository.routingCalls[1].threadVersion)
        XCTAssertEqual(repository.routingCalls[0].targetUUID, repository.routingCalls[1].targetUUID)
        XCTAssertEqual(repository.routingCalls[0].reasonCode, repository.routingCalls[1].reasonCode)
    }

    func testLostResponseAfterCommittedRerouteCanReplayExactRequestWhileReadsAreTemporarilyUnavailable() async throws {
        let fixedKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Connection dropped after commit", statusCode: nil)]
        repository.threadErrors = [APIError(message: "Temporarily unavailable", statusCode: 503)]
        repository.contentMinimizedRerouteReplay = true
        let viewModel = PatientCommunicationsViewModel(repository: repository, makeUUID: { fixedKey })
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let first = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(first)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        XCTAssertEqual(repository.routingCalls.count, 1)

        // Even if a later source inbox response still contains the row, the
        // possible-reroute quarantine must not republish it.
        await viewModel.loadInbox(bearer: "token")

        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)

        // A 503 is not a known authorization denial. The exact replay remains
        // available, while a later 403/404 regression below proves it is purged.
        viewModel.handleUnavailableThreadRefresh()
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        XCTAssertNil(viewModel.routeCandidates)

        let replayed = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")

        XCTAssertTrue(replayed)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertEqual(
            viewModel.routingConfirmationMessage,
            "Your earlier reroute was confirmed. The conversation is now with the destination care team."
        )
        XCTAssertEqual(repository.routingCalls.count, 2)
        XCTAssertEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
        XCTAssertEqual(repository.routingCalls[0].workItemVersion, repository.routingCalls[1].workItemVersion)
        XCTAssertEqual(repository.routingCalls[0].threadVersion, repository.routingCalls[1].threadVersion)
        XCTAssertEqual(repository.routingCalls[0].targetUUID, repository.routingCalls[1].targetUUID)
        XCTAssertEqual(repository.routingCalls[0].reasonCode, repository.routingCalls[1].reasonCode)
    }

    func testInbox200OmissionPurgesOrdinaryDetailAndNonRerouteRetryIdentity() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        _ = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reason: repository.candidates.reasonOptions.reassign[0],
            canRespond: true,
            bearer: "token"
        )
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reassign)
        repository.inboxItemsOverride = []

        await viewModel.loadInbox(bearer: "token")

        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        let retried = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")
        XCTAssertFalse(retried)
        XCTAssertEqual(repository.routingCalls.count, 1)
    }

    func testInFlightRerouteOmissionBecomesExplicitExactMinimizedReplay() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.pauseNextRerouteRequest = true
        repository.contentMinimizedRerouteReplay = true
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        let firstRequest = Task {
            await viewModel.route(
                .reroute,
                item: repository.item,
                targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
                reason: repository.candidates.reasonOptions.reroute[0],
                canRespond: true,
                bearer: "token"
            )
        }
        for _ in 0..<20 where !repository.hasPausedRerouteRequest {
            await Task.yield()
        }
        XCTAssertTrue(repository.hasPausedRerouteRequest)
        repository.inboxItemsOverride = []

        await viewModel.loadInbox(bearer: "token")

        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        repository.resumePausedRerouteRequest()
        let firstSucceeded = await firstRequest.value
        XCTAssertFalse(firstSucceeded)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)

        let replayed = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")

        XCTAssertTrue(replayed)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertEqual(repository.routingCalls.count, 2)
        XCTAssertEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
        XCTAssertEqual(repository.routingCalls[0].workItemVersion, repository.routingCalls[1].workItemVersion)
        XCTAssertEqual(repository.routingCalls[0].threadVersion, repository.routingCalls[1].threadVersion)
        XCTAssertEqual(repository.routingCalls[0].targetUUID, repository.routingCalls[1].targetUUID)
        XCTAssertEqual(repository.routingCalls[0].reasonCode, repository.routingCalls[1].reasonCode)
        XCTAssertEqual(
            viewModel.routingConfirmationMessage,
            "Your earlier reroute was confirmed. The conversation is now with the destination care team."
        )
    }

    func testMalformedFreshRerouteMinimizedShapeBecomesExactRetryOnly() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.nilWorkItemForNextMutation = true
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let succeeded = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        XCTAssertNil(viewModel.routingConfirmationMessage)
        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.inbox.isEmpty)

        let retried = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")

        XCTAssertTrue(retried)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        XCTAssertEqual(repository.routingCalls.count, 2)
        XCTAssertEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
    }

    func testGenericRoutingReplayAcceptsAdvancedCurrentProjectionWithExactTuple() async throws {
        let fixedKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
        repository.replayGenericRoutingOnRetry = true
        repository.genericRoutingReplayVersionDelta = 4
        let viewModel = PatientCommunicationsViewModel(repository: repository, makeUUID: { fixedKey })
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let first = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reason: repository.candidates.reasonOptions.reassign[0],
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(first)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reassign)

        let retried = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")

        XCTAssertTrue(retried)
        XCTAssertEqual(viewModel.thread?.workItemVersion, repository.item.workItemVersion + 4)
        XCTAssertEqual(viewModel.thread?.threadVersion, repository.item.threadVersion + 4)
        XCTAssertEqual(viewModel.actionMessage, "Your earlier reassignment was confirmed.")
        XCTAssertEqual(repository.routingCalls.count, 2)
        XCTAssertEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
        XCTAssertEqual(repository.routingCalls[0].workItemVersion, repository.routingCalls[1].workItemVersion)
        XCTAssertEqual(repository.routingCalls[0].threadVersion, repository.routingCalls[1].threadVersion)
    }

    func testLateInboxSuccessCannotRepublishAfterAmbiguousReroutePurgeFence() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.pauseNextInboxLoad = true
        repository.routingErrors = [APIError(message: "Response lost", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        let staleInbox = Task { await viewModel.loadInbox(bearer: "token") }
        for _ in 0..<20 where !repository.hasPausedInboxLoad { await Task.yield() }
        XCTAssertTrue(repository.hasPausedInboxLoad)

        let routed = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(routed)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.inbox.isEmpty)

        repository.resumePausedInboxLoad(with: [repository.item])
        await staleInbox.value

        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
    }

    func testInbox401PurgesOpenDetailAndExactRetryIdentityAndSignalsReauth() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        _ = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        let purgeGeneration = viewModel.sensitiveContentPurgeGeneration

        repository.inboxErrors = [APIError(message: "Expired", statusCode: 401)]
        await viewModel.loadInbox(bearer: "token")

        XCTAssertTrue(viewModel.needsReauth)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        XCTAssertGreaterThan(viewModel.sensitiveContentPurgeGeneration, purgeGeneration)
        let retried = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")
        XCTAssertFalse(retried)
        XCTAssertEqual(repository.routingCalls.count, 1)
    }

    func testInbox403And404PurgeOpenDetailAndExactRetryIdentityWithoutReauth() async throws {
        for statusCode in [403, 404] {
            let repository = PatientCommunicationRoutingFakeRepository()
            repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
            let viewModel = PatientCommunicationsViewModel(repository: repository)
            await viewModel.loadInbox(bearer: "token")
            await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
            await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
            _ = await viewModel.route(
                .reroute,
                item: repository.item,
                targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
                reason: repository.candidates.reasonOptions.reroute[0],
                canRespond: true,
                bearer: "token"
            )
            XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
            let purgeGeneration = viewModel.sensitiveContentPurgeGeneration
            repository.inboxErrors = [APIError(message: "Denied", statusCode: statusCode)]

            await viewModel.loadInbox(bearer: "token")

            XCTAssertFalse(viewModel.needsReauth, "status \(statusCode)")
            XCTAssertTrue(viewModel.inbox.isEmpty, "status \(statusCode)")
            XCTAssertNil(viewModel.thread, "status \(statusCode)")
            XCTAssertNil(viewModel.routeCandidates, "status \(statusCode)")
            XCTAssertTrue(viewModel.inboxUnavailable, "status \(statusCode)")
            XCTAssertTrue(viewModel.threadUnavailable, "status \(statusCode)")
            XCTAssertNil(viewModel.pendingRoutingRetryAction, "status \(statusCode)")
            XCTAssertGreaterThan(
                viewModel.sensitiveContentPurgeGeneration,
                purgeGeneration,
                "status \(statusCode)"
            )
            let retried = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")
            XCTAssertFalse(retried, "status \(statusCode)")
            XCTAssertEqual(repository.routingCalls.count, 1, "status \(statusCode)")
        }
    }

    func testLateThreadSuccessCannotRepublishAfterInboxAuthorizationPurge() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.pauseNextThreadLoad = true
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        let staleThreadLoad = Task {
            await viewModel.loadThread(
                workItemUUID: repository.item.workItemUuid,
                bearer: "token"
            )
        }
        for _ in 0..<20 where !repository.hasPausedThreadLoad {
            await Task.yield()
        }
        XCTAssertTrue(repository.hasPausedThreadLoad)
        repository.inboxErrors = [APIError(message: "Denied", statusCode: 403)]

        await viewModel.loadInbox(bearer: "token")
        repository.resumePausedThreadLoad()
        await staleThreadLoad.value

        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertFalse(viewModel.needsReauth)
    }

    func testCandidate401PurgesAllSensitiveStateAndSignalsReauth() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.candidateErrors = [APIError(message: "Expired", statusCode: 401)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")

        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        XCTAssertTrue(viewModel.needsReauth)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertGreaterThan(viewModel.sensitiveContentPurgeGeneration, 0)
    }

    func testCandidate404PurgesAffectedThreadAndPendingCommandWithoutReauth() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        _ = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reroute)
        repository.candidateErrors = [APIError(message: "Not found", statusCode: 404)]

        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        XCTAssertFalse(viewModel.needsReauth)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        let retried = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")
        XCTAssertFalse(retried)
    }

    func testThread403PurgesAffectedThreadAndPendingCommand() async throws {
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        _ = await viewModel.route(
            .reroute,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.poolUUID,
            reason: repository.candidates.reasonOptions.reroute[0],
            canRespond: true,
            bearer: "token"
        )
        repository.threadErrors = [APIError(message: "Forbidden", statusCode: 403)]

        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")

        XCTAssertNil(viewModel.thread)
        XCTAssertTrue(viewModel.threadUnavailable)
        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.pendingRoutingRetryAction)
        XCTAssertFalse(viewModel.needsReauth)
    }

    func testMutation503RetainsExactReplayTupleUntilExplicitRetry() async throws {
        let fixedKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Unavailable", statusCode: 503)]
        let viewModel = PatientCommunicationsViewModel(repository: repository, makeUUID: { fixedKey })
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")

        let first = await viewModel.route(
            .reassign,
            item: repository.item,
            targetUUID: PatientCommunicationRoutingContractTests.membershipUUID,
            reason: repository.candidates.reasonOptions.reassign[0],
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(first)
        XCTAssertEqual(viewModel.pendingRoutingRetryAction, .reassign)

        let replayed = await viewModel.retryPendingRouting(canRespond: true, bearer: "token")

        XCTAssertTrue(replayed)
        XCTAssertEqual(repository.routingCalls.count, 2)
        XCTAssertEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
        XCTAssertEqual(repository.routingCalls[0].workItemVersion, repository.routingCalls[1].workItemVersion)
        XCTAssertEqual(repository.routingCalls[0].threadVersion, repository.routingCalls[1].threadVersion)
        XCTAssertEqual(repository.routingCalls[0].targetUUID, repository.routingCalls[1].targetUUID)
        XCTAssertEqual(repository.routingCalls[0].reasonCode, repository.routingCalls[1].reasonCode)
    }

    func testSuspendPurgesCandidatesAndAmbiguousReplayIdentity() async throws {
        let ids = [UUID(), UUID()]
        var iterator = ids.makeIterator()
        let repository = PatientCommunicationRoutingFakeRepository()
        repository.routingErrors = [APIError(message: "Offline", statusCode: nil)]
        let viewModel = PatientCommunicationsViewModel(repository: repository, makeUUID: { iterator.next()! })
        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        let reason = repository.candidates.reasonOptions.release[0]
        _ = await viewModel.route(
            .release,
            item: repository.item,
            targetUUID: nil,
            reason: reason,
            canRespond: true,
            bearer: "token"
        )

        viewModel.suspend()
        XCTAssertNil(viewModel.routeCandidates)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.routingErrorMessage)

        await viewModel.loadRouteCandidates(for: repository.item, canRespond: true, bearer: "token")
        _ = await viewModel.route(
            .release,
            item: repository.item,
            targetUUID: nil,
            reason: reason,
            canRespond: true,
            bearer: "token"
        )
        XCTAssertNotEqual(repository.routingCalls[0].idempotencyKey, repository.routingCalls[1].idempotencyKey)
    }
}

private final class PatientCommunicationRoutingURLProtocol: URLProtocol {
    static var handler: ((URLRequest) throws -> (Int, Data))?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
    override func startLoading() {
        do {
            let handler = try XCTUnwrap(Self.handler)
            let (status, data) = try handler(request)
            let response = HTTPURLResponse(
                url: try XCTUnwrap(request.url),
                statusCode: status,
                httpVersion: "HTTP/1.1",
                headerFields: ["Content-Type": "application/json"]
            )!
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }
    override func stopLoading() {}
}

@MainActor
private final class PatientCommunicationRoutingFakeRepository: PatientCommunicationsRepository {
    struct ClaimCall {
        let workItemVersion: Int
        let threadVersion: Int
        let idempotencyKey: UUID
    }

    struct ReplyCall {
        let workItemVersion: Int
        let threadVersion: Int
        let body: String
        let clientMessageUUID: UUID
        let idempotencyKey: UUID
    }

    struct CloseCall {
        let workItemVersion: Int
        let threadVersion: Int
        let reason: PatientCommunicationCloseReason
        let idempotencyKey: UUID
    }

    struct RoutingCall {
        let action: PatientCommunicationRoutingAction
        let workItemVersion: Int
        let threadVersion: Int
        let targetUUID: String?
        let reasonCode: String
        let idempotencyKey: UUID
    }

    let item: PatientCommunicationWorkItem
    let candidates: PatientCommunicationRouteCandidatesData
    var candidateLoads = 0
    var threadLoads = 0
    var inboxLoads = 0
    var routingCalls: [RoutingCall] = []
    var claimCalls: [ClaimCall] = []
    var replyCalls: [ReplyCall] = []
    var closeCalls: [CloseCall] = []
    var inboxErrors: [Error] = []
    var candidateErrors: [Error] = []
    var routingErrors: [Error] = []
    var threadErrors: [Error] = []
    var claimErrors: [Error] = []
    var replyErrors: [Error] = []
    var closeErrors: [Error] = []
    var commitReplyBeforeFirstError = false
    var commitCloseBeforeFirstError = false
    var committedReplyProjectionVersionDelta = 1
    var contentMinimizedRerouteReplay = false
    var replayGenericRoutingOnRetry = false
    var genericRoutingReplayVersionDelta = 3
    var nilWorkItemForNextMutation = false
    var routingResponseVersionDelta = 1
    var routingResponsePoolUUIDOverride: String?
    var inboxItemsOverride: [PatientCommunicationWorkItem]?
    var pauseNextThreadLoad = false
    var pauseNextInboxLoad = false
    var pauseNextReplyRequest = false
    var pauseNextRerouteRequest = false
    private var pausedInboxContinuation: CheckedContinuation<PatientCommunicationInboxData, Error>?
    private var currentItemOverride: PatientCommunicationWorkItem?
    private var committedReplyCall: ReplyCall?
    private var committedReplyMessage: PatientCommunicationMessage?
    private var pausedThreadContinuation: CheckedContinuation<PatientCommunicationWorkItem, Error>?
    private var pausedThreadResult: PatientCommunicationWorkItem?
    private var pausedReplyContinuation: CheckedContinuation<Void, Never>?
    private var pausedRerouteContinuation: CheckedContinuation<Void, Never>?

    var hasPausedThreadLoad: Bool { pausedThreadContinuation != nil }
    var hasPausedInboxLoad: Bool { pausedInboxContinuation != nil }
    var hasPausedReplyRequest: Bool { pausedReplyContinuation != nil }
    var hasPausedRerouteRequest: Bool { pausedRerouteContinuation != nil }

    init(
        candidateWorkItemVersion: Int = 4,
        ownershipState: String = "acknowledged",
        assignedToMe: Bool = true
    ) {
        item = PatientCommunicationWorkItem(
            workItemUuid: PatientCommunicationRoutingContractTests.workItemUUID,
            threadUuid: "22222222-2222-4222-8222-222222222222",
            patientContextRef: nil,
            topic: .init(code: "discharge_planning", label: "Discharge planning"),
            unit: .init(id: 85, label: "5 East"),
            pool: .init(label: "5 East care team"),
            status: "open", ownershipState: ownershipState, assignedToMe: assignedToMe,
            workItemVersion: 4, threadVersion: 8,
            lastMessageAt: "2026-07-20T12:00:00Z", dueAt: "2026-07-20T13:00:00Z",
            escalateAt: "2026-07-20T14:00:00Z", isResponseDue: false, isEscalationDue: false,
            closedAt: nil, messages: [], hasEarlierMessages: false
        )
        candidates = try! PatientCommunicationRoutingContractTests.decode(
            PatientCommunicationRoutingContractTests.validCandidatesJSON(
                workItemVersion: candidateWorkItemVersion
            )
        )
    }

    func patientCommunicationsInbox(bearer: String) async throws -> PatientCommunicationInboxData {
        inboxLoads += 1
        if !inboxErrors.isEmpty { throw inboxErrors.removeFirst() }
        let current = currentItemOverride ?? item
        let items = inboxItemsOverride ?? (current.isOpen ? [current] : [])
        if pauseNextInboxLoad {
            pauseNextInboxLoad = false
            return try await withCheckedThrowingContinuation { continuation in
                pausedInboxContinuation = continuation
            }
        }
        return .init(items: items, count: items.count)
    }

    func resumePausedInboxLoad(with items: [PatientCommunicationWorkItem]? = nil) {
        let continuation = pausedInboxContinuation
        pausedInboxContinuation = nil
        let resolved = items ?? [currentItemOverride ?? item]
        continuation?.resume(returning: .init(items: resolved, count: resolved.count))
    }

    @discardableResult
    func simulateServerTransition(
        ownershipState: String,
        assignedToMe: Bool,
        poolUUID: String?,
        poolLabel: String,
        unitID: Int,
        unitLabel: String,
        versionDelta: Int = 1
    ) -> PatientCommunicationWorkItem {
        let current = currentItemOverride ?? item
        let projection = PatientCommunicationWorkItem(
            workItemUuid: current.workItemUuid,
            threadUuid: current.threadUuid,
            patientContextRef: current.patientContextRef,
            topic: current.topic,
            unit: .init(id: unitID, label: unitLabel),
            pool: .init(poolUuid: poolUUID, label: poolLabel),
            status: "open",
            ownershipState: ownershipState,
            assignedToMe: assignedToMe,
            workItemVersion: current.workItemVersion + versionDelta,
            threadVersion: current.threadVersion + versionDelta,
            lastMessageAt: current.lastMessageAt,
            dueAt: "2026-07-20T13:20:00Z",
            escalateAt: "2026-07-20T14:20:00Z",
            isResponseDue: false,
            isEscalationDue: false,
            closedAt: nil,
            messages: current.messages,
            hasEarlierMessages: current.hasEarlierMessages
        )
        currentItemOverride = projection
        return projection
    }

    func patientCommunicationThread(workItemUUID: String, bearer: String) async throws -> PatientCommunicationWorkItem {
        threadLoads += 1
        if !threadErrors.isEmpty { throw threadErrors.removeFirst() }
        if pauseNextThreadLoad {
            pauseNextThreadLoad = false
            pausedThreadResult = currentItemOverride ?? item
            return try await withCheckedThrowingContinuation { continuation in
                pausedThreadContinuation = continuation
            }
        }
        return currentItemOverride ?? item
    }

    func resumePausedThreadLoad() {
        let continuation = pausedThreadContinuation
        pausedThreadContinuation = nil
        let result = pausedThreadResult ?? currentItemOverride ?? item
        pausedThreadResult = nil
        continuation?.resume(returning: result)
    }

    func patientCommunicationRouteCandidates(
        workItemUUID: String,
        bearer: String
    ) async throws -> PatientCommunicationRouteCandidatesData {
        candidateLoads += 1
        if !candidateErrors.isEmpty { throw candidateErrors.removeFirst() }
        return candidates
    }

    func claimPatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        idempotencyKey: UUID, bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let call = ClaimCall(
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            idempotencyKey: idempotencyKey
        )
        claimCalls.append(call)
        if !claimErrors.isEmpty { throw claimErrors.removeFirst() }
        let projection = genericProjection(
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            status: "open",
            ownershipState: "acknowledged",
            assignedToMe: true
        )
        currentItemOverride = projection
        return .init(
            workItem: projection,
            message: nil,
            eventUuid: "77777777-7777-4777-8777-777777777777",
            replayed: false
        )
    }

    func replyToPatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        message: String, clientMessageUUID: UUID, idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let call = ReplyCall(
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            body: message,
            clientMessageUUID: clientMessageUUID,
            idempotencyKey: idempotencyKey
        )
        replyCalls.append(call)
        if pauseNextReplyRequest {
            pauseNextReplyRequest = false
            await withCheckedContinuation { continuation in
                pausedReplyContinuation = continuation
            }
        }
        if let committedReplyCall, let committedReplyMessage {
            guard call.workItemVersion == committedReplyCall.workItemVersion,
                  call.threadVersion == committedReplyCall.threadVersion,
                  call.body == committedReplyCall.body,
                  call.clientMessageUUID == committedReplyCall.clientMessageUUID,
                  call.idempotencyKey == committedReplyCall.idempotencyKey,
                  let projection = currentItemOverride else {
                throw APIError(message: "Exact reply tuple changed.", statusCode: 409)
            }
            return .init(
                workItem: projection,
                message: committedReplyMessage,
                eventUuid: "77777777-7777-4777-8777-777777777777",
                replayed: true
            )
        }

        let reply = PatientCommunicationMessage(
            messageUuid: "88888888-8888-4888-8888-888888888888",
            senderDisplayRole: "Care team",
            visibility: "patient_visible",
            messageKind: "message",
            body: message,
            deliveryState: "delivered",
            sentAt: "2026-07-20T12:01:00Z"
        )
        let projection = genericProjection(
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            status: "open",
            ownershipState: "responded",
            assignedToMe: true,
            messages: [reply],
            versionDelta: commitReplyBeforeFirstError ? committedReplyProjectionVersionDelta : 1
        )
        if !replyErrors.isEmpty {
            let error = replyErrors.removeFirst()
            if commitReplyBeforeFirstError {
                currentItemOverride = projection
                committedReplyCall = call
                committedReplyMessage = reply
            }
            throw error
        }
        currentItemOverride = projection
        return .init(
            workItem: projection,
            message: reply,
            eventUuid: "77777777-7777-4777-8777-777777777777",
            replayed: false
        )
    }

    func resumePausedReplyRequest() {
        let continuation = pausedReplyContinuation
        pausedReplyContinuation = nil
        continuation?.resume()
    }

    func closePatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        reasonCode: PatientCommunicationCloseReason, idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        let call = CloseCall(
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            reason: reasonCode,
            idempotencyKey: idempotencyKey
        )
        closeCalls.append(call)
        let projection = genericProjection(
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            status: "closed",
            ownershipState: "closed",
            assignedToMe: true
        )
        if !closeErrors.isEmpty {
            let error = closeErrors.removeFirst()
            if commitCloseBeforeFirstError { currentItemOverride = projection }
            throw error
        }
        currentItemOverride = projection
        return .init(
            workItem: projection,
            message: nil,
            eventUuid: "77777777-7777-4777-8777-777777777777",
            replayed: false
        )
    }

    func releasePatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        reasonCode: String, idempotencyKey: UUID, bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try perform(
            .release,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetUUID: nil,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey
        )
    }

    func reassignPatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        targetMembershipUUID: String, reasonCode: String, idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        try perform(
            .reassign,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetUUID: targetMembershipUUID,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey
        )
    }

    func reroutePatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        targetPoolUUID: String, reasonCode: String, idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        if pauseNextRerouteRequest {
            pauseNextRerouteRequest = false
            await withCheckedContinuation { continuation in
                pausedRerouteContinuation = continuation
            }
        }
        return try perform(
            .reroute,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetUUID: targetPoolUUID,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey
        )
    }

    func resumePausedRerouteRequest() {
        let continuation = pausedRerouteContinuation
        pausedRerouteContinuation = nil
        continuation?.resume()
    }

    private func perform(
        _ action: PatientCommunicationRoutingAction,
        workItemVersion: Int,
        threadVersion: Int,
        targetUUID: String?,
        reasonCode: String,
        idempotencyKey: UUID
    ) throws -> PatientCommunicationMutationData {
        routingCalls.append(.init(
            action: action,
            workItemVersion: workItemVersion,
            threadVersion: threadVersion,
            targetUUID: targetUUID,
            reasonCode: reasonCode,
            idempotencyKey: idempotencyKey
        ))
        if !routingErrors.isEmpty { throw routingErrors.removeFirst() }
        if nilWorkItemForNextMutation {
            nilWorkItemForNextMutation = false
            return .init(
                workItem: nil,
                message: nil,
                eventUuid: "66666666-6666-4666-8666-666666666666",
                replayed: true
            )
        }
        if contentMinimizedRerouteReplay, action == .reroute, routingCalls.count > 1 {
            return .init(
                workItem: nil,
                message: nil,
                eventUuid: "66666666-6666-4666-8666-666666666666",
                replayed: true
            )
        }
        let isGenericReplay = replayGenericRoutingOnRetry
            && action != .reroute
            && routingCalls.count > 1
        let responseVersionDelta = isGenericReplay
            ? genericRoutingReplayVersionDelta
            : routingResponseVersionDelta
        let projection = PatientCommunicationWorkItem(
            workItemUuid: item.workItemUuid,
            threadUuid: item.threadUuid,
            patientContextRef: item.patientContextRef,
            topic: item.topic,
            unit: item.unit,
            pool: action == .reroute
                ? .init(
                    poolUuid: routingResponsePoolUUIDOverride ?? targetUUID,
                    label: "Destination care team"
                )
                : item.pool,
            status: item.status,
            ownershipState: isGenericReplay
                ? "responded"
                : (action == .reroute ? "rerouted" : (action == .release ? "pool_owned" : "assigned")),
            assignedToMe: isGenericReplay,
            workItemVersion: workItemVersion + responseVersionDelta,
            threadVersion: threadVersion + responseVersionDelta,
            lastMessageAt: item.lastMessageAt,
            dueAt: item.dueAt,
            escalateAt: item.escalateAt,
            isResponseDue: item.isResponseDue,
            isEscalationDue: item.isEscalationDue,
            closedAt: item.closedAt,
            messages: item.messages,
            hasEarlierMessages: item.hasEarlierMessages
        )
        // The server mutation response and subsequent authoritative reads must
        // expose the same committed projection. Retained-row drift detection
        // intentionally rejects the older pre-mutation fake row.
        currentItemOverride = projection
        return .init(
            workItem: projection,
            message: nil,
            eventUuid: UUID().uuidString.lowercased(),
            replayed: isGenericReplay
        )
    }

    private func genericProjection(
        workItemVersion: Int,
        threadVersion: Int,
        status: String,
        ownershipState: String,
        assignedToMe: Bool,
        messages: [PatientCommunicationMessage]? = nil,
        versionDelta: Int = 1
    ) -> PatientCommunicationWorkItem {
        PatientCommunicationWorkItem(
            workItemUuid: item.workItemUuid,
            threadUuid: item.threadUuid,
            patientContextRef: item.patientContextRef,
            topic: item.topic,
            unit: item.unit,
            pool: item.pool,
            status: status,
            ownershipState: ownershipState,
            assignedToMe: assignedToMe,
            workItemVersion: workItemVersion + versionDelta,
            threadVersion: threadVersion + versionDelta,
            lastMessageAt: item.lastMessageAt,
            dueAt: item.dueAt,
            escalateAt: item.escalateAt,
            isResponseDue: item.isResponseDue,
            isEscalationDue: item.isEscalationDue,
            closedAt: status == "closed" ? "2026-07-20T12:02:00Z" : nil,
            messages: messages ?? item.messages,
            hasEarlierMessages: item.hasEarlierMessages
        )
    }
}
