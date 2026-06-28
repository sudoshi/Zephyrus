import SwiftUI

/// Account + shift settings: who you are, the role/unit you confirmed for this shift, your
/// default workflow, connection info, and the sign-out / switch-role actions (previously
/// buried in the Home toolbar menu). Presented as a sheet from Home.
struct ProfileView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Z.s4) {
                    identity
                    shiftCard
                    accountCard
                    aboutCard
                    signOut
                }
                .padding(Z.s4)
            }
            .background(Z.bg)
            .navigationTitle("Profile")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }.tint(Z.primary)
                }
            }
        }
        .tint(Z.primary)
    }

    // MARK: Identity

    private var identity: some View {
        VStack(spacing: Z.s2) {
            Image(systemName: "person.crop.circle.fill")
                .font(.system(size: 64))
                .foregroundStyle(Z.primary)
            Text(auth.me?.name ?? "—")
                .font(.system(size: 22, weight: .semibold))
                .foregroundStyle(Z.ink)
            if let me = auth.me {
                Text("@\(me.username)\(me.email.map { " · \($0)" } ?? "")")
                    .font(.system(size: 13))
                    .foregroundStyle(Z.inkMuted)
            }
        }
        .padding(.top, Z.s4)
    }

    // MARK: This shift (role + unit)

    private var shiftCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                sectionLabel("THIS SHIFT")
                if let role = profile.role {
                    HStack(spacing: Z.s3) {
                        Image(systemName: role.symbol)
                            .font(.system(size: 22))
                            .foregroundStyle(Z.primary)
                            .frame(width: 30)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(role.title)
                                .font(.system(size: 16, weight: .semibold))
                                .foregroundStyle(Z.ink)
                            Text(profile.unitName ?? role.subtitle)
                                .font(.system(size: 13))
                                .foregroundStyle(Z.inkMuted)
                        }
                        Spacer()
                    }
                } else {
                    Text("No role confirmed for this shift.")
                        .font(.system(size: 14)).foregroundStyle(Z.inkMuted)
                }
                Divider().overlay(Z.border)
                Button(action: switchRole) {
                    Label("Switch role / unit", systemImage: "arrow.left.arrow.right")
                        .font(.system(size: 15, weight: .medium))
                        .foregroundStyle(Z.primary)
                }
            }
        }
    }

    // MARK: Account

    private var accountCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                sectionLabel("ACCOUNT")
                infoRow("Default workflow", workflowDisplay)
                if auth.me?.isAdmin == true {
                    Divider().overlay(Z.border)
                    infoRow("Access", "Administrator")
                }
            }
        }
    }

    // MARK: About / connection

    private var aboutCard: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                sectionLabel("ABOUT")
                infoRow("App", "Hummingbird \(appVersion)")
                Divider().overlay(Z.border)
                infoRow("Connected to", AppConfig.baseURL)
            }
        }
    }

    private var signOut: some View {
        Button(action: signOutTapped) {
            Label("Sign out", systemImage: "rectangle.portrait.and.arrow.right")
                .font(.system(size: 16, weight: .semibold))
                .frame(maxWidth: .infinity)
                .padding(.vertical, Z.s3)
                .foregroundStyle(Z.status(.critical))
                .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.status(.critical).opacity(0.4), lineWidth: 1))
        }
        .padding(.top, Z.s2)
    }

    // MARK: Actions

    private func switchRole() {
        guard let id = auth.me?.id else { return }
        dismiss()
        profile.reset(userId: id) // RootView re-gates to onboarding
    }

    private func signOutTapped() {
        dismiss()
        Task { await auth.logout() }
    }

    // MARK: Bits

    private var workflowDisplay: String {
        guard let wf = auth.me?.workflowPreference else { return "—" }
        return wf == "rtdc" ? "RTDC" : wf.capitalized
    }

    private var appVersion: String {
        let v = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        let b = Bundle.main.infoDictionary?["CFBundleVersion"] as? String
        return b.map { "\(v) (\($0))" } ?? v
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
            .foregroundStyle(Z.inkMuted)
    }

    private func infoRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).font(.system(size: 14)).foregroundStyle(Z.inkMuted)
            Spacer()
            Text(value).font(.system(size: 15, weight: .medium))
                .foregroundStyle(Z.ink)
                .multilineTextAlignment(.trailing)
        }
    }
}
