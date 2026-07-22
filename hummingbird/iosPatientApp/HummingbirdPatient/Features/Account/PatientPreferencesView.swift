import SwiftUI

struct PatientPreferencesView: View {
    @ObservedObject var viewModel: PatientAppViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var textSize: PatientTextSizePreference
    @State private var reducedMotion: Bool
    @State private var highContrast: Bool
    @State private var notificationPreview: PatientNotificationPreviewPreference
    @State private var preferredChannel: PatientPreferredChannel

    init(viewModel: PatientAppViewModel) {
        self.viewModel = viewModel
        let preferences = viewModel.patientPreferences
        _textSize = State(initialValue: preferences.textSize ?? .standard)
        _reducedMotion = State(initialValue: preferences.reducedMotion ?? false)
        _highContrast = State(initialValue: preferences.highContrast ?? false)
        _notificationPreview = State(initialValue: preferences.notificationPreview ?? .hidden)
        _preferredChannel = State(initialValue: preferences.preferredChannel ?? .push)
    }

    var body: some View {
        NavigationStack {
            ZStack {
                PatientPhotoBackground(scene: .sessions)
                    .ignoresSafeArea()

                Form {
                    Section {
                        Text("Choose how Hummingbird Patient presents non-clinical account information. These choices never change your care plan, clinical orders, or urgent-help instructions.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }

                    Section("Reading and movement") {
                        Picker("Text size", selection: $textSize) {
                            Text("Standard").tag(PatientTextSizePreference.standard)
                            Text("Large").tag(PatientTextSizePreference.large)
                            Text("Extra large").tag(PatientTextSizePreference.extraLarge)
                        }
                        .accessibilityIdentifier("patient-preference-text-size")

                        Toggle("Reduce motion", isOn: $reducedMotion)
                            .accessibilityIdentifier("patient-preference-reduced-motion")
                        Toggle("Prefer high contrast", isOn: $highContrast)
                            .accessibilityIdentifier("patient-preference-high-contrast")

                        Text("Hummingbird also respects the accessibility settings on this device.")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }

                    Section("Notifications") {
                        Picker("Notification preview", selection: $notificationPreview) {
                            Text("Hide details").tag(PatientNotificationPreviewPreference.hidden)
                            Text("Use a general preview").tag(PatientNotificationPreviewPreference.generic)
                        }
                        .accessibilityIdentifier("patient-preference-notification-preview")

                        Picker("Preferred delivery", selection: $preferredChannel) {
                            Text("App notification").tag(PatientPreferredChannel.push)
                            Text("Email").tag(PatientPreferredChannel.email)
                        }
                        .accessibilityIdentifier("patient-preference-delivery-channel")

                        Text("This records a preference; it does not guarantee delivery, replace bedside communication, or change emergency guidance.")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }

                    if let message = viewModel.preferencesMessage {
                        Section {
                            VStack(alignment: .leading, spacing: 8) {
                                Label(message, systemImage: "checkmark.shield.fill")
                                    .foregroundStyle(.secondary)
                                if textSize == .extraLarge && highContrast {
                                    Text("Extra Large text and high contrast are applied in Hummingbird Patient. Your device accessibility settings remain in effect.")
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                        .accessibilityIdentifier("patient-preferences-applied-accessibility")
                                }
                            }
                        }
                        .accessibilityIdentifier("patient-preferences-status")
                    }
                }
                .scrollContentBackground(.hidden)
            }
            .navigationTitle("Preferences")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        Task {
                            await viewModel.savePreferences(
                                PatientPreferencesInput(
                                    textSize: textSize,
                                    reducedMotion: reducedMotion,
                                    highContrast: highContrast,
                                    notificationPreview: notificationPreview,
                                    preferredChannel: preferredChannel
                                )
                            )
                        }
                    }
                    .disabled(viewModel.isSavingPreferences)
                    .accessibilityIdentifier("save-patient-preferences")
                }
            }
        }
        .accessibilityIdentifier("patient-preferences")
    }
}
