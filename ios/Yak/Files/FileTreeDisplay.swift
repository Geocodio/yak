import YakKit

/// What the tree list draws: the rows of the snapshot with the notes that belong under a folder.
enum FileTreeDisplayItem: Equatable, Identifiable {
    case row(FileTreeRow)
    case loading(parent: String, depth: Int)
    case empty(parent: String, depth: Int)
    case failed(parent: String, depth: Int)
    case notLoadedOffline(parent: String, depth: Int)
    case truncated(parent: String, depth: Int)

    var id: String {
        switch self {
        case .row(let row): row.id
        case .loading(let parent, _): "loading:\(parent)"
        case .empty(let parent, _): "empty:\(parent)"
        case .failed(let parent, _): "failed:\(parent)"
        case .notLoadedOffline(let parent, _): "offline:\(parent)"
        case .truncated(let parent, _): "truncated:\(parent)"
        }
    }
}

enum FileTreeDisplay {
    /// Notes sit right after the folder they describe, except "truncated", which ends the folder's children.
    static func make(rows: [FileTreeRow], rootState: FolderState, isOffline: Bool) -> [FileTreeDisplayItem] {
        var items: [FileTreeDisplayItem] = []
        var openTruncated: [(path: String, depth: Int)] = []
        func closeFolders(downTo depth: Int) {
            while let last = openTruncated.last, last.depth >= depth {
                items.append(.truncated(parent: last.path, depth: last.depth + 1))
                openTruncated.removeLast()
            }
        }
        for row in rows {
            closeFolders(downTo: row.depth)
            items.append(.row(row))
            guard row.isDirectory, row.isExpanded else { continue }
            switch row.folderState {
            case .loading: items.append(.loading(parent: row.id, depth: row.depth + 1))
            case .failed: items.append(.failed(parent: row.id, depth: row.depth + 1))
            case .notLoaded:
                if isOffline { items.append(.notLoadedOffline(parent: row.id, depth: row.depth + 1)) }
            case .loaded(let isTruncated):
                if row.isEmptyFolder { items.append(.empty(parent: row.id, depth: row.depth + 1)) }
                if isTruncated { openTruncated.append((row.id, row.depth)) }
            case nil: break
            }
        }
        closeFolders(downTo: 0)
        if case .loaded(true) = rootState { items.append(.truncated(parent: "", depth: 0)) }
        return items
    }
}
