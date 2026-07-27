import SwiftUI

/**
 Account-level presentation choices that may improve readability, but never
 reduce a stronger accessibility choice made in Settings. These values are
 deliberately separate from clinical preferences and only affect rendering.
 */
struct PatientPresentationPreferences: Equatable {
    let textSize: PatientTextSizePreference
    let reducedMotion: Bool
    let highContrast: Bool

    init(_ preferences: PatientPreferences = PatientPreferences()) {
        textSize = preferences.textSize ?? .standard
        reducedMotion = preferences.reducedMotion ?? false
        highContrast = preferences.highContrast ?? false
    }

    func effectiveDynamicTypeSize(systemSize: DynamicTypeSize) -> DynamicTypeSize {
        max(systemSize, preferredMinimumDynamicTypeSize)
    }

    var accessibilityIdentifier: String {
        let contrast = highContrast ? "high-contrast" : "standard-contrast"
        return "patient-presentation-\(textSize.rawValue)-\(contrast)"
    }

    private var preferredMinimumDynamicTypeSize: DynamicTypeSize {
        switch textSize {
        case .standard:
            .large
        case .large:
            .xLarge
        case .extraLarge:
            .accessibility1
        }
    }
}

private struct PatientPresentationPreferencesKey: EnvironmentKey {
    static let defaultValue = PatientPresentationPreferences()
}

extension EnvironmentValues {
    var patientPresentationPreferences: PatientPresentationPreferences {
        get { self[PatientPresentationPreferencesKey.self] }
        set { self[PatientPresentationPreferencesKey.self] = newValue }
    }
}

private struct PatientPresentationModifier: ViewModifier {
    let preferences: PatientPresentationPreferences
    @Environment(\.dynamicTypeSize) private var systemDynamicTypeSize

    func body(content: Content) -> some View {
        content
            .environment(\.patientPresentationPreferences, preferences)
            .dynamicTypeSize(preferences.effectiveDynamicTypeSize(systemSize: systemDynamicTypeSize)...)
    }
}

extension View {
    func patientPresentation(_ preferences: PatientPreferences) -> some View {
        modifier(PatientPresentationModifier(preferences: PatientPresentationPreferences(preferences)))
    }
}
