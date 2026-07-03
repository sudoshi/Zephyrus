import SwiftUI

/// A centered state view for empty / error / offline conditions, with an optional Retry.
/// Honors the rationed palette: `.info` (sky) for neutral states, `.warning` (amber) for a
/// reachable-but-failed load. Keeps "status never by color alone" — icon + title + message.
struct RetryableMessage: View {
    let symbol: String
    let title: String
    let message: String
    var tone: CapacityStatus = .info
    var retry: (() -> Void)?

    init(symbol: String, title: String, message: String, tone: CapacityStatus = .info, retry: (() -> Void)? = nil) {
        self.symbol = symbol
        self.title = title
        self.message = message
        self.tone = tone
        self.retry = retry
    }

    var body: some View {
        VStack(spacing: Z.s3) {
            Image(systemName: symbol)
                .font(Z.scaledFont(40))
                .foregroundStyle(Z.status(tone))
            Text(title)
                .font(Z.scaledFont(18, weight: .semibold))
                .foregroundStyle(Z.ink)
            Text(message)
                .font(Z.scaledFont(13))
                .foregroundStyle(Z.inkMuted)
                .multilineTextAlignment(.center)
            if let retry {
                Button(action: retry) {
                    Label("Try again", systemImage: "arrow.clockwise")
                        .font(Z.scaledFont(15, weight: .semibold))
                        .padding(.horizontal, Z.s4).padding(.vertical, Z.s2)
                        .foregroundStyle(Z.primary)
                        .background(RoundedRectangle(cornerRadius: 10).strokeBorder(Z.border, lineWidth: 1))
                }
                .padding(.top, Z.s1)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, Z.s6)
        .padding(.horizontal, Z.s4)
    }
}
