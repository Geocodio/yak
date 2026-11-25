import Foundation

/// The pull request line under a Review row: which parts show, in which order, and how they sit on lines. The rules
/// are the web's (`ReviewLine` in the thread row) read from the app API's facts, so the view only draws.
public struct ReviewLine: Equatable, Sendable {
    /// One pull request number, its own tap target.
    public struct Link: Equatable, Sendable {
        public let number: Int
        public let repository: String
        /// `#271`, or `example/web#271` when the row's pull requests are in more than one repository.
        public let text: String
        /// The pull request on GitHub; nil when the server's address is not one the in-app browser may open.
        public let url: URL?

        /// Only a number with an address the in-app browser may open is a target, an action and a menu item; any
        /// other is plain text.
        public var isActionable: Bool { url != nil }
    }

    /// A fact after the numbers. Anything the app does not know shows nothing.
    public enum Part: Equatable, Sendable {
        case draft, ready
        case conflicts, ciFailing, checksPassing
        case newCommitsSinceApproval
        case otherApprovals(Int)
    }

    /// What one slot of a drawn line holds.
    public enum Item: Equatable, Sendable {
        case numbers
        case part(Part)
    }

    public let links: [Link]
    /// The numbers that open a pull request, in order.
    public var actionableLinks: [Link] { links.filter(\.isActionable) }
    /// The parts in the web's order: Draft or Ready, then Conflicts, CI failing or the check mark, then the new
    /// commits, then the other approvals.
    public let parts: [Part]

    /// The line of a Review row, or nil for a row without a pull request.
    public static func make(_ review: ThreadReview?) -> ReviewLine? {
        guard let review, !review.pullRequests.isEmpty else { return nil }
        let pullRequests = review.pullRequests
        let hasSeveralRepositories = Set(pullRequests.map(\.repository)).count > 1
        let links = pullRequests.map {
            Link(
                number: $0.number, repository: $0.repository,
                text: hasSeveralRepositories ? "\($0.repository)#\($0.number)" : "#\($0.number)",
                url: WebLinkRule.safeURL($0.url))
        }
        var parts: [Part] = [pullRequests.contains { $0.isDraft } ? .draft : .ready]
        // Conflicts win over failing checks; the check mark needs every pull request to have passed.
        if pullRequests.contains(where: { $0.mergeability == "conflicts" }) {
            parts.append(.conflicts)
        } else if pullRequests.contains(where: { $0.checks == "failing" }) {
            parts.append(.ciFailing)
        } else if pullRequests.allSatisfy({ $0.checks == "passing" }) {
            parts.append(.checksPassing)
        }
        if review.hasNewCommitsSinceApproval { parts.append(.newCommitsSinceApproval) }
        if review.otherApprovals > 0 { parts.append(.otherApprovals(review.otherApprovals)) }
        return ReviewLine(links: links, parts: parts)
    }

    /// The parts on their lines. The first line holds the numbers, the Draft or Ready badge and the mark after it; the
    /// new commits badge is long, so it starts the next line and the other approvals follow it. Several pull requests
    /// give their numbers a line of their own. The line count never depends on the text size: at large text the
    /// view stacks the items inside each line.
    public var lines: [[Item]] {
        let status = parts.filter { $0.isStatus }.map(Item.part)
        let commits = parts.contains(.newCommitsSinceApproval) ? [Item.part(.newCommitsSinceApproval)] : []
        let approvals = parts.compactMap { part -> Item? in
            if case .otherApprovals = part { Item.part(part) } else { nil }
        }
        var lines: [[Item]] = links.count > 1 ? [[.numbers], status] : [[.numbers] + status]
        if commits.isEmpty {
            lines[lines.count - 1] += approvals
        } else {
            lines.append(commits + approvals)
        }
        return lines
    }
}

extension ReviewLine.Part {
    /// Draft or Ready and the mark after it, the parts that share the first line.
    fileprivate var isStatus: Bool {
        switch self {
        case .draft, .ready, .conflicts, .ciFailing, .checksPassing: true
        case .newCommitsSinceApproval, .otherApprovals: false
        }
    }
}
