import SwiftUI

/// The signed-in shell: the role's Home + the For You queue. The first tab is role-adaptive —
/// most personas get the census glance; the transporter gets the bespoke "My Trips" home.
struct MainTabView: View {
    @EnvironmentObject var push: PushManager
    @EnvironmentObject var profile: ProfileStore
    @State private var selection: Int

    init() {
        // Test affordance: SIMCTL_CHILD_HB_TAB=foryou|activity opens a tab for screenshots.
        let tab = ProcessInfo.processInfo.environment["HB_TAB"]
        _selection = State(initialValue: tab == "activity" ? 2 : (tab == "foryou" ? 1 : 0))
    }

    private var home: RoleExperience.HomeKind { RoleExperience.of(profile.roleId).home }

    var body: some View {
        TabView(selection: $selection) {
            Group {
                switch home {
                case .census: HomeView()
                case .transportJobs: TransportJobsView()
                case .evsTurns: BedTurnsView()
                case .houseCapacity: HouseCapacityView()
                case .orBoard: ORBoardView()
                case .capacityDemand: CapacityDemandView()
                case .houseBrief: ExecutiveHomeView()
                case .staffing: StaffingView()
                case .improvement: ImprovementView()
                }
            }
            .tag(0)
            .tabItem { Label(home.tabLabel, systemImage: home.tabSymbol) }
            ForYouView()
                .tag(1)
                .tabItem { Label("For You", systemImage: "tray.full.fill") }
            ActivityFeedView()
                .tag(2)
                .tabItem { Label("Activity", systemImage: "waveform.path.ecg.rectangle") }
        }
        .tint(Z.primary)
        .hbScrollEdge()
        .onChange(of: push.deeplinkTab) { _, tab in
            // A tapped push routes here: For You by default, House if specified.
            guard let tab else { return }
            selection = (tab == "house") ? 0 : 1
            push.deeplinkTab = nil
        }
    }
}
