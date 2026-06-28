import SwiftUI

/// Account + shift settings: who you are, the role/unit you confirmed for this shift, your
/// default workflow, connection info, and the sign-out / switch-role actions (previously
/// buried in the Home toolbar menu). Presented as a sheet from Home.
struct ProfileView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @Environment(\.dismiss) private var dismiss
    @State private var census: [CensusUnit] = []

    private let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)

    /// Demo / admin accounts get the quick persona switcher and skip onboarding.
    private var isSuperuser: Bool {
        auth.me?.isAdmin == true || auth.me?.workflowPreference == "superuser"
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Z.s4) {
                    identity
                    shiftCard
                    if isSuperuser { personaSwitcher }
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
            .task {
                // Census powers a sensible default unit when switching into a unit-bound persona.
                if let env = try? await api.census(bearer: auth.accessToken ?? "") { census = env.data }
            }
        }
        .tint(Z.primary)
    }

    // MARK: Persona switcher (demo / superuser)

    private var personaSwitcher: some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                sectionLabel("SWITCH PERSONA")
                Text("Jump into any role to demo its tailored view.")
                    .font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                ForEach(Array(Role.catalog.enumerated()), id: \.element.id) { index, role in
                    if index > 0 { Divider().overlay(Z.border) }
                    Button { switchTo(role) } label: { personaRow(role) }
                        .buttonStyle(.plain)
                }
            }
        }
    }

    private func personaRow(_ role: Role) -> some View {
        let isCurrent = profile.roleId == role.id
        return HStack(spacing: Z.s3) {
            Image(systemName: role.symbol)
                .font(.system(size: 18))
                .foregroundStyle(isCurrent ? Z.primary : Z.inkMuted)
                .frame(width: 26)
            VStack(alignment: .leading, spacing: 1) {
                Text(role.title)
                    .font(.system(size: 15, weight: isCurrent ? .semibold : .medium))
                    .foregroundStyle(Z.ink)
                Text(role.subtitle)
                    .font(.system(size: 12)).foregroundStyle(Z.inkMuted).lineLimit(1)
            }
            Spacer()
            if isCurrent {
                Image(systemName: "checkmark.circle.fill")
                    .font(.system(size: 16)).foregroundStyle(Z.primary)
            }
        }
        .contentShape(Rectangle())
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

    /// One-tap persona switch for demos: confirm the role with a sensible default unit
    /// (the most pressured matching unit for unit-bound roles; House-wide otherwise).
    private func switchTo(_ role: Role) {
        guard let id = auth.me?.id else { return }
        let unit = defaultUnit(for: role)
        profile.confirm(userId: id, roleId: role.id,
                        unitId: unit?.unitId, unitName: unit?.name ?? "House-wide")
        dismiss()
    }

    private func defaultUnit(for role: Role) -> CensusUnit? {
        guard role.unitBound, !census.isEmpty else { return nil }
        let pool: [CensusUnit]
        if role.id == "intensivist" {
            pool = census.filter { $0.type == "icu" || $0.type == "step_down" }
        } else {
            let medSurg = census.filter { $0.type == "med_surg" }
            pool = medSurg.isEmpty ? census : medSurg
        }
        // Prefer the unit with the most going on, so the demo lands somewhere interesting.
        return pool.max { ($0.bedNeed, $0.occupied) < ($1.bedNeed, $1.occupied) } ?? pool.first
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
