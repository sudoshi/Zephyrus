import Foundation

/// The rationed status vocabulary shared with the BFF
/// (`success|warning|critical|info`). Foundation-only so contract fixtures can
/// compile without linking the UI framework.
enum CapacityStatus: String {
    case success, warning, critical, info

    init(apiValue: String) {
        self = CapacityStatus(rawValue: apiValue) ?? .info
    }

    /// A short label so status is never communicated by color alone.
    var label: String {
        switch self {
        case .critical: return "At capacity"
        case .warning: return "Near capacity"
        case .success: return "Within capacity"
        case .info: return "No data"
        }
    }

    var symbol: String {
        switch self {
        case .critical: return "exclamationmark.triangle.fill"
        case .warning: return "exclamationmark.circle.fill"
        case .success: return "checkmark.circle.fill"
        case .info: return "minus.circle.fill"
        }
    }

    /// Severity rank for rolling up a worst-case house status.
    var severity: Int {
        switch self {
        case .info: return 0
        case .success: return 1
        case .warning: return 2
        case .critical: return 3
        }
    }
}
