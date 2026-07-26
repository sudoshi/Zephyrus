import SwiftUI

@main
struct NightingaleApp: App {
    var body: some Scene {
        WindowGroup {
            NightingaleFoundationView()
        }
    }
}

/// Compile-time guard for the safe, pre-pilot Nightingale foundation.
enum NightingaleProductBoundary {
    static let productName = "Nightingale"
    static let livePatientAccessEnabled = false
    static let staffEndpointsPermitted = false
}

private struct NightingaleFoundationView: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text(NightingaleProductBoundary.productName)
                .font(.largeTitle.weight(.semibold))
            Text("A patient-centered care experience.")
                .font(.title3)
            Text("Live patient access is not available in this foundation build. Please ask your care team for current information.")
                .font(.body)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .center)
        .padding(32)
        .accessibilityIdentifier("nightingale-safe-shell")
    }
}
