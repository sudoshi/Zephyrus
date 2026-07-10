import Foundation

enum OperationalDuration {
    static func seconds(_ value: Double, compact: Bool = false) -> String {
        guard value.isFinite else { return "--" }

        let totalSeconds = Int(abs(value).rounded())
        let negative = value < 0 && totalSeconds > 0
        let hours = totalSeconds / 3_600
        let minutes = (totalSeconds % 3_600) / 60
        let seconds = totalSeconds % 60
        var parts: [String] = []

        if hours > 0 {
            parts.append(compact ? "\(hours)h" : "\(hours) hr")
        }
        if hours > 0 || minutes > 0 {
            parts.append(compact ? "\(minutes)m" : "\(minutes) min")
        }
        parts.append(compact ? "\(seconds)s" : "\(seconds) sec")

        return (negative ? "-" : "") + parts.joined(separator: " ")
    }

    static func minutes(_ value: Double, compact: Bool = false) -> String {
        seconds(value * 60, compact: compact)
    }

    static func age(since date: Date, relativeTo reference: Date = Date()) -> String {
        let elapsed = reference.timeIntervalSince(date)
        guard elapsed >= -0.5 else { return "scheduled" }

        return "\(seconds(max(elapsed, 0))) ago"
    }
}
