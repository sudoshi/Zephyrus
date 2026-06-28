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
                if let me = auth.me, !profile.isOnboarded(userId: me.id) {
                    OnboardingView()
                } else {
                    MainTabView()
                }
            }
        }
        .onChange(of: auth.me?.id) { _, id in
            if let id { profile.load(userId: id) }
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
}

