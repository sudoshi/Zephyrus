import SwiftUI

struct RootView: View {
    @EnvironmentObject var auth: AuthStore

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
                HomeView()
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
