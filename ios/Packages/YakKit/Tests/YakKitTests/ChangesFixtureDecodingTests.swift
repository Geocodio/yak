import Foundation
import Testing

@testable import YakKit

/// Decodes the server's contract fixtures that the changes feature reads. A missing fixture is recorded as an
/// issue, so the run fails instead of passing without decoding anything.
@Suite struct ChangesFixtureDecodingTests {
    /// True when the fixture is on disk; otherwise records a failure.
    private static func isPresent(_ name: String) -> Bool {
        if Fixtures.exists(name) { return true }
        Issue.record("The server fixture \(name) is missing, so nothing was decoded.")
        return false
    }

    @Test(arguments: ["thread-diff", "thread-diff-none"])
    func decodesTheThreadDiffFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let response = try JSONDecoder().decode(ThreadDiffResponse.self, from: Fixtures.data(name))
        if name == "thread-diff" {
            let diff = try #require(response.diff)
            #expect(!diff.files.isEmpty && !diff.repositories.isEmpty)
        } else {
            #expect(response.diff == nil)
        }
    }

    @Test func aFileInAScratchRepositoryIsNotInThePullRequest() {
        let diff = ThreadDiff(
            baseSha: "a", headSha: "b", capturedAt: "2026-11-18T09:00:00Z", filesCount: 2, additions: 2, deletions: 0,
            repositories: [
                DiffRepository(repo: "example/web", role: .primary, filesCount: 1, additions: 1, deletions: 0),
                DiffRepository(
                    repo: "example/scratch", role: .changes, isThrowaway: true, filesCount: 1, additions: 1,
                    deletions: 0),
            ])
        let tracked = DiffFileSummary(id: 1, repo: "example/web", path: "a.txt", status: .added)
        let scratch = DiffFileSummary(id: 2, repo: "example/scratch", path: "b.txt", status: .added)
        let unlisted = DiffFileSummary(id: 3, repo: "example/other", path: "c.txt", status: .added)
        #expect(!diff.isThrowaway(tracked))
        #expect(diff.isThrowaway(scratch))
        #expect(!diff.isThrowaway(unlisted))
    }

    @Test(arguments: ["diff-file", "diff-file-binary", "diff-file-too-large"])
    func decodesTheDiffFileFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(DiffFileContent.self, from: Fixtures.data(name))
    }

    @Test(arguments: ["pull-requests", "pull-requests-none"])
    func decodesThePullRequestPanelFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(PullRequestsPanel.self, from: Fixtures.data(name))
    }

    @Test(arguments: [
        "pull-request-opening", "pull-request-open", "pull-request-held", "pull-requests-order",
        "pull-request-fix",
    ])
    func decodesThePullRequestStatusFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(StatusResponse.self, from: Fixtures.data(name))
    }

    @Test(arguments: ["thread-with-changes", "thread-with-pull-request"])
    func decodesTheThreadShowFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let show = try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data(name))
        if name == "thread-with-changes" {
            #expect(show.canOpenPullRequest)
            #expect(show.diffTotals?.filesCount == 3)
            #expect(!show.publishWarning)
        } else {
            #expect(show.pullRequest != nil)
            #expect(!show.canOpenPullRequest)
            #expect(show.thread.pullRequestCount == 1)
        }
    }

    @Test(arguments: ["error-private-publish", "error-nothing-to-publish", "error-ci-not-failing"])
    func decodesTheErrorFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let status = name == "error-private-publish" ? 422 : 409
        #expect(APIError.parse(status: status, body: try Fixtures.data(name)) != nil, "\(name)")
    }

    @Test(arguments: ["tasks", "tasks-empty"])
    func decodesTheTaskListFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let page = try JSONDecoder().decode(TaskListPage.self, from: Fixtures.data(name))
        if name == "tasks-empty" {
            #expect(page.tasks.isEmpty)
        } else {
            #expect(!page.tasks.isEmpty)
        }
    }

    @Test(arguments: ["task", "task-question", "task-read-only"])
    func decodesTheTaskFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let task = try JSONDecoder().decode(AppTask.self, from: Fixtures.data(name))
        switch name {
        case "task": #expect(task.threadId != nil)
        case "task-question": #expect(task.threadId == nil && task.questionRound != nil)
        default: #expect(!task.canDrive)
        }
    }

    @Test func decodesTheCoOwnerCandidatesFixture() throws {
        let name = "task-co-owner-candidates"
        guard Self.isPresent(name) else { return }
        let candidates = try JSONDecoder().decode(CoOwnerCandidates.self, from: Fixtures.data(name))
        #expect(candidates.candidates.count == 2)
    }

    @Test(arguments: ["observations", "observations-empty"])
    func decodesTheObservationFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        let page = try JSONDecoder().decode(ObservationPage.self, from: Fixtures.data(name))
        #expect(page.observations.isEmpty == (name == "observations-empty"))
    }

    @Test(arguments: [
        "task-retry", "task-cancel", "task-rerun-review", "task-retry-render", "task-reroute",
        "task-co-owner-approve", "task-co-owner-request-changes", "task-co-owner-reject",
        "task-co-owner-withdraw", "task-co-owner-tag", "task-co-owner-decline", "task-clarification-answers",
    ])
    func decodesTheTaskActionFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        _ = try JSONDecoder().decode(StatusResponse.self, from: Fixtures.data(name))
    }

    @Test(arguments: ["error-task-not-retryable", "error-already-resolved"])
    func decodesTheTaskErrorFixtures(name: String) throws {
        guard Self.isPresent(name) else { return }
        #expect(APIError.parse(status: 409, body: try Fixtures.data(name)) != nil, "\(name)")
    }

    @Test func theDiffUpdatedEventListDecodes() throws {
        let name = "events/diff-updated"
        guard Self.isPresent(name) else { return }
        let list = try EventList.loadFixture(name)
        #expect(list.stored.last?.payload == .diffUpdated)
        let root = try #require(try Fixtures.object(name) as? [String: Any])
        let catchUp = try #require(root["catchUp"] as? [String: Any])
        let events = try #require(catchUp["events"] as? [[String: Any]])
        let last = try #require(events.last)
        #expect(last["type"] as? String == "diff.updated")
        // The data is an empty object, never an empty array.
        let data = try #require(last["data"] as? [String: Any])
        #expect(data.isEmpty)
    }
}
