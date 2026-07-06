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

    /// The top-bar avatar diameter — Eddy (top-left) and the profile circle (top-right)
    /// share it so the two chrome circles are exactly the same size.
    static let topAvatar: CGFloat = 40

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

// MARK: - Hex parsing for server-driven categorical palettes

/// "#RRGGBB" → components in 0...1. Used by the data-viz palettes the BFF ships (e.g. the
/// Flow 4D service-line legend), which are categorical, NOT the semantic status ramp above.
private func flowHexComponents(_ hex: String) -> (r: Double, g: Double, b: Double)? {
    var s = hex.trimmingCharacters(in: .whitespacesAndNewlines)
    if s.hasPrefix("#") { s.removeFirst() }
    guard s.count == 6, let value = UInt32(s, radix: 16) else { return nil }
    return (Double((value >> 16) & 0xFF) / 255.0,
            Double((value >> 8) & 0xFF) / 255.0,
            Double(value & 0xFF) / 255.0)
}

extension Color {
    /// Parses "#RRGGBB"; falls back to a neutral slate if malformed.
    init(flowHex hex: String) {
        let c = flowHexComponents(hex) ?? (0.34, 0.38, 0.45)
        self.init(red: c.r, green: c.g, blue: c.b)
    }
}

extension UIColor {
    /// Parses "#RRGGBB"; falls back to a neutral slate if malformed.
    convenience init(flowHex hex: String, alpha: CGFloat = 1) {
        let c = flowHexComponents(hex) ?? (0.34, 0.38, 0.45)
        self.init(red: c.r, green: c.g, blue: c.b, alpha: alpha)
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
