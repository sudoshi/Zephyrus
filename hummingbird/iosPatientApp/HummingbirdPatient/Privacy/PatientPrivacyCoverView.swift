import SwiftUI
import UIKit

enum PatientPrivacyCoverReason: Equatable {
    case inactive
    case accessVerification
    case screenCapture

    var message: String {
        switch self {
        case .inactive:
            "Your care information is covered while the app is not active."
        case .accessVerification:
            "Your care information stays covered while we check your current care access."
        case .screenCapture:
            "Your care information is covered while screen recording or sharing is active."
        }
    }

    var accessibilityLabel: String {
        "Privacy cover. \(message)"
    }
}

/**
 Observes iOS's ongoing screen-capture state for recording, external display,
 and sharing. This cannot retroactively prevent a one-time screenshot because
 iOS reports that event only after the image has already been captured.
 */
@MainActor
final class PatientScreenCaptureMonitor: ObservableObject {
    @Published private(set) var isCaptureActive: Bool

    private let captureState: @MainActor () -> Bool
    private let notificationCenter: NotificationCenter
    private var captureObserver: NSObjectProtocol?

    convenience init() {
        self.init(
            notificationCenter: .default,
            captureState: { UITraitCollection.current.sceneCaptureState == .active }
        )
    }

    init(
        notificationCenter: NotificationCenter,
        captureState: @escaping @MainActor () -> Bool
    ) {
        self.notificationCenter = notificationCenter
        self.captureState = captureState
        isCaptureActive = captureState()
        captureObserver = notificationCenter.addObserver(
            forName: UIScreen.capturedDidChangeNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                self?.refresh()
            }
        }
    }

    deinit {
        if let captureObserver {
            notificationCenter.removeObserver(captureObserver)
        }
    }

    func refresh() {
        isCaptureActive = captureState()
    }
}

/**
 Covers protected content as soon as the process begins leaving the foreground,
 before the scene-phase update used by the view hierarchy settles. This is the
 earliest app-level lifecycle hook available for the system app-switcher snapshot.
 */
@MainActor
final class PatientAppActivityMonitor: ObservableObject {
    @Published private(set) var requiresPrivacyCover = false
    @Published private(set) var requiresAccessRevalidation = false

    private let notificationCenter: NotificationCenter
    private var resignActiveObserver: NSObjectProtocol?
    private var becomeActiveObserver: NSObjectProtocol?

    init(notificationCenter: NotificationCenter = .default) {
        self.notificationCenter = notificationCenter
        resignActiveObserver = notificationCenter.addObserver(
            forName: UIApplication.willResignActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                self?.requiresPrivacyCover = true
                self?.requiresAccessRevalidation = true
            }
        }
        becomeActiveObserver = notificationCenter.addObserver(
            forName: UIApplication.didBecomeActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                self?.requiresPrivacyCover = false
            }
        }
    }

    /// The privacy cover can lift when the app becomes active, but protected care content must
    /// stay unavailable until the caller has checked the current session and encounter grant.
    func markAccessRevalidated() {
        requiresAccessRevalidation = false
    }

    deinit {
        if let resignActiveObserver {
            notificationCenter.removeObserver(resignActiveObserver)
        }
        if let becomeActiveObserver {
            notificationCenter.removeObserver(becomeActiveObserver)
        }
    }
}

struct PatientPrivacyCoverView: View {
    let reason: PatientPrivacyCoverReason

    var body: some View {
        ZStack {
            PatientPhotoBackground(scene: .welcome)
                .ignoresSafeArea()
            VStack(spacing: 16) {
                Image(systemName: "hand.raised.fill")
                    .font(.system(size: 48))
                    .foregroundStyle(PatientPalette.blue)
                    .accessibilityHidden(true)
                Text("Hummingbird Patient")
                    .font(.title.bold())
                    .foregroundStyle(PatientPalette.ink)
                Text(reason.message)
                    .font(.body)
                    .patientSecondaryText()
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 36)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(reason.accessibilityLabel)
        .accessibilityIdentifier("patient-privacy-cover")
    }
}
