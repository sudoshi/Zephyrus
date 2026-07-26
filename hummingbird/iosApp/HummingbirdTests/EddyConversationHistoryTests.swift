import XCTest
@testable import Hummingbird

@MainActor
final class EddyConversationHistoryAPIClientTests: XCTestCase {
    override func tearDown() {
        EddyConversationHistoryURLProtocol.handler = nil
        super.tearDown()
    }

    func testHistoryAndDetailAreAuthorizedNoStoreRequestsAndDecodeDraftMarkers() async throws {
        var requests: [URLRequest] = []
        EddyConversationHistoryURLProtocol.handler = { request in
            requests.append(request)
            switch request.url?.path {
            case "/api/mobile/v1/eddy/conversations":
                return (200, Data(Self.historyEnvelope.utf8))
            case "/api/mobile/v1/eddy/conversations/e75d595c-7e67-49f8-b0a2-8189e1c8491d":
                return (200, Data(Self.detailEnvelope.utf8))
            default:
                return (404, Data(#"{"error":{"message":"Not found"}}"#.utf8))
            }
        }

        let client = Self.client()
        let history = try await client.eddyConversations(persona: "bed_manager", bearer: "staff-token")
        let detail = try await client.eddyConversation(
            id: "e75d595c-7e67-49f8-b0a2-8189e1c8491d",
            persona: "bed_manager",
            bearer: "staff-token"
        )

        XCTAssertEqual(history.count, 1)
        XCTAssertEqual(history[0].id, "e75d595c-7e67-49f8-b0a2-8189e1c8491d")
        XCTAssertEqual(history[0].title, "Discharge barriers")
        XCTAssertEqual(history[0].origin, "hummingbird")
        XCTAssertEqual(detail.messages.count, 2)
        XCTAssertEqual(detail.messages[0].role, "user")
        XCTAssertEqual(detail.messages[1].role, "assistant")
        XCTAssertEqual(detail.messages[1].provider, "ollama")
        XCTAssertTrue(detail.messages[1].hasProposedAction)

        XCTAssertEqual(requests.count, 2)
        for request in requests {
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer staff-token")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
            XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalCacheData)
            XCTAssertEqual(URLComponents(url: try XCTUnwrap(request.url), resolvingAgainstBaseURL: false)?.queryItems?.first(where: { $0.name == "persona" })?.value, "bed_manager")
        }
    }

    func testMissingConversationFieldsRemainSafeAndNullDraftIsNotAnApproval() throws {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let message = try decoder.decode(EddyConversationMessage.self, from: Data(#"""
        {"role":"unexpected","content":"","proposed_action":null}
        """#.utf8))
        let summary = try decoder.decode(EddyConversationSummary.self, from: Data(#"""
        {"id":"conversation-id","title":" ","surface":" ","origin":" "}
        """#.utf8))

        XCTAssertEqual(message.role, "assistant")
        XCTAssertFalse(message.hasProposedAction)
        XCTAssertEqual(summary.title, "Eddy conversation")
        XCTAssertEqual(summary.surface, "chat")
        XCTAssertEqual(summary.origin, "unknown")
    }

    func testEddyContextAndChatAlsoUseNoStoreTransport() async throws {
        var requests: [URLRequest] = []
        EddyConversationHistoryURLProtocol.handler = { request in
            requests.append(request)
            switch request.url?.path {
            case "/api/mobile/v1/eddy/context/house":
                return (200, Data(#"""
                {"data":{"scope_ref":"house","scope_type":"house","generated_at":"2026-07-24T16:00:00Z"},"meta":{},"links":{}}
                """#.utf8))
            case "/api/mobile/v1/eddy/chat":
                return (200, Data(#"""
                {"data":{"conversation_id":"e75d595c-7e67-49f8-b0a2-8189e1c8491d","status":"success","message":{"role":"assistant","content":"Review the current status.","provider":"ollama"}},"meta":{},"links":{}}
                """#.utf8))
            default:
                return (404, Data(#"{"error":{"message":"Not found"}}"#.utf8))
            }
        }

        let client = Self.client()
        _ = try await client.eddyContext(scopeRef: "house", persona: "bed_manager", bearer: "staff-token")
        _ = try await client.eddyChat(
            message: "What should I review?",
            conversationId: nil,
            persona: "bed_manager",
            bearer: "staff-token"
        )

        XCTAssertEqual(requests.map(\.httpMethod), ["GET", "POST"])
        for request in requests {
            XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
            XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalCacheData)
        }
    }

    func testEddyApprovalReadsAndExplicitHumanDecisionAreNoStoreAndIdempotent() async throws {
        let approvalID = "f2de3b42-5f41-4a34-9a91-c6292465bba1"
        let replayKey = UUID(uuidString: "5ac78f64-66f8-4db3-a871-6f143e14ea34")!
        var requests: [URLRequest] = []
        EddyConversationHistoryURLProtocol.handler = { request in
            requests.append(request)
            switch (request.httpMethod, request.url?.path) {
            case ("GET", "/api/mobile/v1/eddy/approvals"):
                return (200, Data(Self.approvalListEnvelope.utf8))
            case ("GET", "/api/mobile/v1/eddy/approvals/\(approvalID)"):
                return (200, Data(Self.approvalPreviewEnvelope.utf8))
            case ("POST", "/api/mobile/v1/eddy/approvals/\(approvalID)/decision"):
                return (200, Data(Self.approvalDecisionEnvelope.utf8))
            default:
                return (404, Data(#"{"error":{"message":"Not found"}}"#.utf8))
            }
        }

        let client = Self.client()
        let approvals = try await client.eddyApprovals(persona: "capacity_lead", bearer: "staff-token")
        let preview = try await client.eddyApproval(id: approvalID, persona: "capacity_lead", bearer: "staff-token")
        let outcome = try await client.decideEddyApproval(
            id: approvalID,
            persona: "capacity_lead",
            decision: "approved",
            idempotencyKey: replayKey,
            bearer: "staff-token"
        )

        XCTAssertEqual(approvals.map(\.approvalUuid), [approvalID])
        XCTAssertEqual(preview.summary.actionType, "flag_barrier")
        XCTAssertEqual(preview.params["unit"]?.displayString, "5 East")
        XCTAssertEqual(outcome.approvalUuid, approvalID)
        XCTAssertEqual(outcome.decision, "approved")

        XCTAssertEqual(requests.count, 3)
        for request in requests {
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer staff-token")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
            XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalCacheData)
            XCTAssertEqual(URLComponents(url: try XCTUnwrap(request.url), resolvingAgainstBaseURL: false)?.queryItems?.first(where: { $0.name == "persona" })?.value, "capacity_lead")
        }
        let decisionRequest = try XCTUnwrap(requests.last)
        XCTAssertEqual(decisionRequest.httpMethod, "POST")
        XCTAssertEqual(decisionRequest.value(forHTTPHeaderField: "Idempotency-Key"), replayKey.uuidString.lowercased())
        let body = try XCTUnwrap(Self.bodyData(from: decisionRequest))
        let decisionBody = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: String])
        XCTAssertEqual(decisionBody["decision"], "approved")
    }

    private static func client() -> APIClient {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [EddyConversationHistoryURLProtocol.self]
        configuration.urlCache = nil
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCredentialStorage = nil
        return APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration),
            tokenCoordinator: nil
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

    private static let historyEnvelope = #"""
    {
      "data": [{
        "id": "e75d595c-7e67-49f8-b0a2-8189e1c8491d",
        "title": "Discharge barriers",
        "surface": "hummingbird",
        "origin": "hummingbird",
        "updated_at": "2026-07-24T16:00:00Z"
      }],
      "meta": {"count": 1},
      "links": {}
    }
    """#

    private static let detailEnvelope = #"""
    {
      "data": {
        "id": "e75d595c-7e67-49f8-b0a2-8189e1c8491d",
        "title": "Discharge barriers",
        "surface": "hummingbird",
        "messages": [
          {
            "role": "user",
            "content": "What is blocking discharges?",
            "provider": null,
            "created_at": "2026-07-24T15:58:00Z",
            "proposed_action": null
          },
          {
            "role": "assistant",
            "content": "Two barriers need review.",
            "provider": "ollama",
            "created_at": "2026-07-24T16:00:00Z",
            "proposed_action": {"action_type":"flag_barrier"}
          }
        ]
      },
      "meta": {"stale": false},
      "links": {}
    }
    """#

    private static let approvalListEnvelope = #"""
    {
      "data": [{
        "approval_uuid": "f2de3b42-5f41-4a34-9a91-c6292465bba1",
        "action_uuid": "dca4d2b5-0dca-49d6-a2e4-431aaf1bcb91",
        "action_type": "flag_barrier",
        "title": "Flag a discharge barrier",
        "surface": "rtdc",
        "tier": "T1",
        "risk": "medium",
        "requested_at": "2026-07-24T16:00:00Z"
      }],
      "meta": {"count": 1},
      "links": {}
    }
    """#

    private static let approvalPreviewEnvelope = #"""
    {
      "data": {
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
        "params": {"unit": "5 East", "barrier_count": 2},
        "preview": "Would flag a throughput/discharge barrier on 5 East for the next huddle."
      },
      "meta": {},
      "links": {}
    }
    """#

    private static let approvalDecisionEnvelope = #"""
    {
      "data": {
        "approval_uuid": "f2de3b42-5f41-4a34-9a91-c6292465bba1",
        "decision": "approved",
        "action_status": "approved"
      },
      "meta": {},
      "links": {}
    }
    """#
}

private final class EddyConversationHistoryURLProtocol: URLProtocol {
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
