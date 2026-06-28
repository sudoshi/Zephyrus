import UIKit
import UserNotifications

/// Owns push-notification authorization, APNs registration, and incoming-notification routing.
/// Payloads are PHI-free (generic copy + a `tab` deep-link), per the earned-urgency taxonomy.
/// A singleton so the UIApplicationDelegate (which receives the APNs token) can reach it.
@MainActor
final class PushManager: NSObject, ObservableObject {
    static let shared = PushManager()

    @Published var status: UNAuthorizationStatus = .notDetermined
    /// Set when a notification is tapped; MainTabView consumes it to switch tabs.
    @Published var deeplinkTab: String?
    private(set) var apnsToken: String?

    /// Wired by the app to push the APNs token to the BFF once it's known + the user is signed in.
    var onToken: ((String) -> Void)?

    private override init() { super.init() }

    func bootstrap() {
        UNUserNotificationCenter.current().delegate = self
        Task { await refreshStatus(); registerIfAuthorized() }
    }

    func refreshStatus() async {
        status = await UNUserNotificationCenter.current().notificationSettings().authorizationStatus
    }

    /// Ask permission; on grant, register for remote notifications (APNs token arrives via the delegate).
    func requestAuthorization() async {
        let granted = (try? await UNUserNotificationCenter.current()
            .requestAuthorization(options: [.alert, .badge, .sound])) ?? false
        await refreshStatus()
        if granted { UIApplication.shared.registerForRemoteNotifications() }
    }

    /// On launch, re-acquire the token if the user previously authorized.
    func registerIfAuthorized() {
        if status == .authorized || status == .provisional {
            UIApplication.shared.registerForRemoteNotifications()
        }
    }

    func didRegister(deviceToken: Data) {
        let hex = deviceToken.map { String(format: "%02x", $0) }.joined()
        apnsToken = hex
        onToken?(hex)
    }
}

extension PushManager: UNUserNotificationCenterDelegate {
    // Show banners while the app is foregrounded.
    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter,
                                            willPresent notification: UNNotification) async
        -> UNNotificationPresentationOptions {
        [.banner, .sound, .badge]
    }

    // Route a tapped notification to the relevant tab (default: For You).
    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter,
                                            didReceive response: UNNotificationResponse) async {
        let tab = response.notification.request.content.userInfo["tab"] as? String ?? "foryou"
        await MainActor.run { PushManager.shared.deeplinkTab = tab }
    }
}
