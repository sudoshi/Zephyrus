import SwiftUI

struct PatientPrivacyCoverView: View {
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
                Text("Your care information is covered while the app is not active.")
                    .font(.body)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 36)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Privacy cover. Your care information is hidden while the app is not active.")
        .accessibilityIdentifier("patient-privacy-cover")
    }
}
