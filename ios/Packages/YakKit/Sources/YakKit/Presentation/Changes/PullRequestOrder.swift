import Foundation

/// The merge order of a thread's pull requests as the person edits it. Only open pull requests move; merged and
/// closed ones keep their places, as on the web. The server takes the full order of every row.
public enum PullRequestOrder {
    /// Two or more open pull requests, and a person who can drive the thread.
    public static func canEdit(canDrive: Bool, rows: [PullRequestCardRow]) -> Bool {
        canDrive && openIDs(rows).count >= 2
    }

    public static func openIDs(_ rows: [PullRequestCardRow]) -> [Int] {
        rows.filter { $0.state == .open }.map(\.id)
    }

    /// `allIDs` with its open places filled, in turn, by `openOrder`. Ids that are not open stay where they are.
    public static func fullOrder(allIDs: [Int], openIDs: Set<Int>, openOrder: [Int]) -> [Int] {
        var next = openOrder.makeIterator()
        return allIDs.map { openIDs.contains($0) ? (next.next() ?? $0) : $0 }
    }

    /// `order` with `id` moved one place; nil when it is at that end already or not in the list.
    public static func moving(_ order: [Int], id: Int, by step: Int) -> [Int]? {
        guard let index = order.firstIndex(of: id), order.indices.contains(index + step) else { return nil }
        var next = order
        next.swapAt(index, index + step)
        return next
    }

    /// `order` after a drag of the rows at `source` to `destination`, in the numbering of SwiftUI's `onMove`.
    public static func moving(_ order: [Int], from source: IndexSet, to destination: Int) -> [Int] {
        let moved = source.map { order[$0] }
        var rest = order.enumerated().filter { !source.contains($0.offset) }.map(\.element)
        let insertAt = destination - source.filter { $0 < destination }.count
        rest.insert(contentsOf: moved, at: min(max(insertAt, 0), rest.count))
        return rest
    }
}
