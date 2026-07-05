import Foundation
import WidgetKit

/// What the map/lanes detail strip is showing: a tapped plate, a past event, or a
/// projected ghost (with its provenance chip).
enum FlowSelection: Equatable {
    case plate(FlowPlate)
    case event(FlowTimelineEvent)
    case projection(FlowProjection)
}

/// The requested spatial scope for a Flow Window surface. Serialized to the
/// `scope=house|floor:{n}|unit:{id}` query the lens endpoint expects (server clamps to
/// the lens). A nil floor sends bare `floor` and lets the server resolve the lens
/// default (the periop floor for OR lenses).
enum FlowScopeRequest: Equatable {
    case house
    case floor(Int?)
    case unit(Int)

    var queryValue: String {
        switch self {
        case .house: return "house"
        case .floor(let number): return number.map { "floor:\($0)" } ?? "floor"
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
    /// The 3D space-anchor asset — per-space metre centroids the native scene places by.
    @Published var spaces3d: FlowSpaces3dDocument?
    @Published var t: Date = .now
    @Published var selectedFloor: Int?
    @Published var isPlaying = false
    @Published var selection: FlowSelection?
    @Published var isLoading = false
    @Published var errorMessage: String?
    @Published var needsReauth = false
    @Published var live = false
    /// The web 4D-Navigator deep link from the window envelope (`links.web`) — the PI lens
    /// appends `from=`/`to=` to it for clip-to-share.
    @Published var webLink: String?
    /// When set, the surface is showing a cached window because the live load failed — drives
    /// the "Showing data from HH:mm" staleness caption. Cleared on any fresh (network) load.
    @Published var staleAsOf: Date?

    /// Persona playback pace in sim-seconds per wall-second (PI replays at ~4h/s). Nil keeps
    /// the default proportional pace (a full past half in `replayDuration` seconds).
    var playRate: TimeInterval?

    let api: APIClient
    private var bearerToken = ""
    private var userId: Int?
    private var persona: String?
    private var scope: FlowScopeRequest = .house
    private var didSetInitialT = false
    private var playbackTask: Task<Void, Never>?
    /// The persona+scope the currently-loaded window is for — the delta-refresh guard (a delta
    /// is only valid against the same lens+scope; anything else forces a full load).
    private var loadedScopeKey: String?
    /// Raw plates-asset bytes, kept so a full-window cache write always includes the geometry.
    private var floorsRawData: Data?

    /// Snake_case-aware decoder for reading cached envelope bytes back into DTOs (mirrors the
    /// APIClient decoder — cached blobs are the raw wire JSON).
    private static let cacheDecoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

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

    func load(bearer: String, userId: Int?, persona: String?, scope: FlowScopeRequest) async {
        bearerToken = bearer
        self.userId = userId
        self.persona = persona
        self.scope = scope
        await fetch()
    }

    /// The scope actually sent: the EVS turn map re-scopes to the descended floor because
    /// `bed_statuses` only exists at floor/unit scope — house payloads never carry it.
    private var requestScope: FlowScopeRequest {
        if persona == "evs", let floor = selectedFloor { return .floor(floor) }
        return scope
    }

    private func scopeKey(_ scopeValue: String) -> String { "\(persona ?? "")|\(scopeValue)" }

    /// A FULL (non-delta) load: fetch the whole window, cache its raw bytes for offline, and
    /// (on failure) present the last cached window with a staleness caption. This is the path
    /// for a first load, a scope change (EVS descent), and any delta fallback.
    private func fetch() async {
        isLoading = true
        defer { isLoading = false }
        let scopeValue = requestScope.queryValue
        // The plates asset is versioned + cacheable; a miss only costs the geometry layer.
        if floors == nil, let (env, data) = try? await api.flowFloorsRaw(bearer: bearerToken) {
            floors = env.data
            floorsRawData = data
        }
        // The 3D anchors are versioned + cacheable too; the native scene needs them once.
        if spaces3d == nil, let env = try? await api.flowSpaces3d(bearer: bearerToken) {
            spaces3d = env.data
        }
        do {
            let (env, data) = try await api.flowWindowRaw(persona: persona, scope: scopeValue, bearer: bearerToken)
            window = env.data
            webLink = env.links?["web"]
            errorMessage = nil
            staleAsOf = nil
            loadedScopeKey = scopeKey(scopeValue)
            landInitialTIfNeeded(env.data)
            // Persist the last FULL window + floors for this (user, persona, scope).
            if let userId {
                FlowWindowCache.save(userId: userId, persona: persona, scope: scopeValue,
                                     window: data, floors: floorsRawData)
            }
            writeHouseGlance(scopeValue: scopeValue)
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true }
            errorMessage = error.message
            presentCachedIfPossible(scopeValue: scopeValue)
        } catch {
            errorMessage = error.localizedDescription
            presentCachedIfPossible(scopeValue: scopeValue)
        }
    }

    /// Delta refresh (Reverb re-snapshot / foreground return): when a window is already loaded
    /// for the same persona+scope, request only events/snapshots newer than the newest we hold
    /// (`?since=`) and merge them per the contract. A 422 (invalid_since) or any decode failure
    /// falls back to a full load; a transient network error keeps the loaded window for the
    /// next tick (the frame never resets the user's scrub position or selection).
    private func refresh() async {
        let scopeValue = requestScope.queryValue
        guard window != nil,
              loadedScopeKey == scopeKey(scopeValue),
              let since = newestLoadedTime else {
            await fetch()
            return
        }
        do {
            let env = try await api.flowWindow(persona: persona, scope: scopeValue,
                                               since: Self.iso(since), bearer: bearerToken)
            window = window?.merged(delta: env.data) ?? env.data
            webLink = env.links?["web"] ?? webLink
            errorMessage = nil
            staleAsOf = nil
            writeHouseGlance(scopeValue: scopeValue)
        } catch let error as APIError where error.statusCode == 422 {
            await fetch()
        } catch is DecodingError {
            await fetch()
        } catch let error as APIError {
            if error.statusCode == 401 { needsReauth = true; errorMessage = error.message }
            // Otherwise transient — keep the loaded window; the next live/foreground tick retries.
        } catch {
            // Transient network error — keep the loaded window; retry on the next tick.
        }
    }

    private func landInitialTIfNeeded(_ data: FlowWindowData) {
        // First load lands the scrubber on `now`; later refreshes keep the user's t.
        guard !didSetInitialT, let now = data.window.nowDate else { return }
        t = now
        didSetInitialT = true
    }

    /// The newest event/snapshot timestamp we hold — the `?since=` anchor. Nil when the lens
    /// carries neither layer (then a delta has nothing to anchor on → full load).
    private var newestLoadedTime: Date? {
        let eventTimes = (window?.events ?? []).compactMap { $0.time }
        let snapshotTimes = (window?.snapshots ?? []).compactMap { $0.time }
        return (eventTimes + snapshotTimes).max()
    }

    private static func iso(_ date: Date) -> String { FlowTime.formatter.string(from: date) }

    /// Present the last cached window for this (user, persona, scope) after a failed load.
    /// The full payload restores in memory so the past half scrubs fully offline; the caption
    /// times it. Never crosses users — the cache is keyed by (and re-checks) the user id.
    private func presentCachedIfPossible(scopeValue: String) {
        guard window == nil, let userId,
              let record = FlowWindowCache.load(userId: userId, persona: persona, scope: scopeValue),
              let env = try? Self.cacheDecoder.decode(Envelope<FlowWindowData>.self, from: record.window)
        else { return }
        window = env.data
        webLink = env.links?["web"] ?? webLink
        loadedScopeKey = scopeKey(scopeValue)
        if let floorsData = record.floors,
           let floorsEnv = try? Self.cacheDecoder.decode(Envelope<FlowFloorsDocument>.self, from: floorsData) {
            floors = floorsEnv.data
            floorsRawData = floorsData
        }
        // Land the scrubber on the cached `now` so the accumulated past is scrubbable offline.
        if let now = env.data.window.nowDate { t = now }
        didSetInitialT = true
        staleAsOf = record.capturedAt
    }

    /// Contribute the "next 4h" ghost count to the home-screen glance widget on every Flow
    /// Window load — house scope only (the glance is a house widget; a unit/floor ghost count
    /// would mislead). Merges so it never clobbers the RTDC occupancy / net-bed-need writer.
    private func writeHouseGlance(scopeValue: String) {
        guard scopeValue == "house", let window, let now = window.window.nowDate else { return }
        let horizon = now.addingTimeInterval(4 * 3600)
        let ghostCount = window.projections.filter {
            guard let time = $0.time else { return false }
            return time > now && time <= horizon
        }.count
        HouseGlanceCache.merge { $0.next4hGhostCount = ghostCount }
        WidgetCenter.shared.reloadTimelines(ofKind: HouseGlanceCache.widgetKind)
    }

    /// Descend into a floor (nil ascends back to the house). Personas whose lens serves
    /// bed-level state refetch at the new scope; everyone else re-frames client-side only.
    func descend(to floor: Int?) {
        selectedFloor = floor
        selection = nil
        guard persona == "evs" else { return }
        Task { await self.fetch() }
    }

    private func reload() async {
        guard didSetInitialT else { return }
        await refresh()
    }

    /// Foreground return: delta-refresh the head without disturbing the user's scrub position.
    func refreshForeground(bearer: String) async {
        guard didSetInitialT else { return }
        bearerToken = bearer
        await refresh()
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

    /// Floor rollups with occupancy re-read from the snapshot series at `t` — the heat
    /// time-travel behind the executive time-lapse and the PI process replay. Falls back to
    /// the live rollups when there is no snapshot series yet or `t` is at/near now (live
    /// truth beats an hourly checkpoint there).
    func floorRollups(at t: Date) -> [FlowFloorRollup] {
        let live = window?.spaces?.floors ?? []
        guard !(window?.snapshots.isEmpty ?? true),
              t < nowDate.addingTimeInterval(-30 * 60) else { return live }
        return live.map { floor in
            var occupiedSum = 0
            var staffedSum = 0
            let units = floor.units.map { unit -> FlowUnitRollup in
                guard let snap = censusAt(t, unitId: unit.unitId), let occupied = snap.occupied else {
                    // No checkpoint ≤ t for this unit — keep the live rollup (honest fallback).
                    occupiedSum += unit.occupied
                    staffedSum += unit.staffed
                    return unit
                }
                let staffed = snap.staffed ?? unit.staffed
                occupiedSum += occupied
                staffedSum += staffed
                let pct = staffed > 0 ? 100.0 * Double(occupied) / Double(staffed) : 0
                return FlowUnitRollup(
                    unitId: unit.unitId, abbr: unit.abbr, name: unit.name, type: unit.type,
                    serviceLine: unit.serviceLine, acuity: unit.acuity, cadCode: unit.cadCode,
                    facilitySpaceId: unit.facilitySpaceId, staffed: staffed, occupied: occupied,
                    available: snap.available ?? unit.available, blocked: snap.blocked ?? unit.blocked,
                    occupancyPct: snap.occupancyPct ?? pct, evsOpen: unit.evsOpen,
                    transportActive: unit.transportActive, barriersOpen: unit.barriersOpen,
                    staffingStatus: unit.staffingStatus)
            }
            let pct = staffedSum > 0 ? 100.0 * Double(occupiedSum) / Double(staffedSum) : 0
            return FlowFloorRollup(
                floor: floor.floor, label: floor.label, units: units, staffed: staffedSum,
                occupied: occupiedSum, available: floor.available, blocked: floor.blocked,
                occupancyPct: pct, evsOpen: floor.evsOpen,
                transportActive: floor.transportActive, barriersOpen: floor.barriersOpen)
        }
    }

    /// bed_id → current state (the turn-map tint source). Empty unless the server sent
    /// `bed_statuses` (floor/unit scope + a bed_status-granted lens).
    var bedStatesById: [Int: FlowBedStatus] {
        Dictionary((window?.bedStatuses ?? []).map { ($0.bedId, $0) }, uniquingKeysWith: { first, _ in first })
    }

    /// bed label ("MICU-01") → bed_id, for joining events whose to_space is a bed label.
    var bedIdByLabel: [String: Int] {
        Dictionary((window?.bedStatuses ?? []).compactMap { status in
            status.label.map { ($0, status.bedId) }
        }, uniquingKeysWith: { first, _ in first })
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
    /// restart from the beginning of the past half. `rate` (sim-seconds per wall-second)
    /// overrides the persona pace (`playRate`), which overrides the default proportional
    /// pace (a full 24h past half in `replayDuration` seconds).
    func play(from start: Date? = nil, rate rateOverride: TimeInterval? = nil) {
        pause()
        let now = nowDate
        if let start { t = clamp(start) }
        if t >= now { t = fromDate }
        let span = now.timeIntervalSince(t)
        guard span > 1 else { return }
        let rate: TimeInterval // sim-seconds per wall-second
        if let fixed = rateOverride ?? playRate, fixed > 0 {
            rate = fixed
        } else {
            let fullSpan: TimeInterval = 24 * 3600
            let duration = max(3, replayDuration * span / fullSpan)
            rate = span / duration
        }
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
