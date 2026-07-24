import Foundation

struct PatientAppConfiguration: Equatable {
    static let apiEnabledInfoKey = "HBPPatientAPIEnabled"
    static let apiBaseURLInfoKey = "HBPPatientAPIBaseURL"
    static let apiEnabledEnvironmentKey = "HBP_PATIENT_API_ENABLED"
    static let apiBaseURLEnvironmentKey = "HBP_PATIENT_API_BASE_URL"
    static let syntheticEnvironmentKey = "HBP_SYNTHETIC_REFERENCE"

    let patientAPIEnabled: Bool
    let patientAPIBaseURL: URL?
    let syntheticReferenceRequested: Bool

    static func live() -> PatientAppConfiguration {
        from(info: Bundle.main.infoDictionary ?? [:], environment: ProcessInfo.processInfo.environment)
    }

    static func from(info: [String: Any], environment: [String: String]) -> PatientAppConfiguration {
        let plistEnabled = info[apiEnabledInfoKey] as? Bool ?? false
        let environmentEnabled = bool(environment[apiEnabledEnvironmentKey])
        let enabled = environmentEnabled ?? plistEnabled

        let rawBaseURL = environment[apiBaseURLEnvironmentKey]
            ?? info[apiBaseURLInfoKey] as? String
        let baseURL = PatientAPIBoundary.validatedBaseURL(rawBaseURL)

        #if DEBUG
        let synthetic = bool(environment[syntheticEnvironmentKey]) ?? false
        #else
        let synthetic = false
        #endif

        return PatientAppConfiguration(
            patientAPIEnabled: enabled && baseURL != nil,
            patientAPIBaseURL: baseURL,
            syntheticReferenceRequested: synthetic
        )
    }

    func makeAPIClient() -> PatientAPIClient? {
        guard patientAPIEnabled, let patientAPIBaseURL else { return nil }
        return PatientAPIClient(baseURL: patientAPIBaseURL)
    }

    private static func bool(_ value: String?) -> Bool? {
        guard let value else { return nil }
        switch value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "1", "true", "yes", "on": return true
        case "0", "false", "no", "off": return false
        default: return nil
        }
    }
}

enum PatientTransportEnvironment: Equatable {
    case development
    case production

    static var current: PatientTransportEnvironment {
#if DEBUG
        .development
#else
        .production
#endif
    }
}

enum PatientAPIBoundary {
    static let path = "/api/patient/v1"
    static let productionHost = "zephyrus.acumenus.net"

    static func validatedBaseURL(
        _ raw: String?,
        environment: PatientTransportEnvironment = .current
    ) -> URL? {
        guard let raw,
              !raw.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
              let components = URLComponents(string: raw),
              let scheme = components.scheme?.lowercased(),
              let host = components.host,
              components.user == nil,
              components.password == nil,
              components.query == nil,
              components.fragment == nil,
              components.path.isEmpty || components.path == "/"
        else { return nil }

        guard scheme == "https" else { return nil }
        if let port = components.port, !(1...65_535).contains(port) {
            return nil
        }
        if environment == .production {
            guard host.lowercased() == productionHost,
                  components.port == nil || components.port == 443
            else { return nil }
        }

        return components.url
    }
}
