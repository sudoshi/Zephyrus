import SwiftUI

/// Start-of-shift onboarding: confirm your role (assigned in Zephyrus), then the unit/floor
/// you're covering. The choice tailors the app and is remembered per user. Driven by `/me`
/// (roles) + the census unit list; persisted locally via `ProfileStore`.
struct OnboardingView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore

    @State private var step = 0
    @State private var selectedRole: Role?
    @State private var selectedUnit: CensusUnit?
    @State private var houseWide = false
    @State private var units: [CensusUnit] = []
    @State private var loadingUnits = false

    private var me: MeData? { auth.me }

    var body: some View {
        VStack(spacing: 0) {
            header
            ScrollView {
                if step == 0 { roleStep } else { unitStep }
            }
            footer
        }
        .background(Z.bg.ignoresSafeArea())
        .task {
            if selectedRole == nil, let roles = me?.roles, !roles.isEmpty {
                selectedRole = Role.matching(serverRoles: roles)
            }
        }
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            Text("Welcome, \(firstName)")
                .font(.system(size: 26, weight: .semibold)).foregroundStyle(Z.ink)
            Text(step == 0 ? "Confirm your role for this shift" : "Where are you working today?")
                .font(.system(size: 14)).foregroundStyle(Z.inkMuted)
            HStack(spacing: 6) {
                Capsule().fill(Z.primary).frame(width: 28, height: 4)
                Capsule().fill(step >= 1 ? Z.primary : Z.border).frame(width: 28, height: 4)
            }
            .padding(.top, 4)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(Z.s4)
    }

    private var roleStep: some View {
        VStack(spacing: Z.s3) {
            if let roles = me?.roles, !roles.isEmpty { assignedBanner(roles) }
            ForEach(Role.catalog) { roleCard($0) }
        }
        .padding(Z.s4)
    }

    private func assignedBanner(_ roles: [String]) -> some View {
        HStack(spacing: Z.s2) {
            Image(systemName: "checkmark.seal.fill").foregroundStyle(Z.gold)
            Text("Zephyrus has you as: \(roles.joined(separator: ", "))")
                .font(.system(size: 12)).foregroundStyle(Z.inkMuted)
            Spacer()
        }
        .padding(Z.s3)
        .background(RoundedRectangle(cornerRadius: 10).fill(Z.surface))
        .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.gold.opacity(0.4), lineWidth: 1))
    }

    private func roleCard(_ role: Role) -> some View {
        let selected = selectedRole?.id == role.id
        return Button { selectedRole = role } label: {
            HStack(spacing: Z.s3) {
                Image(systemName: role.symbol)
                    .font(.system(size: 20)).foregroundStyle(selected ? Z.primary : Z.inkMuted).frame(width: 30)
                VStack(alignment: .leading, spacing: 2) {
                    Text(role.title).font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
                    Text(role.subtitle).font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                }
                Spacer()
                Image(systemName: selected ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(selected ? Z.primary : Z.border)
            }
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.surface))
            .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(selected ? Z.primary : Z.border, lineWidth: selected ? 2 : 1))
        }
        .buttonStyle(.plain)
    }

    private var unitStep: some View {
        VStack(spacing: Z.s3) {
            unitRow(title: "House-wide", subtitle: "All units", selected: houseWide) { houseWide = true; selectedUnit = nil }
            if loadingUnits { ProgressView().tint(Z.primary).padding(.top, Z.s3) }
            ForEach(units) { unit in
                unitRow(title: unit.name,
                        subtitle: unit.type.replacingOccurrences(of: "_", with: " ").capitalized,
                        selected: !houseWide && selectedUnit?.unitId == unit.unitId) {
                    houseWide = false; selectedUnit = unit
                }
            }
        }
        .padding(Z.s4)
        .task { await loadUnits() }
    }

    private func unitRow(title: String, subtitle: String, selected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(title).font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
                    Text(subtitle).font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                }
                Spacer()
                Image(systemName: selected ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(selected ? Z.primary : Z.border)
            }
            .padding(Z.s3)
            .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.surface))
            .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(selected ? Z.primary : Z.border, lineWidth: selected ? 2 : 1))
        }
        .buttonStyle(.plain)
    }

    private var footer: some View {
        HStack(spacing: Z.s3) {
            if step == 1 {
                Button("Back") { step = 0 }
                    .font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
                    .frame(maxWidth: .infinity).padding(.vertical, Z.s3)
                    .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
            }
            Button(step == 0 ? "Next" : "Start shift") { advance() }
                .font(.system(size: 16, weight: .semibold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity).padding(.vertical, Z.s3)
                .background(RoundedRectangle(cornerRadius: 10).fill(canAdvance ? Z.primary : Z.primary.opacity(0.4)))
                .disabled(!canAdvance)
        }
        .padding(Z.s4)
    }

    private var canAdvance: Bool {
        step == 0 ? selectedRole != nil : (houseWide || selectedUnit != nil)
    }

    private func advance() {
        if step == 0 {
            if selectedRole?.unitBound == false { houseWide = true }
            step = 1
        } else {
            guard let me, let role = selectedRole else { return }
            profile.confirm(userId: me.id, roleId: role.id,
                            unitId: houseWide ? nil : selectedUnit?.unitId,
                            unitName: houseWide ? "House-wide" : selectedUnit?.name)
        }
    }

    private func loadUnits() async {
        guard units.isEmpty, !loadingUnits else { return }
        loadingUnits = true
        defer { loadingUnits = false }
        if let env = try? await auth.api.census(bearer: auth.accessToken ?? "") {
            units = env.data
        }
    }

    private var firstName: String {
        (me?.name.split(separator: " ").first).map(String.init) ?? "there"
    }
}
