import Foundation

/// What the map/lanes detail strip is showing: a tapped plate, a past event, or a
/// projected ghost (with its provenance chip).
enum FlowSelection: Equatable {
    case plate(FlowPlate)
    case event(FlowTimelineEvent)
    case projection(FlowProjection)
}

/// The requested spatial scope for a Flow Window surface. Serialized to the
/// `scope=house|unit:{id}` query the lens endpoint expects (server clamps to the lens).
enum FlowScopeRequest: Equatable {
    case house
    case unit(Int)

    var queryValue: String {
        switch self {
        case .house: return "house"
        case .unit(let id): return "unit:\(id)"
        }
    }
}

/// State for one 48h Flow Window surface: the lensed window payload, the floor-plates
/// asset, the scrub time `t`, floor selection, selection, and past-half replay playback.
/// Live edge: `hospital.beds` Reverb events re-fetch the window (the existing re-snapshot
/// contract) — refreshes shift the frame but never reset the user's scrub position.
@MainActor
final class FlowWindowStore: ObservableObject {
    @Published var window: FlowWindowData?
    @Published var floors: FlowFloorsDocument?
    @Published var t: Date = .now
    @Published var selectedFloor: Int?
    @Published var isPlaying = false
    @Published var selection: FlowSelection?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var needsReauth = false
    @Published var live = false

    let api: APIClient
    private var bearerToken = ""
    private var persona: String?
    private var scope: FlowScopeRequest = .house
    private var didSetInitialT = false
    private var playbackTask: Task<Void, Never>?

    /// Wall duration of a full past-half replay; shorter scrub spans replay proportionally.
    private let replayDuration: TimeInterval = 15

    private lazy var realtime = RealtimeClient(
        scheme: AppConfig.reverbScheme,
        host: AppConfig.reverbHost, port: AppConfig.reverbPort, key: AppConfig.reverbKey,
        channel: "hospital.beds",
        onEvent: { [weak self] in
            guard let self else { return }
            Task { await self.reload() }
        },
        onState: { [weak self] connected in self?.live = connected }
    )

    init(api: APIClient) { self.api = api }

    // MARK: Loading

    func load(bearer: String, persona: String?, scope: FlowScopeRequest) async {
        bearerToken = bearer
        self.persona = persona
        self.scope = scope
        isLoading = true
        defer { isLoading = false }
        do {
            let env = try await api.flowWindow(persona: persona, scope: scope.queryValue, bearer: bearer)
            window = env.data
            errorMessage = nil
            // First load lands the scrubber on `now`; later refreshes keep the user's t.
            if !didSetInitialT, let now = env.data.window.nowDate {
                t = now
                didSetInitialT = true
            }
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
        } catch {
            errorMessage = error.localizedDescription
        }
        // The plates asset is versioned + cacheable; a miss only costs the geometry layer.
        if floors == nil, let env = try? await api.flowFloors(bearer: bearer) {
            floors = env.data
        }
    }

    private func reload() async {
        guard didSetInitialT else { return }
        await load(bearer: bearerToken, persona: persona, scope: scope)
    }

    func startLive(bearer: String) {
        bearerToken = bearer
        realtime.start()
    }

    func stopLive() {
        realtime.stop()
        pause()
    }

    // MARK: Time model

    var nowDate: Date { window?.window.nowDate ?? .now }
    var fromDate: Date { window?.window.fromDate ?? nowDate.addingTimeInterval(-24 * 3600) }
    var toDate: Date { window?.window.toDate ?? nowDate.addingTimeInterval(24 * 3600) }

    func clamp(_ date: Date) -> Date { min(max(date, fromDate), toDate) }

    /// Past events accumulated up to the scrub time (solid rendering).
    func eventsUpTo(_ t: Date) -> [FlowTimelineEvent] {
        (window?.events ?? [])
            .filter { ($0.time ?? .distantFuture) <= t }
            .sorted { ($0.time ?? .distantPast) < ($1.time ?? .distantPast) }
    }

    /// Ghosts accumulated when scrubbing forward — projections with time ≤ t, only once
    /// t is past `now` (symmetric to how the past half accumulates events).
    func ghostsUpTo(_ t: Date) -> [FlowProjection] {
        guard t > nowDate else { return [] }
        return (window?.projections ?? [])
            .filter { ($0.time ?? .distantFuture) <= t }
            .sorted { ($0.time ?? .distantPast) < ($1.time ?? .distantPast) }
    }

    /// Census at t for a unit via the nearest checkpoint ≤ t (D2 seek semantics).
    /// Nil when no snapshot series exists yet — callers fall back to the live rollup.
    func censusAt(_ t: Date, unitId: Int) -> FlowSnapshot? {
        (window?.snapshots ?? [])
            .filter { $0.unitId == unitId && ($0.time ?? .distantFuture) <= t }
            .max { ($0.time ?? .distantPast) < ($1.time ?? .distantPast) }
    }

    /// The most recent shift boundary (07:00 / 19:00 local) at or before `date`.
    static func lastShiftBoundary(before date: Date, calendar: Calendar = .current) -> Date {
        let day = calendar.startOfDay(for: date)
        let seven = day.addingTimeInterval(7 * 3600)
        let nineteen = day.addingTimeInterval(19 * 3600)
        if date >= nineteen { return nineteen }
        if date >= seven { return seven }
        // Before 07:00 — last night's 19:00.
        return day.addingTimeInterval(-24 * 3600 + 19 * 3600)
    }

    /// Shift-boundary detents (07:00 / 19:00) inside the current 48h window, ascending.
    var shiftBoundaries: [Date] {
        var boundaries: [Date] = []
        let calendar = Calendar.current
        var day = calendar.startOfDay(for: fromDate)
        while day <= toDate {
            for hour in [7, 19] {
                let boundary = day.addingTimeInterval(TimeInterval(hour) * 3600)
                if boundary >= fromDate && boundary <= toDate { boundaries.append(boundary) }
            }
            day = day.addingTimeInterval(24 * 3600)
        }
        return boundaries.sorted()
    }

    // MARK: Playback (replay of the past half)

    func togglePlayback() {
        isPlaying ? pause() : play()
    }

    /// Replay from the current scrub position to `now`. If t is already at/after now,
    /// restart from the beginning of the past half.
    func play(from start: Date? = nil) {
        pause()
        let now = nowDate
        if let start { t = clamp(start) }
        if t >= now { t = fromDate }
        let span = now.timeIntervalSince(t)
        guard span > 1 else { return }
        // Proportional pace: a full 24h past half replays in `replayDuration` seconds.
        let fullSpan: TimeInterval = 24 * 3600
        let duration = max(3, replayDuration * span / fullSpan)
        let rate = span / duration // sim-seconds per wall-second
        isPlaying = true
        playbackTask = Task { [weak self] in
            let tick: TimeInterval = 1.0 / 30.0
            while !Task.isCancelled {
                try? await Task.sleep(for: .milliseconds(Int(tick * 1000)))
                guard let self, self.isPlaying else { return }
                let next = self.t.addingTimeInterval(rate * tick)
                if next >= self.nowDate {
                    self.t = self.nowDate
                    self.pause()
                    return
                }
                self.t = next
            }
        }
    }

    func pause() {
        playbackTask?.cancel()
        playbackTask = nil
        isPlaying = false
    }
}
