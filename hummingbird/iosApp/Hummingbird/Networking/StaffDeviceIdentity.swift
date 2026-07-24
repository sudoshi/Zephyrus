import Foundation
import UIKit

enum StaffDeviceIdentityError: LocalizedError {
    case unavailable

    var errorDescription: String? {
        "Hummingbird could not establish an identity for this app installation."
    }
}

/// A stable identifier for one installed copy of Hummingbird. It is not a
/// credential or attestation claim. UserDefaults intentionally gives it
/// reinstall semantics while the access/refresh pair remains Keychain-only.
struct StaffAuthDevice: Equatable, Sendable {
    let installationUUID: String
    let platform: String
    let name: String?
    let appVersion: String?
    let osVersion: String?

    var requestBody: [String: Any] {
        var result: [String: Any] = [
            "installation_uuid": installationUUID,
            "platform": platform,
        ]
        if let name = StaffDeviceIdentity.boundedMetadata(name, maxLength: 120) {
            result["name"] = name
        }
        if let appVersion = StaffDeviceIdentity.boundedMetadata(appVersion, maxLength: 80) {
            result["app_version"] = appVersion
        }
        if let osVersion = StaffDeviceIdentity.boundedMetadata(osVersion, maxLength: 80) {
            result["os_version"] = osVersion
        }
        return result
    }

    static func current(defaults: UserDefaults = .standard) throws -> StaffAuthDevice {
        let key = "staffInstallationUUID.v1"
        let installationUUID: String

        if let stored = defaults.string(forKey: key),
           let uuid = UUID(uuidString: stored),
           stored == uuid.uuidString.lowercased() {
            installationUUID = stored
        } else {
            let generated = UUID().uuidString.lowercased()
            defaults.set(generated, forKey: key)
            guard defaults.string(forKey: key) == generated else {
                throw StaffDeviceIdentityError.unavailable
            }
            installationUUID = generated
        }

        return StaffAuthDevice(
            installationUUID: installationUUID,
            platform: "ios",
            name: UIDevice.current.name,
            appVersion: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String,
            osVersion: "iOS \(UIDevice.current.systemVersion)"
        )
    }
}

enum StaffDeviceIdentity {
    static func boundedMetadata(_ value: String?, maxLength: Int) -> String? {
        guard maxLength > 0 else { return nil }
        let normalized = value?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        guard !normalized.isEmpty else { return nil }
        return String(normalized.unicodeScalars.prefix(maxLength))
    }
}
