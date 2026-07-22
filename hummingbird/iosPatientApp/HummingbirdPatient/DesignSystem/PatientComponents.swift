import Foundation
import SwiftUI

enum PatientPalette {
    static let ink = Color(uiColor: .label)
    static let blue = Color(uiColor: UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 0.38, green: 0.72, blue: 0.94, alpha: 1)
            : UIColor(red: 0.04, green: 0.35, blue: 0.58, alpha: 1)
    })
    static let teal = Color(uiColor: UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 0.32, green: 0.82, blue: 0.75, alpha: 1)
            : UIColor(red: 0.02, green: 0.42, blue: 0.40, alpha: 1)
    })
    static let amber = Color(uiColor: UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 1.0, green: 0.72, blue: 0.30, alpha: 1)
            : UIColor(red: 0.64, green: 0.33, blue: 0.01, alpha: 1)
    })
    static let rose = Color(uiColor: UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 1.0, green: 0.50, blue: 0.55, alpha: 1)
            : UIColor(red: 0.64, green: 0.08, blue: 0.14, alpha: 1)
    })
    static let surface = Color(uiColor: .secondarySystemBackground)
}

struct PatientScreenHeader: View {
    let eyebrow: String
    let title: String
    let subtitle: String

    var body: some View {
        VStack(alignment: .leading, spacing: 7) {
            Text(eyebrow.uppercased())
                .font(.caption.weight(.bold))
                .foregroundStyle(PatientPalette.blue)
                .accessibilityLabel(eyebrow)
            Text(title)
                .font(.largeTitle.bold())
                .foregroundStyle(PatientPalette.ink)
            Text(subtitle)
                .font(.body)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

struct PatientCard<Content: View>: View {
    @ViewBuilder let content: Content
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    @Environment(\.patientPresentationPreferences) private var presentationPreferences

    var body: some View {
        content
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(18)
            .background(
                PatientPalette.surface.opacity(
                    reduceTransparency || presentationPreferences.highContrast ? 1 : 0.93
                ),
                in: RoundedRectangle(cornerRadius: 20)
            )
            .overlay {
                RoundedRectangle(cornerRadius: 20)
                    .stroke(
                        Color.primary.opacity(
                            colorSchemeContrast == .increased || presentationPreferences.highContrast
                                ? 0.30
                                : 0.06
                        ),
                        lineWidth: colorSchemeContrast == .increased || presentationPreferences.highContrast ? 2 : 1
                    )
            }
    }
}

struct PatientFreshnessView: View {
    let snapshot: PatientExperienceSnapshot

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 10) {
                Label(snapshot.isStale ? "May be out of date" : freshnessLabel, systemImage: snapshot.isStale ? "exclamationmark.triangle.fill" : "clock.badge.checkmark")
                    .font(.headline)
                    .foregroundStyle(snapshot.isStale ? PatientPalette.amber : PatientPalette.teal)
                Text("Source: \(snapshot.sourceDescription)")
                    .font(.subheadline.weight(.semibold))
                Text(snapshot.sourceLimitation)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Information freshness. \(freshnessLabel). Source: \(snapshot.sourceDescription). \(snapshot.sourceLimitation)")
        .accessibilityIdentifier("patient-freshness")
    }

    private var freshnessLabel: String {
        guard let asOf = snapshot.asOf else { return "Update time is not available" }
        return "Updated \(asOf.formatted(.relative(presentation: .named)))"
    }
}

struct PatientPresentationPreferenceNotice: View {
    @Environment(\.patientPresentationPreferences) private var presentationPreferences

    var body: some View {
        if shouldDisplay {
            PatientCard {
                VStack(alignment: .leading, spacing: 8) {
                    Label("Your reading preferences", systemImage: "textformat.size")
                        .font(.headline)
                        .foregroundStyle(PatientPalette.blue)
                    Text(summary)
                        .font(.body)
                    Text("These display choices do not change your care plan, clinical orders, or urgent-help instructions.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
            }
            .accessibilityIdentifier("patient-presentation-preference-notice")
        }
    }

    private var shouldDisplay: Bool {
        presentationPreferences.textSize != .standard
            || presentationPreferences.highContrast
            || presentationPreferences.reducedMotion
    }

    private var summary: String {
        var choices: [String] = []
        switch presentationPreferences.textSize {
        case .standard:
            break
        case .large:
            choices.append("Large text")
        case .extraLarge:
            choices.append("Extra Large text")
        }
        if presentationPreferences.highContrast {
            choices.append("high contrast")
        }
        if presentationPreferences.reducedMotion {
            choices.append("reduced motion")
        }
        let joinedChoices = ListFormatter.localizedString(byJoining: choices)
        return "Hummingbird Patient is using \(joinedChoices). Your device accessibility settings can make text larger."
    }
}

struct PatientCertaintyBadge: View {
    let certainty: PatientCertainty

    var body: some View {
        Text(certainty.rawValue)
            .font(.caption.weight(.bold))
            .foregroundStyle(color)
            .padding(.horizontal, 10)
            .padding(.vertical, 6)
            .background(color.opacity(0.12), in: Capsule())
            .accessibilityLabel("Certainty: \(certainty.rawValue)")
    }

    private var color: Color {
        switch certainty {
        case .confirmed: PatientPalette.teal
        case .expected: PatientPalette.blue
        case .beingClarified: PatientPalette.amber
        }
    }
}

struct PatientUrgentHelpNotice: View {
    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 8) {
                Label("Need help now?", systemImage: "cross.case.fill")
                    .font(.headline)
                    .foregroundStyle(PatientPalette.rose)
                Text("In the hospital, use your bedside call button or speak with a staff member. If you are elsewhere and may be having an emergency, call your local emergency number.")
                    .font(.body)
                Text("Messages are for nonurgent questions. They are not monitored as an emergency service or live chat.")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("urgent-help-notice")
    }
}

struct PatientProvenanceText: View {
    let value: String

    var body: some View {
        Label("Source: \(value)", systemImage: "checkmark.shield")
            .font(.caption)
            .foregroundStyle(.secondary)
            .accessibilityLabel("Information source: \(value)")
    }
}

struct PatientProjectionSummaryCard: View {
    let headline: String
    let summary: String

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 7) {
                Text(headline)
                    .font(.title3.bold())
                    .foregroundStyle(PatientPalette.ink)
                Text(summary)
                    .font(.body)
                    .foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
    }
}

struct PatientProjectionRevisionNoticeCard: View {
    let notice: PatientProjectionRevisionNotice

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 8) {
                Label("Information updated", systemImage: "arrow.triangle.2.circlepath.circle.fill")
                    .font(.headline)
                    .foregroundStyle(PatientPalette.blue)
                Text(notice.message)
                    .font(.body)
                Text("Ask your care team if you have questions about what is shown here.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("patient-projection-revision-notice")
    }
}

struct PatientBulletListCard: View {
    let title: String
    let icon: String
    let items: [String]

    var body: some View {
        PatientCard {
            VStack(alignment: .leading, spacing: 10) {
                Label(title, systemImage: icon)
                    .font(.headline)
                    .foregroundStyle(PatientPalette.blue)
                ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                    HStack(alignment: .top, spacing: 9) {
                        Image(systemName: "circle.fill")
                            .font(.system(size: 6))
                            .padding(.top, 7)
                            .foregroundStyle(PatientPalette.blue)
                            .accessibilityHidden(true)
                        Text(item)
                            .font(.body)
                    }
                }
            }
        }
    }
}

#if DEBUG
struct SyntheticReferenceBanner: View {
    var body: some View {
        Label("Synthetic reference scenario — not a real patient", systemImage: "testtube.2")
            .font(.subheadline.weight(.bold))
            .foregroundStyle(PatientPalette.blue)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            .background(PatientPalette.blue.opacity(0.1), in: RoundedRectangle(cornerRadius: 14))
            .accessibilityLabel("Synthetic reference scenario. This is not a real patient.")
            .accessibilityIdentifier("synthetic-reference-banner")
    }
}
#endif
