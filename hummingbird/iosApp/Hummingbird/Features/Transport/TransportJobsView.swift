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

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        // Test affordance: HB_FORCE_ERROR=1 simulates an unreachable server. No-op in prod.
        if ProcessInfo.processInfo.environment["HB_FORCE_ERROR"] == "1" {
            errorMessage = "Can't reach the server. Check your connection and try again."
            stale = true
            return
        }
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.transportQueue(bearer: bearer)
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
            try await api.transportStatus(id: id, status: status, bearer: bearer)
            await load(bearer: bearer)
        } catch let error as APIError { errorMessage = error.message }
        catch { errorMessage = error.localizedDescription }
    }

    func handoff(id: Int, to: String, summary: String?, bearer: String) async {
        do {
            try await api.transportHandoff(id: id, handoffTo: to, summary: summary, bearer: bearer)
            await load(bearer: bearer)
        } catch let error as APIError { errorMessage = error.message }
        catch { errorMessage = error.localizedDescription }
    }
}

struct TransportJobsView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm: TransportJobsViewModel
    @State private var showProfile = false

    private let refreshInterval: Duration = .seconds(20)

    init() {
        _vm = StateObject(wrappedValue: TransportJobsViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!)))
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Z.s4) {
                    if vm.queue == nil && vm.isLoading {
                        ProgressView().tint(Z.primary).frame(maxWidth: .infinity).padding(.top, Z.s6)
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
            .background(Z.bg)
            .navigationTitle("Transport")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showProfile = true } label: {
                        Image(systemName: "person.crop.circle").foregroundStyle(Z.ink)
                    }
                    .accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
            .task {
                let token = auth.accessToken ?? ""
                while !Task.isCancelled {
                    await vm.load(bearer: token)
                    try? await Task.sleep(for: refreshInterval)
                }
            }
            .onChange(of: vm.needsReauth) { _, needs in
                if needs { Task { await auth.logout() } }
            }
        }
        .tint(Z.primary)
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
            sectionLabel("ACTIVE QUEUE (\(q.jobs.count))")
            ForEach(q.jobs) { job in
                NavigationLink {
                    JobDetailView(
                        job: job, webLink: vm.webLink,
                        advance: { id, s in await vm.advance(id: id, to: s, bearer: auth.accessToken ?? "") },
                        handoff: { id, to, summary in await vm.handoff(id: id, to: to, summary: summary, bearer: auth.accessToken ?? "") }
                    )
                } label: { jobRow(job) }
                .buttonStyle(.plain)
            }
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
                        .foregroundStyle((job.sla.minutesUntilDue ?? 0) < 0 ? Z.status(.critical) : Z.inkMuted)
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
