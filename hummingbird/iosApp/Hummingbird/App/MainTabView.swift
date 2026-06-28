import SwiftUI

/// The signed-in shell: House Status + the For You queue.
struct MainTabView: View {
    @EnvironmentObject var push: PushManager
    @State private var selection: Int

    init() {
        // Test affordance: SIMCTL_CHILD_HB_TAB=foryou opens the For You tab for screenshots.
        _selection = State(initialValue: ProcessInfo.processInfo.environment["HB_TAB"] == "foryou" ? 1 : 0)
    }

    var body: some View {
        TabView(selection: $selection) {
            HomeView()
                .tag(0)
                .tabItem { Label("House", systemImage: "building.2.fill") }
            ForYouView()
                .tag(1)
                .tabItem { Label("For You", systemImage: "tray.full.fill") }
        }
        .tint(Z.primary)
        .onChange(of: push.deeplinkTab) { _, tab in
            // A tapped push routes here: For You by default, House if specified.
            guard let tab else { return }
            selection = (tab == "house") ? 0 : 1
            push.deeplinkTab = nil
        }
    }
}
