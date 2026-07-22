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
            // The persistent Hummingbird artwork carousel — the app's living background,
            // behind every screen (the login screen paints its own on top). Frosted panels
            // and translucent screen scrims let it read through the whole app.
            HummingbirdBackdrop()

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
                        // Eddy (left) + profile (right) are matched chrome avatars, overlaid on
                        // the shell so they align exactly and neither is clipped by a nav bar.
                        .overlay(alignment: .topLeading) { EddyAccessButton() }
                        .overlay(alignment: .topTrailing) { ProfileAccessButton() }
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
            #if DEBUG
            let env = ProcessInfo.processInfo.environment
            if StaffCommunicationsUITestMode.isEnabled {
                auth.installPatientCommunicationsUITestSession()
            } else if case .loading = auth.phase {
                await auth.bootstrap()
            }

            // Test/demo affordance: SIMCTL_CHILD_HB_AUTOLOGIN=1 lets UI tests and
            // headless screenshots land on Home without a manual tap. The credentials
            // must be supplied explicitly; neither the app nor this hook has defaults.
            // Test affordance: SIMCTL_CHILD_HB_SHOWLOGIN=1 forces the sign-in screen even if a
            // token is cached (useful for screenshots/QA).
            if env["HB_SHOWLOGIN"] == "1" { await auth.logout() }
            if env["HB_AUTOLOGIN"] == "1",
               let username = env["HB_USER"], !username.isEmpty,
               let password = env["HB_PASS"], !password.isEmpty,
               case .loggedOut = auth.phase {
                await auth.login(username: username, password: password)
            }
            // Test affordance: SIMCTL_CHILD_HB_ROLE=<id> pre-confirms onboarding so screenshots
            // can land past it.
            // (Overrides any prior confirmation so role-specific surfaces can be exercised per launch.)
            if let roleId = env["HB_ROLE"], Role.by(id: roleId) != nil, let me = auth.me {
                profile.confirm(userId: me.id, roleId: roleId,
                                unitId: env["HB_ONBOARD_UNIT"].flatMap { Int($0) },
                                unitName: env["HB_ONBOARD_UNIT_NAME"] ?? "House-wide")
            }
            // Test affordance: SIMCTL_CHILD_HB_LOCK=1 engages the lock screen for QA/screenshots
            // even without enrolled biometrics (pair with HB_NO_AUTOUNLOCK=1).
            if env["HB_LOCK"] == "1", isLoggedIn { lock.isLocked = true }
            // Test affordance: SIMCTL_CHILD_HB_DEMO_LIVE_ACTIVITY=1 starts a demo trip Live
            // Activity so the island/lock-screen UI can be screenshot-verified.
            JobActivityController.startDemoIfRequested()
            #else
            if case .loading = auth.phase { await auth.bootstrap() }
            #endif

            // The real cold-launch lock behavior is part of every configuration.
            // Engage it after any debug setup so a cached production session never bypasses it.
            if isLoggedIn, lock.enabled { lock.isLocked = true }

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

            #if DEBUG
            // Test affordance: SIMCTL_CHILD_HB_ASK_PUSH=1 triggers the permission prompt.
            if env["HB_ASK_PUSH"] == "1" { await push.requestAuthorization() }
            #endif
        }
    }

    private func isSuperuser(_ me: MeData) -> Bool {
        me.isAdmin || me.workflowPreference == "superuser"
    }
}

/// The persistent Hummingbird artwork carousel — the app's living background. Crossfades
/// through the auth photography every ~9.5s (Reduce-Motion holds on one frame), with a
/// dim scrim so foreground content stays legible over the photography. Self-timed so any
/// screen can sit on it; non-interactive and accessibility-hidden.
struct HummingbirdBackdrop: View {
    var dim: Double = 0.42
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var index = 0

    private static let slides = [
        "AuthHummingbird10", "AuthHummingbird05", "AuthHummingbird04", "AuthHummingbird11",
        "AuthHummingbird12", "AuthHummingbird01", "AuthHummingbird08", "AuthHummingbird09",
        "AuthHummingbird06", "AuthHummingbird03", "AuthHummingbird02", "AuthHummingbird07",
    ]
    private let timer = Timer.publish(every: 9.5, on: .main, in: .common).autoconnect()

    var body: some View {
        GeometryReader { proxy in
            ZStack {
                Color(red: 0.02, green: 0.04, blue: 0.06)

                ForEach(Array(Self.slides.enumerated()), id: \.offset) { position, asset in
                    Image(asset)
                        .resizable()
                        .scaledToFill()
                        .frame(width: proxy.size.width, height: proxy.size.height)
                        .clipped()
                        .opacity(position == index ? 1 : 0)
                }

                // Legibility scrim: a top-weighted gradient (behind the status/nav chrome)
                // plus a flat wash. Photo stays clearly visible; text stays readable.
                LinearGradient(
                    colors: [Color.black.opacity(dim + 0.16), Color.black.opacity(dim)],
                    startPoint: .top, endPoint: .bottom
                )
                Color.black.opacity(dim * 0.5)
            }
            .animation(.easeInOut(duration: 1.6), value: index)
        }
        .ignoresSafeArea()
        .onReceive(timer) { _ in
            guard !reduceMotion else { return }
            index = (index + 1) % Self.slides.count
        }
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }
}
