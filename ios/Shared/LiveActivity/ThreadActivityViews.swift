import SwiftUI
import WidgetKit
import YakKit

/// The one place the Live Activity views get their words. The catalog `LiveActivity.xcstrings` is compiled into the
/// app and the widget extension.
enum LiveActivityText {
    private static func resource(_ key: String.LocalizationValue) -> LocalizedStringResource {
        LocalizedStringResource(key, table: "LiveActivity")
    }

    static func text(for detail: LiveActivityDetail) -> LocalizedStringResource {
        switch detail {
        case .step(let step): resource("liveActivity.detail.step \(step)")
        case .working: resource("liveActivity.detail.working")
        case .needsYou: resource("liveActivity.detail.needsYou")
        case .finished: resource("liveActivity.detail.finished")
        case .failed: resource("liveActivity.detail.failed")
        case .stopped: resource("liveActivity.detail.stopped")
        case .noRecentUpdate: resource("liveActivity.detail.noRecentUpdate")
        }
    }

    static func label(for glyph: LiveActivityGlyph) -> LocalizedStringResource {
        switch glyph {
        case .working: resource("liveActivity.glyph.working")
        case .needsYou: resource("liveActivity.glyph.needsYou")
        case .finished: resource("liveActivity.glyph.finished")
        case .failed: resource("liveActivity.glyph.failed")
        case .stopped: resource("liveActivity.glyph.stopped")
        case .stale: resource("liveActivity.glyph.stale")
        }
    }

    /// The state word shown in the narrow compact trailing slot.
    static func word(for glyph: LiveActivityGlyph) -> LocalizedStringResource {
        switch glyph {
        case .working: resource("liveActivity.word.working")
        case .needsYou: resource("liveActivity.word.needsYou")
        case .finished: resource("liveActivity.word.finished")
        case .failed: resource("liveActivity.word.failed")
        case .stopped: resource("liveActivity.word.stopped")
        case .stale: resource("liveActivity.word.stale")
        }
    }

    static let privateTitle = resource("liveActivity.title.private")

    static func title(for title: LiveActivityTitle) -> Text {
        switch title {
        case .text(let text): Text(verbatim: text)
        case .privateThread: Text(privateTitle)
        }
    }

    static func header(host: String) -> LocalizedStringResource { resource("liveActivity.header \(host)") }
    static let brand = resource("liveActivity.brand")
}

/// The state glyph: a spinning arc, a filled dot, a check, an exclamation mark, a square or a clock.
struct ThreadActivityGlyph: View {
    let glyph: LiveActivityGlyph
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isTurning = false

    var body: some View {
        Group {
            switch glyph {
            case .working:
                Circle().trim(from: 0.12, to: 0.82)
                    .stroke(Color.green, style: StrokeStyle(lineWidth: 2.5, lineCap: .round))
                    .rotationEffect(.degrees(isTurning && !reduceMotion ? 360 : 0))
                    .animation(
                        reduceMotion ? nil : .linear(duration: 1.2).repeatForever(autoreverses: false), value: isTurning
                    )
                    .onAppear { isTurning = true }
            case .needsYou:
                Circle().fill(Color.accentColor).padding(3)
            case .finished:
                Image(systemName: "checkmark").font(.system(size: 12, weight: .bold)).foregroundStyle(Color.green)
            case .failed:
                Image(systemName: "exclamationmark").font(.system(size: 13, weight: .bold))
                    .foregroundStyle(Color.orange)
            case .stopped:
                RoundedRectangle(cornerRadius: 2).fill(Color.secondary).padding(5)
            case .stale:
                Image(systemName: "clock").font(.system(size: 12, weight: .semibold)).foregroundStyle(Color.secondary)
            }
        }
        .frame(width: 18, height: 18)
        .accessibilityElement()
        .accessibilityLabel(Text(LiveActivityText.label(for: glyph)))
    }
}

private struct ElapsedTimer: View {
    let start: Date

    var body: some View {
        Text(timerInterval: start...Date.distantFuture, pauseTime: nil, countsDown: false, showsHours: true)
            .monospacedDigit()
            .multilineTextAlignment(.trailing)
    }
}

/// The lock screen and banner presentation.
struct ThreadActivityLockScreenView: View {
    let look: LiveActivityLook
    let host: String
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                ThreadActivityGlyph(glyph: look.glyph)
                Text(LiveActivityText.header(host: host)).font(.footnote.weight(.medium)).lineLimit(1)
                    .foregroundStyle(.secondary)
                Spacer(minLength: 8)
                if let start = look.timerStart {
                    ElapsedTimer(start: start).font(.footnote.weight(.medium)).foregroundStyle(.secondary)
                        .frame(maxWidth: 80, alignment: .trailing)
                }
            }
            LiveActivityText.title(for: look.title).font(.system(size: 17, weight: .semibold)).lineLimit(2)
            Text(LiveActivityText.text(for: look.detail)).font(.subheadline.weight(.medium))
                .foregroundStyle(look.glyph == .needsYou ? AnyShapeStyle(Color.accentColor) : AnyShapeStyle(.secondary))
                .lineLimit(2)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .foregroundStyle(.primary)
        .environment(\.colorScheme, .dark)
        .background(reduceTransparency ? Color.black : Color.black.opacity(0.78))
    }
}

struct ThreadActivityExpandedLeading: View {
    let look: LiveActivityLook
    var body: some View { ThreadActivityGlyph(glyph: look.glyph) }
}

struct ThreadActivityExpandedTrailing: View {
    let look: LiveActivityLook
    var body: some View {
        if let start = look.timerStart {
            ElapsedTimer(start: start).font(.footnote.weight(.medium)).frame(maxWidth: 70, alignment: .trailing)
        } else {
            Text(LiveActivityText.word(for: look.glyph)).font(.footnote.weight(.medium)).lineLimit(1)
        }
    }
}

struct ThreadActivityExpandedCenter: View {
    let look: LiveActivityLook
    var body: some View {
        LiveActivityText.title(for: look.title).font(.system(size: 17, weight: .semibold)).lineLimit(2)
    }
}

struct ThreadActivityExpandedBottom: View {
    let look: LiveActivityLook
    var body: some View {
        Text(LiveActivityText.text(for: look.detail)).font(.subheadline.weight(.medium))
            .foregroundStyle(look.glyph == .needsYou ? AnyShapeStyle(Color.accentColor) : AnyShapeStyle(.secondary))
            .lineLimit(2)
            .frame(maxWidth: .infinity, alignment: .leading)
    }
}

struct ThreadActivityCompactLeading: View {
    let look: LiveActivityLook
    var body: some View { ThreadActivityGlyph(glyph: look.glyph) }
}

/// The timer, the state word, or in the Duo's narrow pill the word "Yak" under the glyph and never a timer.
struct ThreadActivityCompactTrailing: View {
    let look: LiveActivityLook
    var body: some View {
        switch look.compactTrailing {
        case .timer(let start):
            ElapsedTimer(start: start).font(.footnote.weight(.medium)).frame(maxWidth: 52, alignment: .trailing)
        case .glyph:
            Text(LiveActivityText.word(for: look.glyph)).font(.footnote.weight(.semibold)).lineLimit(1)
                .minimumScaleFactor(0.7)
        case .brand:
            Text(LiveActivityText.brand).font(.system(size: 10, weight: .semibold)).lineLimit(1)
        }
    }
}

struct ThreadActivityMinimal: View {
    let look: LiveActivityLook
    var body: some View { ThreadActivityGlyph(glyph: look.glyph) }
}

/// The small family for Apple Watch and CarPlay: the glyph, the title and the detail on two lines.
struct ThreadActivitySmallView: View {
    let look: LiveActivityLook
    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            ThreadActivityGlyph(glyph: look.glyph)
            VStack(alignment: .leading, spacing: 2) {
                LiveActivityText.title(for: look.title).font(.headline).lineLimit(1)
                Text(LiveActivityText.text(for: look.detail)).font(.footnote).lineLimit(1)
            }
        }
    }
}
