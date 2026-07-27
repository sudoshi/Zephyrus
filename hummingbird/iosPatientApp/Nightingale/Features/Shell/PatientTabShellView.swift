import SwiftUI

struct PatientTabShellView: View {
    @ObservedObject var viewModel: PatientAppViewModel
    let snapshot: PatientExperienceSnapshot
    let signOut: () -> Void
    @State private var selection: PatientTab
    @State private var isManagingDevices = false
    @State private var isManagingPreferences = false
    @State private var isShowingAccountOptions = false

    init(
        viewModel: PatientAppViewModel,
        snapshot: PatientExperienceSnapshot,
        signOut: @escaping () -> Void
    ) {
        self.viewModel = viewModel
        self.snapshot = snapshot
        self.signOut = signOut
        #if DEBUG
        let requested = ProcessInfo.processInfo.environment["HBP_INITIAL_TAB"]
        _selection = State(initialValue: PatientTab(rawValue: requested ?? "") ?? .today)
        #else
        _selection = State(initialValue: .today)
        #endif
    }

    var body: some View {
        TabView(selection: $selection) {
            PatientTodayView(snapshot: snapshot)
            .tabItem { Label("Today", systemImage: "sun.max.fill") }
            .tag(PatientTab.today)

            PatientPathView(snapshot: snapshot, viewModel: viewModel)
            .tabItem { Label("My Path", systemImage: "point.topleft.down.to.point.bottomright.curvepath") }
            .tag(PatientTab.path)

            PatientCareTeamView(snapshot: snapshot)
            .tabItem { Label("Care Team", systemImage: "person.3.fill") }
            .tag(PatientTab.careTeam)

            PatientMessagesView(snapshot: snapshot, viewModel: viewModel)
            .tabItem { Label("Messages", systemImage: "message.fill") }
            .tag(PatientTab.messages)
        }
        .navigationTitle(selection.title)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar { accountMenu }
        .confirmationDialog(
            "Account options",
            isPresented: $isShowingAccountOptions,
            titleVisibility: .visible
        ) {
            Button("Preferences", systemImage: "textformat.size") {
                isManagingPreferences = true
            }
            .accessibilityIdentifier("manage-preferences")
            Button("Manage devices", systemImage: "iphone.gen3") {
                isManagingDevices = true
            }
            .accessibilityIdentifier("manage-devices")
            Button("Sign out", role: .destructive, action: signOut)
        } message: {
            Text("Choose account settings or manage the devices signed in to Hummingbird Patient.")
        }
        .tint(PatientPalette.blue)
        .sheet(isPresented: $isManagingDevices, onDismiss: {
            viewModel.dismissSessionManagement()
        }) {
            PatientSessionManagementView(viewModel: viewModel)
        }
        .sheet(isPresented: $isManagingPreferences) {
            PatientPreferencesView(viewModel: viewModel)
        }
    }

    @ToolbarContentBuilder
    private var accountMenu: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Button {
                isShowingAccountOptions = true
            } label: {
                Image(systemName: "person.crop.circle")
            }
            .accessibilityIdentifier("account-options")
            .accessibilityLabel("Account options")
            .accessibilityHint("Open preferences, signed-in devices, and sign-out options.")
        }
    }
}

private enum PatientTab: String {
    case today
    case path
    case careTeam = "care-team"
    case messages

    var title: String {
        switch self {
        case .today: "Today"
        case .path: "My Path"
        case .careTeam: "Care Team"
        case .messages: "Messages"
        }
    }
}
