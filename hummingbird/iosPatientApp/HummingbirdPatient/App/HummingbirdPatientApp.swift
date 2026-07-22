import SwiftUI

@main
struct HummingbirdPatientApp: App {
    @StateObject private var viewModel: PatientAppViewModel

    init() {
        let configuration = PatientAppConfiguration.live()
        _viewModel = StateObject(
            wrappedValue: PatientAppViewModel(
                configuration: configuration,
                api: configuration.makeAPIClient(),
                tokenStore: KeychainPatientTokenStore()
            )
        )
    }

    var body: some Scene {
        WindowGroup {
            PatientPrivacyProtectedRoot(viewModel: viewModel)
            .task {
                await viewModel.bootstrap()
            }
        }
    }
}

private struct PatientPrivacyProtectedRoot: View {
    @ObservedObject var viewModel: PatientAppViewModel
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var presentationPreferences: PatientPresentationPreferences {
        PatientPresentationPreferences(viewModel.patientPreferences)
    }

    var body: some View {
        ZStack {
            PatientRootView(viewModel: viewModel)

            if privacyCoverVisible {
                PatientPrivacyCoverView()
                    .transition(effectiveReduceMotion ? .identity : .opacity)
                    .zIndex(100)
            }
        }
        .animation(
            effectiveReduceMotion ? nil : .easeOut(duration: 0.12),
            value: scenePhase
        )
        .patientPresentation(viewModel.patientPreferences)
        .onChange(of: scenePhase) { _, newPhase in
            if newPhase == .background {
                viewModel.protectPatientSessionRowsForBackground()
            }
        }
    }

    private var privacyCoverVisible: Bool {
        #if DEBUG
        scenePhase != .active
            || ProcessInfo.processInfo.environment["HBP_SHOW_PRIVACY_COVER"] == "1"
        #else
        scenePhase != .active
        #endif
    }

    private var effectiveReduceMotion: Bool {
        reduceMotion || presentationPreferences.reducedMotion
    }
}
