import SwiftUI
import UIKit

/// The 48h scrubber (D5): solid past half, dashed future half, a `now` marker,
/// shift-boundary detents at 07:00/19:00 (light haptic when the thumb crosses one),
/// drag-to-scrub, and a play/pause control that replays the past half.
struct ChronobarView: View {
    @ObservedObject var store: FlowWindowStore
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    @State private var dragStartT: Date?

    private let trackHeight: CGFloat = 4
    private let thumbSize: CGFloat = 18

    var body: some View {
        VStack(alignment: .leading, spacing: Z.s2) {
            HStack(spacing: Z.s3) {
                playButton
                GeometryReader { geo in
                    track(in: geo.size)
                        .contentShape(Rectangle())
                        .gesture(scrubGesture(width: geo.size.width))
                }
                .frame(height: 32)
            }
            hourLabels
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("48 hour flow window scrubber")
    }

    // MARK: Play / pause

    private var playButton: some View {
        Button {
            store.togglePlayback()
        } label: {
            Image(systemName: store.isPlaying ? "pause.fill" : "play.fill")
                .font(.system(size: 14, weight: .semibold))
                .foregroundStyle(Z.ink)
                .frame(width: 32, height: 32)
                .background(Circle().fill(Z.surface))
                .overlay(Circle().strokeBorder(Z.border, lineWidth: 1))
        }
        .accessibilityLabel(store.isPlaying ? "Pause replay" : "Replay the past 24 hours")
    }

    // MARK: Track

    private func track(in size: CGSize) -> some View {
        let width = size.width
        let midY = size.height / 2
        let nowX = xPosition(for: store.nowDate, width: width)
        let thumbX = xPosition(for: store.t, width: width)

        return ZStack(alignment: .leading) {
            // Past half — solid.
            Path { p in
                p.move(to: CGPoint(x: 0, y: midY))
                p.addLine(to: CGPoint(x: nowX, y: midY))
            }
            .stroke(Z.primary, style: StrokeStyle(lineWidth: trackHeight, lineCap: .round))

            // Future half — dashed (ghost territory).
            Path { p in
                p.move(to: CGPoint(x: nowX, y: midY))
                p.addLine(to: CGPoint(x: width, y: midY))
            }
            .stroke(Z.primary.opacity(0.45),
                    style: StrokeStyle(lineWidth: trackHeight, lineCap: .round, dash: [5, 5]))

            // Shift-boundary detents (07:00 / 19:00).
            ForEach(Array(store.shiftBoundaries.enumerated()), id: \.offset) { _, boundary in
                let x = xPosition(for: boundary, width: width)
                Rectangle()
                    .fill(Z.inkMuted)
                    .frame(width: 1.5, height: 12)
                    .position(x: x, y: midY)
            }

            // `now` marker tick.
            Rectangle()
                .fill(Z.ink)
                .frame(width: 2, height: 16)
                .position(x: nowX, y: midY)

            // Thumb.
            Circle()
                .fill(Z.ink)
                .overlay(Circle().strokeBorder(Z.bg, lineWidth: 2))
                .frame(width: thumbSize, height: thumbSize)
                .position(x: thumbX, y: midY)
                .animation(reduceMotion ? nil : .linear(duration: 0.03), value: store.t)
        }
    }

    private func scrubGesture(width: CGFloat) -> some Gesture {
        DragGesture(minimumDistance: 0)
            .onChanged { value in
                if dragStartT == nil {
                    dragStartT = store.t
                    store.pause()
                }
                let previous = store.t
                let next = store.clamp(date(atX: value.location.x, width: width))
                // Detent haptic: fire once per boundary the thumb crosses.
                let crossed = store.shiftBoundaries.contains {
                    (previous < $0 && $0 <= next) || (next <= $0 && $0 < previous)
                }
                if crossed {
                    UIImpactFeedbackGenerator(style: .light).impactOccurred()
                }
                store.t = next
            }
            .onEnded { _ in dragStartT = nil }
    }

    // MARK: Labels

    private var hourLabels: some View {
        HStack {
            Text(hourText(store.fromDate))
            Spacer()
            Text("Now \(hourText(store.nowDate))")
                .foregroundStyle(Z.ink)
            Spacer()
            Text(hourText(store.toDate))
        }
        .font(.system(size: 11, weight: .medium))
        .monospacedDigit()
        .foregroundStyle(Z.inkMuted)
        .padding(.leading, 32 + Z.s3) // align with the track, past the play button
    }

    // MARK: Geometry

    private var span: TimeInterval { max(store.toDate.timeIntervalSince(store.fromDate), 1) }

    private func xPosition(for date: Date, width: CGFloat) -> CGFloat {
        let fraction = date.timeIntervalSince(store.fromDate) / span
        return CGFloat(min(max(fraction, 0), 1)) * width
    }

    private func date(atX x: CGFloat, width: CGFloat) -> Date {
        let fraction = Double(min(max(x / max(width, 1), 0), 1))
        return store.fromDate.addingTimeInterval(fraction * span)
    }

    private func hourText(_ date: Date) -> String {
        let f = DateFormatter()
        f.dateFormat = "HH:mm"
        return f.string(from: date)
    }
}
