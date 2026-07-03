import Foundation

// DTOs matching the Hummingbird BFF (hummingbird-bff.v1.yaml). The decoder uses
// .convertFromSnakeCase, so snake_case JSON maps to camelCase here.

/// POST /api/auth/token — either a token pair, or a must-change-password challenge.
struct TokenResponse: Decodable {
    let tokenType: String?
    let accessToken: String?
    let refreshToken: String?
    let expiresIn: Int?
    let abilities: [String]?
    let passwordChangeRequired: Bool?
    let changeToken: String?
}

/// The uniform BFF envelope: { data, meta, links }.
struct Envelope<T: Decodable>: Decodable {
    let data: T
    let meta: Meta?
    let links: [String: String]?
}

struct Meta: Decodable {
    let asOf: String?
    let stale: Bool?
    let version: Int?
    let count: Int?
    let nextCursor: String?
}

/// Lossless-enough JSON value for open-ended BFF fields (`scope`, `relay`, `phi_policy`,
/// provenance payloads). Keeps the Swift DTOs compile-time safe without hard-coding every key.
indirect enum JSONValue: Decodable {
    case string(String)
    case int(Int)
    case double(Double)
    case bool(Bool)
    case object([String: JSONValue])
    case array([JSONValue])
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() {
            self = .null
        } else if let value = try? container.decode(Bool.self) {
            self = .bool(value)
        } else if let value = try? container.decode(Int.self) {
            self = .int(value)
        } else if let value = try? container.decode(Double.self) {
            self = .double(value)
        } else if let value = try? container.decode(String.self) {
            self = .string(value)
        } else if let value = try? container.decode([String: JSONValue].self) {
            self = .object(value)
        } else if let value = try? container.decode([JSONValue].self) {
            self = .array(value)
        } else {
            self = .null
        }
    }

    var stringValue: String? {
        if case let .string(value) = self { return value }
        return nil
    }

    var displayString: String {
        switch self {
        case .string(let value): return value
        case .int(let value): return "\(value)"
        case .double(let value): return "\(value)"
        case .bool(let value): return value ? "yes" : "no"
        case .object(let value): return "\(value.count) fields"
        case .array(let value): return "\(value.count) items"
        case .null: return "—"
        }
    }
}

struct MobilePersona: Decodable {
    let roleId: String?
    let assignmentScope: String?
    let title: String?
    let home: String?
    let focus: String?
    let question: String?
    let web: String?
}

struct OperationalStatus: Decodable {
    let value: String?
    let glyph: String?
    let label: String?
    let generatedAt: String?

    var capacity: CapacityStatus { CapacityStatus(apiValue: value ?? "info") }
}

struct Provenance: Decodable {
    let sourceService: String?
    let metricKey: String?
    let snapshotVersion: String?
    let stale: Bool?
    let generatedAt: String?
}

struct MobileAction: Decodable, Identifiable {
    let kind: String
    let label: String?
    let endpoint: String?
    let method: String?
    let requiresOnline: Bool?

    var id: String { "\(kind)|\(endpoint ?? label ?? "")" }
}

struct OperationalDependency: Decodable, Identifiable {
    let type: String?
    let dependencyType: String?
    let ownerRole: String?
    let ownerUser: String?
    let downstreamRoles: [String]?
    let downstreamPatientRefs: [String]?
    let status: String?
    let label: String?
    let entityRef: String?

    var id: String {
        [dependencyType ?? type ?? "dependency", ownerRole, status, label, entityRef]
            .compactMap { $0 }
            .joined(separator: "|")
    }

    var displayType: String { dependencyType ?? type ?? "dependency" }
}

/// GET /api/mobile/v1/me
struct MeData: Decodable {
    let id: Int
    let name: String
    let username: String
    let email: String?
    let roles: [String]
    let isAdmin: Bool
    let workflowPreference: String?
    let mustChangePassword: Bool
    let units: [UnitAssignment]
}

struct UnitAssignment: Decodable, Identifiable {
    let unitId: Int
    let name: String
    let role: String?
    let isPrimary: Bool
    var id: Int { unitId }
}

/// GET /api/mobile/v1/rtdc/census — one entry per unit.
struct CensusUnit: Decodable, Identifiable {
    let unitId: Int
    let name: String
    let type: String
    let staffedBedCount: Int
    let occupied: Int
    let available: Int
    let blocked: Int
    let canAdmit: Int
    let bedNeed: Int
    let status: String

    var id: Int { unitId }
    var capacity: CapacityStatus { CapacityStatus(apiValue: status) }
}

/// GET /api/mobile/v1/for-you — one prioritized, PHI-minimized action item.
struct ForYouItem: Decodable, Identifiable {
    let id: String
    let type: String
    let domain: String?
    let altitude: String?
    let tier: String
    let visualStatus: String?
    let status: String?
    let statusDetail: OperationalStatus?
    let title: String
    let subtitle: String
    let unit: String?
    let at: String?
    let patientContextRef: String?
    let dependencies: [OperationalDependency]?
    let recommendedActions: [MobileAction]?
    let activity: [ActivityEvent]?
    let provenance: Provenance?

    var capacity: CapacityStatus { CapacityStatus(apiValue: visualStatus ?? status ?? tier) }
}

/// GET /api/mobile/v1/transport/queue — the transporter's "My Trips" home payload.
struct TransportQueue: Decodable {
    let metrics: TransportMetrics
    let jobs: [TransportJob]
}

struct TransportMetrics: Decodable {
    let active: Int
    let stat: Int
    let atRisk: Int
    let completedToday: Int
}

/// One PHI-minimized transport job (no patient ref, no free-text).
struct TransportJob: Decodable, Identifiable {
    let id: Int
    let uuid: String?
    let type: String
    let priority: String
    let status: String
    let tier: String
    let visualStatus: String?
    let origin: String?
    let destination: String?
    let mode: String?
    let neededAt: String?
    let patientContextRef: String?
    let sla: TransportSla

    var capacity: CapacityStatus { CapacityStatus(apiValue: visualStatus ?? tier) }
}

struct TransportSla: Decodable {
    let minutesUntilDue: Int?
    let atRisk: Bool
    let label: String
}

/// GET /api/mobile/v1/evs/queue — the EVS tech's "Bed Turns" home payload.
struct EvsQueue: Decodable {
    let metrics: EvsMetrics
    let turns: [EvsTurn]
}

struct EvsMetrics: Decodable {
    let pending: Int
    let overdue: Int
    let isolation: Int
    let completedToday: Int
}

/// One PHI-minimized bed-turn (no patient ref — just the bed, turn type, isolation, SLA).
struct EvsTurn: Decodable, Identifiable {
    let id: Int
    let uuid: String?
    let requestType: String
    let priority: String
    let status: String
    let tier: String
    let visualStatus: String?
    let locationLabel: String?
    let unitId: Int?
    let turnType: String?
    let isolationRequired: Bool
    let neededAt: String?
    let patientContextRef: String?
    let sla: EvsSla

    var capacity: CapacityStatus { CapacityStatus(apiValue: visualStatus ?? tier) }
}

struct EvsSla: Decodable {
    // Double? not Int?: Laravel 11 / Carbon 3 returns a float from diffInMinutes(…, false), and the
    // staffing service surfaces it uncast. Double decodes both JSON ints and floats.
    let minutesUntilDue: Double?
    let atRisk: Bool
    let label: String
}

/// GET /api/mobile/v1/rtdc/house — the bed manager's "House Capacity" roll-up.
struct HouseRollup: Decodable {
    let occupancy: Occupancy
    let netBedNeed: Int
    let pendingPlacements: Int
    let edBoarding: Int
    let units: [CensusUnit]
}

struct Occupancy: Decodable {
    let occupied: Int
    let staffed: Int
    let percent: Int
}

/// GET /api/mobile/v1/rtdc/bed-requests — one pending placement (PHI-minimized).
struct Placement: Decodable, Identifiable {
    let id: Int
    let source: String?
    let service: String?
    let acuityTier: Int?
    let tier: String
    let visualStatus: String?
    let isolationRequired: String?   // "none" | "contact" | "droplet" | "airborne"
    let requiredUnitType: String?
    let at: String?
    let patientContextRef: String?

    var capacity: CapacityStatus { CapacityStatus(apiValue: visualStatus ?? tier) }
    var needsIsolation: Bool { isolationRequired != nil && isolationRequired != "none" }
}

/// GET …/bed-requests/{id}/recommendations — ranked candidate beds with transparent scores.
struct PlacementRecs: Decodable {
    let recommendations: [PlacementRecommendation]
    let runnerUpDelta: Int?
}

struct PlacementRecommendation: Decodable, Identifiable {
    let bedId: Int
    let bedLabel: String
    let unitName: String
    let score: Int
    let chips: [PlacementChip]
    var id: Int { bedId }
}

struct PlacementChip: Decodable {
    let label: String
    let ok: Bool
}

// MARK: Executive / Capacity (P9 / P6) — GET /command/house

struct HouseBrief: Decodable {
    let strain: ExecStrain
    let hero: [HeroKpi]
}

struct ExecStrain: Decodable {
    let level: Int
    let label: String
    let status: String
    let previousLevel: Int
    let drivers: [StrainDriver]
    var capacity: CapacityStatus { CapacityStatus(apiValue: status) }
}

struct StrainDriver: Decodable, Identifiable {
    let label: String
    let value: String
    let status: String
    var id: String { label }
    var capacity: CapacityStatus { CapacityStatus(apiValue: status) }
}

struct HeroKpi: Decodable, Identifiable {
    let key: String
    let label: String
    let display: String
    let status: String
    let targetDisplay: String?
    var id: String { key }
    var capacity: CapacityStatus { CapacityStatus(apiValue: status) }
}

// MARK: OR board (P4 / P7) — GET /or/board

struct ORBoard: Decodable {
    let rooms: [ORRoom]
    let metrics: ORMetrics
}

struct ORMetrics: Decodable {
    let running: Int
    let turnover: Int
    let available: Int
    let total: Int
    let avgTurnoverMin: Int
}

struct ORRoom: Decodable, Identifiable {
    let id: Int
    let name: String
    let status: String
    let tier: String
    let visualStatus: String?
    let timeRemaining: Int?
    let turnoverMin: Int?
    let current: ORCaseInfo?
    let next: ORNextInfo?
    var capacity: CapacityStatus { CapacityStatus(apiValue: visualStatus ?? tier) }
}

struct ORCaseInfo: Decodable {
    let procedure: String
    let surgeon: String
    let elapsed: Int
    let expectedDuration: Int
    let expectedEnd: String?
    let startTime: String?
}

struct ORNextInfo: Decodable {
    let startTime: String?
    let procedure: String
}

// MARK: Ops approvals inbox (P6) — GET /ops/inbox

struct OpsApproval: Decodable, Identifiable {
    let approvalUuid: String
    let title: String
    let rationale: String?
    let type: String?
    let risk: String?
    let tier: String
    let visualStatus: String?
    let owner: String?
    let requestedAt: String?
    var id: String { approvalUuid }
    var capacity: CapacityStatus { CapacityStatus(apiValue: visualStatus ?? tier) }
}

// MARK: Staffing (P10) — GET /staffing/overview

struct StaffingOverview: Decodable {
    let metrics: StaffingMetrics
    let unitsAtRisk: [UnitAtRisk]
    let queue: [StaffingReq]
}

struct StaffingMetrics: Decodable {
    let openRequests: Int
    let atRiskUnits: Int
    let criticalGaps: Int
    let coveragePct: Int
    let statRequests: Int
    let totalGapHeadcount: Int
}

struct UnitAtRisk: Decodable, Identifiable {
    let unitId: Int
    let unitLabel: String
    let status: String
    let gapHeadcount: Int
    let worstRoleLabel: String
    let belowMinimumSafe: Bool
    var id: Int { unitId }
    var capacity: CapacityStatus { CapacityStatus(apiValue: status == "critical_gap" ? "critical" : "warning") }
}

struct StaffingReq: Decodable, Identifiable {
    let staffingRequestId: Int
    let unitLabel: String?
    let roleLabel: String?
    let priority: String
    let status: String
    let headcountNeeded: Int?
    let sla: EvsSla
    var id: Int { staffingRequestId }
    var capacity: CapacityStatus {
        priority == "stat" ? .critical : (priority == "urgent" || sla.atRisk ? .warning : .info)
    }
}

// MARK: Improvement / PI (P8) — GET /improvement/pdsa + /improvement/opportunities

struct PdsaCycle: Decodable, Identifiable {
    let id: Int
    let title: String
    let status: String
    let owner: String?
    let objective: String?
    let unit: String?
    let startedAt: String?
    let targetDate: String?
}

struct Opportunity: Decodable, Identifiable {
    let id: Int
    let title: String
    let description: String?
    let department: String?
    let priority: String
    let status: String
    let impact: Int?
    var priorityTier: CapacityStatus { priority == "High" ? .critical : (priority == "Medium" ? .warning : .info) }
}

// MARK: Altitude 2.0

struct AltitudeTile: Decodable, Identifiable {
    let key: String
    let label: String
    let value: String
    let status: String
    let provenance: Provenance?

    var id: String { key }
    var capacity: CapacityStatus { CapacityStatus(apiValue: status) }
}

struct MobileAltitudeHome: Decodable {
    let altitude: String
    let persona: MobilePersona?
    let status: OperationalStatus?
    let generatedAt: String?
    let glanceQuestion: String?
    let tiles: [AltitudeTile]?
    let forYouHead: [ForYouItem]?
    let activity: [ActivityEvent]?
    let subscriptions: [String: JSONValue]?
    let web: WebLink?
}

struct MobileAltitudeWorkspace: Decodable {
    let altitude: String
    let persona: MobilePersona?
    let domain: String?
    let generatedAt: String?
    let status: OperationalStatus?
    let workspace: WorkspacePayload?
    let activity: [ActivityEvent]?
    let web: WebLink?
}

struct WorkspacePayload: Decodable {
    let summary: WorkspaceSummary?
    let items: [JSONValue]?
}

struct WorkspaceSummary: Decodable {
    let label: String?
    let count: Int?
}

struct MobileAltitudeDrill: Decodable {
    let altitude: String
    let persona: MobilePersona?
    let itemUuid: String?
    let generatedAt: String?
    let domain: String?
    let status: OperationalStatus?
    let explanation: String?
    let dependencies: [OperationalDependency]?
    let activity: [ActivityEvent]?
    let patientContextRef: String?
    let actions: [MobileAction]?
    let web: WebLink?
}

struct WebLink: Decodable {
    let href: String?
    let label: String?
    let altitude: String?
}

struct ActivityEvent: Decodable, Identifiable {
    let eventUuid: String
    let eventType: String
    let occurredAt: String?
    let actorUserId: Int?
    let actorRole: String?
    let sourceSurface: String?
    let domain: String?
    let scope: [String: JSONValue]?
    let patientContextRef: String?
    let status: [String: JSONValue]?
    let recommendation: [String: JSONValue]?
    let relay: [String: JSONValue]?
    let phiPolicy: [String: JSONValue]?
    let entities: [ActivityEntity]?

    var id: String { eventUuid }

    var severity: CapacityStatus {
        if let raw = status?["severity"]?.stringValue { return CapacityStatus(apiValue: raw) }
        if let raw = status?["current"]?.stringValue { return CapacityStatus(apiValue: raw) }
        return .info
    }

    var currentStatus: String? {
        status?["current"]?.stringValue ?? status?["status"]?.stringValue
    }
}

struct ActivityEntity: Decodable {
    let entityType: String?
    let entityRef: String?
    let patientContextRef: String?
    let encounterRef: String?
}

struct ActivityAck: Decodable {
    let eventUuid: String
    let acknowledged: Bool
    let acknowledgedAt: String?
}

struct PatientOperationalContext: Decodable {
    let altitude: String
    let persona: MobilePersona?
    let patient: OperationalPatient
    let header: PatientContextHeader
    let statusSpine: [PatientStatusSpineItem]
    let timeline: [PatientTimelineEvent]
    let dependencies: [OperationalDependency]
    let recommendations: [PatientRecommendation]?
    let actions: [MobileAction]?
    let relay: PatientRelay?
    let activity: [ActivityEvent]?
    let web: WebLink?
    let phiPolicy: PatientPhiPolicy?
}

struct OperationalPatient: Decodable {
    let patientContextRef: String?
    let display: String?
    let phiMinimized: Bool?
}

struct PatientContextHeader: Decodable {
    let currentLocation: String?
    let targetLocation: String?
    let service: String?
    let isolationRequired: Bool?
    let responsibleTeam: String?
    let asOf: String?
}

struct PatientStatusSpineItem: Decodable, Identifiable {
    let domain: String?
    let label: String?
    let status: String?
    let at: String?

    var id: String { [domain, label, status, at].compactMap { $0 }.joined(separator: "|") }
}

struct PatientTimelineEvent: Decodable, Identifiable {
    let eventType: String?
    let domain: String?
    let actorRole: String?
    let statusBefore: String?
    let statusAfter: String?
    let occurredAt: String?
    let affectedDependency: String?

    var id: String { [eventType, domain, actorRole, statusAfter, occurredAt].compactMap { $0 }.joined(separator: "|") }
}

struct PatientRecommendation: Decodable, Identifiable {
    let recommendationUuid: String?
    let source: String?
    let title: String?
    let status: String?
    let riskLevel: String?
    let rationale: String?
    let runnerUp: String?

    var id: String { recommendationUuid ?? [source, title, status].compactMap { $0 }.joined(separator: "|") }
}

struct PatientRelay: Decodable {
    let willNotifyRoles: [String]?
    let activityRoles: [String]?
}

struct PatientPhiPolicy: Decodable {
    let listSafe: Bool?
    let pushSafe: Bool?
    let requiresDetailAuth: Bool?
}

struct EddyContextPacket: Decodable {
    let scopeRef: String
    let scopeType: String?
    let generatedAt: String?
    let persona: MobilePersona?
    let phiPolicy: [String: JSONValue]?
    let context: [String: JSONValue]?
    let questionsSupported: [String]?
}

/// An error surfaced from the API (either a Laravel `{message}` or a BFF `{error:{message}}`).
struct APIError: Error {
    let message: String
    let statusCode: Int?
}
