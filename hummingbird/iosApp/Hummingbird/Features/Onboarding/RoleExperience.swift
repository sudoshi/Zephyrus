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

    let homeTitle: String
    let homeFocus: String
    let censusScope: CensusScope
    let queueTitle: String
    let emptyQueue: String
    let queueFilter: QueueFilter

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
                         emptyQueue: "No pending placements or full units.", queueFilter: .placements)
        case "house_supervisor":
            return .init(homeTitle: "House Status", homeFocus: "Status & escalations",
                         censusScope: .house, queueTitle: "Escalations",
                         emptyQueue: "No open escalations right now.", queueFilter: .escalations)
        case "evs":
            return .init(homeTitle: "Bed Turns", homeFocus: "Dirty & blocked beds",
                         censusScope: .turns, queueTitle: "Turn priority",
                         emptyQueue: "No cleaning tasks queued yet.", queueFilter: .turns)
        case "transport":
            return .init(homeTitle: "Transport", homeFocus: "Moves & trips",
                         censusScope: .house, queueTitle: "Requests",
                         emptyQueue: "No transport requests yet.", queueFilter: .none)
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
