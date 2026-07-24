import XCTest
@testable import Hummingbird

final class TransportSecurityPolicyTests: XCTestCase {
    func testProductionAllowsOnlyExactHTTPSOrigin() throws {
        let policy = HummingbirdTransportSecurityPolicy(environment: .production)

        XCTAssertTrue(policy.permitsHTTPBaseURL(try url("https://zephyrus.acumenus.net")))
        XCTAssertTrue(policy.permitsHTTPBaseURL(try url("https://zephyrus.acumenus.net:443/")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("http://zephyrus.acumenus.net")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("https://api.acumenus.net")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("https://zephyrus.acumenus.net:8443")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("https://zephyrus.acumenus.net/api")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("https://user:secret@zephyrus.acumenus.net")))
    }

    func testProductionAllowsOnlyExactSecureRealtimeOrigin() {
        let policy = HummingbirdTransportSecurityPolicy(environment: .production)

        XCTAssertTrue(policy.permitsWebSocket(
            scheme: "wss",
            host: "zephyrus.acumenus.net",
            port: 443
        ))
        XCTAssertFalse(policy.permitsWebSocket(
            scheme: "ws",
            host: "zephyrus.acumenus.net",
            port: 443
        ))
        XCTAssertFalse(policy.permitsWebSocket(
            scheme: "wss",
            host: "reverb.acumenus.net",
            port: 443
        ))
        XCTAssertFalse(policy.permitsWebSocket(
            scheme: "wss",
            host: "zephyrus.acumenus.net",
            port: 8443
        ))
    }

    func testDevelopmentCleartextIsLoopbackOrEmulatorOnly() throws {
        let policy = HummingbirdTransportSecurityPolicy(environment: .development)

        XCTAssertTrue(policy.permitsHTTPBaseURL(try url("http://localhost:8001")))
        XCTAssertTrue(policy.permitsHTTPBaseURL(try url("http://127.0.0.1:8001")))
        XCTAssertTrue(policy.permitsHTTPBaseURL(try url("http://10.0.2.2:8001")))
        XCTAssertTrue(policy.permitsHTTPBaseURL(try url("https://staff-dev.example.test")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("http://192.168.1.35:8001")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("http://staff-dev.example.test")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("https://localhost:0")))
        XCTAssertFalse(policy.permitsHTTPBaseURL(try url("https://localhost:65536")))
        XCTAssertTrue(policy.permitsWebSocket(scheme: "ws", host: "localhost", port: 8080))
        XCTAssertFalse(policy.permitsWebSocket(
            scheme: "ws",
            host: "192.168.1.35",
            port: 8080
        ))
    }

    func testDebugAppConfigurationSatisfiesCurrentPolicy() throws {
        let policy = HummingbirdTransportSecurityPolicy(environment: .current)

        XCTAssertTrue(policy.permitsHTTPBaseURL(try url(AppConfig.baseURL)))
        XCTAssertTrue(policy.permitsWebSocket(
            scheme: AppConfig.reverbScheme,
            host: AppConfig.reverbHost,
            port: AppConfig.reverbPort
        ))
    }

    func testGovernedSessionRefusesRedirects() throws {
        let delegate = HummingbirdNoRedirectDelegate()
        let session = HummingbirdURLSessionFactory.make()
        let original = try url("https://zephyrus.acumenus.net/api/mobile/v1/me")
        let redirected = try url("https://redirected.example.test/credential-target")
        let task = session.dataTask(with: original)
        let response = try XCTUnwrap(HTTPURLResponse(
            url: original,
            statusCode: 302,
            httpVersion: "HTTP/1.1",
            headerFields: ["Location": redirected.absoluteString]
        ))
        var proposedRequest: URLRequest? = URLRequest(url: redirected)

        delegate.urlSession(
            session,
            task: task,
            willPerformHTTPRedirection: response,
            newRequest: URLRequest(url: redirected)
        ) { proposedRequest = $0 }

        XCTAssertNil(proposedRequest)
        XCTAssertTrue(session.delegate is HummingbirdNoRedirectDelegate)
    }

    func testInvalidRealtimePortsAreRejectedBeforeURLConstruction() {
        let policy = HummingbirdTransportSecurityPolicy(environment: .development)

        XCTAssertFalse(policy.permitsWebSocket(scheme: "wss", host: "localhost", port: 0))
        XCTAssertFalse(policy.permitsWebSocket(scheme: "ws", host: "localhost", port: 65_536))
    }

    private func url(_ value: String) throws -> URL {
        try XCTUnwrap(URL(string: value))
    }
}
