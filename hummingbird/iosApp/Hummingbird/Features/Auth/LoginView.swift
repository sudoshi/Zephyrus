import SwiftUI

struct LoginView: View {
    @EnvironmentObject var auth: AuthStore
    @State private var username = "demo"
    @State private var password = "Password123!"
    @FocusState private var focused: Field?

    private enum Field { case username, password }

    var body: some View {
        VStack(spacing: Z.s5) {
            Spacer()

            VStack(spacing: Z.s2) {
                Image(systemName: "bird.fill")
                    .font(.system(size: 44, weight: .semibold))
                    .foregroundStyle(Z.primary)
                Text("Hummingbird")
                    .font(.system(size: 28, weight: .semibold))
                    .foregroundStyle(Z.ink)
                Text("Zephyrus operations, in your pocket")
                    .font(.system(size: 14))
                    .foregroundStyle(Z.inkMuted)
            }

            Panel {
                VStack(alignment: .leading, spacing: Z.s4) {
                    field("Username or email", text: $username, field: .username, secure: false)
                    field("Password", text: $password, field: .password, secure: true)

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
                            Text(auth.isBusy ? "Signing in…" : "Sign in")
                                .font(.system(size: 16, weight: .semibold))
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, Z.s3)
                        .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                        .foregroundStyle(Color.white)
                    }
                    .disabled(auth.isBusy || username.isEmpty || password.isEmpty)
                    .opacity(auth.isBusy || username.isEmpty || password.isEmpty ? 0.6 : 1)
                }
            }

            Text("Connected to \(AppConfig.baseURL)")
                .font(.system(size: 11))
                .foregroundStyle(Z.inkMuted)

            Spacer()
        }
        .padding(Z.s5)
    }

    private func submit() {
        focused = nil
        Task { await auth.login(username: username, password: password) }
    }

    @ViewBuilder
    private func field(_ label: String, text: Binding<String>, field: Field, secure: Bool) -> some View {
        VStack(alignment: .leading, spacing: Z.s1) {
            Text(label.uppercased())
                .font(.system(size: 11, weight: .semibold))
                .tracking(0.5)
                .foregroundStyle(Z.inkMuted)
            Group {
                if secure {
                    SecureField("", text: text).focused($focused, equals: field)
                } else {
                    TextField("", text: text)
                        .focused($focused, equals: field)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }
            }
            .font(.system(size: 16))
            .foregroundStyle(Z.ink)
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: 10).fill(Z.bg))
            .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
        }
    }
}
