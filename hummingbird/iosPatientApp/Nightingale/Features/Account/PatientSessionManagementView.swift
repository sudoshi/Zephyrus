import SwiftUI

struct PatientSessionManagementView: View {
    @ObservedObject var viewModel: PatientAppViewModel
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ZStack {
                PatientPhotoBackground(scene: .sessions)
                    .ignoresSafeArea()

                ScrollView {
                    VStack(alignment: .leading, spacing: 18) {
                        PatientScreenHeader(
                            eyebrow: "Account security",
                            title: "Manage devices",
                            subtitle: "Review the devices currently signed in to Hummingbird Patient."
                        )

                        content
                    }
                    .padding(20)
                }
            }
            .navigationTitle("Devices")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }
                }
            }
        }
        .task {
            await viewModel.openSessionManagement()
        }
        .interactiveDismissDisabled(viewModel.sessionManagementState == .revoking)
        .confirmationDialog(
            confirmationTitle,
            isPresented: revocationConfirmationPresented,
            titleVisibility: .visible,
            presenting: viewModel.selectedSessionForRevocation
        ) { session in
            Button(session.current ? "Sign out here" : "Sign out device", role: .destructive) {
                Task { await viewModel.revokePatientSession(session) }
            }
            .accessibilityIdentifier(
                session.current
                    ? "confirm-current-session-revocation"
                    : "confirm-other-session-revocation"
            )
            Button("Keep signed in", role: .cancel) {
                viewModel.cancelSessionRevocation()
            }
        } message: { session in
            Text(confirmationMessage(for: session))
        }
        .accessibilityIdentifier("patient-session-management")
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.sessionManagementState {
        case .idle:
            PatientPhotoStateCard(
                scene: .sessions,
                icon: "lock.shield",
                title: "Device details are hidden",
                message: "For your privacy, the device list was cleared. Load it again when you are ready.",
                actionTitle: "Load devices"
            ) {
                Task { await viewModel.openSessionManagement() }
            }
            .accessibilityIdentifier("patient-sessions-cleared")

        case .loading:
            PatientCard {
                HStack(spacing: 14) {
                    ProgressView()
                        .accessibilityHidden(true)
                    Text("Opening your device list…")
                        .font(.body.weight(.semibold))
                }
            }
            .accessibilityElement(children: .combine)
            .accessibilityLabel("Opening your device list")

        case .ready, .revoking:
            if let message = viewModel.sessionManagementMessage {
                PatientPhotoStateCard(
                    scene: .sessions,
                    icon: "checkmark.shield.fill",
                    title: "Device security update",
                    message: message
                )
                .accessibilityIdentifier("patient-session-update")
            }

            if viewModel.patientSessions.isEmpty {
                PatientPhotoStateCard(
                    scene: .empty,
                    icon: "iphone.slash",
                    title: "No active devices",
                    message: "No active Hummingbird Patient devices are available to show."
                )
            } else {
                VStack(spacing: 14) {
                    ForEach(viewModel.patientSessions) { session in
                        PatientSessionCard(
                            session: session,
                            isRevoking: viewModel.sessionManagementState == .revoking
                        ) {
                            viewModel.selectSessionForRevocation(session)
                        }
                    }
                }
                .privacySensitive()
            }

            PatientCard {
                Label(
                    "Signing out a device ends its Hummingbird Patient session. It does not change your hospital care or bedside support.",
                    systemImage: "info.circle.fill"
                )
                .font(.subheadline)
                .foregroundStyle(.secondary)
            }
            .accessibilityElement(children: .combine)

        case .disabled:
            PatientPhotoStateCard(
                scene: .empty,
                icon: "lock.shield",
                title: "Device management is not available",
                message: viewModel.sessionManagementMessage
                    ?? "Device management is not available for this account. Your care view is still available."
            )
            .accessibilityIdentifier("patient-sessions-disabled")

        case .unavailable, .failed:
            PatientPhotoStateCard(
                scene: .error,
                icon: "wifi.exclamationmark",
                title: "Devices are not available right now",
                message: viewModel.sessionManagementMessage
                    ?? "Device management is temporarily unavailable. Your care view is still available.",
                actionTitle: "Try again"
            ) {
                Task { await viewModel.openSessionManagement() }
            }
            .accessibilityIdentifier("patient-sessions-unavailable")
        }
    }

    private var revocationConfirmationPresented: Binding<Bool> {
        Binding(
            get: { viewModel.selectedSessionForRevocation != nil },
            set: { isPresented in
                if !isPresented { viewModel.cancelSessionRevocation() }
            }
        )
    }

    private var confirmationTitle: String {
        guard let session = viewModel.selectedSessionForRevocation else {
            return "Sign out this device?"
        }
        return session.current ? "Sign out here?" : "Sign out \(session.safeDeviceName)?"
    }

    private func confirmationMessage(for session: PatientSessionSummary) -> String {
        if session.current {
            return "Signing out this device immediately closes Hummingbird Patient here and returns you to the welcome screen."
        }
        return "This signs out \(session.safeDeviceName) from Hummingbird Patient. It will not sign out this device."
    }
}

private struct PatientSessionCard: View {
    let session: PatientSessionSummary
    let isRevoking: Bool
    let revoke: () -> Void

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 14) {
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: session.deviceSymbol)
                        .font(.title2)
                        .foregroundStyle(PatientPalette.blue)
                        .frame(width: 30)
                        .accessibilityHidden(true)

                    VStack(alignment: .leading, spacing: 5) {
                        HStack(alignment: .firstTextBaseline, spacing: 8) {
                            Text(session.safeDeviceName)
                                .font(.headline)
                            if session.current {
                                Text("This device")
                                    .font(.caption.bold())
                                    .foregroundStyle(PatientPalette.teal)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 4)
                                    .background(PatientPalette.teal.opacity(0.12), in: Capsule())
                            }
                        }

                        Text(session.safeDeviceDetails)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }

                Divider()

                VStack(alignment: .leading, spacing: 7) {
                    Label("Last used \(session.lastSeenDisplay)", systemImage: "clock")
                    Label("Session ends \(session.expiryDisplay)", systemImage: "calendar.badge.clock")
                }
                .font(.subheadline)
                .foregroundStyle(.secondary)

                Button(role: .destructive, action: revoke) {
                    Label(
                        session.current ? "Sign out this device" : "Sign out that device",
                        systemImage: "rectangle.portrait.and.arrow.right"
                    )
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .disabled(isRevoking)
                .accessibilityHint(
                    session.current
                        ? "You will be asked to confirm before returning to the welcome screen"
                        : "You will be asked to confirm before this device is signed out"
                )
                .accessibilityIdentifier(
                    session.current
                        ? "revoke-current-session-\(session.sessionUUID)"
                        : "revoke-other-session-\(session.sessionUUID)"
                )
            }
        }
    }
}

private extension PatientSessionSummary {
    var safeDeviceName: String {
        let trimmed = device.name?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !trimmed.isEmpty { return trimmed }
        return switch device.platform {
        case .ios: "Apple device"
        case .android: "Android device"
        case .web: "Web browser"
        case nil: "Unknown device"
        }
    }

    var safeDeviceDetails: String {
        let platform = switch device.platform {
        case .ios: "iOS"
        case .android: "Android"
        case .web: "Web"
        case nil: "Platform unavailable"
        }
        let osVersion = device.osVersion?.trimmingCharacters(in: .whitespacesAndNewlines)
        return osVersion.map { $0.isEmpty ? platform : "\(platform) \($0)" } ?? platform
    }

    var deviceSymbol: String {
        switch device.platform {
        case .ios: "iphone"
        case .android: "apps.iphone"
        case .web: "globe"
        case nil: "rectangle.dashed"
        }
    }

    var lastSeenDisplay: String {
        lastSeenDate?.formatted(.relative(presentation: .named)) ?? "recently"
    }

    var expiryDisplay: String {
        expiresDate?.formatted(date: .abbreviated, time: .shortened) ?? "at a time not shown"
    }
}
