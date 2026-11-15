/// One file at a time, in the server's file order. The page is held by repository and path, never by id, because
/// ids change on every capture.
public struct DiffPager: Equatable, Sendable {
    public enum Move: Equatable, Sendable {
        case kept
        case movedBecauseGone(previous: DiffFileKey)
        case emptied
        case started
    }

    public private(set) var files: [DiffFileSummary]
    public private(set) var current: DiffFileKey?

    /// A key that is nil or not found starts at the first file.
    public init(files: [DiffFileSummary], restoring key: DiffFileKey?) {
        self.files = files
        if let key, files.contains(where: { $0.key == key }) {
            current = key
        } else {
            current = files.first?.key
        }
    }

    public var index: Int? {
        guard let current else { return nil }
        return files.firstIndex { $0.key == current }
    }

    /// 1-based, for "1 of 3". Nil when there are no files.
    public var position: (index: Int, count: Int)? {
        index.map { ($0 + 1, files.count) }
    }

    public var canGoBack: Bool { (index ?? 0) > 0 }
    public var canGoForward: Bool { index.map { $0 < files.count - 1 } ?? false }

    public mutating func next() {
        guard let index, canGoForward else { return }
        current = files[index + 1].key
    }

    public mutating func previous() {
        guard let index, canGoBack else { return }
        current = files[index - 1].key
    }

    public mutating func show(_ key: DiffFileKey) {
        guard files.contains(where: { $0.key == key }) else { return }
        current = key
    }

    /// Replaces the files after a reload. Keeps `current` by key; when it is gone, moves to the file now at the same
    /// index, clamped to the last; with no files, `current` becomes nil.
    @discardableResult
    public mutating func replace(files newFiles: [DiffFileSummary]) -> Move {
        let previousIndex = index
        let previous = current
        files = newFiles
        guard let previous, let previousIndex else {
            current = newFiles.first?.key
            return current == nil ? .kept : .started
        }
        if newFiles.contains(where: { $0.key == previous }) { return .kept }
        guard !newFiles.isEmpty else {
            current = nil
            return .emptied
        }
        current = newFiles[min(previousIndex, newFiles.count - 1)].key
        return .movedBecauseGone(previous: previous)
    }

    /// The previous and the next file, for prefetching.
    public func neighbors() -> [DiffFileSummary] {
        guard let index else { return [] }
        return [index - 1, index + 1].filter { files.indices.contains($0) }.map { files[$0] }
    }
}
