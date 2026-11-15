import Foundation

/// One pull request of the card, finished for drawing. Product rules stay on the server: the row only copies
/// what the panel says.
public struct PullRequestCardRow: Equatable, Sendable, Identifiable {
    public enum Checks: Equatable, Sendable {
        case none
        case passing(Int)
        case failing(failed: Int, total: Int, firstExcerpt: String?)
        case running(done: Int, total: Int)
        /// A status word this build does not know.
        case other(String)
    }

    /// The row id.
    public let id: Int
    /// "#41 example/api", or "example/api" while the number is unknown.
    public let title: String
    public let state: PullRequestState
    public let checks: Checks
    /// "Merge after example/api #41".
    public let waitsFor: String?
    /// The row allows it and the viewer can drive the thread.
    public let canAskToFix: Bool
    public let url: String

    public init(
        id: Int, title: String, state: PullRequestState, checks: Checks, waitsFor: String?, canAskToFix: Bool,
        url: String
    ) {
        self.id = id
        self.title = title
        self.state = state
        self.checks = checks
        self.waitsFor = waitsFor
        self.canAskToFix = canAskToFix
        self.url = url
    }
}

public enum PullRequestCardRows {
    public static func make(_ panel: PullRequestsPanel) -> [PullRequestCardRow] {
        panel.pullRequests.map { row in
            PullRequestCardRow(
                id: row.rowId,
                title: row.number.map { "#\($0) \(row.repo)" } ?? row.repo,
                state: row.state,
                checks: checks(row.ci),
                waitsFor: row.mergeAfter.map { after in
                    after.number.map { "Merge after \(after.repo) #\($0)" } ?? "Merge after \(after.repo)"
                },
                canAskToFix: row.canAskToFix && panel.canDrive,
                url: row.url)
        }
    }

    private static func checks(_ ci: PullRequestChecks?) -> PullRequestCardRow.Checks {
        guard let ci else { return .none }
        switch ci.status {
        case .passing:
            return .passing(ci.totalChecks)
        case .failing:
            let failing = ci.checks.filter { $0.state == .failing }
            return .failing(failed: failing.count, total: ci.totalChecks, firstExcerpt: failing.first?.excerpt)
        case .pending:
            return .running(done: ci.passedChecks, total: ci.totalChecks)
        default:
            return .other(ci.status.rawValue)
        }
    }
}
