import SwiftUI

/// Forced/voluntary password change for the mobile companion. Reached when login returns
/// the must_change_password challenge: the backend hands back a narrowly-scoped change
/// token (ability `password:change`), and this screen exchanges the temporary password
/// for a new one, then receives a full session. The protected web flow is never touched.
struct ChangePasswordView: View {
    @EnvironmentObject var auth: AuthStore
    @State private var current: String
    @State private var newPassword: String
    @State private var confirm: String
    @FocusState private var focused: Field?

    private enum Field { case current, newPassword, confirm }

    init() {
        #if DEBUG
        // Test/demo affordance: SIMCTL_CHILD_HB_PW_CURRENT / HB_PW_NEW prefill the form so
        // screenshots and UI tests can land on a ready (or auto-submitting) state.
        let env = ProcessInfo.processInfo.environment
        _current = State(initialValue: env["HB_PW_CURRENT"] ?? "")
        _newPassword = State(initialValue: env["HB_PW_NEW"] ?? "")
        _confirm = State(initialValue: env["HB_PW_NEW"] ?? "")
        #else
        _current = State(initialValue: "")
        _newPassword = State(initialValue: "")
        _confirm = State(initialValue: "")
        #endif
    }

    private var meetsLength: Bool { newPassword.count >= 8 }
    private var matches: Bool { !confirm.isEmpty && newPassword == confirm }
    private var differs: Bool { !newPassword.isEmpty && newPassword != current }
    private var canSubmit: Bool { !current.isEmpty && meetsLength && matches && differs && !auth.isBusy }

    var body: some View {
        ScrollView {
            VStack(spacing: Z.s5) {
                VStack(spacing: Z.s2) {
                    Image(systemName: "lock.rotation")
                        .font(.system(size: 40, weight: .semibold))
                        .foregroundStyle(Z.gold)
                    Text("Set a new password")
                        .font(.system(size: 24, weight: .semibold))
                        .foregroundStyle(Z.ink)
                    Text("Your account uses a temporary password. Choose a new one to finish signing in.")
                        .font(.system(size: 14))
                        .foregroundStyle(Z.inkMuted)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, Z.s6)

                Panel {
                    VStack(alignment: .leading, spacing: Z.s4) {
                        field("Current (temporary) password", text: $current, field: .current, content: .password)
                        field("New password", text: $newPassword, field: .newPassword, content: .newPassword)
                        requirement("At least 8 characters", ok: meetsLength)
                        if !current.isEmpty && !newPassword.isEmpty {
                            requirement("Different from the temporary password", ok: differs)
                        }
                        field("Confirm new password", text: $confirm, field: .confirm, content: .newPassword)
                        if !confirm.isEmpty {
                            requirement("Passwords match", ok: matches)
                        }

                        if let error = auth.errorMessage {
                            HStack(spacing: Z.s2) {
                                Image(systemName: "exclamationmark.triangle.fill")
                                Text(error).font(.system(size: 13))
                            }
                            .foregroundStyle(Z.status(.critical))
                        }

                        Button(action: submit) {
                            HStack {
                                if auth.isBusy { ProgressView().tint(.white) }
                                Text(auth.isBusy ? "Updating…" : "Update password")
                                    .font(.system(size: 16, weight: .semibold))
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, Z.s3)
                            .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                            .foregroundStyle(Color.white)
                        }
                        .disabled(!canSubmit)
                        .opacity(canSubmit ? 1 : 0.6)
                    }
                }

                Button("Back to sign in") { auth.backToLogin() }
                    .font(.system(size: 14))
                    .foregroundStyle(Z.inkMuted)

                Spacer(minLength: Z.s4)
            }
            .padding(Z.s5)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
        .task {
            #if DEBUG
            // Test affordance: SIMCTL_CHILD_HB_PW_AUTOSUBMIT=1 submits the prefilled form once
            // so an end-to-end screenshot can land past the change gate.
            if ProcessInfo.processInfo.environment["HB_PW_AUTOSUBMIT"] == "1", canSubmit {
                submit()
            }
            #endif
        }
    }

    private func submit() {
        focused = nil
        Task { await auth.changePassword(currentPassword: current, newPassword: newPassword) }
    }

    @ViewBuilder
    private func requirement(_ text: String, ok: Bool) -> some View {
        HStack(spacing: Z.s2) {
            Image(systemName: ok ? "checkmark.circle.fill" : "circle")
                .font(.system(size: 12))
                .foregroundStyle(ok ? Z.status(.success) : Z.inkMuted)
            Text(text).font(.system(size: 12)).foregroundStyle(Z.inkMuted)
        }
    }

    @ViewBuilder
    private func field(_ label: String, text: Binding<String>, field: Field,
                       content: UITextContentType) -> some View {
        VStack(alignment: .leading, spacing: Z.s1) {
            Text(label.uppercased())
                .font(.system(size: 11, weight: .semibold))
                .tracking(0.5)
                .foregroundStyle(Z.inkMuted)
            SecureField("", text: text)
                .focused($focused, equals: field)
                .textContentType(content)
                .font(.system(size: 16))
                .foregroundStyle(Z.ink)
                .padding(Z.s3)
                .background(RoundedRectangle(cornerRadius: 10).fill(Z.bg))
                .overlay(RoundedRectangle(cornerRadius: 10)
                    .strokeBorder(focused == field ? Z.gold : Z.border, lineWidth: focused == field ? 1.5 : 1))
                .animation(.easeOut(duration: 0.15), value: focused)
        }
    }
}
