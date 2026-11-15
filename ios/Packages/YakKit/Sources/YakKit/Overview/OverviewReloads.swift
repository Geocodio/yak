import Foundation

public enum OverviewResource: Hashable, Sendable { case stack, environment }

/// What a new conversation snapshot asks the Overview to reload.
public enum OverviewReloads {
    /// `previous` nil (the first snapshot) asks for nothing. A sandbox state counts only when both snapshots
    /// know one, so a show that finishes loading does not look like a sandbox change.
    public static func after(
        previous: ConversationSnapshot?, current: ConversationSnapshot, hasSubThreads: Bool
    ) -> Set<OverviewResource> {
        guard let previous else { return [] }
        var resources: Set<OverviewResource> = []
        if items(.child, in: previous) != items(.child, in: current) { resources.insert(.stack) }
        if let before = sandboxState(of: previous), let after = sandboxState(of: current), before != after {
            resources.insert(.environment)
        }
        if items(.repository, in: previous) != items(.repository, in: current) { resources.insert(.environment) }
        if hasSubThreads, previous.turn != current.turn { resources.insert(.stack) }
        return resources
    }

    private static func items(_ kind: ConversationItem.Kind, in snapshot: ConversationSnapshot) -> [ConversationItem] {
        snapshot.entries.compactMap { entry in
            if case .event(let item) = entry, item.kind == kind { return item }
            return nil
        }
    }

    private static func sandboxState(of snapshot: ConversationSnapshot) -> String? {
        snapshot.thread?.sandboxState ?? snapshot.show?.thread.sandboxState
    }
}
