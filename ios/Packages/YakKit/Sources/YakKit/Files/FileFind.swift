import Foundation

public struct FileFindResult: Equatable, Sendable, Identifiable {
    public let name: String, path: String, mark: FileMark?
    public var id: String { path }

    public init(name: String, path: String, mark: FileMark?) {
        self.name = name
        self.path = path
        self.mark = mark
    }
}

public enum FindState: Equatable, Sendable {
    case idle
    case searching
    /// `isPartial`: only opened folders and changed paths were searched.
    case results([FileFindResult], truncated: Bool, isPartial: Bool)
    case noMatch(query: String, isPartial: Bool)
    case failed(ThreadProblem)
}

public enum FileFind {
    public static let resultLimit = 100

    /// Case-insensitive substring match on the path over the loaded entries and the changed and added paths,
    /// each path once, files only, changed files first, then by path; at most 100.
    public static func local(_ query: String, in snapshot: FileTreeSnapshot, loaded: [FileTreeEntry]) -> FindState {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return .idle }

        var folderStates: [String: FolderState] = [:]
        for row in snapshot.rows where row.isDirectory { folderStates[row.id] = row.folderState }
        let completeFolder = FolderState.loaded(truncated: false)
        let isPartial =
            snapshot.root != completeFolder
            || loaded.contains { $0.isDirectory && folderStates[$0.path] != completeFolder }

        let changed = Set(snapshot.changedPaths)
        let added = Set(snapshot.addedPaths)
        var paths = Set(loaded.lazy.filter { !$0.isDirectory }.map(\.path))
        paths.formUnion(changed)
        paths.formUnion(added)

        let matches = paths.filter { $0.range(of: trimmed, options: .caseInsensitive) != nil }
            .map { path in
                FileFindResult(
                    name: path.split(separator: "/").last.map(String.init) ?? path, path: path,
                    mark: added.contains(path) ? .added : (changed.contains(path) ? .modified : nil))
            }
            .sorted { left, right in
                if (left.mark != nil) != (right.mark != nil) { return left.mark != nil }
                return left.path < right.path
            }
        guard !matches.isEmpty else { return .noMatch(query: trimmed, isPartial: isPartial) }
        return .results(
            Array(matches.prefix(resultLimit)), truncated: matches.count > resultLimit, isPartial: isPartial)
    }
}
