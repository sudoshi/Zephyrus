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
