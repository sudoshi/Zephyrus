import SwiftUI

enum StaffSessionsUITestMode {
    #if DEBUG
    static var isEnabled: Bool {
        ProcessInfo.processInfo.arguments.contains("-HBStaffSessionsUITest")
            && ProcessInfo.processInfo.environment["HB_STAFF_SESSIONS_UI_TEST"] == "1"
    }

    static var sessions: [StaffSession] {
        [
            StaffSession(
                sessionUuid: "11111111-1111-4111-8111-111111111111",
                current: true,
                status: "active",
                device: StaffSessionDevice(
                    platform: "ios",
                    name: "Rounds iPhone",
                    appVersion: "0.1.0",
                    osVersion: "iOS 26.3"
                ),
                environment: "production",
                lastSeenAt: "2026-07-23T22:55:00Z",
                expiresAt: "2026-08-22T22:55:00Z",
                createdAt: "2026-07-23T22:00:00Z"
            ),
            StaffSession(
                sessionUuid: "22222222-2222-4222-8222-222222222222",
                current: false,
                status: "active",
                device: StaffSessionDevice(
                    platform: "android",
                    name: "Unit tablet",
                    appVersion: "0.1.0",
                    osVersion: "Android 16"
                ),
                environment: "production",
                lastSeenAt: "2026-07-22T18:15:00Z",
                expiresAt: "2026-08-21T18:15:00Z",
                createdAt: "2026-07-20T12:00:00Z"
            ),
        ]
    }
    #else
    static let isEnabled = false
    #endif
}

struct StaffSessionsView: View {
    @EnvironmentObject private var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    @State private var sessions: [StaffSession] = []
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var pendingRevocation: StaffSession?
    @State private var revokingUUID: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                guidance

                if isLoading {
                    ProgressView("Checking signed-in devices…")
                        .frame(maxWidth: .infinity, alignment: .center)
                        .padding(.vertical, Z.s6)
                        .accessibilityIdentifier("staffSessions.loading")
                } else if let errorMessage {
                    errorState(errorMessage)
                } else if sessions.isEmpty {
                    Panel {
                        Text("No active Hummingbird sessions were found.")
                            .font(.system(size: 15))
                            .foregroundStyle(Z.inkMuted)
                    }
                    .accessibilityIdentifier("staffSessions.empty")
                } else {
                    ForEach(sessions) { session in
                        sessionCard(session)
                    }
                }
            }
            .padding(Z.s4)
        }
        .background { HummingbirdBackdrop(dim: 0.46) }
        .navigationTitle("Signed-in devices")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .refreshable { await load() }
        .alert(
            pendingRevocation?.current == true ? "Sign out this device?" : "Revoke this session?",
            isPresented: Binding(
                get: { pendingRevocation != nil },
                set: { if !$0 { pendingRevocation = nil } }
            ),
            presenting: pendingRevocation
        ) { session in
            Button(session.current ? "Sign out this device" : "Revoke session", role: .destructive) {
                Task { await revoke(session) }
            }
            Button("Cancel", role: .cancel) {}
        } message: { session in
            Text(
                session.current
                    ? "Hummingbird will erase this device's protected credentials and cached operational data."
                    : "That device will need to sign in again. Other devices stay signed in."
            )
        }
    }

    private var guidance: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s2) {
                Label("Your Hummingbird sessions", systemImage: "lock.iphone")
                    .font(.headline)
                    .foregroundStyle(Z.ink)
                Text("Review devices that can use your staff account. Revoke anything you do not recognize.")
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                Text("For privacy, Hummingbird does not show network addresses, token details, or patient information here.")
                    .font(.caption)
                    .foregroundStyle(Z.inkMuted)
            }
        }
        .accessibilityIdentifier("staffSessions.guidance")
    }

    private func sessionCard(_ session: StaffSession) -> some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack(alignment: .top, spacing: Z.s3) {
                    Image(systemName: session.device.platform == "android" ? "rectangle.fill.on.rectangle.fill" : "iphone")
                        .font(.system(size: 22))
                        .foregroundStyle(Z.primary)
                        .frame(width: 28)
                        .accessibilityHidden(true)
                    VStack(alignment: .leading, spacing: 3) {
                        Text(session.device.name?.nonempty ?? session.platformDisplayName)
                            .font(.headline)
                            .foregroundStyle(Z.ink)
                        Text(session.current ? "This device" : session.platformDisplayName)
                            .font(.caption)
                            .fontWeight(session.current ? .semibold : .regular)
                            .foregroundStyle(session.current ? Z.status(.success) : Z.inkMuted)
                    }
                    Spacer()
                }

                Divider().overlay(Z.border)
                detailRow("Last used", displayDate(session.lastSeenAt))
                detailRow("Signed in", displayDate(session.createdAt))
                detailRow("Session expires", displayDate(session.expiresAt))
                if let version = session.device.appVersion?.nonempty {
                    detailRow("App version", version)
                }
                if let os = session.device.osVersion?.nonempty {
                    detailRow("System", os)
                }

                Button(role: .destructive) {
                    pendingRevocation = session
                } label: {
                    if revokingUUID == session.sessionUuid {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    } else {
                        Label(
                            session.current ? "Sign out this device" : "Revoke session",
                            systemImage: session.current ? "rectangle.portrait.and.arrow.right" : "xmark.shield"
                        )
                        .frame(maxWidth: .infinity)
                    }
                }
                .buttonStyle(.bordered)
                .tint(Z.status(.critical))
                .disabled(revokingUUID != nil)
                .accessibilityIdentifier("staffSessions.revoke.\(session.sessionUuid)")
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("staffSessions.session.\(session.sessionUuid)")
    }

    private func detailRow(_ label: String, _ value: String) -> some View {
        ViewThatFits(in: .horizontal) {
            HStack(alignment: .firstTextBaseline) {
                Text(label)
                    .font(.subheadline)
                    .foregroundStyle(Z.inkMuted)
                    .lineLimit(1)
                Spacer(minLength: Z.s3)
                Text(value)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Z.ink)
                    .lineLimit(1)
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(label)
                    .font(.caption)
                    .foregroundStyle(Z.inkMuted)
                Text(value)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Z.ink)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
    }

    private func errorState(_ message: String) -> some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                Label("Sessions are unavailable", systemImage: "exclamationmark.triangle")
                    .font(.headline)
                    .foregroundStyle(Z.status(.warning))
                Text(message).font(.subheadline).foregroundStyle(Z.inkMuted)
                Button("Try again") { Task { await load() } }
                    .buttonStyle(.borderedProminent)
                    .tint(Z.primary)
                    .accessibilityIdentifier("staffSessions.retry")
            }
        }
        .accessibilityIdentifier("staffSessions.error")
    }

    private func load() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        #if DEBUG
        if StaffSessionsUITestMode.isEnabled {
            if sessions.isEmpty { sessions = StaffSessionsUITestMode.sessions }
            return
        }
        #endif

        guard let bearer = auth.accessToken, !bearer.isEmpty else {
            errorMessage = "Your session is no longer available. Please sign in again."
            return
        }

        do {
            sessions = try await auth.api.staffSessions(bearer: bearer)
        } catch let error as APIError where error.statusCode == 401 || error.statusCode == 403 {
            await auth.completeCurrentSessionRevocation()
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func revoke(_ session: StaffSession) async {
        pendingRevocation = nil
        revokingUUID = session.sessionUuid
        defer { revokingUUID = nil }

        #if DEBUG
        if StaffSessionsUITestMode.isEnabled {
            sessions.removeAll { $0.sessionUuid == session.sessionUuid }
            return
        }
        #endif

        guard let bearer = auth.accessToken, !bearer.isEmpty else {
            await auth.completeCurrentSessionRevocation()
            return
        }

        do {
            let result = try await auth.api.revokeStaffSession(
                sessionUUID: session.sessionUuid,
                bearer: bearer
            )
            if result.current {
                await auth.completeCurrentSessionRevocation()
                dismiss()
            } else {
                await load()
            }
        } catch let error as APIError where error.statusCode == 401 {
            // The mutation itself is never replayed. A safe inventory refetch may
            // rotate once, after which the user must review and confirm again.
            await load()
        } catch let error as APIError where error.statusCode == 403 {
            await auth.completeCurrentSessionRevocation()
        } catch let error as APIError where error.statusCode == 404 {
            await load()
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func displayDate(_ raw: String) -> String {
        guard let date = StaffSessionTimestamp.parse(raw) else {
            return "Time unavailable"
        }
        return date.formatted(date: .abbreviated, time: .shortened)
    }
}

private extension StaffSession {
    var platformDisplayName: String {
        switch device.platform {
        case "ios": "Apple device"
        case "android": "Android device"
        default: "Hummingbird device"
        }
    }
}

private extension String {
    var nonempty: String? {
        let value = trimmingCharacters(in: .whitespacesAndNewlines)
        guard !value.isEmpty else {
            return nil
        }
        return value
    }
}
