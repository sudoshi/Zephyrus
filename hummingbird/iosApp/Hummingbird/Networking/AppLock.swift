import Foundation

/// Opt-in biometric app-lock. When enabled, the app locks on every foreground entry (cold
/// launch + return from background) and requires Face ID / Touch ID (or passcode) to reveal
/// content. Honors PHI-adjacency without blocking demos by default (off until the user opts in).
@MainActor
final class AppLock: ObservableObject {
    /// True while the lock screen should cover content.
    @Published var isLocked = false
    /// User preference, persisted across launches.
    @Published var enabled: Bool { didSet { defaults.set(enabled, forKey: Self.key) } }
    /// True while a biometric prompt is in flight (so the lock screen can reflect it).
    @Published var authenticating = false

    private let defaults = UserDefaults.standard
    private static let key = "hb.applock.enabled"

    init() {
        enabled = defaults.bool(forKey: Self.key)
    }

    /// Engage the lock if the feature is on (called on launch + when leaving the foreground).
    func lockIfEnabled() {
        if enabled { isLocked = true }
    }

    /// Turning the lock on engages it immediately so the next foreground requires auth; turning
    /// it off clears any active lock.
    func setEnabled(_ on: Bool) {
        enabled = on
        isLocked = on ? isLocked : false
    }

    /// Prompt the owner and unlock on success.
    func authenticate() async {
        guard isLocked, !authenticating else { return }
        authenticating = true
        defer { authenticating = false }
        if await BiometricAuth.authenticate(reason: "Unlock Hummingbird") {
            isLocked = false
        }
    }
}
