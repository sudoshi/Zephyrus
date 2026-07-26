import XCTest
@testable import Hummingbird

@MainActor
final class EddyStreamingTests: XCTestCase {
    override func tearDown() {
        EddyStreamingURLProtocol.handler = nil
        super.tearDown()
    }

    func testParserIgnoresMalformedAndPersistedProposalFrames() throws {
        var parser = EddySSEFrameParser()
        XCTAssertEqual(try parser.consume(line: "data: not-json"), [])
        XCTAssertEqual(try parser.consume(line: ""), [])
        XCTAssertEqual(try parser.consume(line: "data: {\"persisted\":true,\"proposed_action\":{\"action_type\":\"flag_barrier\"}}"), [])
        XCTAssertEqual(try parser.consume(line: ""), [])

        XCTAssertEqual(EddyStreamDisplayText.provisional("Review <propose_act"), "Review ")
        XCTAssertEqual(
            EddyStreamDisplayText.provisional("Review <propose_action>{\"action_type\":\"flag_barrier\"}"),
            "Review "
        )
        XCTAssertEqual(EddyStreamDisplayText.terminal("Reviewed."), "Reviewed.")
    }

    func testStreamUsesNoStoreTransportWithoutIdempotencyReplay() async throws {
        var capturedRequest: URLRequest?
        EddyStreamingURLProtocol.handler = { request in
            capturedRequest = request
            return Data(#"""
            data: {"conversation_id":"conversation-1"}

            data: {"token":"Review "}

            data: {"token":"the capacity board."}

            data: {"complete":true,"clean_reply":"Review the capacity board.","provider":"ollama","proposed_action":{"action_type":"flag_barrier"}}

            data: [DONE]

            """#.utf8)
        }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [EddyStreamingURLProtocol.self]
        configuration.urlCache = nil
        let client = APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration),
            tokenCoordinator: nil
        )
        var events: [EddyStreamEvent] = []

        let reply = try await client.eddyChatStream(
            message: "What needs review?",
            conversationId: nil,
            persona: "bed_manager",
            pageContext: "house",
            pageComponent: "House capacity",
            pageData: ["scope_ref": "house"],
            bearer: "staff-token",
            onEvent: { events.append($0) }
        )

        XCTAssertEqual(reply.conversationId, "conversation-1")
        XCTAssertEqual(reply.cleanReply, "Review the capacity board.")
        XCTAssertEqual(reply.provider, "ollama")
        XCTAssertEqual(events, [
            .conversationStarted("conversation-1"),
            .token("Review "),
            .token("the capacity board."),
        ])

        let request = try XCTUnwrap(capturedRequest)
        XCTAssertEqual(request.httpMethod, "POST")
        XCTAssertEqual(request.url?.path, "/api/mobile/v1/eddy/chat/stream")
        XCTAssertEqual(URLComponents(url: try XCTUnwrap(request.url), resolvingAgainstBaseURL: false)?
            .queryItems?.first(where: { $0.name == "persona" })?.value, "bed_manager")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer staff-token")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Accept"), "text/event-stream")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
        XCTAssertNil(request.value(forHTTPHeaderField: "Idempotency-Key"))
        XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalCacheData)
        let body = try XCTUnwrap(Self.bodyData(from: request))
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])
        XCTAssertEqual(json["surface"] as? String, "hummingbird")
        XCTAssertEqual(json["page_context"] as? String, "house")
        XCTAssertEqual((json["page_data"] as? [String: String])?["scope_ref"], "house")
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
}

private final class EddyStreamingURLProtocol: URLProtocol {
    static var handler: ((URLRequest) throws -> Data)?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        do {
            let handler = try XCTUnwrap(Self.handler)
            let data = try handler(request)
            let response = HTTPURLResponse(
                url: try XCTUnwrap(request.url),
                statusCode: 200,
                httpVersion: "HTTP/1.1",
                headerFields: ["Content-Type": "text/event-stream"]
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
