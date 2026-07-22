import SwiftUI

/// The signed-in shell: the role's Home + the For You queue. The first tab is role-adaptive —
/// most personas get the census glance; the transporter gets the bespoke "My Trips" home.
struct MainTabView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var push: PushManager
    @EnvironmentObject var profile: ProfileStore
    @State private var selection: Int

    init() {
        #if DEBUG
        // Test affordance: SIMCTL_CHILD_HB_TAB=foryou|activity|communications opens a tab for screenshots.
        let tab = ProcessInfo.processInfo.environment["HB_TAB"]
        _selection = State(initialValue: tab == "communications" ? 3 : (tab == "activity" ? 2 : (tab == "foryou" ? 1 : 0)))
        #else
        _selection = State(initialValue: 0)
        #endif
    }

    private var home: RoleExperience.HomeKind { RoleExperience.of(profile.roleId).home }
    private var showsPatientCommunications: Bool {
        PatientCommunicationsEligibility.isEligible(auth.me)
    }

    var body: some View {
        Group {
            if showsPatientCommunications {
                communicationsTabView
            } else {
                standardTabView
            }
        }
        .tint(Z.primary)
        .hbScrollEdge()
        .onChange(of: push.deeplinkTab) { _, tab in
            // A tapped push routes here: For You by default, House if specified.
            guard let tab else { return }
            selection = (tab == "house") ? 0 : 1
            push.deeplinkTab = nil
        }
        .onChange(of: showsPatientCommunications) { _, visible in
            if !visible, selection == 3 { selection = 0 }
        }
    }

    /// Keep each TabView's child set structurally stable. Dynamically inserting a
    /// conditional tab can leave a selected tab with an empty host during /me
    /// bootstrap; switching between two complete shells avoids that lifecycle gap.
    private var communicationsTabView: some View {
        TabView(selection: $selection) {
            homeContent
            .tag(0)
            .tabItem { Label(home.tabLabel, systemImage: home.tabSymbol) }
            ForYouView()
                .tag(1)
                .tabItem { Label("For You", systemImage: "tray.full.fill") }
            ActivityFeedView()
                .tag(2)
                .tabItem { Label("Activity", systemImage: "waveform.path.ecg.rectangle") }
            PatientCommunicationsView()
                .tag(3)
                .tabItem { Label("Messages", systemImage: "message.badge.fill") }
        }
    }

    private var standardTabView: some View {
        TabView(selection: $selection) {
            homeContent
                .tag(0)
                .tabItem { Label(home.tabLabel, systemImage: home.tabSymbol) }
            ForYouView()
                .tag(1)
                .tabItem { Label("For You", systemImage: "tray.full.fill") }
            ActivityFeedView()
                .tag(2)
                .tabItem { Label("Activity", systemImage: "waveform.path.ecg.rectangle") }
        }
    }

    @ViewBuilder private var homeContent: some View {
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
}
