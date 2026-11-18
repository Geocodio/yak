import AppIntents
import Foundation
import YakKit

/// A Focus filter that chooses which Yaks may notify. The system evaluates the predicate against the
/// `filter-criteria` string of each notification's payload, which holds the instance's host.
struct YakFocusFilter: SetFocusFilterIntent {
    static let title = LocalizedStringResource("focus.title")
    static let description = IntentDescription(LocalizedStringResource("focus.description"))

    @Parameter(title: LocalizedStringResource("focus.param"))
    var instances: [InstanceEntity]?

    var displayRepresentation: DisplayRepresentation {
        DisplayRepresentation(title: "\(Self.summary(names: instances?.map(\.name)))")
    }

    var appContext: FocusFilterAppContext {
        FocusFilterAppContext(notificationFilterPredicate: Self.predicate(for: instances))
    }

    func perform() async throws -> some IntentResult { .result() }

    /// Nil without a choice: the Focus then filters nothing. An empty choice lets nothing through.
    static func predicate(for chosen: [InstanceEntity]?) -> NSPredicate? {
        guard let chosen else { return nil }
        let hosts = FocusFilterHosts.allowed(instances: chosen.map(\.instance), chosen: chosen.map(\.id))
        return hosts.map { NSPredicate(format: "SELF IN %@", Array($0).sorted()) }
    }

    /// "All Yaks", one name, "A and B", "A and 2 more", "No Yaks".
    static func summary(names: [String]?) -> String {
        guard let names else { return String(localized: "focus.summary.all") }
        switch names.count {
        case 0: return String(localized: "focus.summary.none")
        case 1: return names[0]
        case 2: return String(localized: "focus.summary.two \(names[0]) \(names[1])")
        default: return String(localized: "focus.summary.more \(names[0]) \(names.count - 1)")
        }
    }
}
