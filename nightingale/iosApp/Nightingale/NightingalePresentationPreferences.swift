import Combine
import Foundation

enum NightingalePresentationPreferenceNamespace {
    static let reduceMotion = "net.acumenus.nightingale.presentation.v1.reduce-motion"
    static let hideDecorativeImagery =
        "net.acumenus.nightingale.presentation.v1.hide-decorative-imagery"

    static let allKeys = [
        reduceMotion,
        hideDecorativeImagery,
    ]
}

struct NightingalePresentationPreferenceSnapshot: Equatable {
    let reduceMotionRequested: Bool
    let hideDecorativeImageryRequested: Bool
}

struct NightingaleSceneAccessibilityPolicy: Equatable {
    let reduceMotion: Bool
    let showDecorativeImagery: Bool
    let decorativeImageOpacity: Double
    let cardOpacity: Double
    let transitionDuration: Double

    static func resolve(
        preferences: NightingalePresentationPreferenceSnapshot,
        systemReduceMotion: Bool,
        systemReduceTransparency: Bool,
        increasedContrast: Bool,
        accessibilityTextSize: Bool,
        darkMode: Bool
    ) -> NightingaleSceneAccessibilityPolicy {
        let reduceMotion = systemReduceMotion || preferences.reduceMotionRequested
        let showDecorativeImagery =
            !preferences.hideDecorativeImageryRequested
            && !systemReduceTransparency
            && !increasedContrast

        let imageOpacity: Double
        if !showDecorativeImagery {
            imageOpacity = 0
        } else if accessibilityTextSize {
            imageOpacity = 0.04
        } else {
            imageOpacity = darkMode ? 0.10 : 0.08
        }

        return NightingaleSceneAccessibilityPolicy(
            reduceMotion: reduceMotion,
            showDecorativeImagery: showDecorativeImagery,
            decorativeImageOpacity: imageOpacity,
            cardOpacity: systemReduceTransparency || increasedContrast ? 1 : 0.92,
            transitionDuration: reduceMotion ? 0 : 0.18
        )
    }
}

@MainActor
final class NightingalePresentationPreferences: ObservableObject {
    @Published private(set) var snapshot: NightingalePresentationPreferenceSnapshot

    private let defaults: UserDefaults

    init(
        defaults: UserDefaults = .standard,
        environment: [String: String] = ProcessInfo.processInfo.environment
    ) {
        self.defaults = defaults

        #if DEBUG
        if environment["NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES"] == "1" {
            for key in NightingalePresentationPreferenceNamespace.allKeys {
                defaults.removeObject(forKey: key)
            }
        }
        #endif

        snapshot = NightingalePresentationPreferenceSnapshot(
            reduceMotionRequested: defaults.bool(
                forKey: NightingalePresentationPreferenceNamespace.reduceMotion
            ),
            hideDecorativeImageryRequested: defaults.bool(
                forKey: NightingalePresentationPreferenceNamespace.hideDecorativeImagery
            )
        )
    }

    func setReduceMotionRequested(_ requested: Bool) {
        defaults.set(
            requested,
            forKey: NightingalePresentationPreferenceNamespace.reduceMotion
        )
        snapshot = NightingalePresentationPreferenceSnapshot(
            reduceMotionRequested: requested,
            hideDecorativeImageryRequested: snapshot.hideDecorativeImageryRequested
        )
    }

    func setHideDecorativeImageryRequested(_ requested: Bool) {
        defaults.set(
            requested,
            forKey: NightingalePresentationPreferenceNamespace.hideDecorativeImagery
        )
        snapshot = NightingalePresentationPreferenceSnapshot(
            reduceMotionRequested: snapshot.reduceMotionRequested,
            hideDecorativeImageryRequested: requested
        )
    }
}
