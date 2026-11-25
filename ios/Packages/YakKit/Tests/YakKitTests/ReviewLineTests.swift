import Foundation
import Testing

@testable import YakKit

private func pullRequest(
    _ number: Int, repository: String = "example/web", draft: Bool = false, mergeability: String = "no_conflicts",
    checks: String? = "passing"
) -> ReviewPullRequest {
    ReviewPullRequest(
        number: number, url: "https://github.com/\(repository)/pull/\(number)", repository: repository,
        isDraft: draft, mergeability: mergeability, checks: checks)
}

private func line(
    _ pullRequests: [ReviewPullRequest], newCommits: Bool = false, others: Int = 0
) -> ReviewLine {
    ReviewLine.make(
        ThreadReview(pullRequests: pullRequests, hasNewCommitsSinceApproval: newCommits, otherApprovals: others))!
}

/// Every state of board K2, and the web's rules for which parts show.
@Suite struct ReviewLineTests {
    @Test func readyWithPassingChecks() {
        let result = line([pullRequest(262)])
        #expect(result.links.map(\.text) == ["#262"])
        #expect(result.parts == [.ready, .checksPassing])
    }

    @Test func draftWithNoChecksSeenShowsNoMark() {
        #expect(line([pullRequest(263, draft: true, checks: nil)]).parts == [.draft])
    }

    @Test func conflictsWinOverFailingChecks() {
        #expect(line([pullRequest(264, mergeability: "conflicts", checks: "failing")]).parts == [.ready, .conflicts])
    }

    @Test func failingChecksShowCIFailing() {
        #expect(line([pullRequest(267, checks: "failing")]).parts == [.ready, .ciFailing])
    }

    @Test func aDraftWithFailingChecksShowsBoth() {
        #expect(line([pullRequest(267, draft: true, checks: "failing")]).parts == [.draft, .ciFailing])
    }

    @Test func newCommitsComeAfterTheMark() {
        #expect(line([pullRequest(268)], newCommits: true).parts == [.ready, .checksPassing, .newCommitsSinceApproval])
    }

    @Test func otherApprovalsComeLastAndCountThem() {
        #expect(line([pullRequest(269)], others: 1).parts == [.ready, .checksPassing, .otherApprovals(1)])
        #expect(
            line([pullRequest(264, mergeability: "conflicts")], newCommits: true, others: 2).parts
                == [.ready, .conflicts, .newCommitsSinceApproval, .otherApprovals(2)])
    }

    @Test func noOtherApprovalsShowNothing() {
        #expect(!line([pullRequest(1)], others: 0).parts.contains(.otherApprovals(0)))
    }

    @Test func pendingAndUnknownFactsShowNothing() {
        #expect(line([pullRequest(1, mergeability: "unknown", checks: "pending")]).parts == [.ready])
        #expect(line([pullRequest(1, mergeability: "blocked", checks: "somethingNew")]).parts == [.ready])
        #expect(line([pullRequest(1, checks: nil)]).parts == [.ready])
    }

    @Test func theMarkNeedsEveryPullRequestToPass() {
        #expect(line([pullRequest(1), pullRequest(2, checks: "pending")]).parts == [.ready])
        #expect(line([pullRequest(1), pullRequest(2)]).parts == [.ready, .checksPassing])
    }

    @Test func oneDraftAmongSeveralMakesTheLineDraft() {
        #expect(line([pullRequest(1), pullRequest(2, draft: true)]).parts.first == .draft)
    }

    @Test func oneRepositoryShowsBareNumbersInOrder() {
        #expect(line([pullRequest(271), pullRequest(88)]).links.map(\.text) == ["#271", "#88"])
    }

    @Test func twoRepositoriesShowTheirNames() {
        let result = line([pullRequest(271), pullRequest(88, repository: "example/api")])
        #expect(result.links.map(\.text) == ["example/web#271", "example/api#88"])
        #expect(result.links.map(\.number) == [271, 88])
        #expect(
            result.links.map { $0.url?.absoluteString } == [
                "https://github.com/example/web/pull/271", "https://github.com/example/api/pull/88",
            ])
    }

    @Test func anUnsafeAddressLeavesTheNumberWithoutALink() {
        let unsafe = ReviewPullRequest(
            number: 5, url: "http://github.com/example/web/pull/5", repository: "example/web")
        #expect(line([unsafe]).links.first?.url == nil)
    }

    @Test func aRowWithoutPullRequestsHasNoLine() {
        #expect(ReviewLine.make(nil) == nil)
        #expect(ReviewLine.make(ThreadReview(pullRequests: [])) == nil)
    }

    @Test func linesFollowTheBoard() {
        let status: [ReviewLine.Item] = [.numbers, .part(.ready), .part(.checksPassing)]
        #expect(line([pullRequest(262)]).lines == [status])
        #expect(
            line([pullRequest(262)], newCommits: true).lines == [
                status, [.part(.newCommitsSinceApproval)],
            ])
        #expect(
            line([pullRequest(262)], newCommits: true, others: 1).lines == [
                status, [.part(.newCommitsSinceApproval), .part(.otherApprovals(1))],
            ])
        #expect(
            line([pullRequest(264, mergeability: "conflicts")], others: 1).lines == [
                [.numbers, .part(.ready), .part(.conflicts), .part(.otherApprovals(1))]
            ])
        #expect(
            line([pullRequest(271), pullRequest(88, repository: "example/api")], others: 3).lines
                == [[.numbers], [.part(.ready), .part(.checksPassing), .part(.otherApprovals(3))]])
    }

    @Test func everyPartAppearsOnExactlyOneLine() {
        let result = line([pullRequest(1)], newCommits: true, others: 2)
        let items = result.lines.flatMap { $0 }
        #expect(items.filter { $0 == .numbers }.count == 1)
        #expect(items.count == result.parts.count + 1)
    }
}
