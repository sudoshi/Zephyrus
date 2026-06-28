import SwiftUI

struct RootView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore

    var body: some View {
        ZStack {
            Z.bg.ignoresSafeArea()

            switch auth.phase {
            case .loading:
                ProgressView().tint(Z.primary)
            case .loggedOut:
                LoginView()
            case .needsPasswordChange:
                ChangePasswordView()
            case .loggedIn:
                if let me = auth.me, !profile.isOnboarded(userId: me.id), !isSuperuser(me) {
                    OnboardingView()
                } else {
                    MainTabView()
                }
            }
        }
        .onChange(of: auth.me?.id) { _, id in
            guard let id else { return }
            profile.load(userId: id)
            // Demo / admin accounts skip onboarding: drop straight into a house-wide view and
            // use the in-app persona switcher (Profile) to explore each role's interface.
            if let me = auth.me, !profile.isOnboarded(userId: id), isSuperuser(me) {
                profile.confirm(userId: id, roleId: "house_supervisor", unitId: nil, unitName: "House-wide")
            }
        }
        .task {
            if case .loading = auth.phase { await auth.bootstrap() }
            // Test/demo affordance: SIMCTL_CHILD_HB_AUTOLOGIN=1 lets UI tests and
            // headless screenshots land on Home without a manual tap. No-op in production.
            let env = ProcessInfo.processInfo.environment
            if env["HB_AUTOLOGIN"] == "1", case .loggedOut = auth.phase {
                await auth.login(username: env["HB_USER"] ?? "demo",
                                 password: env["HB_PASS"] ?? "Password123!")
            }
            // Test affordance: SIMCTL_CHILD_HB_ROLE=<id> pre-confirms onboarding so screenshots
            // can land past it. No-op in production.
            // (Overrides any prior confirmation so role-specific surfaces can be exercised per launch.)
            if let roleId = env["HB_ROLE"], Role.by(id: roleId) != nil, let me = auth.me {
                profile.confirm(userId: me.id, roleId: roleId,
                                unitId: env["HB_ONBOARD_UNIT"].flatMap { Int($0) },
                                unitName: env["HB_ONBOARD_UNIT_NAME"] ?? "House-wide")
            }
        }
    }

    private func isSuperuser(_ me: MeData) -> Bool {
        me.isAdmin || me.workflowPreference == "superuser"
    }
}

