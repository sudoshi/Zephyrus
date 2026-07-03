// Semantic theme mapping + spacing scale for Hummingbird. Dark-default (the app forces
// UIUserInterfaceStyle = Dark for v1), so these resolve the dark token variants. The
// rationed status ramp maps the BFF's status string to color + label + SF Symbol so the
// UI can honor "status never by color alone."

import SwiftUI
import UIKit

enum Z {
    /// Dynamic-Type-aware system font: scales the base point size with the user's text-size
    /// setting (body metrics). Use for all reader-facing text in shared components.
    static func scaledFont(_ size: CGFloat, weight: Font.Weight = .regular) -> Font {
        .system(size: UIFontMetrics(forTextStyle: .body).scaledValue(for: size), weight: weight)
    }

    // Operational surfaces & ink
    static let bg = ZephyrusColors.operationalSurfaceBaseDark
    static let surface = ZephyrusColors.operationalSurfaceRaisedDark
    static let border = ZephyrusColors.operationalBorderDark
    static let ink = ZephyrusColors.operationalInkDark
    static let inkMuted = ZephyrusColors.operationalInkMutedDark
    static let primary = ZephyrusColors.operationalPrimaryDark
    static let gold = ZephyrusColors.brandGoldDark

    // 4px spacing grid
    static let s1: CGFloat = 4
    static let s2: CGFloat = 8
    static let s3: CGFloat = 12
    static let s4: CGFloat = 16
    static let s5: CGFloat = 20
    static let s6: CGFloat = 24
    static let radius: CGFloat = 14

    static func status(_ s: CapacityStatus) -> Color {
        switch s {
        case .success: return ZephyrusColors.statusSuccessDark
        case .warning: return ZephyrusColors.statusWarningDark
        case .critical: return ZephyrusColors.statusCriticalDark
        case .info: return ZephyrusColors.statusInfoDark
        }
    }
}

/// The rationed status vocabulary, shared with the BFF (`success|warning|critical|info`).
enum CapacityStatus: String {
    case success, warning, critical, info

    init(apiValue: String) { self = CapacityStatus(rawValue: apiValue) ?? .info }

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
