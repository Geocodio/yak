import Foundation

public enum LiveActivityGlyph: Equatable, Sendable { case working, needsYou, finished, failed, stopped, stale }
public enum LiveActivityTitle: Equatable, Sendable {
    case text(String)
    case privateThread
}
public enum LiveActivityDetail: Equatable, Sendable {
    case step(String)
    case working, needsYou, finished, failed, stopped, noRecentUpdate
}
public enum LiveActivityCompactTrailing: Equatable, Sendable {
    case timer(Date)
    case glyph, brand
}

/// What a Live Activity shows for a content state, the same in every presentation.
public struct LiveActivityLook: Equatable, Sendable {
    public let glyph: LiveActivityGlyph
    public let title: LiveActivityTitle
    public let detail: LiveActivityDetail
    /// The elapsed timer's start, only while working and not stale.
    public let timerStart: Date?
    public let compactTrailing: LiveActivityCompactTrailing
    /// Finished, failed or stopped.
    public let isEnded: Bool

    /// A private thread never shows its title or step. An unknown state reads as working.
    public static func make(
        content: ThreadActivityContentState, title: String, isStale: Bool, isLimitedWidth: Bool
    ) -> LiveActivityLook {
        let isHidden = content.isPrivate || title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        let shownTitle: LiveActivityTitle = isHidden ? .privateThread : .text(title)

        var glyph = LiveActivityGlyph.working
        var detail = LiveActivityDetail.working
        var isEnded = false
        switch content.state {
        case .needsYou: (glyph, detail) = (.needsYou, .needsYou)
        case .finished: (glyph, detail, isEnded) = (.finished, .finished, true)
        case .failed: (glyph, detail, isEnded) = (.failed, .failed, true)
        case .stopped: (glyph, detail, isEnded) = (.stopped, .stopped, true)
        default:
            if isStale {
                (glyph, detail) = (.stale, .noRecentUpdate)
            } else if !content.isPrivate, !content.step.isEmpty {
                detail = .step(content.step)
            }
        }

        let isTicking = glyph == .working
        let timerStart = isTicking ? parse(content.turnStartedAt) : nil
        let trailing: LiveActivityCompactTrailing =
            isLimitedWidth ? .brand : timerStart.map { .timer($0) } ?? .glyph
        return LiveActivityLook(
            glyph: glyph, title: shownTitle, detail: detail, timerStart: timerStart,
            compactTrailing: trailing, isEnded: isEnded)
    }

    private static func parse(_ text: String) -> Date? {
        for options: ISO8601DateFormatter.Options in [
            [.withInternetDateTime, .withFractionalSeconds], [.withInternetDateTime],
        ] {
            let formatter = ISO8601DateFormatter()
            formatter.formatOptions = options
            if let date = formatter.date(from: text) { return date }
        }
        return nil
    }
}
