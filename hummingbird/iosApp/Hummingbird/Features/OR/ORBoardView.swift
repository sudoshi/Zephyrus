import SwiftUI

/// P4 / P7 — the live OR room board: rooms running / turnover / available, plus each room's
/// current case (surgeon, procedure, elapsed & time-remaining) or next case. Backed by
/// GET /api/mobile/v1/or/board (self-anchored simulated clock, so it always reads live).
@MainActor
final class ORBoardViewModel: ObservableObject {
    @Published var board: ORBoard?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var webLink: String?
    @Published var needsReauth = false

    let api: APIClient
    init(api: APIClient) { self.api = api }

    func load(bearer: String) async {
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.orBoard(bearer: bearer)
            board = env.data
            webLink = env.links?["web"]
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch { errorMessage = error.localizedDescription }
    }
}

struct ORBoardView: View {
    @EnvironmentObject var auth: AuthStore
    @EnvironmentObject var profile: ProfileStore
    @StateObject private var vm = ORBoardViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var showProfile = false
    @State private var viewMode: FlowHomeMode = .list

    // Both OR personas share this board; the Flow Window lens tells them apart
    // (one room's day vs the whole cascade).
    private var orPersona: String {
        profile.roleId == "periop_manager" ? "periop_manager" : "or_nurse"
    }

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window at periop-floor scope: room lanes against the 48h scrubber.
                    ORFlowMapSection(persona: orPersona)
                } else {
                    listBody
                }
            }
            .background(Z.bg)
            .navigationTitle("OR Board")
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
                    Button { showProfile = true } label: { Image(systemName: "person.crop.circle").foregroundStyle(Z.ink) }.accessibilityLabel("Profile and settings")
                }
            }
            .sheet(isPresented: $showProfile) { ProfileView() }
            .onChange(of: vm.needsReauth) { _, n in if n { Task { await auth.logout() } } }
        }
        .tint(Z.primary)
    }

    private var listBody: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Z.s4) {
                AltitudeContextCard(domain: "or")
                if vm.board == nil && vm.isLoading {
                    SkeletonRows()
                } else if vm.board == nil, let e = vm.errorMessage {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load the OR board",
                                     message: e, tone: .warning) { Task { await vm.load(bearer: auth.accessToken ?? "") } }
                } else if let b = vm.board {
                    metrics(b.metrics)
                    sectionLabel("ROOMS")
                    ForEach(b.rooms) { roomCard($0) }
                }
            }
            .padding(Z.s4)
        }
        .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
        .task {
            let token = auth.accessToken ?? ""
            while !Task.isCancelled { await vm.load(bearer: token); try? await Task.sleep(for: .seconds(20)) }
        }
    }

    private func metrics(_ m: ORMetrics) -> some View {
        Panel {
            HStack(spacing: 0) {
                cell("\(m.running)", "Running", tone: .info)
                bar
                cell("\(m.turnover)", "Turnover", tone: m.turnover > 0 ? .warning : nil)
                bar
                cell("\(m.available)", "Open", tone: nil)
                bar
                cell("\(m.avgTurnoverMin)m", "Turnover avg", tone: nil)
            }
        }
    }

    private func cell(_ v: String, _ l: String, tone: CapacityStatus?) -> some View {
        VStack(spacing: 2) {
            Text(v).font(.system(size: 24, weight: .semibold)).monospacedDigit().foregroundStyle(tone.map { Z.status($0) } ?? Z.ink)
            Text(l).font(.system(size: 11)).foregroundStyle(Z.inkMuted)
        }.frame(maxWidth: .infinity)
    }

    private var bar: some View { Rectangle().fill(Z.border).frame(width: 1, height: 26) }

    /// A chip that shows the room's operational status word (never "No data"), colored by tier.
    private func orStatusChip(_ r: ORRoom) -> some View {
        let label: String = {
            switch r.status {
            case "in_progress": return "In Progress"
            case "turnover": return "Turnover"
            case "delayed": return "Delayed"
            default: return "Available"
            }
        }()
        let icon: String = r.status == "delayed" ? "exclamationmark.triangle.fill"
            : (r.status == "turnover" ? "arrow.triangle.2.circlepath" : (r.status == "in_progress" ? "waveform.path.ecg" : "checkmark.circle.fill"))
        return HStack(spacing: Z.s1) {
            Image(systemName: icon).font(.system(size: 11, weight: .semibold))
            Text(label.uppercased()).font(.system(size: 11, weight: .semibold)).tracking(0.4)
        }
        .foregroundStyle(Z.status(r.capacity))
        .padding(.horizontal, Z.s2).padding(.vertical, Z.s1)
        .background(Capsule().fill(Z.status(r.capacity).opacity(0.15)))
        .overlay(Capsule().strokeBorder(Z.status(r.capacity).opacity(0.35), lineWidth: 1))
    }

    private func roomCard(_ r: ORRoom) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: Z.s2) {
                HStack {
                    Text(r.name).font(.system(size: 17, weight: .semibold)).foregroundStyle(Z.ink)
                    orStatusChip(r)
                    Spacer()
                    if let rem = r.timeRemaining, r.current != nil {
                        Text("~\(rem)m left").font(.system(size: 13, weight: .medium)).monospacedDigit().foregroundStyle(Z.inkMuted)
                    } else if let t = r.turnoverMin {
                        Text("ready ~\(t)m").font(.system(size: 13, weight: .medium)).monospacedDigit().foregroundStyle(Z.status(.warning))
                    }
                }
                if let c = r.current {
                    Text(c.procedure).font(.system(size: 15, weight: .medium)).foregroundStyle(Z.ink)
                    Text("\(c.surgeon) · \(c.elapsed)m elapsed of \(c.expectedDuration)m")
                        .font(.system(size: 12)).foregroundStyle(Z.inkMuted)
                    ProgressView(value: Double(min(c.elapsed, c.expectedDuration)), total: Double(max(c.expectedDuration, 1)))
                        .tint(Z.status(r.capacity))
                } else if let n = r.next {
                    Text("Next: \(n.procedure)").font(.system(size: 14, weight: .medium)).foregroundStyle(Z.ink)
                    if let s = n.startTime { Text("scheduled \(s)").font(.system(size: 12)).foregroundStyle(Z.inkMuted) }
                } else {
                    Text("No further cases scheduled").font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                }
            }
        }
    }

    private func sectionLabel(_ t: String) -> some View {
        Text(t).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
    }
}

/// Resolves the periop floor before mounting the Flow Window: the floor whose plates
/// include a procedure room; when the plates asset can't say, bare floor scope lets the
/// server's lens default (the periop floor) decide.
private struct ORFlowMapSection: View {
    @EnvironmentObject var auth: AuthStore
    let persona: String

    @State private var scope: FlowScopeRequest?

    var body: some View {
        Group {
            if let scope {
                FlowMapView(persona: persona, scope: scope)
            } else {
                ScrollView { SkeletonRows().padding(Z.s4) }
            }
        }
        .task {
            guard scope == nil else { return }
            let api = APIClient(baseURL: URL(string: AppConfig.baseURL)!)
            let floors = try? await api.flowFloors(bearer: auth.accessToken ?? "").data
            let procedureFloor = floors?.floors.first { floor in
                floor.spaces.contains { $0.category == "procedure_room" }
            }?.floor
            scope = .floor(procedureFloor)
        }
    }
}
