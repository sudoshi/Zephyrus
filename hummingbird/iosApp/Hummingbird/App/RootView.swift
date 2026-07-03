import SwiftUI
import UIKit

struct RootView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @EnvironmentObject var lock: AppLock
    @EnvironmentObject var push: PushManager
    @Environment(\.scenePhase) private var scenePhase

    private var isLoggedIn: Bool { if case .loggedIn = auth.phase { return true } else { return false } }

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
        .overlay {
            // Biometric app-lock covers everything once a session exists and the lock is engaged.
            // (isLocked is only set through enabled-gated paths, so this implies the feature is on.)
            if isLoggedIn, lock.isLocked {
                LockView()
            }
        }
        .onChange(of: scenePhase) { _, phase in
            // Re-lock when leaving the foreground so returning requires auth.
            if phase == .background { lock.lockIfEnabled() }
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
            // Test affordance: SIMCTL_CHILD_HB_SHOWLOGIN=1 forces the sign-in screen even if a
            // token is cached (useful for screenshots/QA). No-op in production.
            if env["HB_SHOWLOGIN"] == "1" { await auth.logout() }
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
            // Cold-launch into a cached session → engage the lock so it requires auth.
            if isLoggedIn, lock.enabled { lock.isLocked = true }
            // Test affordance: SIMCTL_CHILD_HB_LOCK=1 engages the lock screen for QA/screenshots
            // even without enrolled biometrics (pair with HB_NO_AUTOUNLOCK=1). No-op in production.
            if env["HB_LOCK"] == "1", isLoggedIn { lock.isLocked = true }
            #if DEBUG
            // Test affordance: SIMCTL_CHILD_HB_DEMO_LIVE_ACTIVITY=1 starts a demo trip Live
            // Activity so the island/lock-screen UI can be screenshot-verified.
            JobActivityController.startDemoIfRequested()
            #endif

            // Push: register the APNs token with the BFF once it arrives and we have a session.
            push.onToken = { token in
                guard let bearer = auth.accessToken else { return }
                let appVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String
                Task {
                    try? await auth.api.registerDevice(
                        pushToken: token, appVersion: appVersion,
                        osVersion: UIDevice.current.systemVersion,
                        deviceName: UIDevice.current.name, bearer: bearer)
                }
            }
            push.bootstrap()
            // Test affordance: SIMCTL_CHILD_HB_ASK_PUSH=1 triggers the permission prompt. No-op in prod.
            if env["HB_ASK_PUSH"] == "1" { await push.requestAuthorization() }
        }
    }

    private func isSuperuser(_ me: MeData) -> Bool {
        me.isAdmin || me.workflowPreference == "superuser"
    }
}

