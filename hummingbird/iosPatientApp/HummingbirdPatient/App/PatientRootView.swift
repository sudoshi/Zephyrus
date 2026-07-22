import SwiftUI

struct PatientRootView: View {
    @ObservedObject var viewModel: PatientAppViewModel

    private var presentationPreferences: PatientPresentationPreferences {
        PatientPresentationPreferences(viewModel.patientPreferences)
    }

    var body: some View {
        NavigationStack {
            Group {
                if let snapshot = viewModel.snapshot {
                    PatientTabShellView(viewModel: viewModel, snapshot: snapshot) {
                        Task { await viewModel.signOut() }
                    }
                } else {
                    PatientWelcomeView(viewModel: viewModel)
                }
            }
            .overlay {
                if viewModel.isBusy {
                    PatientLoadingStateView()
                        .ignoresSafeArea()
                        .zIndex(50)
                }
            }
        }
        .accessibilityIdentifier(presentationPreferences.accessibilityIdentifier)
    }
}
