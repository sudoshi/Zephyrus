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
    let hideScenery: Bool

    init(_ preferences: PatientPreferences = PatientPreferences()) {
        textSize = preferences.textSize ?? .standard
        reducedMotion = preferences.reducedMotion ?? false
        highContrast = preferences.highContrast ?? false
        hideScenery = preferences.hideScenery ?? false
    }

    func effectiveDynamicTypeSize(systemSize: DynamicTypeSize) -> DynamicTypeSize {
        max(systemSize, preferredMinimumDynamicTypeSize)
    }

    var accessibilityIdentifier: String {
        let contrast = highContrast ? "high-contrast" : "standard-contrast"
        return "patient-presentation-\(textSize.rawValue)-\(contrast)"
    }

    var hidesDecorativeScenery: Bool {
        highContrast || hideScenery
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

    /// Keeps secondary explanatory copy legible when a patient has selected
    /// high contrast. `colorSchemeContrast` is system-owned and read-only, so
    /// the saved preference is applied explicitly rather than trying to
    /// overwrite that environment value.
    func patientSecondaryText() -> some View {
        modifier(PatientSecondaryTextModifier())
    }
}

private struct PatientSecondaryTextModifier: ViewModifier {
    @Environment(\.colorSchemeContrast) private var colorSchemeContrast
    @Environment(\.colorScheme) private var colorScheme
    @Environment(\.patientPresentationPreferences) private var presentationPreferences

    func body(content: Content) -> some View {
        content.foregroundStyle(foregroundColor)
    }

    private var foregroundColor: Color {
        guard colorSchemeContrast == .increased || presentationPreferences.highContrast else {
            return .secondary
        }

        return Color(
            uiColor: colorScheme == .dark
                ? UIColor(white: 0.94, alpha: 1)
                : UIColor(white: 0.12, alpha: 1)
        )
    }
}
