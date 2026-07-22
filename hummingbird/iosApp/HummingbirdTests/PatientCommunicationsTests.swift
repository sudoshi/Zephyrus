import XCTest
@testable import Hummingbird

final class PatientCommunicationsContractTests: XCTestCase {
    func testDecodesRestrictedThreadRetainingPoolUUIDOnlyForRerouteBinding() throws {
        let data = Data(Self.threadEnvelope.utf8)
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        let envelope = try decoder.decode(Envelope<PatientCommunicationWorkItem>.self, from: data)

        XCTAssertEqual(envelope.data.topic.label, "Discharge planning")
        XCTAssertEqual(envelope.data.pool.label, "5 East care team")
        XCTAssertEqual(envelope.data.pool.poolUuid, "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")
        XCTAssertEqual(envelope.data.workItemVersion, 4)
        XCTAssertEqual(envelope.data.threadVersion, 8)
        XCTAssertEqual(envelope.data.messages?.first?.body, "When can I go home?")
        XCTAssertTrue(envelope.data.canReply)
        XCTAssertFalse(envelope.data.canClose)
    }

    func testEligibilityUsesAuthoritativeServerCapabilityNotRoleName() {
        let eligible = MeData(
            id: 1, name: "Transporter", username: "transport", email: nil,
            roles: ["transport"], isAdmin: false,
            can: .init(viewPatientCommunications: true, respondPatientCommunications: false),
            workflowPreference: nil,
            mustChangePassword: false, units: []
        )
        let ineligible = MeData(
            id: 2, name: "Case Manager", username: "cm", email: nil,
            roles: ["case_manager"], isAdmin: false,
            can: .init(viewPatientCommunications: false, respondPatientCommunications: false),
            workflowPreference: nil,
            mustChangePassword: false, units: []
        )

        XCTAssertTrue(PatientCommunicationsEligibility.isEligible(eligible))
        XCTAssertFalse(PatientCommunicationsEligibility.isEligible(ineligible))
    }

    private static let threadEnvelope = """
    {
      "data": {
        "work_item_uuid": "11111111-1111-4111-8111-111111111111",
        "thread_uuid": "22222222-2222-4222-8222-222222222222",
        "patient_context_ref": "ptok_0123456789abcdef01234567",
        "topic": {"code": "discharge_planning", "label": "Discharge planning"},
        "unit": {"id": 85, "label": "5 East"},
        "pool": {"pool_uuid": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "label": "5 East care team"},
        "status": "open",
        "ownership_state": "acknowledged",
        "assigned_to_me": true,
        "work_item_version": 4,
        "thread_version": 8,
        "last_message_at": "2026-07-19T14:05:00Z",
        "due_at": "2026-07-19T15:05:00Z",
        "escalate_at": "2026-07-19T16:05:00Z",
        "is_response_due": false,
        "is_escalation_due": false,
        "closed_at": null,
        "messages": [{
          "message_uuid": "33333333-3333-4333-8333-333333333333",
          "sender_display_role": "Patient",
          "visibility": "patient_visible",
          "message_kind": "message",
          "body": "When can I go home?",
          "delivery_state": "acknowledged",
          "sent_at": "2026-07-19T14:05:00Z"
        }],
        "has_earlier_messages": false
      },
      "meta": {"as_of": "2026-07-19T14:06:00Z", "stale": false, "version": null,
        "classification": "patient_communication_restricted", "offline_writes_allowed": false},
      "links": {}
    }
    """
}

@MainActor
final class PatientCommunicationsAPIClientTests: XCTestCase {
    override func tearDown() {
        PatientCommunicationsURLProtocol.handler = nil
        super.tearDown()
    }

    func testReplyUsesExactUUIDHeadersStrictBodyAndNoStore() async throws {
        let idempotencyKey = UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!
        let clientMessageUUID = UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!
        var capturedRequest: URLRequest?
        var capturedBody: Data?
        PatientCommunicationsURLProtocol.handler = { request in
            capturedRequest = request
            capturedBody = Self.bodyData(from: request)
            return (200, Data(Self.mutationEnvelope.utf8))
        }

        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [PatientCommunicationsURLProtocol.self]
        configuration.urlCache = nil
        let client = APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration)
        )

        _ = try await client.replyToPatientCommunication(
            workItemUUID: "11111111-1111-4111-8111-111111111111",
            workItemVersion: 4,
            threadVersion: 8,
            message: "Clear patient-visible response",
            clientMessageUUID: clientMessageUUID,
            idempotencyKey: idempotencyKey,
            bearer: "staff-token"
        )

        let request = try XCTUnwrap(capturedRequest)
        XCTAssertEqual(request.httpMethod, "POST")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Idempotency-Key"), idempotencyKey.uuidString.lowercased())
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer staff-token")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
        let body = try XCTUnwrap(capturedBody)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
        XCTAssertEqual(json["work_item_version"] as? Int, 4)
        XCTAssertEqual(json["thread_version"] as? Int, 8)
        XCTAssertEqual(json["client_message_uuid"] as? String, clientMessageUUID.uuidString.lowercased())
        XCTAssertEqual(json["message"] as? String, "Clear patient-visible response")
        XCTAssertEqual(Set(json.keys), ["work_item_version", "thread_version", "client_message_uuid", "message"])
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
          "status": "open", "ownership_state": "responded", "assigned_to_me": true,
          "work_item_version": 5, "thread_version": 9,
          "last_message_at": "2026-07-19T14:07:00Z",
          "due_at": "2026-07-19T15:05:00Z", "escalate_at": "2026-07-19T16:05:00Z",
          "is_response_due": false, "is_escalation_due": false, "closed_at": null
        },
        "message": null,
        "event_uuid": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
        "replayed": false
      },
      "meta": {"as_of": "2026-07-19T14:07:00Z", "stale": false, "version": null,
        "classification": "patient_communication_restricted", "offline_writes_allowed": false},
      "links": {}
    }
    """
}

@MainActor
final class PatientCommunicationsViewModelTests: XCTestCase {
    func testRespondCapabilityFalseNeverInvokesMutationEndpoint() async {
        let repository = PatientCommunicationsFakeRepository()
        let viewModel = PatientCommunicationsViewModel(repository: repository)

        let succeeded = await viewModel.reply(
            repository.item,
            message: "This must not leave the device",
            canRespond: false,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertTrue(repository.replyCalls.isEmpty)
    }

    func testConflictRefetchesAndNeverAutomaticallyResends() async {
        let repository = PatientCommunicationsFakeRepository()
        repository.replyErrors = [APIError(message: "Changed", statusCode: 409)]
        let viewModel = PatientCommunicationsViewModel(repository: repository)
        let item = repository.item

        let succeeded = await viewModel.reply(
            item,
            message: "Patient-visible answer",
            canRespond: true,
            bearer: "token"
        )

        XCTAssertFalse(succeeded)
        XCTAssertEqual(repository.replyCalls.count, 1)
        XCTAssertEqual(repository.threadLoads, 1)
        XCTAssertEqual(repository.inboxLoads, 1)
        XCTAssertTrue(viewModel.actionMessage?.contains("changed since") == true)
    }

    func testExplicitRetryAfterAmbiguousFailureReusesUUIDsWithoutQueueing() async {
        let uuids = [
            UUID(uuidString: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")!,
            UUID(uuidString: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb")!,
        ]
        var iterator = uuids.makeIterator()
        let repository = PatientCommunicationsFakeRepository()
        repository.replyErrors = [APIError(message: "Offline", statusCode: nil)]
        let viewModel = PatientCommunicationsViewModel(
            repository: repository,
            makeUUID: { iterator.next()! }
        )

        let firstSucceeded = await viewModel.reply(
            repository.item,
            message: "Patient-visible answer",
            canRespond: true,
            bearer: "token"
        )
        XCTAssertFalse(firstSucceeded)
        XCTAssertEqual(repository.replyCalls.count, 1, "An ambiguous failure must not trigger an automatic write retry")

        let retrySucceeded = await viewModel.retryPendingMutation(
            canRespond: true,
            bearer: "token"
        )
        XCTAssertTrue(retrySucceeded)
        XCTAssertEqual(repository.replyCalls.count, 2)
        XCTAssertEqual(repository.replyCalls[0].clientMessageUUID, repository.replyCalls[1].clientMessageUUID)
        XCTAssertEqual(repository.replyCalls[0].idempotencyKey, repository.replyCalls[1].idempotencyKey)
    }

    func testSuspendErasesPHIAndInMemoryRetryIdentity() async {
        let repository = PatientCommunicationsFakeRepository()
        repository.replyErrors = [APIError(message: "Offline", statusCode: nil)]
        let ids = [UUID(), UUID(), UUID(), UUID()]
        var iterator = ids.makeIterator()
        let viewModel = PatientCommunicationsViewModel(repository: repository, makeUUID: { iterator.next()! })

        await viewModel.loadInbox(bearer: "token")
        await viewModel.loadThread(workItemUUID: repository.item.workItemUuid, bearer: "token")
        _ = await viewModel.reply(
            repository.item,
            message: "Sensitive draft",
            canRespond: true,
            bearer: "token"
        )
        viewModel.suspend()

        XCTAssertTrue(viewModel.inbox.isEmpty)
        XCTAssertNil(viewModel.thread)
        XCTAssertNil(viewModel.actionMessage)

        _ = await viewModel.reply(
            repository.item,
            message: "Sensitive draft",
            canRespond: true,
            bearer: "token"
        )
        XCTAssertNotEqual(repository.replyCalls[0].idempotencyKey, repository.replyCalls[1].idempotencyKey)
        XCTAssertNotEqual(repository.replyCalls[0].clientMessageUUID, repository.replyCalls[1].clientMessageUUID)
    }
}

private final class PatientCommunicationsURLProtocol: URLProtocol {
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
private final class PatientCommunicationsFakeRepository: PatientCommunicationsRepository {
    struct ReplyCall {
        let clientMessageUUID: UUID
        let idempotencyKey: UUID
    }

    var replyErrors: [Error] = []
    var replyCalls: [ReplyCall] = []
    var threadLoads = 0
    var inboxLoads = 0
    private var currentItemOverride: PatientCommunicationWorkItem?

    let item = PatientCommunicationWorkItem(
        workItemUuid: "11111111-1111-4111-8111-111111111111",
        threadUuid: "22222222-2222-4222-8222-222222222222",
        patientContextRef: nil,
        topic: .init(code: "discharge_planning", label: "Discharge planning"),
        unit: .init(id: 85, label: "5 East"),
        pool: .init(label: "5 East care team"),
        status: "open", ownershipState: "acknowledged", assignedToMe: true,
        workItemVersion: 4, threadVersion: 8,
        lastMessageAt: "2026-07-19T14:05:00Z", dueAt: "2026-07-19T15:05:00Z",
        escalateAt: "2026-07-19T16:05:00Z", isResponseDue: false, isEscalationDue: false,
        closedAt: nil,
        messages: [.init(
            messageUuid: "33333333-3333-4333-8333-333333333333",
            senderDisplayRole: "Patient", visibility: "patient_visible", messageKind: "message",
            body: "Question", deliveryState: "acknowledged", sentAt: "2026-07-19T14:05:00Z"
        )],
        hasEarlierMessages: false
    )

    func patientCommunicationsInbox(bearer: String) async throws -> PatientCommunicationInboxData {
        inboxLoads += 1
        let current = currentItemOverride ?? item
        return .init(items: [current], count: 1)
    }

    func patientCommunicationThread(workItemUUID: String, bearer: String) async throws -> PatientCommunicationWorkItem {
        threadLoads += 1
        return currentItemOverride ?? item
    }

    func claimPatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        idempotencyKey: UUID, bearer: String
    ) async throws -> PatientCommunicationMutationData {
        .init(workItem: item, message: nil, eventUuid: UUID().uuidString, replayed: false)
    }

    func replyToPatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        message: String, clientMessageUUID: UUID, idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        replyCalls.append(.init(clientMessageUUID: clientMessageUUID, idempotencyKey: idempotencyKey))
        if !replyErrors.isEmpty { throw replyErrors.removeFirst() }
        let reply = PatientCommunicationMessage(
            messageUuid: "88888888-8888-4888-8888-888888888888",
            senderDisplayRole: "Care team",
            visibility: "patient_visible",
            messageKind: "message",
            body: message,
            deliveryState: "delivered",
            sentAt: "2026-07-19T14:07:00Z"
        )
        let projection = PatientCommunicationWorkItem(
            workItemUuid: item.workItemUuid,
            threadUuid: item.threadUuid,
            patientContextRef: item.patientContextRef,
            topic: item.topic,
            unit: item.unit,
            pool: item.pool,
            status: "open",
            ownershipState: "responded",
            assignedToMe: true,
            workItemVersion: workItemVersion + 1,
            threadVersion: threadVersion + 1,
            lastMessageAt: item.lastMessageAt,
            dueAt: item.dueAt,
            escalateAt: item.escalateAt,
            isResponseDue: item.isResponseDue,
            isEscalationDue: item.isEscalationDue,
            closedAt: nil,
            messages: (item.messages ?? []) + [reply],
            hasEarlierMessages: item.hasEarlierMessages
        )
        currentItemOverride = projection
        return .init(
            workItem: projection,
            message: reply,
            eventUuid: "77777777-7777-4777-8777-777777777777",
            replayed: false
        )
    }

    func closePatientCommunication(
        workItemUUID: String, workItemVersion: Int, threadVersion: Int,
        reasonCode: PatientCommunicationCloseReason, idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        .init(workItem: item, message: nil, eventUuid: UUID().uuidString, replayed: false)
    }

    func patientCommunicationRouteCandidates(
        workItemUUID: String,
        bearer: String
    ) async throws -> PatientCommunicationRouteCandidatesData {
        throw APIError(message: "Routing fixture not configured.", statusCode: 503)
    }

    func releasePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        throw APIError(message: "Routing fixture not configured.", statusCode: 503)
    }

    func reassignPatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetMembershipUUID: String,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        throw APIError(message: "Routing fixture not configured.", statusCode: 503)
    }

    func reroutePatientCommunication(
        workItemUUID: String,
        workItemVersion: Int,
        threadVersion: Int,
        targetPoolUUID: String,
        reasonCode: String,
        idempotencyKey: UUID,
        bearer: String
    ) async throws -> PatientCommunicationMutationData {
        throw APIError(message: "Routing fixture not configured.", statusCode: 503)
    }
}
