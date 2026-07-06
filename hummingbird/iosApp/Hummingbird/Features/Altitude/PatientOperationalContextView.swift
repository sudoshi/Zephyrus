import SwiftUI

@MainActor
final class PatientOperationalContextViewModel: ObservableObject {
    @Published var context: PatientOperationalContext?
    @Published var isLoading = false
    @Published var errorMessage: String?

    let contextRef: String
    let api: APIClient

    init(contextRef: String, api: APIClient) {
        self.contextRef = contextRef
        self.api = api
    }

    func load(persona: String?, bearer: String) async {
        guard !bearer.isEmpty else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            context = try await api.patientOperationalContext(contextRef: contextRef, persona: persona, bearer: bearer).data
            errorMessage = nil
        } catch let error as APIError {
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

/// A2P — reusable patient/encounter operational lens. This is intentionally operational:
/// status spine, dependencies, relay, recommendations, and timeline, not an EHR chart.
struct PatientOperationalContextView: View {
    let contextRef: String

    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @Environment(\.openURL) private var openURL
    @StateObject private var vm: PatientOperationalContextViewModel

    init(contextRef: String) {
        self.contextRef = contextRef
        _vm = StateObject(wrappedValue: PatientOperationalContextViewModel(
            contextRef: contextRef,
            api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)
        ))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                if vm.context == nil && vm.isLoading {
                    ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
                } else if vm.context == nil, let error = vm.errorMessage {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load patient context",
                                     message: error, tone: .warning) {
                        Task { await vm.load(persona: profile.roleId, bearer: auth.accessToken ?? "") }
                    }
                } else if let context = vm.context {
                    content(context)
                }
            }
            .padding(Z.s4)
        }
        .background { HummingbirdBackdrop(dim: 0.4) }
        .navigationTitle("Patient Context")
        .navigationBarTitleDisplayMode(.inline)
        .task(id: "\(contextRef)|\(profile.roleId ?? "")") {
            await vm.load(persona: profile.roleId, bearer: auth.accessToken ?? "")
        }
        .tint(Z.primary)
    }

    private func content(_ context: PatientOperationalContext) -> some View {
        VStack(alignment: .leading, spacing: Z.s4) {
            header(context)


            sectionLabel("STATUS SPINE")
            Panel(padding: Z.s3) { statusSpine(context.statusSpine) }

            sectionLabel("DEPENDENCIES")
            Panel { DependencyListView(dependencies: context.dependencies) }

            if let recommendations = context.recommendations, !recommendations.isEmpty {
                sectionLabel("RECOMMENDATIONS")
                recommendationsCard(recommendations)
            }

            sectionLabel("TIMELINE")
            Panel(padding: Z.s3) { timeline(context.timeline) }

            if let relay = context.relay {
                sectionLabel("RELAY")
                relayCard(relay)
            }

            if let actions = context.actions, !actions.isEmpty {
                sectionLabel("AUTHORIZED ACTIONS")
                actionsCard(actions)
            }

            sectionLabel("ACTIVITY")
            Panel { ActivityListView(events: context.activity ?? [], limit: 8) }

            if let href = context.web?.href, let url = URL(string: href) {
                Button { openURL(url) } label: {
                    HStack {
                        Label(context.web?.label ?? "Open in Zephyrus", systemImage: "arrow.up.forward.square")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(Z.primary)
                        Spacer()
                    }
                    .padding(Z.s3)
                    .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
                }
                .buttonStyle(.plain)
            }
        }
    }

    private func header(_ context: PatientOperationalContext) -> some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {

                VStack(alignment: .leading, spacing: 2) {
                    Text(context.patient.display ?? "Operational patient context")
                        .font(.system(size: 20, weight: .semibold))
                        .foregroundStyle(Z.ink)
                    Text(context.patient.patientContextRef ?? contextRef)
                        .font(.system(size: 11, weight: .medium))
                        .foregroundStyle(Z.inkMuted)
                        .lineLimit(1)
                        .truncationMode(.middle)
                }

                VStack(spacing: Z.s2) {
                    if let current = context.header.currentLocation {
                        infoRow("Current location", current)
                    }
                    if let target = context.header.targetLocation {
                        infoRow("Target location", target)
                    }
                    if let service = context.header.service {
                        infoRow("Service", service)
                    }
                    if context.header.isolationRequired == true {
                        infoRow("Precautions", "Isolation required", tone: .warning)
                    }
                    if let team = context.header.responsibleTeam {
                        infoRow("Responsible team", team)
                    }
                    if let asOf = altitudeRelativeTime(context.header.asOf) {
                        infoRow("As of", asOf)
                    }
                }

                if let policy = context.phiPolicy {
                    Divider().overlay(Z.border)
                    Text(policyLine(policy))
                        .font(.system(size: 11))
                        .foregroundStyle(Z.inkMuted)
                }
            }
        }
    }

    private func statusSpine(_ spine: [PatientStatusSpineItem]) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            if spine.isEmpty {
                Text("No operational states have been linked yet.")
                    .font(.system(size: 13))
                    .foregroundStyle(Z.inkMuted)
            } else {
                ForEach(spine) { item in
                    HStack(spacing: Z.s2) {
                        Image(systemName: spineIcon(item.domain))
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(Z.status(statusFor(item.status)))
                            .frame(width: 18)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(item.label ?? altitudeTitle(item.domain ?? "state"))
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundStyle(Z.ink)
                            Text([item.status.map(statusLabel), altitudeRelativeTime(item.at)].compactMap { $0 }.joined(separator: " · "))
                                .font(.system(size: 11))
                                .foregroundStyle(Z.inkMuted)
                        }
                        Spacer()
                    }
                }
            }
        }
    }

    private func recommendationsCard(_ recommendations: [PatientRecommendation]) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s3) {
                ForEach(recommendations) { rec in
                    VStack(alignment: .leading, spacing: 3) {
                        HStack(spacing: Z.s2) {
                            Text((rec.source ?? "recommendation").uppercased())
                                .font(.system(size: 10, weight: .semibold))
                                .tracking(0.4)
                                .foregroundStyle(Z.gold)
                            Spacer()
                            if let status = rec.status {
                                Text(altitudeStatusLabel(status))
                                    .font(.system(size: 11, weight: .medium))
                                    .foregroundStyle(Z.inkMuted)
                            }
                        }
                        Text(rec.title ?? "Operational recommendation")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundStyle(Z.ink)
                        if let rationale = rec.rationale {
                            Text(rationale)
                                .font(.system(size: 12))
                                .foregroundStyle(Z.inkMuted)
                        }
                    }
                }
            }
        }
    }

    private func timeline(_ events: [PatientTimelineEvent]) -> some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            if events.isEmpty {
                Text("No timeline events have been linked yet.")
                    .font(.system(size: 13))
                    .foregroundStyle(Z.inkMuted)
            } else {
                ForEach(events) { event in
                    HStack(alignment: .top, spacing: Z.s3) {
                        Circle()
                            .fill(Z.primary)
                            .frame(width: 8, height: 8)
                            .padding(.top, 5)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(altitudeTitle(event.eventType ?? "operational event"))
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundStyle(Z.ink)
                            Text(timelineMeta(event))
                                .font(.system(size: 11))
                                .foregroundStyle(Z.inkMuted)
                        }
                        Spacer()
                    }
                }
            }
        }
    }

    private func relayCard(_ relay: PatientRelay) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                if let roles = relay.willNotifyRoles, !roles.isEmpty {
                    relayLine("Will notify", roles)
                }
                if let roles = relay.activityRoles, !roles.isEmpty {
                    relayLine("Activity only", roles)
                }
            }
        }
    }

    private func relayLine(_ label: String, _ roles: [String]) -> some View {
        HStack(alignment: .top) {
            Text(label)
                .font(.system(size: 13))
                .foregroundStyle(Z.inkMuted)
            Spacer()
            Text(roles.map(altitudeTitle).joined(separator: ", "))
                .font(.system(size: 13, weight: .semibold))
                .foregroundStyle(Z.ink)
                .multilineTextAlignment(.trailing)
        }
    }

    private func actionsCard(_ actions: [MobileAction]) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                ForEach(actions) { action in
                    HStack(spacing: Z.s2) {
                        Image(systemName: action.requiresOnline == true ? "wifi" : "checkmark.circle")
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(Z.primary)
                        Text(action.label ?? altitudeTitle(action.kind))
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(Z.ink)
                        Spacer()
                    }
                }
            }
        }
    }

    private func infoRow(_ label: String, _ value: String, tone: CapacityStatus? = nil) -> some View {
        HStack {
            Text(label)
                .font(.system(size: 13))
                .foregroundStyle(Z.inkMuted)
            Spacer()
            Text(value)
                .font(.system(size: 13, weight: .semibold))
                .foregroundStyle(tone.map { Z.status($0) } ?? Z.ink)
                .multilineTextAlignment(.trailing)
        }
    }

    private func detailPill(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(label.uppercased())
                .font(.system(size: 10, weight: .semibold))
                .tracking(0.4)
                .foregroundStyle(Z.inkMuted)
            Text(value)
                .font(.system(size: 13, weight: .medium))
                .foregroundStyle(Z.ink)
                .lineLimit(1)
                .truncationMode(.middle)
        }
        .padding(.horizontal, Z.s3)
        .padding(.vertical, Z.s2)
        .background(RoundedRectangle(cornerRadius: 10).fill(Z.bg))
        .overlay(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
    }

    private func policyLine(_ policy: PatientPhiPolicy) -> String {
        var parts: [String] = []
        if policy.listSafe == false { parts.append("not list-safe") }
        if policy.pushSafe == false { parts.append("not push-safe") }
        if policy.requiresDetailAuth == true { parts.append("detail auth required") }
        return parts.isEmpty ? "Operational context authorized for this role." : parts.joined(separator: " · ")
    }

    private func timelineMeta(_ event: PatientTimelineEvent) -> String {
        var parts = [event.domain?.uppercased(), event.actorRole.map(altitudeTitle), event.statusAfter.map(altitudeStatusLabel), altitudeRelativeTime(event.occurredAt)]
            .compactMap { $0 }
        if let before = event.statusBefore, let after = event.statusAfter {
            parts.insert("\(altitudeStatusLabel(before)) → \(altitudeStatusLabel(after))", at: 0)
        }
        return parts.joined(separator: " · ")
    }

    private func statusFor(_ raw: String?) -> CapacityStatus {
        switch raw {
        case "blocked", "boarding", "pending": return .warning
        case "critical", "stat": return .critical
        case "completed", "resolved", "placed": return .success
        default: return .info
        }
    }

    private func spineIcon(_ domain: String?) -> String {
        switch domain {
        case "rtdc": return "bed.double.fill"
        case "transport": return "figure.walk"
        case "evs": return "sparkles"
        case "ed": return "cross.case.fill"
        case "or": return "waveform.path.ecg"
        default: return "circle.grid.cross"
        }
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold))
            .tracking(0.5)
            .foregroundStyle(Z.inkMuted)
            .padding(.top, Z.s2)
    }
}
