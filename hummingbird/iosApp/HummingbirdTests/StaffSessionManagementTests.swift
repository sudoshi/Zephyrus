import XCTest
@testable import Hummingbird

final class StaffSessionContractTests: XCTestCase {
    func testSessionTimestampParserAcceptsLaravelFractionalAndStandardIso8601() {
        XCTAssertNotNil(StaffSessionTimestamp.parse("2026-07-23T22:55:00.123456Z"))
        XCTAssertNotNil(StaffSessionTimestamp.parse("2026-07-23T22:55:00Z"))
        XCTAssertNil(StaffSessionTimestamp.parse("not-a-timestamp"))
    }

    func testDeviceIdentityIsStableCanonicalAndRequestBodyExcludesEnvironment() throws {
        let suiteName = "StaffSessionContractTests.\(UUID().uuidString)"
        let defaults = try XCTUnwrap(UserDefaults(suiteName: suiteName))
        defer { defaults.removePersistentDomain(forName: suiteName) }

        let first = try StaffAuthDevice.current(defaults: defaults)
        let second = try StaffAuthDevice.current(defaults: defaults)

        XCTAssertEqual(first.installationUUID, second.installationUUID)
        XCTAssertEqual(UUID(uuidString: first.installationUUID)?.uuidString.lowercased(),
                       first.installationUUID)
        XCTAssertEqual(first.platform, "ios")
        XCTAssertEqual(first.requestBody["installation_uuid"] as? String, first.installationUUID)
        XCTAssertNil(first.requestBody["environment"])
        XCTAssertNil(first.requestBody["access_token"])
        XCTAssertNil(first.requestBody["refresh_token"])
    }

    func testDeviceRequestMetadataIsTrimmedBoundedAndNeverRequiredForIdentity() {
        let device = StaffAuthDevice(
            installationUUID: "11111111-1111-4111-8111-111111111111",
            platform: "ios",
            name: "  \(String(repeating: "n", count: 140))  ",
            appVersion: "   ",
            osVersion: String(repeating: "o", count: 100)
        )

        let body = device.requestBody
        XCTAssertEqual((body["name"] as? String)?.unicodeScalars.count, 120)
        XCTAssertNil(body["app_version"])
        XCTAssertEqual((body["os_version"] as? String)?.unicodeScalars.count, 80)
        XCTAssertEqual(body["installation_uuid"] as? String, device.installationUUID)
    }

    func testSessionProjectionDecodesSafeFieldsWithoutModelsForRestrictedMetadata() throws {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let envelope = try decoder.decode(
            Envelope<StaffSessionListData>.self,
            from: Data(Self.sessionEnvelope.utf8)
        )

        let session = try XCTUnwrap(envelope.data.sessions.first)
        XCTAssertEqual(session.sessionUuid, "11111111-1111-4111-8111-111111111111")
        XCTAssertTrue(session.current)
        XCTAssertEqual(session.device.name, "Rounds iPhone")
        XCTAssertEqual(session.environment, "production")
        let mirrorLabels = Set(Mirror(reflecting: session).children.compactMap(\.label))
        XCTAssertFalse(mirrorLabels.contains("installationUuid"))
        XCTAssertFalse(mirrorLabels.contains("tokenFamilyUuid"))
        XCTAssertFalse(mirrorLabels.contains("ipAddress"))
        XCTAssertFalse(mirrorLabels.contains("userAgent"))
    }

    func testSessionProjectionFailsClosedOnMalformedIdentityStatusMetadataAndTime() throws {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let invalidFragments = [
            "\"session_uuid\": \"00000000-0000-0000-0000-000000000000\"",
            "\"current\": false",
            "\"status\": \"revoked\"",
            "\"platform\": \"web\"",
            "\"last_seen_at\": \"not-a-time\"",
        ]
        let validFragments = [
            "\"session_uuid\": \"11111111-1111-4111-8111-111111111111\"",
            "\"current\": true",
            "\"status\": \"active\"",
            "\"platform\": \"ios\"",
            "\"last_seen_at\": \"2026-07-23T22:55:00Z\"",
        ]

        for (valid, invalid) in zip(validFragments, invalidFragments) {
            let malformed = Self.sessionEnvelope.replacingOccurrences(of: valid, with: invalid)
            XCTAssertThrowsError(
                try decoder.decode(
                    Envelope<StaffSessionListData>.self,
                    from: Data(malformed.utf8)
                )
            )
        }
    }

    fileprivate static let sessionEnvelope = """
    {
      "data": {
        "sessions": [{
          "session_uuid": "11111111-1111-4111-8111-111111111111",
          "current": true,
          "status": "active",
          "device": {
            "platform": "ios",
            "name": "Rounds iPhone",
            "app_version": "0.1.0",
            "os_version": "iOS 26.3"
          },
          "environment": "production",
          "last_seen_at": "2026-07-23T22:55:00Z",
          "expires_at": "2026-08-22T22:55:00Z",
          "created_at": "2026-07-23T22:00:00Z",
          "installation_uuid": "must-not-project",
          "token_family_uuid": "must-not-project",
          "ip_address": "192.0.2.1",
          "user_agent": "must-not-project"
        }]
      },
      "meta": {"stale": false},
      "links": {}
    }
    """
}

@MainActor
final class StaffSessionAPIClientTests: XCTestCase {
    override func tearDown() {
        StaffSessionURLProtocol.handler = nil
        super.tearDown()
    }

    func testAuthenticationBindsExactDeviceAndNeverSendsClientEnvironment() async throws {
        var capturedRequest: URLRequest?
        StaffSessionURLProtocol.handler = { request in
            capturedRequest = request
            return (200, Data(Self.tokenResponse.utf8))
        }
        let client = makeClient()
        let device = fixtureDevice()

        _ = try await client.token(
            username: "clinician",
            password: "not-logged",
            device: device
        )

        let request = try XCTUnwrap(capturedRequest)
        XCTAssertEqual(request.httpMethod, "POST")
        XCTAssertEqual(request.url?.path, "/api/auth/token")
        let body = try Self.jsonBody(request)
        let encoded = try XCTUnwrap(body["device"] as? [String: Any])
        XCTAssertEqual(encoded["installation_uuid"] as? String, device.installationUUID)
        XCTAssertEqual(encoded["platform"] as? String, "ios")
        XCTAssertNil(encoded["environment"])
    }

    func testListAndDeleteAreNoStoreAndDeleteHasNoAutomaticIdempotencyKey() async throws {
        var requests: [URLRequest] = []
        StaffSessionURLProtocol.handler = { request in
            requests.append(request)
            if request.httpMethod == "GET" {
                return (200, Data(Self.sessionEnvelope.utf8))
            }
            return (200, Data(Self.revocationEnvelope.utf8))
        }
        let client = makeClient()

        let sessions = try await client.staffSessions(bearer: "staff-access")
        XCTAssertEqual(sessions.count, 1)
        let revoked = try await client.revokeStaffSession(
            sessionUUID: sessions[0].sessionUuid,
            bearer: "staff-access"
        )

        XCTAssertTrue(revoked.current)
        XCTAssertEqual(requests.count, 2)
        XCTAssertEqual(requests[0].httpMethod, "GET")
        XCTAssertEqual(requests[0].url?.path, "/api/mobile/v1/me/sessions")
        XCTAssertEqual(
            requests[0].value(forHTTPHeaderField: "Cache-Control"),
            "no-store, no-cache, max-age=0"
        )
        XCTAssertEqual(requests[1].httpMethod, "DELETE")
        XCTAssertEqual(
            requests[1].url?.path,
            "/api/mobile/v1/me/sessions/11111111-1111-4111-8111-111111111111"
        )
        XCTAssertEqual(
            requests[1].value(forHTTPHeaderField: "Cache-Control"),
            "no-store, no-cache, max-age=0"
        )
        XCTAssertNil(requests[1].value(forHTTPHeaderField: "Idempotency-Key"))
    }

    func testDeleteRejectsNoncanonicalSessionIdentifierBeforeNetwork() async {
        var networkCalled = false
        StaffSessionURLProtocol.handler = { _ in
            networkCalled = true
            return (500, Data())
        }
        let client = makeClient()

        do {
            _ = try await client.revokeStaffSession(
                sessionUUID: "11111111-1111-4111-8111-11111111111A",
                bearer: "access"
            )
            XCTFail("Expected invalid session identifier")
        } catch let error as APIError {
            XCTAssertEqual(error.message, "The selected session identifier is invalid.")
            XCTAssertNil(error.statusCode)
        } catch {
            XCTFail("Unexpected error: \(error)")
        }

        XCTAssertFalse(networkCalled)
    }

    func testDeleteRejectsUnconfirmedOrMismatchedRevocationResponses() async {
        let client = makeClient()
        for body in [
            Self.revocationEnvelope.replacingOccurrences(
                of: "\"revoked\": true",
                with: "\"revoked\": false"
            ),
            Self.revocationEnvelope.replacingOccurrences(
                of: "11111111-1111-4111-8111-111111111111",
                with: "22222222-2222-4222-8222-222222222222"
            ),
        ] {
            StaffSessionURLProtocol.handler = { _ in (200, Data(body.utf8)) }
            do {
                _ = try await client.revokeStaffSession(
                    sessionUUID: "11111111-1111-4111-8111-111111111111",
                    bearer: "access"
                )
                XCTFail("Expected strict revocation projection failure")
            } catch {
                // A non-confirming or resource-mismatched response must fail closed.
            }
        }
    }

    private func makeClient() -> APIClient {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [StaffSessionURLProtocol.self]
        configuration.urlCache = nil
        return APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration),
            tokenCoordinator: nil
        )
    }

    private func fixtureDevice() -> StaffAuthDevice {
        StaffAuthDevice(
            installationUUID: "11111111-1111-4111-8111-111111111111",
            platform: "ios",
            name: "Rounds iPhone",
            appVersion: "0.1.0",
            osVersion: "iOS 26.3"
        )
    }

    private static func jsonBody(_ request: URLRequest) throws -> [String: Any] {
        let data: Data
        if let body = request.httpBody {
            data = body
        } else {
            let stream = try XCTUnwrap(request.httpBodyStream)
            stream.open()
            defer { stream.close() }
            var result = Data()
            var buffer = [UInt8](repeating: 0, count: 4_096)
            while stream.hasBytesAvailable {
                let count = stream.read(&buffer, maxLength: buffer.count)
                if count <= 0 { break }
                result.append(buffer, count: count)
            }
            data = result
        }
        return try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    private static let tokenResponse = """
    {
      "token_type": "Bearer",
      "access_token": "access",
      "refresh_token": "refresh",
      "expires_in": 900,
      "abilities": ["mobile:read"],
      "password_change_required": false
    }
    """

    private static let sessionEnvelope = StaffSessionContractTests.sessionEnvelope

    private static let revocationEnvelope = """
    {
      "data": {
        "session_uuid": "11111111-1111-4111-8111-111111111111",
        "revoked": true,
        "already_revoked": false,
        "current": true
      },
      "meta": {"stale": false},
      "links": {}
    }
    """
}

private final class StaffSessionURLProtocol: URLProtocol {
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
