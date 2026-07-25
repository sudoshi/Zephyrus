import SwiftUI

struct PatientRootView: View {
    @ObservedObject var viewModel: PatientAppViewModel

    private var presentationPreferences: PatientPresentationPreferences {
        PatientPresentationPreferences(viewModel.patientPreferences)
    }

    var body: some View {
        NavigationStack {
            Group {
                if let noActiveEncounter = viewModel.noActiveEncounter {
                    PatientNoActiveEncounterView(
                        state: noActiveEncounter,
                        onRetry: { Task { await viewModel.retry() } },
                        onExit: { Task { await viewModel.signOut() } }
                    )
                } else if let snapshot = viewModel.snapshot {
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

private struct PatientNoActiveEncounterView: View {
    let state: PatientNoActiveEncounterState
    let onRetry: () -> Void
    let onExit: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                PatientScreenHeader(
                    eyebrow: "Care access",
                    title: "Hello, \(firstName)",
                    subtitle: "No active hospital stay"
                )
                PatientCard {
                    VStack(alignment: .leading, spacing: 10) {
                        Label("Your care view is not available", systemImage: "lock.shield")
                            .font(.headline)
                            .foregroundStyle(PatientPalette.blue)
                        Text(state.message)
                            .font(.body)
                        Text("No care information is shown while your account has no active hospital stay.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                PatientUrgentHelpNotice()
                Button("Check again", action: onRetry)
                    .buttonStyle(.borderedProminent)
                    .frame(maxWidth: .infinity)
                    .accessibilityHint("Checks whether a current hospital stay is available. No patient message is sent.")
                Button("Exit securely", action: onExit)
                    .buttonStyle(.bordered)
                    .frame(maxWidth: .infinity)
                    .accessibilityHint("Clears this device's patient session.")
            }
            .padding(22)
        }
        .background {
            PatientPhotoBackground(scene: .empty)
                .ignoresSafeArea()
        }
        .navigationTitle("Patient access")
        .navigationBarTitleDisplayMode(.inline)
        .accessibilityIdentifier("patient-no-active-encounter")
    }

    private var firstName: String {
        state.displayName
            .split(separator: " ")
            .first
            .map(String.init) ?? "there"
    }
}
