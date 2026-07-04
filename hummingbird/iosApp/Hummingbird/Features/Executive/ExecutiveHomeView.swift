import SwiftUI

/// P9 — the executive "House Brief": one quiet screen answering "is the hospital OK?". A house
/// strain index (0–4) with its drivers, the single most material breach, and a few defensible
/// hero KPIs. Backed by GET /api/mobile/v1/command/house.
@MainActor
final class ExecutiveHomeViewModel: ObservableObject {
    @Published var brief: HouseBrief?
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
            let env = try await api.commandHouse(bearer: bearer)
            brief = env.data
            webLink = env.links?["web"]
            errorMessage = nil
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch { errorMessage = error.localizedDescription }
    }
}

struct ExecutiveHomeView: View {
    @EnvironmentObject var auth: AuthStore
    @StateObject private var vm = ExecutiveHomeViewModel(api: APIClient(baseURL: URL(string: AppConfig.baseURL)!))
    @State private var showProfile = false
    @State private var viewMode: FlowHomeMode = .list

    var body: some View {
        NavigationStack {
            Group {
                if viewMode == .map {
                    // The Flow Window — the executive time-lapse brief (P9): the house
                    // breathes through the last 24h, then shows tomorrow's band. House
                    // scope, aggregate heat only — the lens never serves patient detail.
                    FlowMapView(persona: "executive", scope: .house)
                } else {
                    listBody
                }
            }
            .background(Z.bg)
            .navigationTitle("House Brief")
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
                AltitudeContextCard(domain: "ops")
                EddyContextButton(scopeRef: "house")
                if vm.brief == nil && vm.isLoading {
                    SkeletonRows()
                } else if vm.brief == nil, let e = vm.errorMessage {
                    RetryableMessage(symbol: "wifi.exclamationmark", title: "Can't load the brief",
                                     message: e, tone: .warning) { Task { await vm.load(bearer: auth.accessToken ?? "") } }
                } else if let b = vm.brief {
                    strainCard(b.strain)
                    if let one = b.strain.drivers.first(where: { $0.status != "success" }) {
                        theOneThing(one)
                    }
                    sectionLabel("HOUSE KPIS")
                    LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: Z.s3) {
                        ForEach(b.hero) { kpiTile($0) }
                    }
                }
            }
            .padding(Z.s4)
        }
        .refreshable { await vm.load(bearer: auth.accessToken ?? "") }
        .task {
            let token = auth.accessToken ?? ""
            while !Task.isCancelled { await vm.load(bearer: token); try? await Task.sleep(for: .seconds(30)) }
        }
    }

    private func strainCard(_ s: ExecStrain) -> some View {
        Panel {
            VStack(alignment: .leading, spacing: Z.s3) {
                HStack {
                    Text("HOUSE STRAIN").font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted)
                    Spacer()
                    StatusChip(status: s.capacity)
                }
                // A gauge track, not blocks: filled segments carry the status color; empty
                // segments are outlined so surge 0 reads as "instrument at rest", not
                // unloaded content.
                HStack(spacing: Z.s2) {
                    ForEach(0..<4, id: \.self) { i in
                        Group {
                            if i < s.level {
                                RoundedRectangle(cornerRadius: 3).fill(Z.status(s.capacity))
                            } else {
                                RoundedRectangle(cornerRadius: 3).strokeBorder(Z.border, lineWidth: 1)
                            }
                        }
                        .frame(height: 10)
                    }
                }
                .accessibilityElement(children: .ignore)
                .accessibilityLabel("Surge level \(s.level) of 4")
                Text("\(s.label) · \(s.level) / 4")
                    .font(.system(size: 15, weight: .semibold)).monospacedDigit().foregroundStyle(Z.ink)
                Divider().overlay(Z.border)
                ForEach(s.drivers) { d in
                    HStack {
                        Image(systemName: d.capacity.symbol).font(.system(size: 12)).foregroundStyle(Z.status(d.capacity))
                        Text(d.label).font(.system(size: 13)).foregroundStyle(Z.inkMuted)
                        Spacer()
                        Text(d.value).font(.system(size: 14, weight: .semibold)).monospacedDigit().foregroundStyle(Z.ink)
                    }
                }
            }
        }
    }

    private func theOneThing(_ d: StrainDriver) -> some View {
        HStack(spacing: Z.s2) {
            Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Z.status(d.capacity))
            VStack(alignment: .leading, spacing: 1) {
                Text("THE ONE THING").font(.system(size: 10, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted)
                Text("\(d.label): \(d.value)").font(.system(size: 15, weight: .semibold)).foregroundStyle(Z.ink)
            }
            Spacer()
        }
        .padding(Z.s3)
        .background(RoundedRectangle(cornerRadius: Z.radius).fill(Z.status(d.capacity).opacity(0.12)))
        .overlay(RoundedRectangle(cornerRadius: Z.radius).strokeBorder(Z.status(d.capacity).opacity(0.4), lineWidth: 1))
    }

    private func kpiTile(_ m: HeroKpi) -> some View {
        Panel(padding: Z.s3) {
            VStack(alignment: .leading, spacing: 4) {
                Text(m.label.uppercased()).font(.system(size: 10, weight: .semibold)).tracking(0.4).foregroundStyle(Z.inkMuted).lineLimit(1)
                Text(m.display).font(.system(size: 26, weight: .semibold)).monospacedDigit().foregroundStyle(Z.status(m.capacity))
                if let t = m.targetDisplay {
                    Text("target \(t)").font(.system(size: 11)).foregroundStyle(Z.inkMuted)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private func sectionLabel(_ t: String) -> some View {
        Text(t).font(.system(size: 11, weight: .semibold)).tracking(0.5).foregroundStyle(Z.inkMuted).padding(.top, Z.s2)
    }
}
