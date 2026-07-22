import SwiftUI

/// P1 — the transporter's "My Trips" home: glanceable metrics, a STAT banner when one exists,
/// and the prioritized active queue. Each job opens a detail with the claim-and-run lifecycle.
/// Backed by GET /api/mobile/v1/transport/queue (PHI-minimized).
@MainActor
final class TransportJobsViewModel: ObservableObject {
    @Published var queue: TransportQueue?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var stale = false
    @Published var webLink: String?
    @Published var needsReauth = false
    @Published var nextCursor: String?
    @Published var hasMore = false
    @Published var isLoadingMore = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        #if DEBUG
        // Test affordance: HB_FORCE_ERROR=1 simulates an unreachable server.
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            errorMessage = "Can't reach the server. Check your connection and try again."
            stale = true
            return
        }
        #endif
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.transportQueue(bearer: bearer)
            queue = env.data
            webLink = env.links?["web"]
            stale = env.meta?.stale ?? false
            nextCursor = env.meta?.nextCursor
            hasMore = env.meta?.hasMore ?? false
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

    func loadMore(bearer: String) async {
        guard !isLoadingMore, hasMore, let nextCursor else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        do {
            let env = try await api.transportQueue(bearer: bearer, cursor: nextCursor)
            let existing = queue?.jobs ?? []
            let known = Set(existing.map(\.id))
            queue = TransportQueue(
                metrics: env.data.metrics,
                jobs: existing + env.data.jobs.filter { !known.contains($0.id) }
            )
            self.nextCursor = env.meta?.nextCursor
            hasMore = env.meta?.hasMore ?? false
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func advance(id: Int, to status: String, lifecycleVersion: Int,
                 bearer: String) async -> TransportJob? {
        do {
            let updated = try await api.transportStatus(
                id: id, status: status, lifecycleVersion: lifecycleVersion, bearer: bearer
            )
            await load(bearer: bearer)
            return updated
        } catch let error as APIError { errorMessage = error.message }
        catch { errorMessage = error.localizedDescription }
        return nil
    }

    func handoff(id: Int, to: String, receiverRole: String, acceptanceStatus: String,
                 outstandingRisk: String?, summary: String?, lifecycleVersion: Int,
                 bearer: String) async -> TransportJob? {
        do {
            let updated = try await api.transportHandoff(
                id: id,
                handoffTo: to,
                receiverRole: receiverRole,
                acceptanceStatus: acceptanceStatus,
                outstandingRisk: outstandingRisk,
                summary: summary,
                lifecycleVersion: lifecycleVersion,
                bearer: bearer
            )
            await load(bearer: bearer)
            return updated
        } catch let error as APIError { errorMessage = error.message }
        catch { errorMessage = error.localizedDescription }
        return nil
    }
}

struct TransportJobsView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm: TransportJobsViewModel
    @State private var showProfile = false
    @State private var viewMode: FlowHomeMode = .list

    private let refreshInterval: Duration = .seconds(20)

    init() {
        _vm = StateObject(wrappedValue: TransportJobsViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)))
    }

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window — "my day in the building": trips as routes over the
                    // house stack. A presentation mode of this home, not a new tab.
                    FlowMapView(persona: "transport", scope: .house)
                } else {
                    listBody
                }
            }
            .background { HummingbirdBackdrop(dim: 0.4) }
            .navigationTitle("Transport")
            .eddyContext("transport_jobs", title: "Transport", summary: "my trips & moves")
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
                AltitudeContextCard(domain: "transport")
                if vm.queue == nil && vm.isLoading {
                    SkeletonRows()
                } else if vm.queue == nil && vm.errorMessage != nil {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load trips",
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
    private func content(_ q: TransportQueue) -> some View {
        metrics(q.metrics)

        if q.metrics.stat > 0 || q.metrics.atRisk > 0 {
            statBanner(q.metrics)
        }

        if q.jobs.isEmpty {
            RetryableMessage(symbol: "checkmark.circle", title: "Queue clear",
                             message: "No active transport jobs right now.", tone: .success)
        } else {
            let myTrips = q.jobs.filter(\.claimedByMe)
            let availableTrips = q.jobs.filter(\.availableToClaim)
            let otherTrips = q.jobs.filter { !$0.claimedByMe && !$0.availableToClaim }

            if !myTrips.isEmpty {
                sectionLabel("MY TRIPS (\(myTrips.count))")
                jobLinks(myTrips)
            }
            if !availableTrips.isEmpty {
                sectionLabel("AVAILABLE TRIPS (\(availableTrips.count))")
                jobLinks(availableTrips)
            }
            if !otherTrips.isEmpty {
                sectionLabel("AWAITING DISPATCH (\(otherTrips.count))")
                jobLinks(otherTrips)
            }
            if vm.hasMore {
                Button {
                    Task { await vm.loadMore(bearer: auth.accessToken ?? "") }
                } label: {
                    Text(vm.isLoadingMore ? "Loading…" : "Load more trips")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .disabled(vm.isLoadingMore)
            }
        }
    }

    @ViewBuilder
    private func jobLinks(_ jobs: [TransportJob]) -> some View {
        ForEach(jobs) { job in
            NavigationLink {
                JobDetailView(
                    job: job, webLink: vm.webLink,
                    advance: { id, status, lifecycleVersion in
                        await vm.advance(
                            id: id, to: status, lifecycleVersion: lifecycleVersion,
                            bearer: auth.accessToken ?? ""
                        )
                    },
                    handoff: { id, to, role, acceptance, risk, summary, lifecycleVersion in
                        await vm.handoff(
                            id: id,
                            to: to,
                            receiverRole: role,
                            acceptanceStatus: acceptance,
                            outstandingRisk: risk,
                            summary: summary,
                            lifecycleVersion: lifecycleVersion,
                            bearer: auth.accessToken ?? ""
                        )
                    }
                )
            } label: { jobRow(job) }
            .buttonStyle(.plain)
        }
    }

    // MARK: Metrics

    private func metrics(_ m: TransportMetrics) -> some View {
        Panel {
            HStack(spacing: 0) {
                statCell("\(m.active)", "Active", tone: nil)
                divider
                statCell("\(m.stat)", "STAT", tone: m.stat > 0 ? .critical : nil)
                divider
                statCell("\(m.atRisk)", "At risk", tone: m.atRisk > 0 ? .warning : nil)
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

    private var divider: some View {
        Rectangle().fill(Z.border).frame(width: 1, height: 28)
    }

    private func statBanner(_ m: TransportMetrics) -> some View {
        HStack(spacing: Z.s2) {
            Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Z.status(.critical))
            Text(bannerText(m))
                .font(.system(size: 14, weight: .semibold)).foregroundStyle(Z.ink)
            Spacer()
        }
        .padding(Z.s3)
        .background(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).fill(Z.status(.critical).opacity(0.12)))
        .overlay(RoundedRectangle(cornerRadius: Z.radius, style: .continuous).strokeBorder(Z.status(.critical).opacity(0.4), lineWidth: 1))
    }

    private func bannerText(_ m: TransportMetrics) -> String {
        var parts: [String] = []
        if m.stat > 0 { parts.append("\(m.stat) STAT") }
        if m.atRisk > 0 { parts.append("\(m.atRisk) at risk") }
        return parts.joined(separator: " · ") + " — needs a runner now"
    }

    // MARK: Job row

    private func jobRow(_ job: TransportJob) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack(spacing: Z.s2) {
                    JobPriorityChip(job: job)
                    Spacer()
                    Text(job.sla.label)
                        .font(.system(size: 12, weight: .medium)).monospacedDigit()
                        .foregroundStyle(job.sla.atRisk ? Z.status(.critical) : Z.inkMuted)
                }
                Text("\(job.origin ?? "—")  →  \(job.destination ?? "—")")
                    .font(.system(size: 16, weight: .semibold)).foregroundStyle(Z.ink)
                Text(rowSubtitle(job))
                    .font(.system(size: 12)).foregroundStyle(Z.inkMuted)
            }
        }
    }

    private func rowSubtitle(_ job: TransportJob) -> String {
        [job.type, job.mode, statusLabel(job.status)]
            .compactMap { $0 }
            .map { $0.replacingOccurrences(of: "_", with: " ") }
            .joined(separator: " · ")
    }

    private func sectionLabel(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold)).tracking(0.5)
            .foregroundStyle(Z.inkMuted)
            .padding(.top, Z.s2)
    }
}

/// STAT / URGENT / ROUTINE pill, colored by the rationed tier and always paired with a label.
struct JobPriorityChip: View {
    let job: TransportJob
    var body: some View {
        let status = job.capacity
        return HStack(spacing: Z.s1) {
            Image(systemName: status.symbol).font(.system(size: 11, weight: .semibold))
            Text(job.priority.uppercased())
                .font(.system(size: 11, weight: .semibold)).tracking(0.4)
        }
        .foregroundStyle(Z.status(status))
        .padding(.horizontal, Z.s2).padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(status).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(status).opacity(0.35), lineWidth: 1))
    }
}

/// Human label for a transport lifecycle status (snake_case → Title Case).
func statusLabel(_ raw: String) -> String {
    raw.replacingOccurrences(of: "_", with: " ")
        .split(separator: " ").map { $0.prefix(1).uppercased() + $0.dropFirst() }
        .joined(separator: " ")
}
