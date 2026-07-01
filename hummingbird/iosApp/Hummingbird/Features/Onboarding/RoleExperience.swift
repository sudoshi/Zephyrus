import Foundation

/// How a confirmed role shapes the app's primary surfaces. Every persona gets a tailored Home
/// (title, focus, and a census scoped to their world) and a tailored For You queue (title +
/// filter), derived from data the BFF already provides. Deeper role-specific feeds — EVS turn
/// tickets, transport trips, hospitalist discharge worklists, bedside patient lists — arrive
/// when those backends land; until then a role sees the best available proxy, honestly framed.
struct RoleExperience {
    /// Which slice of the census a role leads with.
    enum CensusScope: Equatable {
        case house          // every unit (flow / supervisor / transport)
        case criticalCare   // ICU + step-down only (intensivist)
        case unitFocused    // the worker's unit first, rest of house as context
        case turns          // sorted by dirty/blocked beds (EVS)
    }

    /// Which queue items matter to a role.
    enum QueueFilter {
        case all            // placements + barriers + capacity
        case placements     // inbound bed requests + at-capacity units
        case escalations    // open barriers + at-capacity units
        case myUnit         // items on the worker's unit (+ inbound placements)
        case criticalCare   // items on ICU/step-down units (+ ICU placements)
        case turns          // at-capacity units (cleaning pressure) — full feed pending
        case none           // no relevant feed yet (honest empty)
    }

    /// Which Home surface a role lands on. `.census` is the shared role-scoped capacity glance
    /// (the original Home); bespoke homes are added per-wave as their BFF feed lands. The first
    /// tab of the shell renders this; everything else (For You) stays shared.
    enum HomeKind: Equatable {
        case census             // role-scoped census glance (default)
        case transportJobs      // P1 — "My Trips" claim-and-run queue
        case evsTurns           // P2 — "Bed Turns" next-dirty-bed queue
        case houseCapacity      // P5 — bed manager "House Capacity" + placements
        case orBoard            // P4/P7 — live OR room board
        case capacityDemand     // P6 — capacity/demand + ops approvals inbox
        case houseBrief         // P9 — executive strain + hero KPIs
        case staffing           // P10 — staffing gaps + requests
        case improvement        // P8 — PDSA cycles + opportunities

        var tabLabel: String {
            switch self {
            case .transportJobs: return "Trips"
            case .evsTurns: return "Turns"
            case .houseCapacity, .census: return "House"
            case .orBoard: return "OR"
            case .capacityDemand: return "Capacity"
            case .houseBrief: return "Brief"
            case .staffing: return "Staffing"
            case .improvement: return "Improve"
            }
        }

        var tabSymbol: String {
            switch self {
            case .transportJobs: return "figure.walk"
            case .evsTurns: return "sparkles"
            case .houseCapacity: return "bed.double.fill"
            case .census: return "building.2.fill"
            case .orBoard: return "cross.case.fill"
            case .capacityDemand: return "chart.bar.fill"
            case .houseBrief: return "chart.line.uptrend.xyaxis"
            case .staffing: return "person.3.fill"
            case .improvement: return "arrow.triangle.2.circlepath"
            }
        }
    }

    let homeTitle: String
    let homeFocus: String
    let censusScope: CensusScope
    let queueTitle: String
    let emptyQueue: String
    let queueFilter: QueueFilter
    var home: HomeKind = .census

    private static let criticalTypes: Set<String> = ["icu", "step_down"]

    static func of(_ roleId: String?) -> RoleExperience {
        switch roleId {
        case "charge_nurse":
            return .init(homeTitle: "Your Unit", homeFocus: "Placements, barriers & staffing",
                         censusScope: .unitFocused, queueTitle: "On your unit",
                         emptyQueue: "Nothing needs action on your unit right now.", queueFilter: .myUnit)
        case "bedside_nurse":
            return .init(homeTitle: "Your Unit", homeFocus: "Your unit at a glance",
                         censusScope: .unitFocused, queueTitle: "On your unit",
                         emptyQueue: "Nothing flagged on your unit right now.", queueFilter: .myUnit)
        case "hospitalist":
            return .init(homeTitle: "Your Service", homeFocus: "Your unit & discharges",
                         censusScope: .unitFocused, queueTitle: "Your service",
                         emptyQueue: "Nothing needs action on your service right now.", queueFilter: .myUnit)
        case "intensivist":
            return .init(homeTitle: "Critical Care", homeFocus: "ICU & step-down",
                         censusScope: .criticalCare, queueTitle: "Critical care",
                         emptyQueue: "No critical-care items need action right now.", queueFilter: .criticalCare)
        case "bed_manager":
            return .init(homeTitle: "House Capacity", homeFocus: "Placement & flow",
                         censusScope: .house, queueTitle: "Placement queue",
                         emptyQueue: "No pending placements or full units.", queueFilter: .placements,
                         home: .houseCapacity)
        case "house_supervisor":
            return .init(homeTitle: "House Status", homeFocus: "Status & escalations",
                         censusScope: .house, queueTitle: "Escalations",
                         emptyQueue: "No open escalations right now.", queueFilter: .escalations)
        case "evs":
            return .init(homeTitle: "Bed Turns", homeFocus: "Dirty & blocked beds",
                         censusScope: .turns, queueTitle: "Turn priority",
                         emptyQueue: "No cleaning tasks queued yet.", queueFilter: .turns,
                         home: .evsTurns)
        case "transport":
            return .init(homeTitle: "Transport", homeFocus: "Moves & trips",
                         censusScope: .house, queueTitle: "Requests",
                         emptyQueue: "No transport requests yet.", queueFilter: .none,
                         home: .transportJobs)
        case "or_nurse":
            // Greenfield OR surface (Wave 2). Until the OR board feed lands, an OR nurse sees the
            // house glance with an honestly-empty OR queue rather than a shrunk census.
            return .init(homeTitle: "Perioperative", homeFocus: "OR rooms, cases & safety",
                         censusScope: .house, queueTitle: "OR",
                         emptyQueue: "No OR items need action yet.", queueFilter: .none,
                         home: .orBoard)
        case "periop_manager":
            return .init(homeTitle: "OR Today", homeFocus: "Starts, turnover & delays",
                         censusScope: .house, queueTitle: "OR alerts",
                         emptyQueue: "No OR delays or cancellations right now.", queueFilter: .none,
                         home: .orBoard)
        case "capacity_lead":
            // Ops leader: capacity vs. demand + approvals. The full feed (placements + barriers +
            // capacity) is the honest proxy until the Ops approvals inbox lands (Wave 2).
            return .init(homeTitle: "Capacity & Demand", homeFocus: "Capacity, demand & approvals",
                         censusScope: .house, queueTitle: "Approvals & alerts",
                         emptyQueue: "Nothing needs your decision right now.", queueFilter: .all,
                         home: .capacityDemand)
        case "staffing_coordinator":
            return .init(homeTitle: "Staffing", homeFocus: "Open requests & gaps below safe",
                         censusScope: .house, queueTitle: "Staffing",
                         emptyQueue: "No staffing gaps or open requests yet.", queueFilter: .none,
                         home: .staffing)
        case "pi_lead":
            // Barriers are the available improvement signal until the PDSA feed lands (Wave 4).
            return .init(homeTitle: "Improvement", homeFocus: "Cycles, opportunities & barriers",
                         censusScope: .house, queueTitle: "Improvement",
                         emptyQueue: "No improvement items need action yet.", queueFilter: .escalations,
                         home: .improvement)
        case "executive":
            return .init(homeTitle: "House Brief", homeFocus: "Is the hospital OK?",
                         censusScope: .house, queueTitle: "Escalations",
                         emptyQueue: "No house escalations right now.", queueFilter: .escalations,
                         home: .houseBrief)
        default:
            return .init(homeTitle: "House Status", homeFocus: "",
                         censusScope: .house, queueTitle: "Needs you now",
                         emptyQueue: "Nothing needs your action right now.", queueFilter: .all)
        }
    }

    // MARK: Census scoping

    /// The unit pinned at the top for unit-focused roles (the worker's unit), if any.
    func pinnedUnit(_ units: [CensusUnit], myUnitId: Int?) -> CensusUnit? {
        guard censusScope == .unitFocused, let myUnitId else { return nil }
        return units.first { $0.unitId == myUnitId }
    }

    /// The units a role's Home lists (excluding any pinned unit, which is shown separately).
    func censusList(_ units: [CensusUnit], myUnitId: Int?) -> [CensusUnit] {
        switch censusScope {
        case .house:
            return units
        case .criticalCare:
            return units.filter { Self.criticalTypes.contains($0.type) }
        case .turns:
            return units.sorted { $0.blocked > $1.blocked }
        case .unitFocused:
            return units.filter { $0.unitId != myUnitId }
        }
    }

    // MARK: Queue filtering

    func keep(_ item: ForYouItem, unitsByName: [String: CensusUnit], myUnit: String?) -> Bool {
        switch queueFilter {
        case .all:
            return true
        case .none:
            return false
        case .placements:
            return item.type == "bed_request" || item.type == "capacity"
        case .escalations:
            return item.type == "barrier" || item.type == "capacity"
        case .turns:
            return item.type == "capacity"
        case .myUnit:
            guard let myUnit else { return true } // house-wide worker → don't over-filter
            return item.unit == myUnit || item.type == "bed_request"
        case .criticalCare:
            if item.type == "bed_request" {
                let s = item.subtitle.lowercased()
                return s.contains("icu") || s.contains("step")
            }
            if let name = item.unit, let unit = unitsByName[name] {
                return Self.criticalTypes.contains(unit.type)
            }
            return false
        }
    }
}
