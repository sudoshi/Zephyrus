import Foundation

enum HummingbirdTransportEnvironment: Equatable {
    case development
    case production

    static var current: HummingbirdTransportEnvironment {
#if DEBUG
        .development
#else
        .production
#endif
    }
}

/// Fail-closed transport boundary for the staff product.
///
/// Release builds have one HTTPS/WSS origin. Development builds may use a
/// system-trusted HTTPS origin or a tightly bounded loopback/emulator cleartext
/// origin. Certificate validation remains owned by ATS and URLSession; this type
/// never installs a permissive trust delegate.
struct HummingbirdTransportSecurityPolicy {
    static let productionHost = "zephyrus.acumenus.net"

    let environment: HummingbirdTransportEnvironment

    func permitsHTTPBaseURL(_ url: URL) -> Bool {
        guard let components = normalizedComponents(url),
              let scheme = components.scheme?.lowercased(),
              let host = components.host?.lowercased()
        else { return false }
        if let port = components.port, !(1...65_535).contains(port) {
            return false
        }

        switch environment {
        case .production:
            return scheme == "https"
                && host == Self.productionHost
                && (components.port == nil || components.port == 443)
        case .development:
            if scheme == "https" {
                return true
            }
            return scheme == "http"
                && Self.developmentCleartextHosts.contains(host)
        }
    }

    func permitsWebSocket(scheme: String, host: String, port: Int) -> Bool {
        guard (1...65_535).contains(port) else { return false }

        let normalizedScheme = scheme.lowercased()
        let normalizedHost = host.lowercased()

        switch environment {
        case .production:
            return normalizedScheme == "wss"
                && normalizedHost == Self.productionHost
                && port == 443
        case .development:
            if normalizedScheme == "wss" {
                return port == 443
            }
            return normalizedScheme == "ws"
                && Self.developmentCleartextHosts.contains(normalizedHost)
        }
    }

    private func normalizedComponents(_ url: URL) -> URLComponents? {
        guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
              components.user == nil,
              components.password == nil,
              components.query == nil,
              components.fragment == nil,
              components.path.isEmpty || components.path == "/"
        else { return nil }

        return components
    }

    private static let developmentCleartextHosts: Set<String> = [
        "localhost",
        "127.0.0.1",
        "::1",
        "10.0.2.2",
    ]
}

/// Refuses every HTTP redirect so an otherwise valid API or realtime origin
/// cannot move a credential-bearing request to a second endpoint.
final class HummingbirdNoRedirectDelegate: NSObject, URLSessionTaskDelegate, @unchecked Sendable {
    func urlSession(
        _ session: URLSession,
        task: URLSessionTask,
        willPerformHTTPRedirection response: HTTPURLResponse,
        newRequest request: URLRequest,
        completionHandler: @escaping (URLRequest?) -> Void
    ) {
        completionHandler(nil)
    }
}

enum HummingbirdURLSessionFactory {
    static func make(
        configuration: URLSessionConfiguration = .ephemeral
    ) -> URLSession {
        URLSession(
            configuration: configuration,
            delegate: HummingbirdNoRedirectDelegate(),
            delegateQueue: nil
        )
    }
}
