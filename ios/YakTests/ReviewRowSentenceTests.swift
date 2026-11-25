import Testing
import YakKit

@testable import Yak

/// What VoiceOver reads for the pull request line of a Review row.
@MainActor @Suite struct ReviewRowSentenceTests {
    private func sentence(_ pullRequests: [ReviewPullRequest], newCommits: Bool = false, others: Int = 0) -> String {
        let review = ThreadReview(
            pullRequests: pullRequests, hasNewCommitsSinceApproval: newCommits, otherApprovals: others)
        return DrawerRowView.spokenSentence(of: ReviewLine.make(review)!)
    }

    private func pullRequest(
        _ number: Int, repository: String = "example/web", mergeability: String = "unknown", checks: String? = nil
    ) -> ReviewPullRequest {
        ReviewPullRequest(
            number: number, url: "https://github.com/\(repository)/pull/\(number)", repository: repository,
            mergeability: mergeability, checks: checks)
    }

    @Test func oneRowReadsEveryFactInOrder() {
        #expect(
            sentence([pullRequest(271, mergeability: "conflicts")], newCommits: true, others: 1)
                == "Pull request 271, ready, has conflicts, new commits since your approval, 1 other approved")
    }

    @Test func passingChecksAndSeveralApprovals() {
        #expect(
            sentence([pullRequest(5, checks: "passing")], others: 3)
                == "Pull request 5, ready, checks passing, 3 others approved")
    }

    @Test func severalPullRequestsNameTheirRepositories() {
        #expect(
            sentence([pullRequest(271), pullRequest(88, repository: "example/api")])
                == "Pull requests example/web 271, example/api 88, ready")
    }
}
