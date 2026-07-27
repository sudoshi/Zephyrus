import SwiftUI
import UIKit

/// Stable, nonclinical source-language keys for the bounded offline foundation.
///
/// Additional languages require named language/interpreter and patient-content review.
/// Pseudolanguages are test configurations, not shipped translations.
enum NightingaleCopyKey {
    static let productName: LocalizedStringKey = "app_name"
    static let foundationMission: LocalizedStringKey = "foundation_mission"
    static let privacyHeading: LocalizedStringKey = "privacy_heading"
    static let foundationUnavailable: LocalizedStringKey = "foundation_unavailable"
    static let foundationNoPatientData: LocalizedStringKey = "foundation_no_patient_data"
    static let displayComfortHeading: LocalizedStringKey = "display_comfort_heading"
    static let displayComfortScope: LocalizedStringKey = "display_comfort_scope"
    static let reduceMotionLabel: LocalizedStringKey = "reduce_motion_label"
    static let motionReducedStatus: LocalizedStringKey = "motion_reduced_status"
    static let motionStandardStatus: LocalizedStringKey = "motion_standard_status"
    static let hideImageryLabel: LocalizedStringKey = "hide_imagery_label"
    static let imageryShownStatus: LocalizedStringKey = "imagery_shown_status"
    static let imageryHiddenStatus: LocalizedStringKey = "imagery_hidden_status"
    static let privacyCoverAccessibilityLabel: LocalizedStringKey =
        "privacy_cover_accessibility_label"
    static let privacyCoverMessage: LocalizedStringKey = "privacy_cover_message"
}

enum NightingaleAccessibilityAnnouncement {
    static func motionStatus(reduced: Bool) {
        postPoliteStatus(
            String(
                localized: reduced
                    ? "motion_reduced_status"
                    : "motion_standard_status"
            )
        )
    }

    static func imageryStatus(hidden: Bool) {
        postPoliteStatus(
            String(
                localized: hidden
                    ? "imagery_hidden_status"
                    : "imagery_shown_status"
            )
        )
    }

    private static func postPoliteStatus(_ message: String) {
        let announcement = NSAttributedString(
            string: message,
            attributes: [
                .accessibilitySpeechAnnouncementPriority:
                    UIAccessibilityPriority.low,
            ]
        )

        UIAccessibility.post(notification: .announcement, argument: announcement)
    }
}
