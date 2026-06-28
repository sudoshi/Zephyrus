import LocalAuthentication

/// Thin wrapper over LocalAuthentication for the app-lock. Uses `.deviceOwnerAuthentication`
/// so it prefers Face ID / Touch ID but falls back to the device passcode, and works on
/// devices without enrolled biometrics.
enum BiometricAuth {
    /// The device's biometry kind (faceID / touchID / opticID / none).
    static var biometryType: LABiometryType {
        let ctx = LAContext()
        _ = ctx.canEvaluatePolicy(.deviceOwnerAuthentication, error: nil)
        return ctx.biometryType
    }

    /// Whether the device can authenticate the owner at all (biometry or passcode).
    static var isAvailable: Bool {
        LAContext().canEvaluatePolicy(.deviceOwnerAuthentication, error: nil)
    }

    /// Prompt the owner. Returns true on success, false on cancel/failure.
    static func authenticate(reason: String) async -> Bool {
        let ctx = LAContext()
        ctx.localizedFallbackTitle = "Use Passcode"
        do {
            return try await ctx.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: reason)
        } catch {
            return false
        }
    }

    static var label: String {
        switch biometryType {
        case .faceID: return "Face ID"
        case .touchID: return "Touch ID"
        case .opticID: return "Optic ID"
        default: return "device passcode"
        }
    }

    static var symbol: String {
        switch biometryType {
        case .faceID: return "faceid"
        case .touchID: return "touchid"
        case .opticID: return "opticid"
        default: return "lock.fill"
        }
    }
}
