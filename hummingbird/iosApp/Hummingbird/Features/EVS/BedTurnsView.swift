import SwiftUI

/// P2 — the EVS tech's "Bed Turns" home: pending / overdue / isolation metrics and the
/// SLA-ordered turn queue (the top row is the next dirty bed). Each turn opens a detail with
/// the Claim → Start → Complete lifecycle. Backed by GET /api/mobile/v1/evs/queue.
@MainActor
final class EvsTurnsViewModel: ObservableObject {
    @Published var queue: EvsQueue?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var stale = false
    @Published var webLink: String?
    @Published var needsReauth = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            errorMessage = "Can't reach the server. Check your connection and try again."
            stale = true
            return
        }
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.evsQueue(bearer: bearer)
            queue = env.data
            webLink = env.links?["web"]
            stale = env.meta?.stale ?? false
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
            stale = true
        } catch {
            errorMessage = error.localizedDescription
            stale = true
        }
    }

    func advance(id: Int, to status: String, bearer: String) async {
        do {
            try await api.evsStatus(id: id, status: status, bearer: bearer)
            await load(bearer: bearer)
        } catch let error as APIError { errorMessage = error.message }
        catch { errorMessage = error.localizedDescription }
    }
}

struct BedTurnsView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm: EvsTurnsViewModel
    @State private var showProfile = false
    @State private var viewMode: FlowHomeMode = .list

    private let refreshInterval: Duration = .seconds(20)

    init() {
        _vm = StateObject(wrappedValue: EvsTurnsViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)))
    }

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window — "the turn map": house heat first; tapping a floor
                    // descends and lights bed states + turn markers at floor scope.
                    FlowMapView(persona: "evs", scope: .house)
                } else {
                    listBody
                }
            }
            .background { HummingbirdBackdrop(dim: 0.4) }
            .navigationTitle("Bed Turns")
            .eddyContext("bed_turns", title: "Bed Turns", summary: "dirty & blocked beds")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .principal) {
                    Picker("View", selection: $viewMode) {
                        Text("List").tag(FlowHomeMode.list)
                        Text("Map").tag(FlowHomeMode.map)
                    }
                    .pickerStyle(.segmented)
                    .frame(width: 160)
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showProfile = true } label: {
                        EmptyView()
                    }
                    .accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .onChange(of: vm.needsReauth) { _, needs in
                if needs { Task { await auth.logout() } }
            }
        }
        .tint(Z.primary)
    }

    private var listBody: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                AltitudeContextCard(domain: "evs")
                if vm.queue == nil && vm.isLoading {
                    SkeletonRows()
                } else if vm.queue == nil && vm.errorMessage != nil {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load turns",
                                     message: vm.errorMessage ?? "", tone: .warning) {
                        Task { await vm.load(bearer: auth.accessToken ?? "") }
                    }
                } else if let q = vm.queue {
                    content(q)
                }
            }
            .padding(Z.s4)
        }
        .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
        .task {
            let token = auth.accessToken ?? ""
            while !Task.isCancelled {
                await vm.load(bearer: token)
                try? await Task.sleep(for: refreshInterval)
            }
        }
    }

    @ViewBuilder
    private func content(_ q: EvsQueue) -> some View {
        metrics(q.metrics)

        if q.metrics.overdue > 0 {
            overdueBanner(q.metrics.overdue)
        }

        if q.turns.isEmpty {
            RetryableMessage(symbol: "checkmark.circle", title: "All clear",
                             message: "No bed-turns waiting right now.", tone: .success)
        } else {
            sectionLabel("TURN QUEUE (\(q.turns.count)) · next dirty bed first")
            ForEach(q.turns) { turn in
                NavigationLink {
                    TurnDetailView(turn: turn, webLink: vm.webLink) { id, s in
                        await vm.advance(id: id, to: s, bearer: auth.accessToken ?? "")
                    }
                } label: { turnRow(turn) }
                .buttonStyle(.plain)
            }
        }
    }

    private func metrics(_ m: EvsMetrics) -> some View {
        Panel {
            HStack(spacing: 0) {
                statCell("\(m.pending)", "Pending", tone: nil)
                divider
                statCell("\(m.overdue)", "Overdue", tone: m.overdue > 0 ? .critical : nil)
                divider
                statCell("\(m.isolation)", "Isolation", tone: m.isolation > 0 ? .warning : nil)
                divider
                statCell("\(m.completedToday)", "Done", tone: nil)
            }
        }
    }

    private func statCell(_ value: String, _ label: String, tone: CapacityStatus?) -> some View {
        VStack(spacing: 2) {
            Text(value)
                .font(.system(size: 26, weight: .semibold)).monospacedDigit()
                .foregroundStyle(tone.map { Z.status($0) } ?? Z.ink)
            Text(label)
                .font(.system(size: 11, weight: .medium)).tracking(0.3)
                .foregroundStyle(Z.inkMuted)
        }
        .frame(maxWidth: .infinity)
    }

    private var divider: some View { Rectangle().fill(Z.border).frame(width: 1, height: 28) }

    private func overdueBanner(_ overdue: Int) -> some View {
        HStack(spacing: Z.s2) {
            Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Z.status(.critical))
            Text("\(overdue) turn\(overdue == 1 ? "" : "s") past due — beds waiting to open").monospacedDigit()
                .font(.system(size: 14, weight: .semibold)).foregroundStyle(Z.ink)
            Spacer()
        }
        .padding(Z.s3)
        .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.status(.critical).opacity(0.12)))
        .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.status(.critical).opacity(0.4), lineWidth: 1))
    }

    private func turnRow(_ turn: EvsTurn) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(spacing: Z.s2) {
                    TurnPriorityChip(turn: turn)
                    if turn.isolationRequired { IsolationBadge() }
                    Spacer()
                    Text(turn.sla.label)
                        .font(.system(size: 12, weight: .medium)).monospacedDigit()
                        .foregroundStyle((turn.sla.minutesUntilDue ?? 0) < 0 ? Z.status(.critical) : Z.inkMuted)
                }
                Text(turn.locationLabel ?? "—")
                    .font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
                Text(statusLabel(turn.turnType ?? turn.requestType))
                    .font(.system(size: 12)).foregroundStyle(Z.inkMuted)
            }
        }
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
            .foregroundStyle(Z.inkMuted)
            .padding(.top, Z.s2)
    }
}

/// STAT / URGENT / ROUTINE pill for a bed-turn, colored by the rationed tier.
struct TurnPriorityChip: View {
    let turn: EvsTurn
    var body: some View {
        let status = turn.capacity
        return HStack(spacing: Z.s1) {
            Image(systemName: status.symbol).font(.system(size: 11, weight: .semibold))
            Text(turn.priority.uppercased())
                .font(.system(size: 11, weight: .semibold)).tracking(0.4)
        }
        .foregroundStyle(Z.status(status))
        .padding(.horizontal, Z.s2).padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(status).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(status).opacity(0.35), lineWidth: 1))
    }
}

/// Isolation badge — a non-color-alone marker for PPE/SOP turns.
struct IsolationBadge: View {
    var body: some View {
        HStack(spacing: Z.s1) {
            Image(systemName: "cross.case.fill").font(.system(size: 10, weight: .semibold))
            Text("ISO").font(.system(size: 11, weight: .semibold)).tracking(0.4)
        }
        .foregroundStyle(Z.status(.warning))
        .padding(.horizontal, Z.s2).padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(.warning).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(.warning).opacity(0.35), lineWidth: 1))
    }
}
