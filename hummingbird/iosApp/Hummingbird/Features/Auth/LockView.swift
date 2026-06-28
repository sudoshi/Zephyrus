import SwiftUI

/// Full-screen lock shown when the biometric app-lock is engaged. Auto-prompts on appear and
/// offers a manual retry; a sign-out escape covers a failed/declined unlock.
struct LockView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var lock: AppLock

    var body: some View {
        ZStack {
            Z.bg.ignoresSafeArea()
            VStack(spacing: Z.s4) {
                Spacer()
                Image("BrandMark")
                    .resizable().scaledToFit()
                    .frame(width: 72, height: 72)
                    .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .opacity(0.9)
                Text("Hummingbird is locked")
                    .font(.system(size: 20, weight: .semibold))
                    .foregroundStyle(Z.ink)
                Text("Unlock with \(BiometricAuth.label) to continue.")
                    .font(.system(size: 14))
                    .foregroundStyle(Z.inkMuted)
                    .multilineTextAlignment(.center)

                Button { Task { await lock.authenticate() } } label: {
                    HStack(spacing: Z.s2) {
                        if lock.authenticating {
                            ProgressView().tint(.white)
                        } else {
                            Image(systemName: BiometricAuth.symbol)
                        }
                        Text("Unlock")
                            .font(.system(size: 16, weight: .semibold))
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, Z.s3)
                    .background(RoundedRectangle(cornerRadius: 10).fill(Z.primary))
                    .foregroundStyle(Color.white)
                }
                .disabled(lock.authenticating)
                .padding(.top, Z.s2)
                .padding(.horizontal, Z.s6)

                Spacer()

                Button("Sign out") { Task { await auth.logout() } }
                    .font(.system(size: 14))
                    .foregroundStyle(Z.inkMuted)
                    .padding(.bottom, Z.s4)
            }
            .padding(Z.s5)
        }
        .task {
            // Auto-prompt as soon as the lock appears (skip in UI tests that drive it manually).
            if ProcessInfo.processInfo.environment["HB_NO_AUTOUNLOCK"] != "1" {
                await lock.authenticate()
            }
        }
    }
}
