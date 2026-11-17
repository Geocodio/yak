import Foundation
import YakKit

/// The one place the widget views and the widget intents get their words. The catalog `Widgets.xcstrings` is compiled
/// into the app and the extension.
enum WidgetStrings {
    private static func resource(_ key: String.LocalizationValue) -> LocalizedStringResource {
        LocalizedStringResource(key, table: "Widgets")
    }

    static let brand = resource("widget.brand")
    static let privateThread = resource("widget.row.private")
    static let unknownThread = resource("widget.row.unknown")
    static let working = resource("widget.working")
    static let needsYouHeader = resource("widget.header.needsYou")
    static let runningHeader = resource("widget.header.running")
    static let needsYouEmpty = resource("widget.empty.needsYou")
    static let runningEmpty = resource("widget.empty.running")
    static let lockOne = resource("widget.lock.one")
    static let lockNone = resource("widget.lock.none")
    static let opensThread = resource("widget.a11y.opensThread")
    static let opensYak = resource("widget.a11y.opensYak")

    static func needsYouHeader(on name: String) -> LocalizedStringResource {
        resource("widget.header.needsYouOn \(name)")
    }
    static func runningHeader(on name: String) -> LocalizedStringResource {
        resource("widget.header.runningOn \(name)")
    }
    static func task(_ title: String) -> LocalizedStringResource { resource("widget.row.task \(title)") }
    static func lockMany(_ countLabel: String) -> LocalizedStringResource { resource("widget.lock.many \(countLabel)") }
    static func updated(_ age: String) -> LocalizedStringResource { resource("widget.updated \(age)") }
    static func detailWithAge(_ detail: String, _ age: String) -> LocalizedStringResource {
        resource("widget.row.detailAge \(detail) \(age)")
    }

    static func title(_ title: WidgetRowTitle) -> LocalizedStringResource {
        switch title {
        case .text(let text): LocalizedStringResource(stringLiteral: text)
        case .task(let text): task(text)
        case .privateThread: privateThread
        case .unknown: unknownThread
        }
    }

    /// What a needs-you row says when the server sent no label.
    static func reason(_ reason: NeedsYouReason?) -> LocalizedStringResource {
        switch reason {
        case .some(.question): resource("widget.reason.question")
        case .some(.blocked): resource("widget.reason.blocked")
        case .some(.unseen): resource("widget.reason.unseen")
        case .some(.coOwner): resource("widget.reason.coOwner")
        default: resource("widget.reason.other")
        }
    }

    /// "now", "5m", "3h", "2d": the time since `date` at `now`, shortened for a row.
    static func age(from date: Date, to now: Date) -> LocalizedStringResource {
        let seconds = max(0, Int(now.timeIntervalSince(date)))
        switch seconds {
        case ..<60: return resource("widget.age.now")
        case ..<3600: return resource("widget.age.minutes \(seconds / 60)")
        case ..<86400: return resource("widget.age.hours \(seconds / 3600)")
        default: return resource("widget.age.days \(seconds / 86400)")
        }
    }

    static func a11yNeedsYou(count: String, instance: String, first: String) -> LocalizedStringResource {
        resource("widget.a11y.needsYou \(count) \(instance) \(first)")
    }
    static func a11yNeedsYouLock(count: String, instance: String) -> LocalizedStringResource {
        resource("widget.a11y.needsYouLock \(count) \(instance)")
    }
    static func a11yNeedsYouNone(instance: String) -> LocalizedStringResource {
        resource("widget.a11y.needsYouNone \(instance)")
    }
    static func a11yRunning(count: String, instance: String) -> LocalizedStringResource {
        resource("widget.a11y.running \(count) \(instance)")
    }
    static func a11yRunningNone(instance: String) -> LocalizedStringResource {
        resource("widget.a11y.runningNone \(instance)")
    }

    // MARK: States

    static func stateText(_ state: WidgetState) -> LocalizedStringResource {
        switch state {
        case .noInstance, .content: resource("widget.state.noInstance")
        case .instanceMissing: resource("widget.state.instanceMissing")
        case .needsPairing(let host): resource("widget.state.needsPairing \(host)")
        case .notLoaded: resource("widget.state.notLoaded")
        }
    }

    /// The one line of the rectangular lock screen widget.
    static func stateShort(_ state: WidgetState) -> LocalizedStringResource {
        switch state {
        case .noInstance, .content: resource("widget.state.noInstance.short")
        case .instanceMissing: resource("widget.state.instanceMissing.short")
        case .needsPairing: resource("widget.state.needsPairing.short")
        case .notLoaded: resource("widget.state.notLoaded.short")
        }
    }

    /// The small caption under "Yak" in the circular widget; nil when the state shows the mark or "?" alone.
    static func stateCircular(_ state: WidgetState) -> LocalizedStringResource? {
        if case .notLoaded = state { return resource("widget.state.notLoaded.circular") }
        return nil
    }
}
