import SwiftUI

struct PatientWelcomeView: View {
    @ObservedObject var viewModel: PatientAppViewModel
    @State private var mode: AccessMode = .signIn
    @State private var email = ""
    @State private var password = ""
    @State private var passwordConfirmation = ""
    @State private var displayName = ""
    @State private var challengeUUID = ""
    @State private var challengeToken = ""
    @State private var verificationCode = ""

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 22) {
                brand

                Picker("Access method", selection: $mode) {
                    ForEach(AccessMode.allCases) { item in
                        Text(item.title).tag(item)
                    }
                }
                .pickerStyle(.segmented)

                if mode == .signIn {
                    signInFields
                } else {
                    enrollmentFields
                }

                if let errorMessage = viewModel.errorMessage {
                    PatientPhotoStateCard(
                        scene: .error,
                        icon: "exclamationmark.circle.fill",
                        title: "We couldn’t open your care view",
                        message: errorMessage
                    )
                        .accessibilityAddTraits(.isStaticText)
                        .accessibilitySortPriority(10)
                        .accessibilityIdentifier("patient-error-state")
                }

                if !viewModel.liveAccessAvailable {
                    PatientPhotoStateCard(
                        scene: .empty,
                        icon: "lock.shield",
                        title: "Live access is off",
                        message: "No care information will be requested until a patient API is explicitly configured."
                    )
                    .accessibilityIdentifier("patient-api-off-state")
                }

                Button(action: submit) {
                    Text(mode.actionTitle)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 5)
                }
                .buttonStyle(.borderedProminent)
                .controlSize(.large)
                .disabled(!canSubmit || !viewModel.liveAccessAvailable)

                #if DEBUG
                Button("Open synthetic reference scenario") {
                    viewModel.activateSyntheticReference()
                }
                .buttonStyle(.bordered)
                .frame(maxWidth: .infinity)
                .accessibilityHint("Opens clearly labeled sample data without contacting a server")
                #endif

                privacyNote
            }
            .padding(22)
        }
        .background {
            PatientPhotoBackground(scene: .welcome)
                .ignoresSafeArea()
        }
        .navigationTitle("Patient access")
        .navigationBarTitleDisplayMode(.inline)
        .accessibilityIdentifier("patient-welcome")
    }

    private var brand: some View {
        VStack(alignment: .leading, spacing: 10) {
            Image(systemName: "bird.fill")
                .font(.system(size: 42))
                .foregroundStyle(PatientPalette.blue)
                .accessibilityHidden(true)
            Text("Hummingbird Patient")
                .font(.largeTitle.bold())
                .foregroundStyle(PatientPalette.ink)
            Text("Understand what is happening today, what may come next, and who is helping with your care.")
                .font(.title3)
                .foregroundStyle(.secondary)
        }
    }

    private var signInFields: some View {
        VStack(spacing: 14) {
            TextField("Email", text: $email)
                .textContentType(.emailAddress)
                .keyboardType(.emailAddress)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .patientField()
            SecureField("Password", text: $password)
                .textContentType(.password)
                .patientField()
        }
    }

    private var enrollmentFields: some View {
        VStack(alignment: .leading, spacing: 14) {
            Text("Use the invitation details given to you by your hospital. These values are verified once and are not stored as care identifiers on this device.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
            TextField("Invitation ID", text: $challengeUUID)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .patientField()
            SecureField("Invitation token", text: $challengeToken)
                .textContentType(.oneTimeCode)
                .patientField()
            TextField("Verification code", text: $verificationCode)
                .textContentType(.oneTimeCode)
                .keyboardType(.numberPad)
                .patientField()
            TextField("Your name", text: $displayName)
                .textContentType(.name)
                .patientField()
            TextField("Email", text: $email)
                .textContentType(.emailAddress)
                .keyboardType(.emailAddress)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .patientField()
            SecureField("Create password", text: $password)
                .textContentType(.newPassword)
                .patientField()
            SecureField("Confirm password", text: $passwordConfirmation)
                .textContentType(.newPassword)
                .patientField()
        }
    }

    private var privacyNote: some View {
        VStack(alignment: .leading, spacing: 6) {
            Label("Designed for your privacy", systemImage: "hand.raised.fill")
                .font(.headline)
            Text("Credentials are kept in this app’s device-only secure storage. The screen is covered whenever the app moves out of the foreground.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .padding(.top, 6)
    }

    private var canSubmit: Bool {
        if mode == .signIn {
            return email.contains("@") && !password.isEmpty
        }
        return UUID(uuidString: challengeUUID) != nil
            && challengeToken.count >= 32
            && verificationCode.count >= 6
            && !displayName.isEmpty
            && email.contains("@")
            && password.count >= 12
            && password == passwordConfirmation
    }

    private func submit() {
        viewModel.errorMessage = nil
        Task {
            if mode == .signIn {
                await viewModel.signIn(email: email, password: password)
            } else {
                await viewModel.enroll(
                    PatientEnrollmentInput(
                        challengeUUID: challengeUUID,
                        challengeToken: challengeToken,
                        verificationCode: verificationCode,
                        displayName: displayName,
                        email: email,
                        password: password,
                        passwordConfirmation: passwordConfirmation
                    )
                )
            }
        }
    }
}

private enum AccessMode: String, CaseIterable, Identifiable {
    case signIn
    case enroll

    var id: String { rawValue }
    var title: String { self == .signIn ? "Sign in" : "Join with invite" }
    var actionTitle: String { self == .signIn ? "Sign in securely" : "Verify and join" }
}

private extension View {
    func patientField() -> some View {
        padding(14)
            .background(PatientPalette.surface, in: RoundedRectangle(cornerRadius: 14))
    }
}
