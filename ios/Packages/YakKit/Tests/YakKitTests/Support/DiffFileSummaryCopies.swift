@testable import YakKit

extension DiffFileSummary {
    func withID(_ id: Int) -> DiffFileSummary {
        DiffFileSummary(
            id: id, repo: repo, path: path, oldPath: oldPath, status: status, additions: additions,
            deletions: deletions, isBinary: isBinary, isTooLarge: isTooLarge)
    }

    func withCounts(additions: Int?, deletions: Int?) -> DiffFileSummary {
        DiffFileSummary(
            id: id, repo: repo, path: path, oldPath: oldPath, status: status, additions: additions,
            deletions: deletions, isBinary: isBinary, isTooLarge: isTooLarge)
    }
}
