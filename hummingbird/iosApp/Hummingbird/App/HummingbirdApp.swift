import SwiftUI

@main
struct HummingbirdApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var auth: AuthStore
    @StateObject private var profile = ProfileStore()
    @StateObject private var lock = AppLock()
    @StateObject private var push = PushManager.shared
    @StateObject private var eddyContext = EddyContextStore()

    init() {
        let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)
        _auth = StateObject(wrappedValue: AuthStore(api: api))
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .environmentObject(profile)
                .environmentObject(lock)
                .environmentObject(push)
                .environmentObject(eddyContext)
                .preferredColorScheme(.dark)
        }
    }
}

/// Bridges UIKit's APNs callbacks to the PushManager (SwiftUI App has no didRegister hook).
final class AppDelegate: NSObject, UIApplicationDelegate {
    func application(_ application: UIApplication,
                     didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        Task { @MainActor in PushManager.shared.didRegister(deviceToken: deviceToken) }
    }

    func application(_ application: UIApplication,
                     didFailToRegisterForRemoteNotificationsWithError error: Error) {
        // Token simply won't be available (e.g., simulator without APNs); push via the BFF is skipped.
    }
}
