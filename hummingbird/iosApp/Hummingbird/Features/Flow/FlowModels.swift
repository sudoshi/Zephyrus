import Foundation

// DTOs for the 48-hour Flow Window (FLOW-WINDOW-PLAN §5/§7) — the persona-lensed
// spatiotemporal payload. Shapes match the shared fixtures exactly
// (docs/hummingbird/api-contract/fixtures/mobile-flow-window.json + mobile-flow-floors.json);
// the decoder uses .convertFromSnakeCase, so snake_case JSON maps to camelCase here.
// All timestamps stay ISO-8601 Strings on the wire and parse lazily via `FlowTime`.

// MARK: Time

/// Shared ISO-8601 parsing for flow payload timestamps ("2026-07-04T01:27:05+00:00" —
/// internet date-time, no fractional seconds).
enum FlowTime {
    static let formatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    /// Fallback for meta-style timestamps that carry fractional seconds.
    private static let fractional: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    static func parse(_ raw: String?) -> Date? {
        guard let raw else { return nil }
        return formatter.date(from: raw) ?? fractional.date(from: raw)
    }
}

/// Ghost confidence vocabulary (D3). Renderers map confidence to opacity — never to a
/// status color; copy says "probable/possible", never "will".
enum FlowConfidence: String {
    case definite, probable, possible

    init(apiValue: String?) { self = FlowConfidence(rawValue: apiValue ?? "") ?? .possible }

    var ghostOpacity: Double {
        switch self {
        case .definite: return 0.8
        case .probable: return 0.5
        case .possible: return 0.3
        }
    }
}

// MARK: GET /api/mobile/v1/flow/window

struct FlowWindowData: Decodable, Equatable {
    let window: FlowWindow
    let lens: FlowLens
    let scope: FlowScope
    let spaces: FlowSpaces?
    let snapshots: [FlowSnapshot]
    let events: [FlowTimelineEvent]
    let projections: [FlowProjection]

    // The server omits any layer the lens excludes (the executive lens has
    // no `events` at all), so every layer decodes as absent-means-empty.
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        window = try container.decode(FlowWindow.self, forKey: .window)
        lens = try container.decode(FlowLens.self, forKey: .lens)
        scope = try container.decode(FlowScope.self, forKey: .scope)
        spaces = try container.decodeIfPresent(FlowSpaces.self, forKey: .spaces)
        snapshots = try container.decodeIfPresent([FlowSnapshot].self, forKey: .snapshots) ?? []
        events = try container.decodeIfPresent([FlowTimelineEvent].self, forKey: .events) ?? []
        projections = try container.decodeIfPresent([FlowProjection].self, forKey: .projections) ?? []
    }

    private enum CodingKeys: String, CodingKey {
        case window, lens, scope, spaces, snapshots, events, projections
    }
}

struct FlowWindow: Decodable, Equatable {
    let from: String
    let to: String
    let now: String

    var fromDate: Date? { FlowTime.parse(from) }
    var toDate: Date? { FlowTime.parse(to) }
    var nowDate: Date? { FlowTime.parse(now) }
}

/// The role lens the server applied: which scopes/layers/kinds this persona may see.
struct FlowLens: Decodable, Equatable {
    let roleId: String
    let scopeDefault: String?
    let scopesAllowed: [String]
    let layers: [String]
    let eventKinds: [String]
    let projectionKinds: [String]
    let patientDots: String
    let actions: [String]
    let defaultZoomHours: Int?
}

struct FlowScope: Decodable, Equatable {
    let type: String
    let floor: Int?
    let unitId: Int?
    let patientContextRef: String?
    let label: String?
}

struct FlowSpaces: Decodable, Equatable {
    let platesVersion: String?
    let floors: [FlowFloorRollup]
}

/// Per-floor census rollup (heat source for HouseStack + FloorPlate fills).
struct FlowFloorRollup: Decodable, Equatable, Identifiable {
    let floor: Int
    let label: String
    let units: [FlowUnitRollup]
    let staffed: Int
    let occupied: Int
    let available: Int
    let blocked: Int
    let occupancyPct: Double
    let evsOpen: Int
    let transportActive: Int
    let barriersOpen: Int

    var id: Int { floor }
}

struct FlowUnitRollup: Decodable, Equatable, Identifiable {
    let unitId: Int
    let abbr: String?
    let name: String?
    let type: String?
    let serviceLine: String?
    let acuity: String?
    let cadCode: String?
    let facilitySpaceId: Int?
    let staffed: Int
    let occupied: Int
    let available: Int
    let blocked: Int
    let occupancyPct: Double
    let evsOpen: Int
    let transportActive: Int
    let barriersOpen: Int
    let staffingStatus: String?

    var id: Int { unitId }
}

/// Hourly census checkpoint (D2 seek semantics: state at t = nearest snapshot ≤ t).
/// The fixture ships an empty array; fields mirror the per-unit rollup vocabulary.
struct FlowSnapshot: Decodable, Equatable {
    let t: String
    let unitId: Int?
    let occupied: Int?
    let staffed: Int?
    let available: Int?
    let blocked: Int?
    let occupancyPct: Double?

    var time: Date? { FlowTime.parse(t) }
}

struct FlowEntityRef: Decodable, Equatable {
    let type: String?
    let ref: String?
}

/// One normalized past event (§6.2 OperationalTimelineService shape).
struct FlowTimelineEvent: Decodable, Equatable, Identifiable {
    let t: String
    let kind: String
    let entity: FlowEntityRef?
    let patientContextRef: String?
    let fromSpace: String?
    let toSpace: String?
    let unitId: Int?
    let label: String?
    let tier: String?
    let provenance: FlowEventProvenance?

    var id: String {
        [t, kind, entity?.type, entity?.ref, toSpace, label].compactMap { $0 }.joined(separator: "|")
    }

    var time: Date? { FlowTime.parse(t) }
    var capacity: CapacityStatus { CapacityStatus(apiValue: tier ?? "info") }
}

struct FlowEventProvenance: Decodable, Equatable {
    let source: String?
}

struct FlowBand: Decodable, Equatable {
    let lower: Double?
    let upper: Double?
}

/// One typed projection item on the future half (D3): confidence + provenance mandatory;
/// renderers draw these as ghosts (dashed, translucent, never a solid fill).
struct FlowProjection: Decodable, Equatable, Identifiable {
    let t: String
    let kind: String
    let confidence: String
    let unitId: Int?
    let bedId: Int?
    let entity: FlowEntityRef?
    let patientContextRef: String?
    let label: String?
    let value: Double?
    let band: FlowBand?
    let endsAt: String?
    let derived: Bool?
    let provenance: FlowProjectionProvenance?

    var id: String {
        [t, kind, confidence, unitId.map(String.init), bedId.map(String.init), label]
            .compactMap { $0 }.joined(separator: "|")
    }

    var time: Date? { FlowTime.parse(t) }
    var confidenceLevel: FlowConfidence { FlowConfidence(apiValue: confidence) }
}

struct FlowProjectionProvenance: Decodable, Equatable {
    let service: String?
    let reliability: Double?

    /// Defensible-by-default chip copy: "Source: demand_forecast · reliability 0.86".
    var chipText: String {
        var parts = ["Source: \(service ?? "unknown")"]
        if let reliability { parts.append("reliability \(String(format: "%.2f", reliability))") }
        return parts.joined(separator: " · ")
    }
}

// MARK: GET /api/mobile/v1/flow/floors

/// The versioned floor-plates asset (§6.1): simplified 2D plate rects per floor,
/// plan-view feet with a top-left origin; `rect` = [x, y, w, h].
struct FlowFloorsDocument: Decodable, Equatable {
    let version: String
    let facility: FlowFacility?
    let floors: [FlowFloor]
}

struct FlowFacility: Decodable, Equatable {
    let code: String?
    let cadCode: String?
    let name: String?
}

struct FlowFloor: Decodable, Equatable, Identifiable {
    let floor: Int
    let label: String
    let bounds: [Double]
    let shapeCount: Int?
    let spaces: [FlowPlate]

    var id: Int { floor }
}

struct FlowPlate: Decodable, Equatable, Identifiable {
    let id: Int
    let code: String?
    let category: String
    let label: String?
    let rect: [Double]
    let unitId: Int?
    let bedId: Int?
}
