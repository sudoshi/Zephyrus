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
                PasswordChangeNoticeView()
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
            if let roleId = env["HB_ROLE"], Role.by(id: roleId) != nil,
               let me = auth.me, !profile.isOnboarded(userId: me.id) {
                profile.confirm(userId: me.id, roleId: roleId, unitId: nil,
                                unitName: env["HB_ONBOARD_UNIT_NAME"] ?? "House-wide")
            }
        }
    }
}

/// v1 placeholder: the must-change-password challenge is honored by the backend; the full
/// in-app change flow lands in a later phase. For now we explain and let the user sign out.
struct PasswordChangeNoticeView: View {
    @EnvironmentObject var auth: AuthStore

    var body: some View {
        VStack(spacing: Z.s4) {
            Image(systemName: "lock.rotation")
                .font(.system(size: 40, weight: .semibold))
                .foregroundStyle(Z.gold)
            Text("Password change required")
                .font(.system(size: 20, weight: .semibold))
                .foregroundStyle(Z.ink)
            Text("Your account must set a new password before using Hummingbird. Finish this on the web app, then sign in again.")
                .font(.system(size: 14))
                .foregroundStyle(Z.inkMuted)
                .multilineTextAlignment(.center)
            Button("Back to sign in") { Task { await auth.logout() } }
                .buttonStyle(.borderedProminent)
                .tint(Z.primary)
        }
        .padding(Z.s6)
    }
}
