import Foundation

public struct ShellEntry: Equatable, Sendable, Identifiable {
    public let id: UUID
    public let title: String

    public init(id: UUID, title: String) {
        self.id = id
        self.title = title
    }
}

/// The shells open in one thread's terminal, at most four, named as the web names them.
public struct ShellList: Equatable, Sendable {
    public static let maximum = 4
    public private(set) var shells: [ShellEntry]
    public private(set) var focused: UUID?

    public var canAdd: Bool { shells.count < Self.maximum }

    public init() {
        shells = []
        focused = nil
    }

    /// Adds and focuses a shell, or returns nil at the maximum. The first is "main"; the next is "dev server"
    /// when the thread's preview has a port and no shell has that name; otherwise "shell N" with the lowest free N from 2.
    public mutating func add(id: UUID, hasPreviewPort: Bool) -> ShellEntry? {
        guard canAdd else { return nil }
        let titles = Set(shells.map(\.title))
        let title: String
        if shells.isEmpty {
            title = "main"
        } else if hasPreviewPort && !titles.contains("dev server") {
            title = "dev server"
        } else {
            var number = 2
            while titles.contains("shell \(number)") { number += 1 }
            title = "shell \(number)"
        }
        let entry = ShellEntry(id: id, title: title)
        shells.append(entry)
        focused = id
        return entry
    }

    /// Removes a shell; the focus moves to the shell before it, else the first, else nil.
    public mutating func remove(_ id: UUID) {
        guard let index = shells.firstIndex(where: { $0.id == id }) else { return }
        shells.remove(at: index)
        guard focused == id else { return }
        if index > 0 {
            focused = shells[index - 1].id
        } else {
            focused = shells.first?.id
        }
    }

    /// Focuses a shell of the list; an unknown id changes nothing.
    public mutating func focus(_ id: UUID) {
        if shells.contains(where: { $0.id == id }) { focused = id }
    }
}
