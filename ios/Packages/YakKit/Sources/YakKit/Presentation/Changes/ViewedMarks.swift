/// "Viewed as it is now": a mark holds only while the file's additions and deletions stay the same.
public struct ViewedMark: Codable, Hashable, Sendable {
    public let repo: String
    public let path: String
    public let additions: Int?
    public let deletions: Int?

    public init(_ file: DiffFileSummary) {
        repo = file.repo
        path = file.path
        additions = file.additions
        deletions = file.deletions
    }

    var key: DiffFileKey { DiffFileKey(repo: repo, path: path) }
}

public struct ViewedMarks: Codable, Equatable, Sendable {
    public static let limit = 500

    public var marks: Set<ViewedMark> { Set(order) }
    /// Insertion order, oldest first, so the cap drops the oldest mark.
    private var order: [ViewedMark]

    public init(marks: Set<ViewedMark> = []) {
        order = marks.sorted { ($0.repo, $0.path) < ($1.repo, $1.path) }
    }

    public func isViewed(_ file: DiffFileSummary) -> Bool {
        order.contains(ViewedMark(file))
    }

    public mutating func set(_ file: DiffFileSummary, viewed: Bool) {
        order.removeAll { $0.key == file.key }
        guard viewed else { return }
        order.append(ViewedMark(file))
        if order.count > Self.limit { order.removeFirst(order.count - Self.limit) }
    }

    /// Drops marks whose key is not in `files` or whose counts moved.
    public mutating func prune(keeping files: [DiffFileSummary]) {
        let current = Set(files.map(ViewedMark.init))
        order.removeAll { !current.contains($0) }
    }

    public func viewedCount(in files: [DiffFileSummary]) -> Int {
        files.filter(isViewed).count
    }
}
