import SwiftUI

@main
struct HummingbirdApp: App {
    @StateObject private var auth: AuthStore
    @StateObject private var profile = ProfileStore()

    init() {
        let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)
        _auth = StateObject(wrappedValue: AuthStore(api: api))
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .environmentObject(profile)
                .preferredColorScheme(.dark)
        }
    }
}
